<?php
/**
 * Compone la introducción de autor con hechos del catálogo.
 *
 * Si el término no tiene descripción, presenta cantidad de tomos, series con
 * enlaces y editoriales. Sin productos publicados no inventa una frase. La
 * primera frase también puede servir de meta descripción cuando el dueño no
 * escribió una. La descripción editorial del término prevalece siempre y su
 * pantalla de edición muestra la alternativa automática.
 *
 * Dos consultas por autor se cachean doce horas con una clave que incorpora
 * el número de productos publicados; cambios de pertenencia cambian esa clave.
 * No se escribe termmeta editorial ni se dispara el rebuild del buscador.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeoEnhancements;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.116.30
 */
final class AuthorIntro {

	public const TAXONOMY = 'pa_autor';

	private const SERIES_TAXONOMY = 'pa_serie';

	private const BRAND_TAXONOMY = 'product_brand';

	private const TRANSIENT_PREFIX = 'akb_autor_intro_';

	private const TTL = 12 * HOUR_IN_SECONDS;

	/** Series (y editoriales) que se nombran; el resto se cuenta («y 2 más»). */
	private const SERIES_NOMBRADAS = 3;

	/** Segunda frase, siempre cierta: cada serie del catálogo tiene su guía. */
	private const CIERRE = 'Cada serie tiene su propia guía con sus tomos, disponibles o a pedido.';

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( self::TAXONOMY . '_edit_form_fields', array( $this, 'render_admin_preview' ), 10, 1 );
	}

	/**
	 * Párrafo HTML (`<p>` con enlaces a las series) para la landing, o '' si
	 * el término tiene descripción propia o el catálogo no da para una frase.
	 * La descripción propia se comprueba AQUÍ, no en cada llamador: es la
	 * regla «manda el dueño» y no puede depender de quién pregunte.
	 *
	 * @param mixed $term Término de `pa_autor`.
	 * @return string
	 */
	public function for_term( $term ): string {
		$facts = $this->facts( $term );
		if ( null === $facts ) {
			return '';
		}

		return self::compose( (string) $term->name, $facts['tomos'], $facts['series'], $facts['brands'] );
	}

	/**
	 * Primera frase en texto plano (sin entidades HTML: Rank Math escapa al
	 * imprimir), para la meta description. Se compone de los hechos, no de
	 * re-cortar el HTML: un «Dr. STONE» o un apóstrofo no la parten.
	 *
	 * @param mixed $term Término de `pa_autor`.
	 * @return string
	 */
	public function meta_description( $term ): string {
		$facts = $this->facts( $term );
		if ( null === $facts ) {
			return '';
		}

		$frase = self::first_sentence( (string) $term->name, $facts['tomos'], $facts['series'], $facts['brands'], false );

		return '' === $frase ? '' : $frase . '.';
	}

	/**
	 * Función pura: el párrafo a partir de los hechos.
	 *
	 * @param string                                   $author Nombre del autor.
	 * @param int                                      $tomos  Productos publicados.
	 * @param array<int, array{name: string, url: string}> $series Series, de más a menos tomos.
	 * @param array<int, string>                       $brands Editoriales.
	 * @return string '' si no hay tomos.
	 */
	public static function compose( string $author, int $tomos, array $series, array $brands ): string {
		$frase = self::first_sentence( $author, $tomos, $series, $brands, true );

		return '' === $frase ? '' : '<p>' . $frase . '. ' . self::CIERRE . '</p>';
	}

	/**
	 * La frase de los hechos, en HTML (escapada, series enlazadas) o en texto
	 * plano (tal cual, para una meta que Rank Math escapa al imprimir).
	 *
	 * @param string                                   $author Nombre del autor.
	 * @param int                                      $tomos  Productos publicados.
	 * @param array<int, array{name: string, url: string}> $series Series, de más a menos tomos.
	 * @param array<int, string>                       $brands Editoriales.
	 * @param bool                                     $html   ¿Con marcado?
	 * @return string Sin punto final; '' si no hay tomos.
	 */
	private static function first_sentence( string $author, int $tomos, array $series, array $brands, bool $html ): string {
		$author = trim( $author );
		if ( '' === $author || $tomos < 1 ) {
			return '';
		}

		$texto = static fn ( string $s ): string => $html ? esc_html( $s ) : $s;

		$frase = $texto( $author ) . ' en Akibara: ' . self::plural( $tomos, 'tomo', 'tomos' );

		$nombres = array();
		foreach ( $series as $serie ) {
			$name = trim( (string) ( $serie['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$url       = trim( (string) ( $serie['url'] ?? '' ) );
			$nombres[] = $html && '' !== $url
				? '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>'
				: $texto( $name );
		}

		$total_series = count( $nombres );
		if ( 1 === $total_series ) {
			$frase .= ' de la serie ' . $nombres[0];
		} elseif ( $total_series > 1 ) {
			$frase .= ' de ' . $total_series . ' series —' . self::enumerar( self::acotar( $nombres ) ) . '—';
		}

		$editoriales = array_values( array_filter( array_map( 'trim', array_map( 'strval', $brands ) ) ) );
		if ( count( $editoriales ) > 0 ) {
			$frase .= ', ' . ( 1 === $tomos ? 'editado' : 'editados' ) . ' por ' . self::enumerar( self::acotar( array_map( $texto, $editoriales ) ) );
		}

		return $frase;
	}

	/**
	 * Tres nombres y «N más»: series y editoriales por igual, para que un
	 * autor de muchas editoriales no dé una frase kilométrica.
	 *
	 * @param array<int, string> $nombres Ya escapados si toca.
	 * @return array<int, string>
	 */
	private static function acotar( array $nombres ): array {
		$vistas = array_slice( $nombres, 0, self::SERIES_NOMBRADAS );
		$resto  = count( $nombres ) - count( $vistas );
		if ( $resto > 0 ) {
			$vistas[] = $resto . ' más';
		}

		return $vistas;
	}

	/**
	 * Fila bajo el formulario de edición del término: qué texto automático
	 * sale hoy y cómo reemplazarlo. Sin esto la landing tendría un texto que
	 * el dueño no podría ver ni cambiar sin código.
	 *
	 * @param mixed $term Término en edición.
	 * @return void
	 */
	public function render_admin_preview( $term ): void {
		if ( ! is_object( $term ) || ! isset( $term->term_id ) ) {
			return;
		}

		$propia = '' !== trim( (string) ( $term->description ?? '' ) );
		$texto  = $propia ? '' : $this->for_term( $term );
		?>
		<tr class="form-field">
			<th scope="row">Texto automático de la landing</th>
			<td>
				<?php if ( $propia ) : ?>
					<p class="description">La landing muestra la descripción de arriba. Si la borras, vuelve el texto automático compuesto del catálogo (tomos, series y editoriales).</p>
				<?php elseif ( '' !== $texto ) : ?>
					<div style="max-width:40em"><?php echo wp_kses_post( $texto ); ?></div>
					<p class="description">Se compone del catálogo y se actualiza solo. Para reemplazarlo, escribe una descripción arriba.</p>
				<?php else : ?>
					<p class="description">Sin tomos publicados de este autor: la landing no lleva texto automático. Puedes escribir una descripción arriba.</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Hechos del catálogo, cacheados 12 h por término y número de productos.
	 *
	 * @param mixed $term Término.
	 * @return array{tomos: int, series: array<int, array{name: string, url: string}>, brands: array<int, string>}|null
	 */
	private function facts( $term ): ?array {
		if ( ! is_object( $term ) || ! isset( $term->term_id, $term->taxonomy, $term->name ) || self::TAXONOMY !== (string) $term->taxonomy ) {
			return null;
		}

		// Manda el dueño: con descripción propia no hay texto automático.
		if ( '' !== trim( (string) ( $term->description ?? '' ) ) ) {
			return null;
		}

		$term_id = (int) $term->term_id;
		if ( $term_id < 1 ) {
			return null;
		}

		$key    = self::TRANSIENT_PREFIX . $term_id . '_' . (int) ( $term->count ?? 0 );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['tomos'], $cached['series'], $cached['brands'] ) ) {
			return $cached;
		}

		$facts = $this->query_facts( $term_id );
		set_transient( $key, $facts, self::TTL );

		return $facts;
	}

	/**
	 * @param int $term_id Término.
	 * @return array{tomos: int, series: array<int, array{name: string, url: string}>, brands: array<int, string>}
	 */
	private function query_facts( int $term_id ): array {
		$vacio = array(
			'tomos'  => 0,
			'series' => array(),
			'brands' => array(),
		);

		$asignados = get_objects_in_term( $term_id, self::TAXONOMY );
		if ( ! is_array( $asignados ) || array() === $asignados ) {
			return $vacio;
		}

		// Sólo lo publicado: `get_objects_in_term` también trae borradores y papelera.
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'post__in'       => array_map( 'intval', $asignados ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		if ( array() === $ids ) {
			return $vacio;
		}

		$relaciones = wp_get_object_terms( $ids, array( self::SERIES_TAXONOMY, self::BRAND_TAXONOMY ), array( 'fields' => 'all_with_object_id' ) );
		if ( ! is_array( $relaciones ) ) {
			$relaciones = array();
		}

		$series = array();
		$brands = array();
		foreach ( $relaciones as $relacion ) {
			if ( ! is_object( $relacion ) || ! isset( $relacion->taxonomy, $relacion->name, $relacion->term_id ) ) {
				continue;
			}
			if ( self::SERIES_TAXONOMY === (string) $relacion->taxonomy ) {
				$id = (int) $relacion->term_id;
				if ( ! isset( $series[ $id ] ) ) {
					$link          = get_term_link( $relacion );
					$series[ $id ] = array(
						'name'  => (string) $relacion->name,
						'url'   => is_string( $link ) ? $link : '',
						'tomos' => 0,
					);
				}
				++$series[ $id ]['tomos'];
			} elseif ( self::BRAND_TAXONOMY === (string) $relacion->taxonomy ) {
				$brands[ (int) $relacion->term_id ] = (string) $relacion->name;
			}
		}

		// De más a menos tomos; a igual número, por nombre, para que la frase sea estable.
		uasort(
			$series,
			static fn ( array $a, array $b ): int => ( $b['tomos'] <=> $a['tomos'] ) ?: strnatcasecmp( $a['name'], $b['name'] )
		);
		$brands = array_values( $brands );
		natcasesort( $brands );

		return array(
			'tomos'  => count( $ids ),
			'series' => array_values(
				array_map(
					static fn ( array $s ): array => array(
						'name' => $s['name'],
						'url'  => $s['url'],
					),
					$series
				)
			),
			'brands' => array_values( $brands ),
		);
	}

	/**
	 * @param int    $n        Cantidad.
	 * @param string $singular Palabra en singular.
	 * @param string $plural   Palabra en plural.
	 * @return string
	 */
	private static function plural( int $n, string $singular, string $plural ): string {
		return number_format_i18n( $n ) . ' ' . ( 1 === $n ? $singular : $plural );
	}

	/**
	 * «A», «A y B», «A, B y C».
	 *
	 * @param array<int, string> $partes Ya escapadas.
	 * @return string
	 */
	private static function enumerar( array $partes ): string {
		$partes = array_values( $partes );
		$n      = count( $partes );
		if ( 0 === $n ) {
			return '';
		}
		if ( 1 === $n ) {
			return $partes[0];
		}
		$ultima = array_pop( $partes );

		return implode( ', ', $partes ) . ' y ' . $ultima;
	}
}
