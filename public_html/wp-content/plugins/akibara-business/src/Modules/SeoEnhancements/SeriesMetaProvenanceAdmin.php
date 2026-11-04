<?php
/**
 * SeriesMetaProvenanceAdmin — Interfaz administrativa y control de procedencia de metadatos SEO de serie.
 *
 * Muestra el estado editorial (automático vs manual) de cada serie en el listado y formulario
 * de edición de `pa_serie`, permitiendo restaurar a generación dinámica en 1 clic.
 *
 * @package Akibara\Business\Modules\SeoEnhancements
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeoEnhancements;

use Akibara\Business\Utilities\Text;

defined( 'ABSPATH' ) || exit;

final class SeriesMetaProvenanceAdmin {

	/** Taxonomía de series. */
	public const SERIES_TAXONOMY = 'pa_serie';

	/** Procedencia explícita de una descripción persistida por el generador. */
	public const SERIES_DESCRIPTION_OWNERSHIP_KEY = '_akibara_rank_math_description_ownership';

	/** Hash de la foto que seguía siendo generada al momento de marcarla. */
	public const SERIES_DESCRIPTION_SNAPSHOT_HASH_KEY = '_akibara_rank_math_description_snapshot_sha256';

	/** Procedencia explícita de un título persistido por el generador. */
	public const SERIES_TITLE_OWNERSHIP_KEY = '_akibara_rank_math_title_ownership';

	/** Hash de la foto de título. */
	public const SERIES_TITLE_SNAPSHOT_HASH_KEY = '_akibara_rank_math_title_snapshot_sha256';

	/** Valor versionado: la fila es una foto regenerable, no copy editorial. */
	public const SERIES_META_GENERATED = 'generated_snapshot_v1';

	/** Cierres canónicos de plantillas anteriores del generador. */
	public const SERIES_META_RETIRED_CLOSINGS = array(
		'Revisa precio y disponibilidad en Akibara.',
		' en español | Akibara Chile',
		' tomos | Akibara',
		' en español | Comprar en Akibara Chile',
	);

	/** El campo está vacío: el filtro del front lo compone en cada request. */
	public const SERIES_META_AUTO_LIVE = 'auto-vivo';

	/** Hay valor guardado y su hash lo acredita como foto generada. */
	public const SERIES_META_AUTO_STORED = 'auto-guardado';

	/** Hay valor guardado sin marca válida: manual/congelado. */
	public const SERIES_META_MANUAL = 'manual';

	/** Acción de admin-post.php que devuelve una serie a meta automática. */
	public const SERIES_META_RESTORE_ACTION = 'akibara_serie_seo_auto';

	/** Capability de la pantalla de términos de producto. */
	public const SERIES_TERMS_CAP = 'manage_product_terms';

	/** Generador de metadatos de serie. */
	private SeriesMetaComposer $series_meta_composer;

	/**
	 * Constructor.
	 *
	 * @param SeriesMetaComposer|null $series_meta_composer Compositor de metadatos.
	 */
	public function __construct( ?SeriesMetaComposer $series_meta_composer = null ) {
		$this->series_meta_composer = $series_meta_composer ?? new SeriesMetaComposer();
	}

	/**
	 * Comprueba si una fila sigue siendo la foto generada que fue registrada.
	 *
	 * @param int    $term_id ID del término.
	 * @param string $field   'title' | 'description'.
	 * @return bool
	 */
	public function is_generated_series_meta_field( int $term_id, string $field ): bool {
		if ( $term_id < 1 || ! in_array( $field, array( 'title', 'description' ), true ) ) {
			return false;
		}

		$is_title     = 'title' === $field;
		$value_key    = $is_title ? 'rank_math_title' : 'rank_math_description';
		$owner_key    = $is_title ? self::SERIES_TITLE_OWNERSHIP_KEY : self::SERIES_DESCRIPTION_OWNERSHIP_KEY;
		$snapshot_key = $is_title ? self::SERIES_TITLE_SNAPSHOT_HASH_KEY : self::SERIES_DESCRIPTION_SNAPSHOT_HASH_KEY;

		$value = (string) get_term_meta( $term_id, $value_key, true );
		if ( self::is_retired_generated_meta( $value ) ) {
			return true;
		}

		$owner = (string) get_term_meta( $term_id, $owner_key, true );
		if ( self::SERIES_META_GENERATED !== $owner ) {
			return false;
		}

		$snapshot_hash = strtolower( trim( (string) get_term_meta( $term_id, $snapshot_key, true ) ) );
		if ( '' === trim( $value ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $snapshot_hash ) ) {
			return false;
		}

		return hash_equals( $snapshot_hash, hash( 'sha256', $value ) );
	}

	/**
	 * Verifica si el valor corresponde a un cierre retirado del generador.
	 *
	 * @param string $value Valor a comprobar.
	 * @return bool
	 */
	public static function is_retired_generated_meta( string $value ): bool {
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
		if ( '' === $value ) {
			return false;
		}

		foreach ( self::SERIES_META_RETIRED_CLOSINGS as $closing ) {
			$offset = Text::length( $value ) - Text::length( $closing );
			if ( $offset >= 0 && $closing === Text::substr( $value, $offset, Text::length( $closing ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determina el estado de procedencia (auto-vivo, auto-guardado o manual).
	 *
	 * @param int $term_id ID del término.
	 * @return array{title:string,description:string}
	 */
	public function series_meta_provenance( int $term_id ): array {
		$state = array();
		foreach ( array( 'title' => 'rank_math_title', 'description' => 'rank_math_description' ) as $field => $value_key ) {
			$stored = trim( (string) get_term_meta( $term_id, $value_key, true ) );
			if ( '' === $stored ) {
				$state[ $field ] = self::SERIES_META_AUTO_LIVE;
				continue;
			}
			$state[ $field ] = $this->is_generated_series_meta_field( $term_id, $field )
				? self::SERIES_META_AUTO_STORED
				: self::SERIES_META_MANUAL;
		}

		return $state;
	}

	/**
	 * Etiqueta legible del estado de procedencia.
	 *
	 * @param string $state Estado de procedencia.
	 * @return string
	 */
	public static function series_meta_provenance_label( string $state ): string {
		return match ( $state ) {
			self::SERIES_META_AUTO_LIVE   => 'automático (se compone al servir la página)',
			self::SERIES_META_AUTO_STORED => 'automático (foto guardada del catálogo)',
			default                       => 'manual: congelado, ya no sigue al catálogo',
		};
	}

	/**
	 * Renderiza el bloque de procedencia en la edición de término pa_serie.
	 *
	 * @param mixed  $term     Término editado.
	 * @param string $taxonomy Taxonomía actual.
	 * @return void
	 */
	public function render_series_meta_provenance( $term, string $taxonomy = '' ): void {
		if ( self::SERIES_TAXONOMY !== $taxonomy || ! is_object( $term ) || ! isset( $term->term_id ) ) {
			return;
		}

		$term_id    = (int) $term->term_id;
		$provenance = $this->series_meta_provenance( $term_id );
		$generated  = $this->series_meta_composer->generated_series_meta( $term );
		$frozen     = self::SERIES_META_MANUAL === $provenance['title']
			|| self::SERIES_META_MANUAL === $provenance['description'];
		?>
		<tr class="form-field">
			<th scope="row">Procedencia del SEO</th>
			<td>
				<p>
					<strong>Título:</strong> <?php echo esc_html( self::series_meta_provenance_label( $provenance['title'] ) ); ?><br>
					<strong>Descripción:</strong> <?php echo esc_html( self::series_meta_provenance_label( $provenance['description'] ) ); ?>
				</p>
				<p class="description">
					El título y la descripción automáticos se componen con el catálogo
					de la serie (tomos publicados, autor y editorial) cada vez que se
					sirve la landing. Si editas el campo de Rank Math, ese valor queda
					congelado: deja de seguir al catálogo y ya no cambia cuando entra
					un tomo nuevo.
				</p>
				<?php if ( null !== $generated ) : ?>
					<p class="description">
						<strong>Lo que publicaría el modo automático hoy</strong><br>
						Título: <?php echo esc_html( '' !== $generated['title'] ? $generated['title'] : '(sin variante rica: el catálogo no la sostiene)' ); ?><br>
						Descripción: <?php echo esc_html( '' !== $generated['description'] ? $generated['description'] : '(sin variante rica: el catálogo no la sostiene)' ); ?>
					</p>
				<?php else : ?>
					<p class="description">
						El catálogo de esta serie no sostiene hoy la variante rica
						(hace falta un medio reconocible: manga, cómic o manhwa).
						Se publican las plantillas genéricas de Rank Math.
					</p>
				<?php endif; ?>
				<?php if ( $frozen && null !== $generated ) : ?>
					<p>
						<a class="button" href="<?php echo esc_url( $this->series_meta_restore_url( $term_id ) ); ?>">Volver a automático</a>
					</p>
					<p class="description">
						Reemplaza el título y la descripción guardados por la foto del
						catálogo de ahora y vuelve a marcarlos como automáticos. El
						texto manual actual se pierde.
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Construye la URL de rescate a meta automática.
	 *
	 * @param int $term_id ID del término.
	 * @return string
	 */
	public function series_meta_restore_url( int $term_id ): string {
		$url = add_query_arg(
			array(
				'action'  => self::SERIES_META_RESTORE_ACTION,
				'term_id' => $term_id,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, self::SERIES_META_RESTORE_ACTION . '_' . $term_id );
	}

	/**
	 * Callback de admin_post para restaurar metadatos a automáticos.
	 *
	 * @return void
	 */
	public function handle_restore_generated_series_meta(): void {
		$term_id = isset( $_GET['term_id'] ) ? (int) $_GET['term_id'] : 0;
		if ( $term_id < 1 ) {
			wp_die( 'Serie no indicada.' );
		}
		check_admin_referer( self::SERIES_META_RESTORE_ACTION . '_' . $term_id );
		if ( ! current_user_can( self::SERIES_TERMS_CAP ) ) {
			wp_die( 'Sin permisos.' );
		}

		$term   = get_term( $term_id, self::SERIES_TAXONOMY );
		$result = 'error';
		if ( is_object( $term ) && ! ( function_exists( 'is_wp_error' ) && is_wp_error( $term ) ) ) {
			$result = $this->restore_generated_series_meta( $term ) ? 'ok' : 'sin-foto';
		}

		if ( 'ok' === $result ) {
			do_action( 'litespeed_purge', 'T.' . $term_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de LiteSpeed.
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'taxonomy'  => self::SERIES_TAXONOMY,
					'post_type' => 'product',
					'tag_ID'    => $term_id,
					'akb_seo'   => $result,
				),
				admin_url( 'term.php' )
			)
		);
		exit;
	}

	/**
	 * Escribe la foto del catálogo como meta de Rank Math y la marca como automática.
	 *
	 * @param object $term Término de serie.
	 * @return bool
	 */
	public function restore_generated_series_meta( object $term ): bool {
		$meta = $this->series_meta_composer->generated_series_meta( $term );
		if ( null === $meta ) {
			return false;
		}

		$term_id  = (int) ( $term->term_id ?? 0 );
		$restored = false;
		$fields   = array(
			'title'       => array( 'rank_math_title', self::SERIES_TITLE_OWNERSHIP_KEY, self::SERIES_TITLE_SNAPSHOT_HASH_KEY ),
			'description' => array( 'rank_math_description', self::SERIES_DESCRIPTION_OWNERSHIP_KEY, self::SERIES_DESCRIPTION_SNAPSHOT_HASH_KEY ),
		);
		foreach ( $fields as $field => $keys ) {
			$value = (string) ( $meta[ $field ] ?? '' );
			if ( '' === trim( $value ) ) {
				continue;
			}
			list( $value_key, $owner_key, $hash_key ) = $keys;
			update_term_meta( $term_id, $value_key, $value );
			update_term_meta( $term_id, $owner_key, self::SERIES_META_GENERATED );
			update_term_meta( $term_id, $hash_key, hash( 'sha256', $value ) );
			$restored = true;
		}

		return $restored;
	}

	/**
	 * Notificación de resultado en el panel de administración.
	 *
	 * @return void
	 */
	public function render_series_meta_restore_notice(): void {
		$result = isset( $_GET['akb_seo'] ) ? sanitize_key( wp_unslash( (string) $_GET['akb_seo'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $result ) {
			return;
		}

		$message = match ( $result ) {
			'ok'       => array( 'success', 'Título y descripción SEO vuelven a generarse con el catálogo. Se limpió la caché de esta landing.' ),
			'sin-foto' => array( 'warning', 'No se pudo volver a automático: el catálogo de esta serie no sostiene hoy una foto publicable.' ),
			'error'    => array( 'error', 'No se pudo volver a automático: la serie no existe.' ),
			default    => null,
		};
		if ( null === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $message[0] ),
			esc_html( $message[1] )
		);
	}

	/**
	 * Agrega la columna SEO al listado de pa_serie.
	 *
	 * @param mixed $columns Columnas actuales.
	 * @return mixed
	 */
	public function add_series_meta_provenance_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		$columns['akb_seo'] = 'SEO';

		return $columns;
	}

	/**
	 * Renderiza el contenido de la columna SEO.
	 *
	 * @param mixed $content Contenido previo.
	 * @param mixed $column  Identificador de columna.
	 * @param mixed $term_id ID del término.
	 * @return mixed
	 */
	public function render_series_meta_provenance_column( $content, $column = '', $term_id = 0 ) {
		if ( 'akb_seo' !== (string) $column ) {
			return $content;
		}

		$provenance = $this->series_meta_provenance( (int) $term_id );
		$title_auto = self::SERIES_META_MANUAL !== $provenance['title'];
		$desc_auto  = self::SERIES_META_MANUAL !== $provenance['description'];
		if ( $title_auto && $desc_auto ) {
			return 'auto';
		}
		if ( ! $title_auto && ! $desc_auto ) {
			return 'manual';
		}

		return $title_auto ? 'título auto · desc. manual' : 'título manual · desc. auto';
	}
}
