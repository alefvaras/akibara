<?php
/**
 * Administra el tipo estructurado de la serie desde su término.
 *
 * _akb_serie_schema_type determina el tipo en la landing, fichas de tomos,
 * ItemList del directorio y fecha del sitemap. El guardado usa hooks nativos,
 * nonce, capability y term_exists(), distinguiendo ausencia de campo y vacío.
 * Sólo un cambio en lo publicado provoca purga; una importación de término sin
 * formulario no se interpreta como envío de estos campos.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesSchema;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.116.8
 */
final class SchemaTypeFields {

	/** Taxonomía de serie. */
	private const TAXONOMY = 'pa_serie';

	/** `name` del input; sin el guion bajo de la meta. */
	private const FIELD = 'akb_serie_tipo_schema';

	private const NONCE_NAME   = 'akb_serie_tipo_schema_nonce';
	private const NONCE_ACTION = 'akb_save_serie_tipo_schema';

	/** Columna propia en la lista de términos. */
	private const COLUMN = 'akb_tipo_schema';

	/**
	 * La misma capability que exige WooCommerce para esta pantalla, y la que ya
	 * usan los otros dos campos del mismo formulario.
	 */
	private const CAPABILITY = 'manage_product_terms';

	/** @var array<int,int> Series con cambio efectivo pendiente de purga. */
	private array $pendientes = array();

	private bool $purga_agendada = false;

	public function register(): void {
		add_action( self::TAXONOMY . '_add_form_fields', array( $this, 'handle_add_form_fields' ) );
		add_action( self::TAXONOMY . '_edit_form_fields', array( $this, 'handle_edit_form_fields' ), 10, 2 );
		add_action( 'created_' . self::TAXONOMY, array( $this, 'handle_save_term' ) );
		add_action( 'edited_' . self::TAXONOMY, array( $this, 'handle_save_term' ) );
		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', array( $this, 'handle_admin_columns' ) );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', array( $this, 'handle_admin_column_content' ), 10, 3 );
	}

	/**
	 * Las tres opciones, en el orden en que se leen.
	 *
	 * @return array<string, string>
	 */
	public static function choices(): array {
		return array(
			''                             => 'Sin definir — se trata como cómic o manga',
			SeriesSchemaType::COMIC_SERIES => 'Cómic o manga',
			SeriesSchemaType::NON_COMIC    => 'No es cómic (novela, artbook, guía)',
		);
	}

	/**
	 * @internal Callback de `pa_serie_add_form_fields`.
	 * @return void
	 */
	public function handle_add_form_fields(): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<div class="form-field">
			<label for="<?php echo esc_attr( self::FIELD ); ?>">Tipo de obra</label>
			<?php $this->render_select( '' ); ?>
			<p><?php $this->render_help(); ?></p>
		</div>
		<?php
	}

	/**
	 * @internal Callback de `pa_serie_edit_form_fields`.
	 *
	 * @param mixed  $term     Término editado.
	 * @param string $taxonomy Taxonomía recibida por WordPress.
	 * @return void
	 */
	public function handle_edit_form_fields( $term, $taxonomy = '' ): void {
		if ( self::TAXONOMY !== (string) $taxonomy || ! is_object( $term ) || ! isset( $term->term_id ) ) {
			return;
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<tr class="form-field">
			<th scope="row">
				<label for="<?php echo esc_attr( self::FIELD ); ?>">Tipo de obra</label>
			</th>
			<td>
				<?php $this->render_select( self::stored_value( (int) $term->term_id ) ); ?>
				<p class="description"><?php $this->render_help(); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Guarda la clasificación. Ausente ≠ vacío: ausente no se toca, vacío borra.
	 *
	 * @internal Callback de `created_pa_serie` y `edited_pa_serie`.
	 *
	 * @param int $term_id Término creado o editado.
	 * @return void
	 */
	public function handle_save_term( int $term_id ): void {
		$posted = $_POST[ self::NONCE_NAME ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- éste ES el nonce; se desescapa y sanea justo abajo tras comprobar el tipo.
		$nonce  = is_string( $posted ) ? sanitize_text_field( wp_unslash( $posted ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION )
			|| ! current_user_can( self::CAPABILITY )
			|| ! term_exists( $term_id, self::TAXONOMY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificado arriba.
		if ( ! array_key_exists( self::FIELD, $_POST ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verificado arriba; se desescapa y sanea en la línea siguiente tras comprobar el tipo.
		$crudo = $_POST[ self::FIELD ];
		$valor = is_string( $crudo ) ? sanitize_text_field( wp_unslash( $crudo ) ) : '';
		if ( ! array_key_exists( $valor, self::choices() ) ) {
			return; // Un valor que no está en la lista no puede reclasificar una serie.
		}

		// Se compara con lo que HAY escrito, no con su lectura saneada: una meta
		// fuera de la lista se pinta como «sin definir», pero
		// `SeriesSchemaType::supports_comic_series()` la trata como «no es cómic».
		// Comparando la lectura saneada, elegir «sin definir» sobre esa basura
		// salía sin escribir y la serie seguía sin ComicSeries con la pantalla
		// diciendo lo contrario.
		$guardado = get_term_meta( $term_id, SeriesSchemaType::META_KEY, true );
		if ( is_string( $guardado ) && $guardado === $valor ) {
			return;
		}

		$era_comic = SeriesSchemaType::supports_comic_series( $term_id );

		if ( '' === $valor ) {
			delete_term_meta( $term_id, SeriesSchemaType::META_KEY );
		} else {
			update_term_meta( $term_id, SeriesSchemaType::META_KEY, $valor );
		}

		// La purga total se paga sólo si la serie ENTRA o SALE de «no es cómic».
		// «Sin definir» y «Cómic o manga» publican exactamente el mismo JSON-LD
		// (`supports_comic_series()` da true con las dos), y marcar «Cómic o
		// manga» serie por serie —el trabajo natural tras estrenar el campo—
		// dejaba el sitio entero sin caché una vez por serie sin un byte
		// distinto: la misma sesión de 104 guardados por la que
		// `PublicationStatusFields::purge_landing()` descartó `purge_all`. Lo que
		// sí cambia con la meta (fecha del sitemap, directorio) lo recogen sus
		// propios oyentes de termmeta, que no purgan el sitio.
		if ( SeriesSchemaType::supports_comic_series( $term_id ) !== $era_comic ) {
			$this->queue_purge( $term_id );
		}
	}

	/**
	 * Añade la columna «Tipo» después del nombre.
	 *
	 * @internal Callback de `manage_edit-pa_serie_columns`.
	 *
	 * @param mixed $columns Columnas de la lista de términos.
	 * @return array<string, string>
	 */
	public function handle_admin_columns( $columns ): array {
		if ( ! is_array( $columns ) ) {
			return array( self::COLUMN => 'Tipo' );
		}

		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'name' === $key ) {
				$out[ self::COLUMN ] = 'Tipo';
			}
		}
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = 'Tipo';
		}

		return $out;
	}

	/**
	 * @internal Callback de `manage_pa_serie_custom_column`.
	 *
	 * @param mixed $content Contenido previo.
	 * @param mixed $column  Columna pedida.
	 * @param mixed $term_id Término de la fila.
	 * @return string
	 */
	public function handle_admin_column_content( $content, $column, $term_id ): string {
		if ( self::COLUMN !== (string) $column ) {
			return is_string( $content ) ? $content : '';
		}

		$valor = self::stored_value( (int) $term_id );

		return SeriesSchemaType::NON_COMIC === $valor ? 'No es cómic' : 'Cómic o manga';
	}

	/**
	 * Purga pendiente del request, deduplicada.
	 *
	 * @param int $term_id Serie reclasificada.
	 * @return void
	 */
	private function queue_purge( int $term_id ): void {
		if ( $term_id < 1 ) {
			return;
		}

		$this->pendientes[ $term_id ] = $term_id;
		if ( $this->purga_agendada ) {
			return;
		}

		$this->purga_agendada = true;
		add_action( 'shutdown', array( $this, 'flush_pending_purges' ), 99 );
	}

	/**
	 * Reclasificar una serie cambia el JSON-LD de su landing Y el de las fichas
	 * de todos sus tomos, así que la purga por etiqueta de término no basta.
	 *
	 * @internal Callback de `shutdown` y seam de pruebas.
	 * @return void
	 */
	public function flush_pending_purges(): void {
		$terminos             = array_values( $this->pendientes );
		$this->pendientes     = array();
		$this->purga_agendada = false;

		if ( array() === $terminos || ! function_exists( 'do_action' ) ) {
			return;
		}

		foreach ( $terminos as $term_id ) {
			// `T.` es `LiteSpeed\Tag::TYPE_ARCHIVE_TERM`; no se referencia la clase
			// para que Business siga siendo activable sin LiteSpeed.
			do_action( 'litespeed_purge', 'T.' . $term_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LiteSpeed (tercero).
		}

		// Reclasificar la serie afecta también al JSON-LD de sus fichas. Aplicar
		// la purga que retira la copia de invitado una sola vez por petición.

		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LiteSpeed (tercero).
	}

	/**
	 * Valor guardado, saneado contra la lista: lo que no esté en ella se lee
	 * como «sin definir», igual que hace el frontend.
	 *
	 * @param int $term_id Serie.
	 * @return string
	 */
	private static function stored_value( int $term_id ): string {
		if ( $term_id < 1 || ! function_exists( 'get_term_meta' ) ) {
			return '';
		}

		$valor = get_term_meta( $term_id, SeriesSchemaType::META_KEY, true );
		$valor = is_string( $valor ) ? $valor : '';

		return array_key_exists( $valor, self::choices() ) ? $valor : '';
	}

	/**
	 * @param string $seleccionado Valor guardado.
	 * @return void
	 */
	private function render_select( string $seleccionado ): void {
		printf( '<select name="%1$s" id="%1$s">', esc_attr( self::FIELD ) );
		foreach ( self::choices() as $valor => $etiqueta ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $valor ),
				selected( $seleccionado, $valor, false ),
				esc_html( $etiqueta )
			);
		}
		echo '</select>';
	}

	private function render_help(): void {
		echo esc_html(
			'Decide cómo se describe la serie para Google: «cómic o manga» o una obra que no lo es '
			. '(una novela catalogada bajo Manga sigue siendo novela). Cambia los datos estructurados de '
			. 'la landing de la serie y de las fichas de sus tomos. Si la serie pasa a «no es cómic» o vuelve de ahí, '
			. 'al guardar se limpia la caché del sitio; entre «sin definir» y «cómic o manga» no cambia nada visible y no se limpia.'
		);
	}
}
