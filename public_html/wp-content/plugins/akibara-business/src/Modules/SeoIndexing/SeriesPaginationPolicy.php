<?php
/**
 * SeriesPaginationPolicy — elegibilidad e indexación de /serie/<slug>/.
 *
 * Las páginas reales de paginación amplían la colección con tomos distintos y
 * son rutas de descubrimiento útiles. La política local corrige únicamente la
 * taxonomía `pa_serie`: cada página limpia elegible queda `index,follow` y
 * apunta a su propio canonical. Una landing sin productos publicados y
 * visibles queda `noindex,follow`, aunque el conteo bruto de WordPress sea
 * positivo. Las variantes con filtros u ordenamiento quedan fuera para que
 * `FilteredUrlsPolicy` conserve su contrato `noindex,follow`.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeoIndexing;

use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Utilities\RobotsMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Política de elegibilidad e indexación de una landing de serie.
 */
final class SeriesPaginationPolicy {

	/** Taxonomía de las landings `/serie/<slug>/`. */
	private const TAXONOMY = 'pa_serie';

	/** Meta de término donde Rank Math guarda las directivas robots. */
	private const ROBOTS_META_KEY = 'rank_math_robots';

	/** Autoridad compartida para decidir si la landing tiene catálogo público. */
	private SeriesCatalogEligibility $eligibility;

	/** Evita repetir la señal no-cache si varios filtros leen el mismo fallo. */
	private bool $request_marked_uncacheable = false;

	/**
	 * Crea la política con la misma autoridad usada por directorio y sitemap.
	 *
	 * @since 1.102.3
	 */
	public function __construct( ?SeriesCatalogEligibility $eligibility = null ) {
		$this->eligibility = $eligibility ?? new SeriesCatalogEligibility();
	}

	/**
	 * Registra robots y canonical después de la configuración base de Rank Math.
	 *
	 * La prioridad 98 precede a `FilteredUrlsPolicy` en Rank Math. En WordPress
	 * el guard de query string evita sobrescribir su `noindex` de prioridad 10.
	 */
	public function register(): void {
		add_filter( 'rank_math/frontend/robots', array( $this, 'handle_rank_math_robots' ), 98 );
		add_filter( 'wp_robots', array( $this, 'handle_wordpress_robots' ), 98 );
		add_filter( 'rank_math/frontend/canonical', array( $this, 'handle_rank_math_canonical' ), 98 );
	}

	/**
	 * Afirma `index,follow` para una página paginada limpia de serie.
	 *
	 * @internal Callback de `rank_math/frontend/robots`.
	 *
	 * @param mixed $robots Directivas actuales de Rank Math.
	 * @return mixed
	 */
	public function handle_rank_math_robots( $robots ) {
		if ( ! is_array( $robots ) ) {
			return $robots;
		}

		$catalogue_state = $this->current_catalogue_state();
		if ( false === $catalogue_state && $this->queried_series_is_indexable() ) {
			$robots['index']  = 'noindex';
			$robots['follow'] = 'follow';
			unset( $robots['noindex'], $robots['nofollow'] );

			return $robots;
		}

		if ( true !== $catalogue_state || ! $this->applies_to_current_request() ) {
			return $robots;
		}

		$robots['index']  = 'index';
		$robots['follow'] = 'follow';
		unset( $robots['noindex'], $robots['nofollow'] );

		return $robots;
	}

	/**
	 * Afirma el mismo veredicto en la representación de WordPress.
	 *
	 * @internal Callback de `wp_robots`.
	 *
	 * @param array<string,mixed> $robots Directivas actuales.
	 * @return array<string,mixed>
	 */
	public function handle_wordpress_robots( array $robots ): array {
		$catalogue_state = $this->current_catalogue_state();
		if ( false === $catalogue_state && $this->queried_series_is_indexable() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'], $robots['nofollow'] );

			return $robots;
		}

		if ( true !== $catalogue_state || ! $this->applies_to_current_request() ) {
			return $robots;
		}

		$robots['index']  = true;
		$robots['follow'] = true;
		unset( $robots['noindex'], $robots['nofollow'] );

		return $robots;
	}

	/**
	 * Devuelve el permalink de la página actual como canonical.
	 *
	 * `get_pagenum_link()` usa la base de paginación y las reglas de permalink
	 * efectivas de WordPress; reconstruir `/page/N/` localmente duplicaría esa
	 * lógica y fallaría si cambia la configuración del sitio.
	 *
	 * @internal Callback de `rank_math/frontend/canonical`.
	 *
	 * @param mixed $canonical Canonical calculado por Rank Math.
	 * @return mixed
	 */
	public function handle_rank_math_canonical( $canonical ) {
		if ( ! is_string( $canonical ) || ! $this->applies_to_current_request() || ! function_exists( 'get_pagenum_link' ) ) {
			return $canonical;
		}

		$self_canonical = get_pagenum_link( $this->current_page() );

		return is_string( $self_canonical ) && '' !== trim( $self_canonical )
			? $self_canonical
			: $canonical;
	}

	/**
	 * Limita la corrección a páginas 2+ limpias de `pa_serie`.
	 *
	 * Cualquier query string se delega. Así una URL como
	 * `/serie/one-piece/page/2/?orderby=price` nunca recupera `index` después de
	 * que `FilteredUrlsPolicy` la marcó como variante no indexable.
	 *
	 * @internal Expuesto para probar el guard completo.
	 */
	public function applies_to_current_request(): bool {
		if (
			null === $this->queried_series_term()
			|| ! empty( $_GET ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo detecta la presencia de parámetros.
			|| ! $this->queried_series_is_indexable()
			|| true !== $this->current_catalogue_state()
		) {
			return false;
		}

		return $this->current_page() > 1;
	}

	/**
	 * Conserva el `noindex` editorial del término en todas sus páginas.
	 *
	 * Rank Math puede guardar la directiva como lista, string plano o string
	 * serializado. Ante un término o meta robots ilegible se falla cerrado: esta
	 * política correctiva no debe convertir en indexable una URL incierta.
	 */
	private function queried_series_is_indexable(): bool {
		if ( ! function_exists( 'get_term_meta' ) ) {
			return false;
		}

		$term = $this->queried_series_term();
		if ( null === $term ) {
			return false;
		}

		return RobotsMeta::is_indexable(
			get_term_meta( (int) $term->term_id, self::ROBOTS_META_KEY, true )
		);
	}

	/**
	 * Devuelve la elegibilidad final, o null si no hay una lectura confiable.
	 *
	 * Un fallo de base de datos nunca se interpreta como catálogo vacío. La
	 * respuesta actual conserva sus robots y queda fuera del cache de página
	 * para que el siguiente request pueda reintentar.
	 *
	 * Un catálogo vacío LEÍDO BIEN, en cambio, sí se cachea. La señal no-cache
	 * era para la lectura incierta, pero se aplicaba también al cero legítimo:
	 * las 13 landings de serie sin productos públicos quedaban condenadas a un
	 * render completo en cada visita y en cada paso de rastreador, para servir
	 * un veredicto (`noindex,follow`) que no cambia hasta que se publique un
	 * tomo — y ese día lo invalida la purga del catálogo, no el TTL.
	 */
	private function current_catalogue_state(): ?bool {
		$term = $this->queried_series_term();
		if ( null === $term || ! isset( $term->term_taxonomy_id ) ) {
			return null;
		}

		$is_eligible = $this->eligibility->is_eligible( (int) $term->term_taxonomy_id );
		if ( ! $this->eligibility->is_reliable() ) {
			$this->mark_request_uncacheable( 'series catalogue eligibility unavailable' );

			return null;
		}

		return $is_eligible;
	}

	/** Devuelve el término consultado únicamente dentro de `pa_serie`. */
	private function queried_series_term(): ?object {
		if (
			! function_exists( 'is_tax' )
			|| is_admin()
			|| ! is_tax( self::TAXONOMY )
			|| ! function_exists( 'get_queried_object' )
		) {
			return null;
		}

		$term = get_queried_object();
		if (
			! is_object( $term )
			|| ! isset( $term->term_id, $term->taxonomy )
			|| self::TAXONOMY !== (string) $term->taxonomy
		) {
			return null;
		}

		return $term;
	}

	/** Impide congelar un veredicto SEO producido durante una lectura fallida. */
	private function mark_request_uncacheable( string $reason ): void {
		if ( $this->request_marked_uncacheable ) {
			return;
		}

		$this->request_marked_uncacheable = true;
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constante estándar de facto de los plugins de caché (LiteSpeed, W3TC, WP Super Cache): prefijarla la dejaría inerte.
		}
		do_action( 'litespeed_control_set_nocache', $reason ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
	}

	/**
	 * Devuelve la página actual o uno cuando no hay paginación.
	 */
	private function current_page(): int {
		if ( ! function_exists( 'get_query_var' ) ) {
			return 1;
		}

		return max( 1, (int) get_query_var( 'paged' ) );
	}
}
