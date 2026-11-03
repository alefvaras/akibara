<?php
/**
 * PublicationStatusFields — obra original y edición vendida en `pa_serie`.
 *
 * Hooks nativos de la taxonomía, más la frontera de termmeta:
 *   - `pa_serie_add_form_fields`  → campos al crear el término.
 *   - `pa_serie_edit_form_fields` → campos al editarlo.
 *   - `created_pa_serie` / `edited_pa_serie` → guardado del formulario.
 *   - `added/updated/deleted_term_meta` → purga tras WP-CLI, REST o import,
 *     tanto para publicación como para el head SEO y la guía relacionada.
 *   - `manage_edit-pa_serie_columns` / `manage_pa_serie_custom_column` → estado
 *     de la edición en la lista de términos, la única pantalla que muestra las
 *     115 series de una vez.
 *
 * Cero deploy para cargar los datos: el dueño entra a Productos → Atributos →
 * Serie, edita el término, elige el estado y guarda.
 *
 * ── Por qué el guardado revisa nonce Y capability, si es flujo nativo ───────
 * `edited_{taxonomy}` lo dispara CUALQUIER `wp_update_term()`, no sólo la
 * pantalla de edición: una importación, un cron, otro plugin. En ese camino no
 * hay formulario, no hay nonce y el usuario efectivo puede ser cualquiera. Los
 * tres guards de `SeriesRelatedGuide::handle_save_term()` —nonce, capability y
 * `term_exists()` sobre ESTA taxonomía— son la doctrina ya escrita en el repo
 * y acá se copian tal cual.
 *
 * ── Por qué cada campo se mira por separado ────────────────────────────────
 * El guardado sólo toca la meta cuyo campo VIENE en el POST. Si algún día otra
 * pantalla guarda el término con un formulario que no incluya estos cuatro
 * inputs, un guardado ciego los leería como "vacío" y borraría el trabajo manual
 * de 104 series sin que nadie se entere. Ausente ≠ vacío: ausente no se toca,
 * vacío borra.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFacts;

defined( 'ABSPATH' ) || exit;

/**
 * Editor del estado de publicación en la UI nativa de `pa_serie`.
 *
 * @since 1.100.0
 */
final class PublicationStatusFields {

	/** Taxonomía de serie. Las landings viven en sus archives. */
	private const TAXONOMY = 'pa_serie';

	/**
	 * `name` de los inputs. SIN el guion bajo inicial de las metas: ése es la
	 * convención de `wp_termmeta` que usa el `uninstall.php`, no la del
	 * formulario, y arrastrarlo hasta el HTML sólo invita a confundir la clave
	 * guardada con el campo que la trae.
	 */
	private const FIELD_WORK_STATE    = 'akb_serie_estado';
	private const FIELD_WORK_TOTAL    = 'akb_serie_tomos_obra';
	private const FIELD_EDITION_STATE = 'akb_serie_estado_edicion';
	private const FIELD_EDITION_TOTAL = 'akb_serie_tomos_edicion';

	private const NONCE_NAME   = 'akb_serie_publicacion_nonce';
	private const NONCE_ACTION = 'akb_save_serie_publicacion';

	/** Clave de la columna propia en la lista de términos. */
	private const COLUMN = 'akb_publicacion';

	/**
	 * Capability de la taxonomía, no `manage_categories`.
	 *
	 * WooCommerce registra los atributos de producto con
	 * `'manage_terms' => 'manage_product_terms'`, así que ésta es la que WordPress
	 * exige para llegar a esta pantalla. Es la misma que verifica
	 * `SeriesRelatedGuide` sobre esta misma taxonomía; usar otra dejaría dos
	 * campos del MISMO formulario con dos umbrales distintos.
	 */
	private const CAPABILITY = 'manage_product_terms';

	/** Metas públicas ajenas al formulario que también cambian la landing. */
	private const LANDING_META_KEYS = array(
		'rank_math_title',
		'rank_math_description',
		'rank_math_canonical_url',
		'_akb_related_guide_post_id',
	);

	/** @var array<int,int> Landings con cambios efectivos pendientes de purga. */
	private array $pending_purge_term_ids = array();

	/** Impide registrar más de una barrera tardía por request. */
	private bool $purge_scheduled = false;

	/**
	 * Dos hooks de render, dos de guardado y tres de invalidación directa.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::TAXONOMY . '_add_form_fields', array( $this, 'handle_add_form_fields' ) );
		add_action( self::TAXONOMY . '_edit_form_fields', array( $this, 'handle_edit_form_fields' ), 10, 2 );
		add_action( 'created_' . self::TAXONOMY, array( $this, 'handle_save_term' ) );
		add_action( 'edited_' . self::TAXONOMY, array( $this, 'handle_save_term' ) );
		add_action( 'added_term_meta', array( $this, 'handle_publication_meta_changed' ), 10, 3 );
		add_action( 'updated_term_meta', array( $this, 'handle_publication_meta_changed' ), 10, 3 );
		add_action( 'deleted_term_meta', array( $this, 'handle_publication_meta_changed' ), 10, 3 );

		// La lista de términos es la única pantalla que muestra las 115 series a la
		// vez, y hasta ahora no decía NADA de su estado: para saber cuáles faltaban
		// por cargar había que abrir una por una. Misma mecánica que
		// `BrandsDirectory\BrandFeatured` sobre `product_brand`.
		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', array( $this, 'handle_admin_columns' ) );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', array( $this, 'handle_admin_column_content' ), 10, 3 );
	}

	/**
	 * Añade la columna «Publicación» después del nombre.
	 *
	 * @internal Callback de `manage_edit-pa_serie_columns`.
	 *
	 * @param mixed $columns Columnas de la lista de términos.
	 * @return array<string, string>
	 */
	public function handle_admin_columns( $columns ): array {
		if ( ! is_array( $columns ) ) {
			return array( self::COLUMN => 'Publicación' );
		}

		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'name' === $key ) {
				$out[ self::COLUMN ] = 'Publicación';
			}
		}

		// Si otro plugin quitó la columna `name`, la nuestra va igual al final:
		// una lista sin el dato es exactamente el problema que esto viene a cerrar.
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = 'Publicación';
		}

		return $out;
	}

	/**
	 * Pinta el estado de la edición vendida, que es el que gobierna la landing.
	 *
	 * @internal Callback de `manage_pa_serie_custom_column`.
	 *
	 * @param mixed $content Contenido previo de la celda.
	 * @param mixed $column  Columna que se está pintando.
	 * @param mixed $term_id Término de la fila.
	 * @return string
	 */
	public function handle_admin_column_content( $content, $column, $term_id ): string {
		if ( self::COLUMN !== (string) $column ) {
			return (string) $content;
		}

		$facts = PublicationStatus::scoped_for_term( (int) $term_id );
		$state = (string) $facts['edicion']['estado'];

		if ( '' === $state ) {
			// El guion gris es la señal de «falta cargar esta serie», y es la razón
			// de ser de la columna: se lee de un vistazo sobre las 115 filas.
			return '<span style="color:#ccc">— Sin definir —</span>';
		}

		// La columna usa PublicationStatus::edition_phrase(), igual que la landing.
		// El total sólo se muestra en estados que lo admiten, sin publicar otra
		// interpretación en la pantalla del dueño.

		$total  = (int) $facts['edicion']['tomos'];
		$phrase = PublicationStatus::edition_phrase( $state, $total );
		if ( array() === $phrase ) {
			return '<span style="color:#ccc">— Sin definir —</span>';
		}

		return '' !== $phrase['detalle']
			? esc_html( $phrase['titular'] . ' · ' . $phrase['detalle'] )
			: esc_html( $phrase['titular'] );
	}

	/**
	 * Campos al crear un término. `<div class="form-field">` es el marcado que
	 * espera la pantalla "Añadir término" de WordPress.
	 *
	 * @internal Callback de `pa_serie_add_form_fields`.
	 *
	 * @return void
	 */
	public function handle_add_form_fields(): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<div class="form-field">
			<label for="<?php echo esc_attr( self::FIELD_EDITION_STATE ); ?>">Estado de la edición vendida</label>
			<?php $this->render_state_select( self::FIELD_EDITION_STATE, '', PublicationStatus::edition_states() ); ?>
			<p><?php $this->render_edition_state_help(); ?></p>
		</div>
		<div class="form-field">
			<label for="<?php echo esc_attr( self::FIELD_EDITION_TOTAL ); ?>">Tomos previstos de la edición</label>
			<?php $this->render_total_input( self::FIELD_EDITION_TOTAL, 0 ); ?>
			<p><?php $this->render_edition_total_help(); ?></p>
		</div>
		<div class="form-field">
			<label for="<?php echo esc_attr( self::FIELD_WORK_STATE ); ?>">Estado de la obra original</label>
			<?php $this->render_state_select( self::FIELD_WORK_STATE, '', PublicationStatus::work_states() ); ?>
			<p><?php $this->render_work_state_help(); ?></p>
		</div>
		<div class="form-field">
			<label for="<?php echo esc_attr( self::FIELD_WORK_TOTAL ); ?>">Tomos de la obra original finalizada</label>
			<?php $this->render_total_input( self::FIELD_WORK_TOTAL, 0 ); ?>
			<p><?php $this->render_work_total_help(); ?></p>
		</div>
		<?php
	}

	/**
	 * Campos al editar un término. `<tr class="form-field">` es el marcado que
	 * espera la tabla `form-table` de la pantalla "Editar término".
	 *
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

		// Se lee por el MISMO camino que el frontend, saneado incluido: si algo
		// guardado fuera del enum no se va a publicar, el formulario tampoco
		// puede mostrarlo como si estuviera seleccionado.
		$stored = PublicationStatus::scoped_for_term( (int) $term->term_id );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<tr class="form-field">
			<th scope="row">
				<label for="<?php echo esc_attr( self::FIELD_EDITION_STATE ); ?>">Estado de la edición vendida</label>
			</th>
			<td>
				<?php $this->render_state_select( self::FIELD_EDITION_STATE, $stored['edicion']['estado'], PublicationStatus::edition_states() ); ?>
				<p class="description"><?php $this->render_edition_state_help(); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row">
				<label for="<?php echo esc_attr( self::FIELD_EDITION_TOTAL ); ?>">Tomos previstos de la edición</label>
			</th>
			<td>
				<?php $this->render_total_input( self::FIELD_EDITION_TOTAL, $stored['edicion']['tomos'] ); ?>
				<p class="description"><?php $this->render_edition_total_help(); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row">
				<label for="<?php echo esc_attr( self::FIELD_WORK_STATE ); ?>">Estado de la obra original</label>
			</th>
			<td>
				<?php $this->render_state_select( self::FIELD_WORK_STATE, $stored['obra']['estado'], PublicationStatus::work_states() ); ?>
				<p class="description"><?php $this->render_work_state_help(); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row">
				<label for="<?php echo esc_attr( self::FIELD_WORK_TOTAL ); ?>">Tomos de la obra original finalizada</label>
			</th>
			<td>
				<?php $this->render_total_input( self::FIELD_WORK_TOTAL, $stored['obra']['tomos'] ); ?>
				<p class="description"><?php $this->render_work_total_help(); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row">Descuentos de esta serie</th>
			<td>
				<?php $this->render_bundles_link(); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Enlace a la otra pantalla que configura ESTA MISMA serie.
	 *
	 * La configuración de una serie vive repartida: acá el estado de publicación,
	 * en `SeriesEditions` las ediciones, y los tramos de «Arma tu Pack» en el
	 * panel de Akibara. Ninguna enlazaba a las otras, así que para tocar los dos
	 * lados había que saberse las rutas de memoria.
	 *
	 * El enlace apunta a la PESTAÑA y no a un ancla de la serie: el
	 * `<details class="akb-sb-series">` que pinta cada serie en
	 * `SeriesBundles\Admin\BypConfigController::render_series_block()` no lleva
	 * atributo `id`, y un `#akb-sb-series-<id>` sería un enlace a un ancla que no
	 * existe —el navegador lo ignora y el dueño cree que el panel se equivocó.
	 */
	private function render_bundles_link(): void {
		if ( ! function_exists( 'admin_url' ) || ! function_exists( 'add_query_arg' ) ) {
			return;
		}

		$url = add_query_arg(
			array(
				'page' => 'akibara-panel',
				'tab'  => 'series_bundles',
			),
			admin_url( 'admin.php' )
		);
		?>
		<a href="<?php echo esc_url( $url ); ?>">Configurar «Arma tu Pack» de las series</a>
		<p class="description">
			Los tramos de descuento por serie y los tomos elegibles se editan en el panel de Akibara,
			no en esta pantalla.
		</p>
		<?php
	}

	/**
	 * Persiste lo que venga, campo por campo, o no toca nada.
	 *
	 * @internal Callback de `created_pa_serie` y `edited_pa_serie`.
	 *
	 * @param int $term_id Término creado o editado.
	 * @return void
	 */
	public function handle_save_term( int $term_id ): void {
		// El `is_string()` va ANTES del saneo y no después: `sanitize_text_field()`
		// sobre un array (`campo[]=…`) emite un warning de PHP 8 antes de
		// devolver nada, y un nonce que llega como array no es un ataque
		// sofisticado — es cualquiera escribiendo la URL a mano.
		$posted = $_POST[ self::NONCE_NAME ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- éste ES el nonce, y se desescapa y sanea en la línea siguiente tras comprobar el tipo.
		$nonce  = is_string( $posted ) ? sanitize_text_field( wp_unslash( $posted ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION )
			|| ! current_user_can( self::CAPABILITY )
			|| ! term_exists( $term_id, self::TAXONOMY ) ) {
			return;
		}

		// Se lee ANTES y se compara DESPUÉS en vez de purgar siempre: este hook lo
		// dispara cualquier guardado del término —cambiar la descripción, corregir
		// el slug— y el formulario reenvía los cuatro campos con el mismo valor. Sin
		// la comparación, editar la descripción de una serie purgaría su landing
		// por un dato que no cambió.
		$before = PublicationStatus::scoped_for_term( $term_id );

		$this->save_state( $term_id, self::FIELD_EDITION_STATE, PublicationStatus::META_EDITION_STATE, true );
		$this->save_total( $term_id, self::FIELD_EDITION_TOTAL, PublicationStatus::META_EDITION_TOTAL );
		$this->save_state( $term_id, self::FIELD_WORK_STATE, PublicationStatus::META_WORK_STATE, false );
		$this->save_total( $term_id, self::FIELD_WORK_TOTAL, PublicationStatus::META_WORK_TOTAL );

		if ( $before !== PublicationStatus::scoped_for_term( $term_id ) ) {
			$this->queue_landing_purge( $term_id );
		}
	}

	/**
	 * Detecta escrituras directas por API, WP-CLI, imports u otros plugins.
	 *
	 * El mismo borde cubre title/description/canonical de Rank Math y la guía
	 * relacionada: todos salen en el HTML de la landing, pero `update_term_meta()`
	 * no dispara `edited_pa_serie` ni una purga nativa de LiteSpeed.
	 *
	 * @param mixed  $meta_id  Meta ID or IDs supplied by WordPress.
	 * @param int    $term_id  Changed term ID.
	 * @param string $meta_key Changed metadata key.
	 */
	public function handle_publication_meta_changed( $meta_id, int $term_id, string $meta_key ): void {
		unset( $meta_id );

		if ( ! in_array( $meta_key, self::landing_meta_keys(), true ) ) {
			return;
		}

		$exists = term_exists( $term_id, self::TAXONOMY );
		if ( ! $exists || ( function_exists( 'is_wp_error' ) && is_wp_error( $exists ) ) ) {
			return;
		}

		$this->queue_landing_purge( $term_id );
	}

	/**
	 * Purga una vez el estado final de todas las landings modificadas.
	 *
	 * @internal Callback de `shutdown` y seam de pruebas.
	 */
	public function flush_pending_landing_purges(): void {
		$term_ids                    = array_values( $this->pending_purge_term_ids );
		$this->pending_purge_term_ids = array();

		foreach ( $term_ids as $term_id ) {
			$this->purge_landing( $term_id );
		}
	}

	/** Encola y deduplica una landing hasta que terminó el request. */
	private function queue_landing_purge( int $term_id ): void {
		if ( $term_id < 1 ) {
			return;
		}

		$this->pending_purge_term_ids[ $term_id ] = $term_id;
		if ( $this->purge_scheduled ) {
			return;
		}

		add_action( 'shutdown', array( $this, 'flush_pending_landing_purges' ), PHP_INT_MAX );
		$this->purge_scheduled = true;
	}

	/** @return list<string> */
	private static function landing_meta_keys(): array {
		return array_merge(
			array(
				PublicationStatus::META_WORK_STATE,
				PublicationStatus::META_WORK_TOTAL,
				PublicationStatus::META_EDITION_STATE,
				PublicationStatus::META_EDITION_TOTAL,
			),
			self::LANDING_META_KEYS
		);
	}

	/**
	 * Tira abajo la copia cacheada de la landing de esta serie.
	 *
	 * ── Por qué hace falta, si el módulo evitó a propósito el transient ─────────
	 * `PublicationStatus::for_term()` esquiva el transient de 6 h de `SeriesFacts`
	 * justamente para que un dato recién escrito salga en la página al instante. El
	 * retardo reaparecía una capa más arriba: la landing se sirve desde la caché de
	 * PÁGINA de LiteSpeed y LiteSpeed sólo purga por eventos de
	 * POST —`purge.cls.php` engancha `delete_post`, `wp_trash_post`,
	 * `wp_update_comment_count` y `transition_post_status`—, ninguno de los cuales
	 * dispara al guardar un TÉRMINO. Sin esto, el dueño elegía «Completa», ponía 23,
	 * guardaba, abría la landing y no veía nada; multiplicado por las 104 series que
	 * va a cargar de una sentada, el síntoma es «cargué todo y no salió»: la misma
	 * forma de parecer roto sin estarlo que el transient se cuidó de evitar.
	 *
	 * ── Por qué la etiqueta del término y no `litespeed_purge_all` ─────────────
	 * El repo tiene las dos formas escritas (`AnnouncementBar`, `HomeHero\AdminUI`,
	 * `CatalogOrder` y `TaxonomyDiscount\LscPurger` disparan `litespeed_purge_all`),
	 * pero ésas cambian algo que sale en TODAS las páginas. Acá cambia UNA landing,
	 * y la carga de las 104 series es una sesión seguida de guardados: con
	 * `purge_all` cada guardado dejaría el sitio entero sin caché, 104 veces. La
	 * API permite purgar por etiqueta (`litespeed_purge` → `Purge::add()`), y
	 * LiteSpeed etiqueta cada archive de taxonomía —incluidas sus páginas 2+— con
	 * `T.<term_id>` (`Tag::TYPE_ARCHIVE_TERM`). Ese es exactamente el alcance del
	 * dato: una serie completa, no una sola URL ni todo el sitio. Importa desde que
	 * la paginación volvió a ser indexable con canonical propio: dejar `/page/2/`
	 * sirviendo un estado viejo sería una contradicción pública y rastreable.
	 *
	 * Los hooks de termmeta cubren también WP-CLI, REST, imports y eliminación del
	 * término. La etiqueta `T.<term_id>` sigue siendo válida aunque al llegar a
	 * shutdown el término ya no exista; no hace falta ampliar a `purge_all`.
	 *
	 * @param int $term_id Término recién guardado.
	 * @return void
	 */
	private function purge_landing( int $term_id ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		// `T.` es `LiteSpeed\Tag::TYPE_ARCHIVE_TERM`. No se referencia la clase
		// para que Business siga siendo activable cuando LiteSpeed no esté.
		do_action( 'litespeed_purge', 'T.' . $term_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook público oficial de LiteSpeed Cache (tercero): el nombre lo fija ese plugin.
	}

	/**
	 * Guarda el estado. Vacío borra la meta y devuelve el renglón a no existir.
	 *
	 * @param int $term_id Término creado o editado.
	 * @return void
	 */
	private function save_state( int $term_id, string $field, string $meta_key, bool $edition ): void {
		$raw = $this->posted( $field );
		if ( null === $raw ) {
			return; // Ausente ≠ vacío: ver el docblock del archivo.
		}

		$state = $edition
			? PublicationStatus::sanitize_edition_state( $raw )
			: PublicationStatus::sanitize_work_state( $raw );
		if ( '' === $state ) {
			// Cubre las dos vías por las que un estado deja de existir: el dueño
			// eligió "— Sin definir —", o alguien mandó un valor fuera del enum.
			// Las dos terminan igual porque el resultado publicable es el mismo:
			// nada. Guardar la basura "por si acaso" dejaría en `wp_termmeta` un
			// valor que ninguna superficie sabe leer.
			delete_term_meta( $term_id, $meta_key );
			return;
		}

		update_term_meta( $term_id, $meta_key, $state );
	}

	/**
	 * Guarda un total de tomos ya asociado a su ámbito por la meta recibida.
	 *
	 * @param int $term_id Término creado o editado.
	 * @return void
	 */
	private function save_total( int $term_id, string $field, string $meta_key ): void {
		$raw = $this->posted( $field );
		if ( null === $raw ) {
			return;
		}

		$total = PublicationStatus::sanitize_total( $raw );
		if ( 0 === $total ) {
			delete_term_meta( $term_id, $meta_key );
			return;
		}

		update_term_meta( $term_id, $meta_key, $total );
	}

	/**
	 * Valor crudo de un campo del POST, o `null` si el formulario no lo trajo.
	 *
	 * Un valor no escalar (`campo[]=x`) se trata como AUSENTE y no como vacío:
	 * es la única forma de llegar acá que no puede venir de este formulario, y
	 * ante algo que no entendemos la respuesta correcta es dejar el dato como
	 * está, no borrarlo.
	 *
	 * @param string $field `name` del input.
	 * @return string|null
	 */
	private function posted( string $field ): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- el nonce se verifica en `handle_save_term()`, que es el único que llama acá.
		if ( ! array_key_exists( $field, $_POST ) ) {
			return null;
		}

		$raw = wp_unslash( $_POST[ $field ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- lo sanea `PublicationStatus`, que es la fuente única del contrato.
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return is_scalar( $raw ) ? (string) $raw : null;
	}

	/**
	 * El `<select>` del conjunto cerrado.
	 *
	 * Las opciones salen del conjunto explícito del ámbito y no de literales acá.
	 * Un `<select>` y no un campo de texto hace que el conjunto sea cerrado también
	 * para quien lo llena, no sólo para el validador.
	 *
	 * @param string                $field    Nombre e id del campo.
	 * @param string                $selected Clave guardada, ya saneada.
	 * @param array<string, string> $states   Conjunto cerrado del ámbito.
	 * @return void
	 */
	private function render_state_select( string $field, string $selected, array $states ): void {
		?>
		<select name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( $field ); ?>" class="postform">
			<option value="">— Sin definir —</option>
			<?php foreach ( $states as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>"<?php echo $value === $selected ? ' selected' : ''; ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * El campo numérico del total.
	 *
	 * @param string $field Nombre e id del campo.
	 * @param int    $total Total guardado, ya saneado. 0 = sin dato.
	 * @return void
	 */
	private function render_total_input( string $field, int $total ): void {
		?>
		<input
			type="number"
			name="<?php echo esc_attr( $field ); ?>"
			id="<?php echo esc_attr( $field ); ?>"
			value="<?php echo $total > 0 ? esc_attr( (string) $total ) : ''; ?>"
			min="1"
			max="999"
			step="1"
			inputmode="numeric"
		/>
		<?php
	}

	/**
	 * Glosario del estado. Va como texto de ayuda y no como texto por opción
	 * porque un `<option>` no admite descripción, y sin glosario "En pausa" y
	 * "Cancelada" se eligen a ojo.
	 *
	 * @return void
	 */
	private function render_edition_state_help(): void {
		?>
		Describe la edición exacta que vende esta landing, no la obra original ni lo que
		Akibara tiene en catálogo. <strong>Completa</strong>: la editorial publicó todos sus
		tomos. <strong>En publicación</strong>: siguen saliendo tomos de esta edición.
		<strong>Al día</strong>: alcanzó todo el material publicado de una obra aún no finalizada.
		<strong>En pausa</strong>: está detenida sin cancelación confirmada.
		<strong>Cancelada</strong>: la editorial no completará esta edición.
		<?php
	}

	/** Imprime la ayuda del estado de la obra original. */
	private function render_work_state_help(): void {
		?>
		Describe la historia o serialización original, no una edición española ni la
		disponibilidad de Akibara. <strong>Finalizada</strong>: la obra llegó a su final.
		<strong>En publicación</strong>: la obra original sigue creciendo.
		<strong>En pausa</strong>: está detenida sin final anunciado.
		<strong>Cancelada</strong>: fue interrumpida sin conclusión.
		<?php
	}

	/**
	 * Ayuda del total anunciado para la edición vendida.
	 *
	 * @return void
	 */
	private function render_edition_total_help(): void {
		?>
		Total final anunciado por la editorial para esta edición. Con «En publicación»
		y 8 se muestra <em>8 tomos previstos</em>; con «Completa», <em>Completa en 8 tomos</em>.
		Con «Al día», «En pausa» o «Cancelada» el número NO se publica en la landing
		aunque quede guardado (S-59834769): esos estados no tienen un total que anunciar.
		No es la cantidad disponible en Akibara. Déjalo vacío si no existe una fuente oficial.
		<?php
	}

	/** Imprime la ayuda del total de la obra original. */
	private function render_work_total_help(): void {
		?>
		Sólo se publica cuando la obra está <strong>Finalizada</strong>. Es el total de la
		obra original en tomos y puede ser mayor que el catálogo Akibara. Si la fuente
		habla de números, issues o capítulos, déjalo vacío: no conviertas unidades.
		<?php
	}
}
