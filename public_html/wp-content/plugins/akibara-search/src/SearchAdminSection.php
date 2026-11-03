<?php
/**
 * SearchAdminSection — panel "Buscador" (action-section del AdminPanel).
 *
 * Tres sub-tabs:
 *   - Índice (milestone Operable): banda de veredicto de salud (HealthReport),
 *     servicios por flag, cobertura/última reconstrucción/consistencia,
 *     mantenimiento (rebuild con progreso en vivo + reintento force, reconcile
 *     on-demand) y diagnóstico técnico colapsable (métricas internas,
 *     historial, auditoría, CLI).
 *   - Búsquedas: top del qlog (30 días) + las que dieron 0 resultados — la
 *     observabilidad que Relevanssi nunca tuvo (log OFF en prod).
 *   - Facetas: registrar/editar facetas por taxonomía SIN tocar código
 *     (label, visible, orden, contexto con picker). Persiste overrides en la
 *     option del registry.
 *
 * Siempre registrada (no gateada por flags): el operador necesita rebuild y
 * estado aunque suggest/facetas estén OFF. Es wp-admin, cero superficie front.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.55.0
 */
final class SearchAdminSection {

	/** Slug de la página de admin PROPIA del plugin (menú standalone, no el panel de business). */
	public const PAGE_SLUG = 'akibara-search';

	private const ACTION_REBUILD   = 'akb_search_rebuild';
	private const ACTION_FACETS    = 'akb_search_save_facets';
	private const ACTION_RECONCILE = 'akb_search_reconcile_run';
	private const NONCE_REBUILD    = 'akb_search_rebuild_nonce';
	private const NONCE_FACETS     = 'akb_search_facets_nonce';
	private const NONCE_RECONCILE  = 'akb_search_reconcile_nonce';
	private const ACTION_SYNONYMS  = 'akb_search_save_synonyms';
	private const NONCE_SYNONYMS   = 'akb_search_synonyms_nonce';
	private const ACTION_STOPWORDS = 'akb_search_save_stopwords';
	private const NONCE_STOPWORDS  = 'akb_search_stopwords_nonce';
	private const ACTION_SETTINGS  = 'akb_search_save_settings';
	private const NONCE_SETTINGS   = 'akb_search_settings_nonce';
	private const ACTION_PRECIOS   = 'akb_search_save_precios';
	/**
	 * Acciones del descarte de huecos del radar. PÚBLICAS a propósito: el
	 * Centro de Mando de akibara-business pinta el mismo control desde OTRO
	 * riel de despliegue, y con la constante privada tenía que duplicar el
	 * literal —acción Y nonce— a mano. Consumir `gap_action_url()` es mejor
	 * todavía: el nonce sale de acá y no puede desincronizarse del guard.
	 */
	public const ACTION_GAP_DISMISS = 'akb_search_gap_dismiss';
	public const ACTION_GAP_RESTORE = 'akb_search_gap_restore';
	private const NONCE_PRECIOS    = 'akb_search_precios_nonce';

	/** Techo de sanidad para un corte de precio (el máximo real del catálogo ronda $82.000). */
	private const PRICE_BOUND_MAX = 10000000;

	/** Transient por-usuario con el detalle del último notice (diffs largos no viajan por URL). */
	private const NOTICE_DETAIL_TRANSIENT = 'akb_search_notice_detail_';

	/** Palabras que una diferencia de listas enumera; más allá, las cuenta (`word_groups()`). */
	private const WORDS_LISTED_MAX = 20;

	/**
	 * Etiqueta de cada texto de Ajustes, tal como la pinta la pantalla. Una sola
	 * fuente para la fila y para el aviso que dice qué texto no quedó como se
	 * escribió: si el aviso nombrara otra cosa, el dueño no sabría cuál mirar.
	 */
	private const SETTINGS_TEXT_LABELS = array(
		'empty_hint_catalog'  => 'Pista en el catálogo y los filtros',
		'empty_hint_filters'  => 'Pista cuando los filtros vacían una búsqueda',
		'empty_hint_suggest'  => 'Pista en el desplegable del buscador',
		'empty_series_label'  => 'Rótulo de la serie sugerida',
		'empty_popular_label' => 'Rótulo de lo más buscado',
		'relaxed_notice'      => 'Cuando la editorial pedida no tiene esa serie',
		'network_notice'      => 'Cuando un filtro no carga por la conexión',
	);

	/**
	 * Ajustes que sólo modifican el texto visible de «sin resultados»
	 * (en aviso, desplegable y conexión del catálogo).
	 *
	 * Cambiar uno purga el caché de origen (`litespeed_purge` con `*`)
	 * y permite que el borde de CDN se renueve de forma natural.
	 * A diferencia de los interruptores del motor (que alteran endpoints y scripts
	 * y requieren purga total inmediata), las actualizaciones de texto no afectan
	 * la estructura lógica de los servicios.
	 */
	private const SETTINGS_TEXT_ONLY = array(
		'empty_hint_catalog',
		'empty_hint_filters',
		'empty_hint_suggest',
		'relaxed_notice',
		'empty_suggestions',
		'empty_series_label',
		'empty_popular_label',
		'empty_tail_words',
		'network_notice',
	);

	/**
	 * Query arg que precarga una línea nueva en el textarea de Sinónimos. Sólo
	 * PINTA texto en un formulario que el operador todavía tiene que revisar y
	 * guardar: no persiste nada por sí solo.
	 */
	private const PARAM_PREFILL = 'akb_prefill';

	/**
	 * Hook suffix que devolvió el registro del menú. Vacío hasta que
	 * `register_menu()` corre (y en pantallas donde el menú no se registró).
	 */
	private string $menu_hook = '';

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_REBUILD, array( $this, 'handle_rebuild' ) );
		add_action( 'admin_post_' . self::ACTION_FACETS, array( $this, 'handle_save_facets' ) );
		add_action( 'admin_post_' . self::ACTION_RECONCILE, array( $this, 'handle_reconcile' ) );
		add_action( 'admin_post_' . self::ACTION_SYNONYMS, array( $this, 'handle_save_synonyms' ) );
		add_action( 'admin_post_' . self::ACTION_STOPWORDS, array( $this, 'handle_save_stopwords' ) );
		add_action( 'admin_post_' . self::ACTION_SETTINGS, array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_' . self::ACTION_PRECIOS, array( $this, 'handle_save_precios' ) );
		add_action( 'admin_post_' . self::ACTION_GAP_DISMISS, array( $this, 'handle_gap_dismiss' ) );
		add_action( 'admin_post_' . self::ACTION_GAP_RESTORE, array( $this, 'handle_gap_restore' ) );
		add_action( 'admin_post_akb_update_request_status', array( $this, 'handle_update_request_status' ) );

		// Página de admin PROPIA (standalone). NO es un tab del panel de
		// akibara-business — el buscador es un plugin aparte con su propio menú,
		// como FacetWP tiene el suyo.
		// Prio 20: DESPUÉS de que business registre el menú «Akibara» (prio
		// por defecto) — así el chequeo del padre en register_menu ve el hub
		// cuando existe y el fallback a top-level solo salta sin business.
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
	}

	/**
	 * Menú top-level propio "Buscador" (cap manage_woocommerce). Independiente
	 * del AdminPanel de akibara-business.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		// Integración con el panel principal de Akibara: el buscador se registra
		// como submenú preservando su slug para mantener compatibilidad con enlaces
		// directos existentes. Si el menú padre no existe (entorno aislado o de pruebas),
		// se registra como menú de primer nivel para asegurar accesibilidad continua.
		// El menú padre se detecta por su registro efectivo en $admin_page_hooks.
		global $admin_page_hooks;
		$parent_exists = isset( $admin_page_hooks['akibara-panel'] );

		if ( $parent_exists ) {
			// El hook suffix se GUARDA, no se reconstruye: como submenú vale
			// `akibara_page_akibara-search` (deriva del título del menú padre,
			// que es de business) y como top-level `toplevel_page_akibara-search`.
			// Adivinarlo dejó los assets del panel sin encolar.
			$this->menu_hook = (string) add_submenu_page(
				'akibara-panel',
				'Buscador Akibara',
				'Buscador',
				'manage_woocommerce',
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);
			return;
		}

		$this->menu_hook = (string) add_menu_page(
			'Buscador Akibara',
			'Buscador',
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-search',
			58
		);
	}

	/**
	 * Shell de la página propia: wrap WP + título + contenido del buscador.
	 *
	 * @return void
	 */
	public function render_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- no hay formulario que verificar: el parámetro sólo elige qué pestaña se pinta, no escribe nada, y exigir nonce rompería el enlace del submenú y cualquier marcador. El valor pasa por sanitize_key() y después por una lista blanca cerrada de siete pestañas.
		$sub = isset( $_GET['sub'] ) ? sanitize_key( (string) $_GET['sub'] ) : 'indice';
		$sub = in_array( $sub, array( 'indice', 'busquedas', 'encargos', 'facetas', 'precios', 'sinonimos', 'stopwords', 'ajustes' ), true ) ? $sub : 'indice';

		echo '<div class="wrap akb-fwp"><div class="akb-fwp-panel">';

		// Header estilo FacetWP: marca + versión + tabs + botón de reindex.
		// El brand define el <h1> de la pantalla para garantizar la jerarquía
		// de accesibilidad; la versión queda fuera del h1 como hermana para mantener
		// limpio el nombre accesible («Buscador»).
		echo '<header class="akb-fwp-head">';
		printf(
			'<div class="akb-fwp-brand"><span class="akb-fwp-gem" aria-hidden="true">&#9670;</span> <h1>Buscador</h1> <span class="akb-fwp-ver">v%s</span></div>',
			esc_html( AKIBARA_SEARCH_VERSION )
		);
		$this->render_subnav( $sub );
		// Botón de reindexación global: ejecuta una petición POST autenticada con nonce
		// idéntica a la acción de mantenimiento para garantizar coherencia en todas las vistas.
		printf(
			'<form method="post" action="%s" style="margin-left:auto;align-self:center;display:flex;align-items:center">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::ACTION_REBUILD ) );
		wp_nonce_field( self::NONCE_REBUILD );
		// El estilo vive en `.akb-fwp-reindex` (admin-search.css); los resets van
		// inline porque esa clase se diseñó originalmente para un <a> y un <button>
		// incorpora propiedades tipográficas por defecto del navegador.
		echo '<button type="submit" class="akb-fwp-reindex" style="border:0;margin-left:0;cursor:pointer;font-family:inherit;line-height:normal">Reconstruir índice</button>';
		echo '</form>';
		echo '</header>';
		// Delimitador estándar de WordPress (wp-header-end) para inyectar notificaciones
		// administrativas (.notice, .updated) fuera del encabezado flex.
		echo '<hr class="wp-header-end">';

		echo '<div class="akb-fwp-body">';
		$this->render_admin_section();
		echo '</div></div></div>';
	}

	/**
	 * Encola el JS de admin del buscador (poll del rebuild + reordenar/quitar
	 * facetas + parent-picker) SOLO en la página propia del plugin.
	 *
	 * @param string $hook Hook suffix de la pantalla admin actual.
	 * @return void
	 */
	public function enqueue_admin( string $hook ): void {
		if ( '' === $this->menu_hook || $this->menu_hook !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'akb-search-admin',
			AKIBARA_SEARCH_URL . 'assets/js/admin-search.js',
			array(),
			Search::asset_version( 'assets/js/admin-search.js' ),
			true
		);
		wp_enqueue_style(
			'akb-search-admin',
			AKIBARA_SEARCH_URL . 'assets/css/admin-search.css',
			array(),
			Search::asset_version( 'assets/css/admin-search.css' )
		);
	}

	// -----------------------------------------------------------------------
	// Handlers.
	// -----------------------------------------------------------------------

	/**
	 * Encola un rebuild completo async. Con force=1 (botón "Reintentar" de un
	 * rebuild detenido/fallido) delega en schedule(true), que RE-VERIFICA la
	 * condición server-side antes de resetear (gate DISEÑO 2ª pasada).
	 *
	 * @return void
	 */
	public function handle_rebuild(): void {
		$this->guard( self::NONCE_REBUILD );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ya validó.
		$force     = ! empty( $_POST['force'] );
		$scheduled = RebuildJob::schedule( $force );

		if ( $scheduled ) {
			AuditLog::record( $force ? 'rebuild_forzado' : 'rebuild_encolado', $force ? 'Reintento tras rebuild detenido/fallido' : '' );
			$this->redirect( 'indice', 'rebuild_queued' );
		}

		// Si el encolado no se ejecutó, se discrimina entre una ejecución ya en curso
		// (espera regular) y una fase terminal de bloqueo/aborto que requiere intervención técnica.
		$this->redirect( 'indice', self::rebuild_state_is_blocked( RebuildJob::status() ) ? 'rebuild_blocked' : 'rebuild_running' );
	}

	/**
	 * Encola una verificación de consistencia (Reconciler) on-demand.
	 *
	 * @return void
	 */
	public function handle_reconcile(): void {
		$this->guard( self::NONCE_RECONCILE );

		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$this->redirect( 'indice', 'reconcile_unavailable' );
		}

		as_enqueue_async_action( Reconciler::HOOK, array(), Indexer::AS_GROUP );
		AuditLog::record( 'reconcile_encolado', 'Verificación de consistencia pedida desde el panel' );
		$this->redirect( 'indice', 'reconcile_queued' );
	}

	/**
	 * Persiste los overrides de facetas (visible/label/orden/contexto) en la
	 * option, procesa el alta de facetas nuevas y la baja de las registradas
	 * por el operador. Merge sobre el seed: solo guardamos lo que difiere para
	 * no congelar el seed (si mañana cambia el default, los no-tocados lo
	 * heredan).
	 *
	 * @return void
	 */
	public function handle_save_facets(): void {
		$this->guard( self::NONCE_FACETS );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() ya validó el nonce; es un array que se sanea campo a campo más abajo (sanitize_key/absint), no una cadena que se pueda sanear aquí.
		$raw   = isset( $_POST['facet'] ) && is_array( $_POST['facet'] ) ? wp_unslash( $_POST['facet'] ) : array();
		$order = 0;
		$out   = array();

		$previous         = FacetRegistry::definitions();
		$seed_names       = FacetRegistry::seed_names();
		$needs_reindex    = false;
		$invalid_parent   = array();
		$ambiguous_parent = false;

		// El orden viene del array POST (los botones ↑/↓ reordenan los <tr>);
		// respetamos ese orden.
		foreach ( $raw as $name => $fields ) {
			$name = sanitize_key( (string) $name );
			if ( '' === $name || ! is_array( $fields ) || ! isset( $previous[ $name ] ) ) {
				continue; // solo facetas conocidas; el alta va por new_facet.
			}
			if ( ! FacetRegistry::name_is_valid( $name ) ) {
				continue; // una clave ya envenenada (SQL, WP-CLI) no se reescribe idéntica.
			}

			$override = array(
				'visible' => ! empty( $fields['visible'] ),
				'order'   => $order++,
			);

			$label = isset( $fields['label'] ) ? FacetRegistry::sanitize_label( (string) $fields['label'] ) : '';
			if ( '' !== $label ) {
				$override['label'] = $label;
			}

			$override['ui'] = self::sanitize_facet_ui(
				(string) ( $fields['ui'] ?? ( $previous[ $name ]['ui'] ?? 'checkboxes' ) ),
				$previous[ $name ]
			);

			// Las facetas del operador viven SOLO en la option: si no re-copiamos
			// type/taxonomy/ui acá, el save las degradaría a override vacío.
			if ( ! in_array( $name, $seed_names, true ) ) {
				$override['type']     = (string) ( $previous[ $name ]['type'] ?? 'taxonomy' );
				$override['taxonomy'] = (string) ( $previous[ $name ]['taxonomy'] ?? '' );
			}

			// Contexto jerárquico (parent_term): exclusivo para facetas de taxonomía.
			// Admite nombre exacto o ID numérico; un valor vacío o cero indica sin contexto.
			// Si el término es inexistente o ambiguo se preserva estrictamente el valor previo.
			// Cualquier alteración de jerarquía requiere reindexación para actualizar el índice.
			if ( isset( $fields['parent_term'] ) && 'taxonomy' === ( $previous[ $name ]['type'] ?? '' ) ) {
				$previous_parent = (int) ( $previous[ $name ]['parent_term'] ?? 0 );
				$resolved        = self::resolve_parent_term(
					(string) $fields['parent_term'],
					(string) ( $previous[ $name ]['taxonomy'] ?? '' )
				);

				if ( '' !== $resolved['error'] ) {
					$invalid_parent[] = $name;
					if ( 'facets_ambiguous_parent' === $resolved['error'] ) {
						$ambiguous_parent = true;
					}
					$parent = $previous_parent; // conservar el válido anterior.
				} else {
					$parent = $resolved['id'];
				}

				$override['parent_term'] = $parent > 0 ? $parent : '';
				$needs_reindex           = $needs_reindex || $parent !== $previous_parent;
			}

			$out[ $name ] = $override;
		}

		// Baja: una faceta del operador que estaba y no vino en el POST fue
		// quitada desde la UI → desaparece de la option. El espejo queda con
		// filas huérfanas de su taxonomía hasta el próximo rebuild (inofensivas
		// para counts: nadie las consulta) → avisamos reindex igual.
		foreach ( array_keys( $previous ) as $known ) {
			if ( ! in_array( $known, $seed_names, true ) && ! isset( $out[ $known ] ) ) {
				$needs_reindex = true;
			}
		}

		$added = $this->process_new_facet( $previous, $order, $out );
		if ( 'added' === $added ) {
			$needs_reindex = true;
		}

		if ( ! $this->persist_options_exact( array( FacetRegistry::OPTION_KEY => $out ) ) ) {
			$this->redirect( 'facetas', 'save_failed' );
		}
		FacetRegistry::reset_cache();

		// Auditoría + detalle del notice: qué cambió EXACTAMENTE (diff contra
		// el estado efectivo nuevo). El detalle viaja por transient por-usuario
		// — un diff largo no cabe en la query string del redirect.
		$detail = self::facets_diff_summary( $previous, FacetRegistry::definitions() );
		if ( '' !== $detail ) {
			AuditLog::record( 'facets_guardadas', $detail );
			set_transient( self::NOTICE_DETAIL_TRANSIENT . get_current_user_id(), $detail, 120 );
		}

		if ( $ambiguous_parent ) {
			$this->redirect( 'facetas', 'facets_ambiguous_parent' );
		}
		if ( ! empty( $invalid_parent ) ) {
			$this->redirect( 'facetas', 'facets_invalid_parent' );
		}
		if ( '' !== $added && 'added' !== $added ) {
			$this->redirect( 'facetas', $added ); // código de error del alta.
		}
		$this->redirect( 'facetas', $needs_reindex ? 'facets_saved_reindex' : 'facets_saved' );
	}

	/**
	 * Procesa el alta de una faceta nueva desde el formulario (new_facet[…]).
	 * La agrega a $out por referencia si es válida.
	 *
	 * @param array<string, array<string, mixed>> $previous Definiciones vigentes.
	 * @param int                                 $order    Próximo índice de orden.
	 * @param array<string, array<string, mixed>> $out      Overrides a persistir (por referencia).
	 * @return string '' = no se intentó alta · 'added' = ok · otro = código de error.
	 */
	private function process_new_facet( array $previous, int $order, array &$out ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el caller (handle_save_facets) ya validó nonce; array saneado campo a campo justo debajo (sanitize_key).
		$new = isset( $_POST['new_facet'] ) && is_array( $_POST['new_facet'] ) ? wp_unslash( $_POST['new_facet'] ) : array();

		$name     = sanitize_key( (string) ( $new['name'] ?? '' ) );
		$taxonomy = sanitize_key( (string) ( $new['taxonomy'] ?? '' ) );
		if ( '' === $name && '' === $taxonomy ) {
			return ''; // formulario de alta vacío: no es un intento.
		}

		if ( '' === $name || '' === $taxonomy ) {
			return 'facet_new_incomplete';
		}
		// Se RECHAZA, no se recorta: el nombre es el parámetro de la URL
		// pública, y un slug mutilado a espaldas del dueño crearía un filtro que
		// él no pidió. `sanitize_key()` no mide el largo —y al borrar `:`, `/` y
		// espacios CONCATENA, así que pegar una URL pasa de 64 con facilidad—, y
		// el lector invalida la configuración entera por una clave larga.
		if ( ! FacetRegistry::name_is_valid( $name ) ) {
			return 'facet_new_bad_name';
		}
		// El lector cuenta las entradas de la option, que son exactamente `$out`.
		if ( count( $out ) >= FacetRegistry::MAX_FACETS ) {
			return 'facet_new_limit';
		}
		if ( isset( $previous[ $name ] ) || isset( $out[ $name ] ) ) {
			return 'facet_new_exists';
		}
		if ( ! isset( self::eligible_taxonomies()[ $taxonomy ] ) ) {
			return 'facet_new_bad_taxonomy'; // allowlist idéntica al selector — no basta con taxonomy_exists().
		}

		$ui    = self::sanitize_facet_ui(
			(string) ( $new['ui'] ?? 'checkboxes' ),
			array(
				'type' => 'taxonomy',
			)
		);
		$label = FacetRegistry::sanitize_label( (string) ( $new['label'] ?? '' ) );

		$entry = array(
			'type'     => 'taxonomy',
			'taxonomy' => $taxonomy,
			'ui'       => $ui,
			'visible'  => true,
			'order'    => $order,
			'label'    => '' !== $label ? $label : ucfirst( $name ),
		);

		// Mismo resolver que las filas: nombre exacto o ID, contra la taxonomía
		// ELEGIDA en el select del alta [gate DISEÑO §5].
		$resolved = self::resolve_parent_term( (string) ( $new['parent_term'] ?? '' ), $taxonomy );
		if ( '' !== $resolved['error'] ) {
			return $resolved['error'];
		}
		if ( $resolved['id'] > 0 ) {
			$entry['parent_term'] = $resolved['id'];
		}

		$out[ $name ] = $entry;
		return 'added';
	}

	// -----------------------------------------------------------------------
	// Render.
	// -----------------------------------------------------------------------

	/**
	 * Render del tab (action section: dibuja todo).
	 *
	 * @return void
	 */
	public function render_admin_section(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- no hay formulario que verificar: el parámetro sólo elige qué pestaña se pinta, no escribe nada. El valor pasa por sanitize_key() y después por la misma lista blanca cerrada de siete pestañas.
		$sub = isset( $_GET['sub'] ) ? sanitize_key( (string) $_GET['sub'] ) : 'indice';
		$sub = in_array( $sub, array( 'indice', 'busquedas', 'encargos', 'facetas', 'precios', 'sinonimos', 'stopwords', 'ajustes' ), true ) ? $sub : 'indice';

		$this->render_notice();

		// El subnav lo pinta render_page() dentro del header estilo FacetWP.
		echo '<div class="akb-search-admin">';
		switch ( $sub ) {
			case 'busquedas':
				$this->render_searches();
				break;
			case 'encargos':
				$this->render_encargos();
				break;
			case 'facetas':
				$this->render_facets();
				break;
			case 'precios':
				$this->render_precios();
				break;
			case 'sinonimos':
				$this->render_synonyms();
				break;
			case 'stopwords':
				$this->render_stopwords();
				break;
			case 'ajustes':
				$this->render_settings();
				break;
			default:
				$this->render_index_status();
		}
		echo '</div>';
	}

	/**
	 * @param string $active Sub-tab activo.
	 * @return void
	 */
	private function render_subnav( string $active ): void {
		$pending_count = 0;
		if ( class_exists( '\Akibara\Search\TitleRequests' ) ) {
			$counts = TitleRequests::count_by_status();
			$pending_count = (int) ( $counts[ TitleRequests::STATUS_PENDING ] ?? 0 );
		}
		$encargos_label = $pending_count > 0
			? sprintf( 'Encargos (%d)', $pending_count )
			: 'Encargos';

		$tabs = array(
			'indice'    => 'Índice',
			'busquedas' => 'Búsquedas',
			'encargos'  => $encargos_label,
			'facetas'   => 'Facetas',
			'precios'   => 'Precios',
			'sinonimos' => 'Sinónimos',
			'stopwords' => 'Palabras ignoradas',
			'ajustes'   => 'Ajustes',
		);
		echo '<nav class="nav-tab-wrapper" aria-label="Secciones del buscador" style="margin-bottom:0">';
		foreach ( $tabs as $slug => $label ) {
			$url = add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'sub'  => $slug,
				),
				admin_url( 'admin.php' )
			);
			$is_active = $slug === $active;
			printf(
				'<a href="%s" class="nav-tab%s"%s>%s</a>',
				esc_url( $url ),
				$is_active ? ' nav-tab-active' : '',
				$is_active ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * Carga una plantilla de administración ubicada en templates/admin/.
	 *
	 * @param string               $template_name Nombre del archivo de plantilla.
	 * @param array<string, mixed> $view_data     Variables pasadas a la plantilla.
	 * @return void
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $view_data se consume dentro del template incluido en el scope local.
	private function render_template( string $template_name, array $view_data = array() ): void {
		$template_path = dirname( __DIR__ ) . '/templates/admin/' . $template_name;
		if ( is_readable( $template_path ) ) {
			include $template_path;
		}
	}

	/**
	 * Pestaña Índice: presenta de forma estructurada el estado de salud del motor,
	 * servicios activos, sincronización del índice y herramientas de diagnóstico.
	 * Los datos provienen de una única consulta centralizada en HealthReport::collect().
	 *
	 * @return void
	 */
	private function render_index_status(): void {
		$data    = HealthReport::collect();
		$verdict = HealthReport::verdict( $data );

		$this->render_template(
			'tab-indice.php',
			array(
				'data'    => $data,
				'verdict' => $verdict,
			)
		);
	}

	/**
	 * Banda de veredicto: primero la conclusión, después los números. Cada
	 * razón lleva su CTA contextual (ancla al bloque Mantenimiento, donde el
	 * botón ya viene morfado al estado correcto).
	 *
	 * @param array<string, mixed> $data    Insumos de HealthReport::collect().
	 * @param array<string, mixed> $verdict Salida de HealthReport::verdict().
	 * @return void
	 */
	private function render_health_band( array $data, array $verdict ): void {
		$state  = (string) $verdict['verdict'];
		$titles = array(
			HealthReport::SANO           => 'Índice saludable',
			HealthReport::ATENCION       => 'El buscador requiere atención',
			HealthReport::RECONSTRUYENDO => 'Reconstrucción en curso',
		);

		$attrs = '';
		if ( HealthReport::RECONSTRUYENDO === $state ) {
			// El poll (admin-panel.js) solo se activa con este estado; manda
			// X-WP-Nonce (wp_rest) para la cookie-auth del REST admin.
			$attrs = sprintf(
				' data-endpoint="%s" data-nonce="%s"',
				esc_url( rest_url( 'akibara/v1/search/admin-status' ) ),
				esc_attr( wp_create_nonce( 'wp_rest' ) )
			);
		}

		printf(
			'<div class="akb-health-band akb-health--%1$s" data-akb-search-status data-state="%1$s"%2$s>',
			esc_attr( $state ),
			$attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya escapado arriba.
		);
		printf(
			'<p class="akb-health-verdict"><span class="akb-health-dot" aria-hidden="true"></span><strong>%s</strong></p>',
			esc_html( $titles[ $state ] ?? $state )
		);

		$rebuild = is_array( $data['rebuild'] ?? null ) ? $data['rebuild'] : null;
		if ( HealthReport::RECONSTRUYENDO === $state && null !== $rebuild ) {
			printf(
				'<p data-akb-progress aria-live="polite">Reconstruyendo… %s de %s productos · comenzó %s</p>',
				esc_html( number_format_i18n( (int) $rebuild['done'] ) ),
				esc_html( number_format_i18n( (int) $rebuild['total'] ) ),
				esc_html( $this->stamp( (int) $rebuild['started_at'] ) )
			);
		} else {
			$last = is_array( $data['last_rebuild'] ?? null ) ? $data['last_rebuild'] : array();
			printf(
				'<p>%s de %s productos indexados%s</p>',
				esc_html( number_format_i18n( (int) ( $data['docs'] ?? 0 ) ) ),
				esc_html( number_format_i18n( (int) ( $data['eligible'] ?? 0 ) ) ),
				isset( $last['finished_at'] )
					? esc_html( ' · última reconstrucción ' . $this->stamp( (int) $last['finished_at'] ) )
					: ''
			);
		}

		$reasons = is_array( $verdict['reasons'] ?? null ) ? $verdict['reasons'] : array();
		if ( ! empty( $reasons ) ) {
			echo '<ul>';
			foreach ( $reasons as $reason ) {
				printf(
					'<li>%s%s</li>',
					esc_html( (string) ( $reason['message'] ?? '' ) ),
					$this->reason_cta( (string) ( $reason['cta'] ?? '' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML ya escapado dentro del helper.
				);
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/**
	 * CTA contextual de una razón (HTML listo para imprimir; '' si no aplica).
	 *
	 * @param string $cta Código de CTA del veredicto.
	 * @return string
	 */
	private function reason_cta( string $cta ): string {
		$labels = array(
			'retry'     => 'Reintentar reconstrucción',
			'rebuild'   => 'Reconstruir índice',
			'reconcile' => 'Verificar consistencia',
		);
		if ( 'flags' === $cta ) {
			// Enlaza directamente a la pestaña de Ajustes para revisar o conmutar los servicios del motor.
			return sprintf(
				' <a href="%s">Revisar los servicios en Ajustes</a>',
				esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'sub' => 'ajustes' ), admin_url( 'admin.php' ) ) )
			);
		}
		if ( ! isset( $labels[ $cta ] ) ) {
			return ''; // desconocidos: el mensaje ya dice qué hacer.
		}
		return sprintf( ' <a href="#akb-search-maintenance">%s ↓</a>', esc_html( $labels[ $cta ] ) );
	}

	/**
	 * Muestra el estado individual de cada servicio del motor de búsqueda.
	 * Los servicios inactivos se representan en gris neutral (estado legítimo por configuración),
	 * reservando las advertencias para inconsistencias efectivas del sistema.
	 *
	 * @param array<string, mixed> $flags     Flags de HealthReport::collect().
	 * @param array<string, mixed> $editorial Estado de la publicación editorial (mismo collect()).
	 * @param array<string, mixed> $semantic  Autoridad y publicación de la foto semántica (mismo collect()).
	 * @return void
	 */
	private function render_services( array $flags, array $editorial = array(), array $semantic = array() ): void {
		echo '<h2>Servicios</h2>';
		echo '<div class="akb-admin-scroll"><table class="widefat striped" style="max-width:520px"><tbody>';
		foreach ( array(
			'indexing' => array( 'Indexación automática', 'Activa', 'Apagada' ),
			'suggest'  => array( 'Autocompletado', 'Activo', 'Apagado' ),
			'facets'   => array( 'Filtros del catálogo', 'Activos', 'Apagados' ),
		) as $key => $labels ) {
			$flag       = is_array( $flags[ $key ] ?? null ) ? $flags[ $key ] : array();
			$configured = ! empty( $flag['configured'] );
			$effective  = ! empty( $flag['effective'] );
			if ( $effective ) {
				$status = '<span style="color:#16803d">' . esc_html( $labels[1] ) . '</span>';
			} elseif ( $configured ) {
				// Encendido por el dueño, pero el buscador no lo puede servir: el
				// interruptor NO es el culpable y el panel no puede insinuarlo.
				$status = '<span style="color:#996800">Encendido, pero no disponible ahora</span>';
			} else {
				$status = '<span style="color:#646970">' . esc_html( $labels[2] ) . ' (flag OFF)</span>';
			}
			$status .= sprintf(
				'<br><small>Configurado: %s · efectivo: %s</small>',
				$configured ? 'sí' : 'no',
				$effective ? 'sí' : 'no'
			);
			// «No disponible» sin causa dejaba al operador sin saber si esperar o
			// actuar, y con UNA sola causa posible (la editorial) el panel culpaba a
			// «precios y facetas» de cosas que no eran suyas: la causa sale ahora de
			// la fila misma (service_gate_reason()).
			if ( $configured && ! $effective ) {
				$status .= '<br><small>' . $this->service_gate_reason( $key, $flag, $editorial, $semantic ) . '</small>';
			}
			printf(
				'<tr><th scope="row">%s</th><td>%s</td></tr>',
				esc_html( $labels[0] ),
				$status // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML construido arriba con esc_html en cada parte variable; el resto son literales.
			);
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Causa de que UN servicio guardado encendido no esté efectivo. HTML escapado.
	 *
	 * Hasta 0.1.62 toda fila «no disponible» salía con la causa de la
	 * publicación editorial, y el panel culpaba a «precios y facetas» de tres
	 * cosas que no eran suyas:
	 *
	 *  - Indexación: su efectivo es la foto semántica más el filtro; NUNCA
	 *    depende de los precios publicados.
	 *  - La foto semántica ilegible o un filtro `akb_search_*_enabled` apagan el
	 *    servicio antes de llegar al gate de servido; con el autocompletado así,
	 *    la ruta ni se registra (`Search::register()`) y responde 404, no 503.
	 *  - Un ajuste del buscador guardado y todavía sin publicar cierra el gate
	 *    igual que un cambio de precio, y se arregla solo al publicarse.
	 *
	 * @param string               $key       indexing|suggest|facets.
	 * @param array<string, mixed> $flag      Fila {configured, published, effective}.
	 * @param array<string, mixed> $editorial EditorialDiscountPublication::status().
	 * @param array<string, mixed> $semantic  {available, pending} de HealthReport::collect().
	 * @return string
	 */
	private function service_gate_reason( string $key, array $flag, array $editorial, array $semantic ): string {
		if ( 'indexing' === $key || empty( $flag['published'] ) ) {
			if ( false === ( $semantic['available'] ?? null ) ) {
				return esc_html( 'Está guardado encendido, pero el buscador no puede leer sus ajustes (alguno tiene un valor dañado) y mientras tanto lo trata como apagado.' );
			}
			return esc_html(
				sprintf(
					'Está guardado encendido, pero un filtro de código (%s) lo mantiene apagado.',
					'akb_search_' . $key . '_enabled'
				)
			);
		}
		if ( true === ( $semantic['pending'] ?? null ) ) {
			return esc_html( 'Guardado; se aplica al publicar: el buscador está publicando un cambio de sus ajustes y lo activa en cuanto termine (segundos, o al acabar la reconstrucción en curso).' );
		}

		return $this->editorial_gate_reason( $editorial );
	}

	/**
	 * Causa legible de que un servicio configurado no esté efectivo, más el
	 * enlace a Mantenimiento. Devuelve HTML ya escapado.
	 *
	 * @param array<string, mixed> $editorial EditorialDiscountPublication::status().
	 * @return string
	 */
	private function editorial_gate_reason( array $editorial ): string {
		$causes = array(
			'materialization_stale'      => 'Los precios y facetas publicados quedaron atrás del catálogo: vuelve solo al terminar la próxima reconstrucción.',
			'state_missing_or_invalid'   => 'Falta el registro de la última publicación de precios y facetas: hace falta una reconstrucción completa.',
			'receipt_missing_or_invalid' => 'Falta el comprobante exacto de la última publicación de precios y facetas: hace falta una reconstrucción completa.',
			'source_snapshot_unreadable' => 'No se pudo leer la configuración de descuentos editoriales; hasta resolverlo el buscador no publica cambios.',
		);
		$code = (string) ( $editorial['error'] ?? '' );
		$text = $causes[ $code ] ?? 'La publicación de precios y facetas todavía no está lista.';

		// «No se pudo leer la configuración» sin decir CUÁL dejaba al dueño
		// reintentando a ciegas (A1 de la revisión de 0.1.63): se nombra el dato
		// y la pantalla donde se corrige, tal como los decide el lector.
		$rotos = is_array( $editorial['broken_options'] ?? null ) ? $editorial['broken_options'] : array();
		if ( 'source_snapshot_unreadable' === $code && array() !== $rotos ) {
			$partes = array();
			foreach ( $rotos as $roto ) {
				$label = is_array( $roto ) ? (string) ( $roto['label'] ?? '' ) : '';
				$donde = is_array( $roto ) ? (string) ( $roto['where'] ?? '' ) : '';
				if ( '' !== $label ) {
					$partes[] = '' === $donde ? $label : $label . ' (' . $donde . ')';
				}
			}
			if ( array() !== $partes ) {
				$text = 'No se pudo leer la configuración de descuentos editoriales: tiene un valor que el buscador no puede leer en '
					. implode( ', ', $partes )
					. '. Corrígelo ahí; hasta entonces el buscador no publica cambios y volver a guardar otra cosa no lo arregla.';
			}
		}

		$boundary = $editorial['next_boundary_at'] ?? null;
		if ( is_int( $boundary ) && $boundary > 0 ) {
			$text .= ' Próximo cambio de la ventana de descuentos: ' . $this->stamp( $boundary ) . '.';
		}

		return esc_html( $text ) . ' <a href="#akb-search-maintenance">Ir a Mantenimiento ↓</a>';
	}

	/**
	 * Estado: cobertura contra elegibles, última reconstrucción con fecha
	 * exacta + duración, y consistencia con el tamaño de la muestra.
	 *
	 * @param array<string, mixed> $data Insumos de HealthReport::collect().
	 * @return void
	 */
	private function render_state_table( array $data ): void {
		$docs     = (int) ( $data['docs'] ?? 0 );
		$eligible = (int) ( $data['eligible'] ?? 0 );

		echo '<h2>Estado</h2>';
		echo '<div class="akb-admin-scroll"><table class="widefat striped" style="max-width:520px"><tbody>';
		printf(
			'<tr><th scope="row">Cobertura</th><td>%s · %s / %s productos elegibles</td></tr>',
			esc_html( $eligible > 0 ? floor( $docs * 100 / $eligible ) . '%' : '—' ),
			esc_html( number_format_i18n( $docs ) ),
			esc_html( number_format_i18n( $eligible ) )
		);

		// Informa si existe una reconstrucción pendiente y su motivo.
		// Los detalles de bajo nivel del protocolo residen en el desplegable de diagnóstico técnico.
		$this->render_pending_rebuild_row();

		// history[0] trae started_at (duración); el fallback OPTION_LAST cubre
		// instalaciones anteriores al historial.
		$history = RebuildJob::history();
		$last    = ! empty( $history )
			? $history[0]
			: ( is_array( $data['last_rebuild'] ?? null ) ? $data['last_rebuild'] : array() );
		if ( isset( $last['finished_at'] ) ) {
			$bits   = array( $this->stamp( (int) $last['finished_at'] ) );
			$bits[] = number_format_i18n( (int) ( $last['docs'] ?? 0 ) ) . ' productos';
			if ( ! empty( $last['started_at'] ) && (int) $last['finished_at'] >= (int) $last['started_at'] ) {
				$bits[] = $this->duration( (int) $last['started_at'], (int) $last['finished_at'] );
			}
			printf(
				'<tr><th scope="row">Última reconstrucción</th><td>%s%s</td></tr>',
				esc_html( implode( ' · ', $bits ) ),
				isset( $last['error'] )
					? '<br><span style="color:#b32d2e">' . esc_html( $this->error_label( (string) $last['error'] ) ) . '</span>'
					: ''
			);
		} else {
			echo '<tr><th scope="row">Última reconstrucción</th><td>sin reconstrucciones registradas</td></tr>';
		}

		$reconcile = is_array( $data['reconcile_last'] ?? null ) ? $data['reconcile_last'] : array();
		if ( isset( $reconcile['ran_at'] ) ) {
			// Mismo conjunto de divergencias que HealthReport::verdict(): si el
			// panel y el veredicto contaran distinto, la banda diría "1
			// diferencia corregida" y la tabla "sin diferencias" para la misma
			// corrida. `orphan_facets` (facetas con term_id ya borrado) entra
			// acá igual que `stale`.
			$issues  = (int) ( $reconcile['missing'] ?? 0 ) + (int) ( $reconcile['orphaned'] ?? 0 )
				+ (int) ( $reconcile['divergent'] ?? 0 ) + (int) ( $reconcile['stale'] ?? 0 )
				+ (int) ( $reconcile['orphan_facets'] ?? 0 );
			$checked = (int) ( $reconcile['checked'] ?? 0 );
			printf(
				'<tr><th scope="row">Consistencia</th><td>%s — %s</td></tr>',
				esc_html( $this->stamp( (int) $reconcile['ran_at'] ) ),
				0 === $issues
					? esc_html( sprintf( 'sin diferencias (%d productos revisados al azar)', $checked ) )
					: esc_html( sprintf( 1 === $issues ? '%d diferencia corregida' : '%d diferencias corregidas', $issues ) )
			);
		} else {
			echo '<tr><th scope="row">Consistencia</th><td>todavía no corre (se agenda sola, una vez al día)</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Renderiza la fila de reconstrucción pendiente con su motivo operativo y fecha de solicitud.
	 * Permite visibilidad directa sobre tareas encoladas antes de su ejecución.
	 *
	 * @return void
	 */
	private function render_pending_rebuild_row(): void {
		$request = Install::pending_rebuild_request();
		if ( ! is_array( $request ) ) {
			return;
		}

		$bits = array( 'Motivo: ' . $this->rebuild_reason_label( (string) ( $request['reason'] ?? '' ) ) );
		if ( (int) ( $request['requested_at'] ?? 0 ) > 0 ) {
			$bits[] = 'pedida ' . $this->stamp( (int) $request['requested_at'] );
		}
		if ( (int) ( $request['not_before'] ?? 0 ) > time() ) {
			$bits[] = 'empieza ' . $this->stamp( (int) $request['not_before'] );
		}

		printf(
			'<tr><th scope="row">Reconstrucción pendiente</th><td>%s</td></tr>',
			esc_html( implode( ' · ', $bits ) )
		);
	}

	/**
	 * Motivo técnico de una solicitud de reconstrucción → lenguaje de tienda.
	 * Desconocido cae al código crudo: perder la señal es peor que mostrarla
	 * fea, y los motivos nuevos aparecen sin tocar este mapa.
	 *
	 * @param string $reason Código acuñado por Install::request_rebuild().
	 * @return string
	 */
	private function rebuild_reason_label( string $reason ): string {
		$labels = array(
			'manual'                            => 'Pedida desde el panel',
			'schedule_while_active'             => 'Se pidió otra mientras ya había una corriendo',
			'schema_migration'                  => 'Actualización del plugin (cambio de estructura)',
			'facet_registry_changed'            => 'Cambiaron las facetas',
			'editorial_materialization_pending' => 'Cambiaron los descuentos por editorial',
			'visibility_option_changed'         => 'Cambió la visibilidad del catálogo',
			'term_edit'                         => 'Se editó una categoría o etiqueta',
			'term_delete'                       => 'Se borró una categoría o etiqueta',
			'term_fanout'                       => 'Cambio masivo de categorías o etiquetas',
			'runtime_schema_unready'            => 'El índice no estaba listo para escribir y quedó deuda',
			'live_debt_persist_failed'          => 'No se pudo anotar un cambio del catálogo',
			'live_batch_persist_failed'         => 'No se pudo anotar un lote de cambios del catálogo',
			'source_barrier_debt_failed'        => 'No se pudo procesar la cola de cambios',
			'source_barrier_wake_failed'        => 'No se pudo despertar la cola de cambios',
			'product_save_source_unreadable'    => 'No se pudo leer un producto al guardarlo',
			'post_state_source_unreadable'      => 'No se pudo leer el estado de un producto',
			'variation_parent_unreadable'       => 'No se pudo leer el producto padre de una variación',
			'variation_old_parent_unreadable'   => 'No se pudo leer el producto padre anterior de una variación',
			'term_relationship_source_unreadable' => 'No se pudieron leer las categorías de un producto',
			'term_fanout_source_unreadable'     => 'No se pudieron leer los productos de una categoría',
			'term_delete_snapshot_unreadable'   => 'No se pudo leer la categoría borrada',
			'term_delete_children_unreadable'   => 'No se pudieron leer las subcategorías de la categoría borrada',
			// EditorialDiscountPublication acuña estos al vuelo (stage_term /
			// stage_global): son los cambios de descuento por editorial.
			'term_meta_update'                  => 'Cambió el descuento de una editorial',
			'term_meta_add'                     => 'Se agregó el descuento de una editorial',
			'term_meta_delete'                  => 'Se quitó el descuento de una editorial',
			'term_meta_delete_all'              => 'Se quitaron descuentos de editorial',
		);
		if ( isset( $labels[ $reason ] ) ) {
			return $labels[ $reason ];
		}

		// `option_update_<option>` y familia: el motivo trae la option adentro.
		foreach ( array( 'option_update_' => 'Cambió el ajuste', 'option_add_' => 'Se creó el ajuste', 'option_delete_' => 'Se borró el ajuste' ) as $prefix => $label ) {
			if ( str_starts_with( $reason, $prefix ) ) {
				return $label . ' «' . substr( $reason, strlen( $prefix ) ) . '»';
			}
		}

		return '' !== $reason ? $reason : 'sin motivo registrado';
	}

	/**
	 * Mantenimiento: el botón de rebuild MORFA según el estado — en curso =
	 * deshabilitado con progreso; detenido/fallido = "Reintentar" (reanuda el
	 * estado durable, con preflight server-side); normal = reconstruir.
	 *
	 * @param array<string, mixed> $data    Insumos de HealthReport::collect().
	 * @param array<string, mixed> $verdict Salida de HealthReport::verdict().
	 * @return void
	 */
	private function render_maintenance( array $data, array $verdict ): void {
		$state = (string) $verdict['verdict'];
		$retry = false;
		foreach ( ( is_array( $verdict['reasons'] ?? null ) ? $verdict['reasons'] : array() ) as $reason ) {
			$retry = $retry || 'retry' === ( $reason['cta'] ?? '' );
		}
		$blocked = self::rebuild_state_is_blocked( is_array( $data['rebuild'] ?? null ) ? $data['rebuild'] : null );

		echo '<h2 id="akb-search-maintenance">Mantenimiento</h2>';
		echo '<p class="description">Reconstruye el índice después de cambiar taxonomías o contextos de facetas, o si faltan productos. Se ejecuta en segundo plano y la tienda sigue disponible.</p>';

		// Fase terminal: el botón normal POSTearía y no pasaría nada (schedule()
		// sale temprano) — mostrarlo era prometer una acción inexistente. Se
		// reemplaza por el estado real y lo que sí se sabe: la tienda no se cayó.
		if ( $blocked ) {
			$rebuild = is_array( $data['rebuild'] ?? null ) ? $data['rebuild'] : array();
			$detail  = trim( (string) ( $rebuild['error'] ?? '' ) );
			printf(
				'<div class="notice notice-error inline"><p><strong>La reconstrucción quedó bloqueada%s.</strong> La tienda sigue sirviendo el índice anterior, completo: no se perdió nada y ningún cliente ve un catálogo vacío. No se reanuda sola ni desde esta pantalla: el protocolo no toca las tablas sin diagnóstico, y por eso acá no hay botón de reintento (el que había no hacía nada). Revisa «Diagnóstico técnico», más abajo, y el error log antes de intervenir.</p>%s</div>',
				'' !== $detail ? esc_html( ' (' . $detail . ')' ) : '',
				// En caso de bloqueo huérfano de topología, se guía el procedimiento
				// canónico de rescate respetando el orden estricto de saneamiento.
				'orphan_topology_without_state' === $detail
					? '<p><strong>Este caso concreto sí tiene arreglo conocido:</strong> <code>wp akb-search rescue</code> (o <code>bash ops/rescate-buscador.sh produccion</code>, que lo llama y deja la foto de antes y después). El comando suelta las tablas sombra, retira el estado y arranca un índice nuevo, en ese orden; si hay una copia de respaldo del índice o un índice que sí avanza, no toca nada y lo dice.</p>'
					: ''
			);
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_RECONCILE ) . '">';
			wp_nonce_field( self::NONCE_RECONCILE );
			echo '<button type="submit" class="button button-secondary">Verificar consistencia</button>';
			echo '</form>';
			echo '<p class="description">La verificación compara el índice contra el catálogo, repara las diferencias que encuentra y deja el resultado en «Estado». Es segura con la reconstrucción detenida.</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:12px">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_REBUILD ) . '">';
		wp_nonce_field( self::NONCE_REBUILD );
		if ( HealthReport::RECONSTRUYENDO === $state ) {
			$rebuild = is_array( $data['rebuild'] ?? null ) ? $data['rebuild'] : array();
			printf(
				'<button type="submit" class="button" data-akb-rebuild-btn disabled>Reconstruyendo… %s de %s</button>',
				esc_html( number_format_i18n( (int) ( $rebuild['done'] ?? 0 ) ) ),
				esc_html( number_format_i18n( (int) ( $rebuild['total'] ?? 0 ) ) )
			);
		} elseif ( $retry ) {
			echo '<input type="hidden" name="force" value="1">';
			echo '<button type="submit" class="button button-primary" data-akb-rebuild-btn>Reintentar reconstrucción</button>';
		} else {
			echo '<button type="submit" class="button button-secondary" data-akb-rebuild-btn>Reconstruir índice</button>';
		}
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_RECONCILE ) . '">';
		wp_nonce_field( self::NONCE_RECONCILE );
		echo '<button type="submit" class="button button-secondary">Verificar consistencia</button>';
		echo '</form>';
		echo '<p class="description">La verificación compara el índice contra el catálogo, repara las diferencias que encuentra y deja el resultado en «Estado».</p>';
	}

	/**
	 * ¿El estado durable quedó en una fase terminal de la que el panel NO puede
	 * salir? BLOCKED/ABORTED —o un `error` persistido— significan que ni el
	 * botón normal ni el force reaniman el run (RebuildJob::schedule sale
	 * temprano ante `is_terminal_phase`), y HealthReport devuelve esa razón con
	 * cta '' (nunca 'retry'). Distinguirlo es lo único que separa «esperá, hay
	 * uno corriendo» de «esto no se destraba solo».
	 *
	 * @param array<string, mixed>|null $rebuild Estado del rebuild (RebuildJob::status()).
	 * @return bool
	 */
	private static function rebuild_state_is_blocked( ?array $rebuild ): bool {
		if ( ! is_array( $rebuild ) ) {
			return false;
		}
		$phase = (string) ( $rebuild['phase'] ?? '' );

		return in_array( $phase, array( RebuildJob::PHASE_BLOCKED, RebuildJob::PHASE_ABORTED ), true )
			|| '' !== trim( (string) ( $rebuild['error'] ?? '' ) );
	}

	/**
	 * Divulgación progresiva: métricas internas, historial, auditoría y CLI
	 * viven acá — fuera de la lectura principal del operador.
	 *
	 * @param array<string, mixed> $data Insumos de HealthReport::collect().
	 * @return void
	 */
	private function render_diagnostics( array $data ): void {
		global $wpdb;

		echo '<details class="akb-search-diagnostics" style="margin-top:24px;max-width:840px">';
		echo '<summary><strong>Diagnóstico técnico y comandos CLI</strong></summary>';

		$counts = array( 'Productos indexados (docs)' => (int) ( $data['docs'] ?? 0 ) );
		foreach ( array(
			'Tokens del índice'     => Install::tokens_table(),
			'Valores de filtros'    => Install::facets_table(),
			'Consultas registradas' => Install::qlog_table(),
		) as $label => $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- {$table} recorre Install::tokens_table()/facets_table()/qlog_table(), las tres $wpdb->prefix + literal fijo, y el COUNT no lleva ningún valor que preparar; la lectura directa es deliberada: son conteos de diagnóstico que deben verse en vivo, sin caché.
			$counts[ $label ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}
		echo '<div class="akb-admin-scroll"><table class="widefat striped" style="max-width:520px;margin-top:12px"><tbody>';
		foreach ( $counts as $label => $n ) {
			printf( '<tr><th scope="row">%s</th><td>%s filas</td></tr>', esc_html( $label ), esc_html( number_format_i18n( $n ) ) );
		}

		// Métricas técnicas del protocolo durable (fase, topología y estado de la cola):
		// se muestran dentro del bloque de diagnóstico técnico para resolución avanzada.
		$rebuild  = is_array( $data['rebuild'] ?? null ) ? $data['rebuild'] : array();
		$work     = is_array( $data['work'] ?? null ) ? $data['work'] : array();
		$phase    = ! empty( $rebuild ) ? (string) ( $rebuild['phase'] ?? '—' ) : 'sin reconstrucción activa';
		$topology = (string) ( $rebuild['topology'] ?? ( $data['topology'] ?? '—' ) );
		$pending  = (int) ( $work['pending'] ?? ( $rebuild['work_pending'] ?? 0 ) );
		$leased   = (int) ( $work['leased'] ?? ( $rebuild['work_leased'] ?? 0 ) );
		$blocked  = (int) ( $work['blocked'] ?? ( $rebuild['work_blocked'] ?? 0 ) );
		$error    = trim( (string) ( $work['error'] ?? '' ) );
		printf(
			'<tr><th scope="row">Protocolo durable</th><td>Fase %s · topología %s<br>%s pendientes · %s en ejecución · %s bloqueados%s</td></tr>',
			esc_html( $phase ),
			esc_html( $topology ),
			esc_html( number_format_i18n( $pending ) ),
			esc_html( number_format_i18n( $leased ) ),
			esc_html( number_format_i18n( $blocked ) ),
			'' !== $error ? '<br><span style="color:#b32d2e">' . esc_html( $error ) . '</span>' : ''
		);
		echo '</tbody></table></div>';

		$history = RebuildJob::history();
		if ( ! empty( $history ) ) {
			echo '<h3>Historial de reconstrucciones</h3>';
			echo '<div class="akb-admin-scroll"><table class="widefat striped"><thead><tr><th scope="col">Inicio</th><th scope="col">Duración</th><th scope="col">Productos</th><th scope="col">Resultado</th></tr></thead><tbody>';
			foreach ( $history as $entry ) {
				$started  = (int) ( $entry['started_at'] ?? 0 );
				$finished = (int) ( $entry['finished_at'] ?? 0 );
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( $started > 0 ? $this->stamp( $started ) : '—' ),
					esc_html( $started > 0 && $finished >= $started ? $this->duration( $started, $finished ) : '—' ),
					esc_html( number_format_i18n( (int) ( $entry['docs'] ?? 0 ) ) ),
					$this->history_result_html( $entry ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML ya escapado dentro del helper.
				);
			}
			echo '</tbody></table></div>';
		}

		$audit = AuditLog::entries();
		if ( ! empty( $audit ) ) {
			echo '<h3>Registro de cambios</h3>';
			echo '<div class="akb-admin-scroll"><table class="widefat striped"><thead><tr><th scope="col">Fecha</th><th scope="col">Usuario</th><th scope="col">Acción</th><th scope="col">Detalle</th></tr></thead><tbody>';
			foreach ( array_slice( $audit, 0, 15 ) as $entry ) {
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( $this->stamp( (int) ( $entry['t'] ?? 0 ) ) ),
					esc_html( (string) ( $entry['user'] ?? '' ) ),
					esc_html( $this->audit_action_label( (string) ( $entry['action'] ?? '' ) ) ),
					esc_html( self::audit_detail( $entry ) )
				);
			}
			echo '</tbody></table></div>';
		}

		echo '<p class="description" style="margin-top:12px">CLI: <code>wp akb-search rebuild</code> · <code>wp akb-search status</code> · <code>wp akb-search reconcile</code></p>';
		echo '</details>';
	}

	/**
	 * Resultado de una entrada del historial, con el error traducido a causa +
	 * impacto + siguiente paso (nunca solo el código).
	 *
	 * @param array<string, mixed> $entry Entrada del historial.
	 * @return string HTML escapado.
	 */
	private function history_result_html( array $entry ): string {
		$result = (string) ( $entry['result'] ?? '' );
		if ( 'ok' === $result ) {
			return '<span style="color:#16803d">OK</span>';
		}
		if ( 'interrumpido' === $result ) {
			return '<span style="color:#996800">Interrumpida</span> — no llegó a terminar (caída o reintento forzado); la tienda siguió con el índice anterior.';
		}
		return '<span style="color:#b32d2e">Error</span> — ' . esc_html( $this->error_label( (string) ( $entry['error'] ?? '' ) ) );
	}

	/**
	 * Error técnico → causa + impacto + siguiente paso.
	 *
	 * @param string $code Código de error almacenado.
	 * @return string
	 */
	private function error_label( string $code ): string {
		if ( 'atomic_swap_failed' === $code ) {
			return 'Falló el intercambio final de tablas. La tienda sigue sirviendo el índice anterior; reintenta la reconstrucción y, si se repite, revisa el error log de la base de datos.';
		}
		// Este código NO es un fallo del swap: es el swap que se NEGÓ a correr.
		// El genérico ("reintenta") es cierto pero no dice lo importante — que
		// el guardarraíl hizo su trabajo y que el índice viejo, completo, sigue
		// en pie. Sin esta distinción el operador lee "error" y asume catálogo
		// caído.
		if ( 'rebuild_incompleto' === $code ) {
			return 'La reconstrucción no alcanzó igualdad exacta con el catálogo (faltaba o sobraba al menos un producto), así que NO se publicó y la tienda sigue sirviendo el índice anterior. El sistema dejó la deuda durable para reintentar; revisa la fase y la topología antes de intervenir.';
		}
		return sprintf( 'Error «%s». La tienda sigue sirviendo el índice anterior; reintenta la reconstrucción.', $code );
	}

	/**
	 * Detalle de una fila del registro, con su contador si se repitió.
	 *
	 * @param array<string,mixed> $entry Fila de `AuditLog::entries()`.
	 * @return string Texto plano (lo escapa quien lo imprime).
	 */
	public static function audit_detail( array $entry ): string {
		$detalle = (string) ( $entry['detail'] ?? '' );
		$veces   = (int) ( $entry['n'] ?? 1 );
		if ( $veces < 2 ) {
			return $detalle;
		}

		$desde = (int) ( $entry['t0'] ?? 0 );

		return trim(
			$detalle . sprintf(
				' — se repitió %s veces%s',
				number_format_i18n( $veces ),
				$desde > 0 ? ' desde el ' . wp_date( 'd-m-Y H:i', $desde ) : ''
			)
		);
	}

	/**
	 * @param string $code Código de acción del AuditLog.
	 * @return string
	 */
	private function audit_action_label( string $code ): string {
		$labels = array(
			'rebuild_encolado'  => 'Reconstrucción encolada',
			'rebuild_forzado'   => 'Reconstrucción reintentada (force)',
			'reconcile_encolado' => 'Verificación encolada',
			'facets_guardadas'  => 'Facetas guardadas',
			// Acciones del usuario desde el panel:
			'ajustes_guardados' => 'Ajustes guardados',
			// Acciones y eventos del sistema documentados en castellano para legibilidad en auditoría:
			'rescate'                                => 'Rescate del buscador',
			'serving_alarma'                         => 'Alarma del buscador',
			'serving_recovery'                       => 'Buscador caído: reconstrucción pedida',
			'trabajo_huerfano'                       => 'No se pudo barrer el trabajo de corridas anteriores',
			'avance_generacion_rechazado'            => 'Publicación del índice rechazada',
			'index_term_debt_failed'                 => 'No se pudo anotar un cambio de categoría o marca',
			'option_semantica_sin_cercar'            => 'Ajuste del buscador guardado sin reserva previa',
			'rebuild_de_filtros_no_pedido'           => 'Cambiaron los filtros y no se pudo pedir la reconstrucción',
			'option_editorial_sin_cercar'            => 'Ajuste de descuentos guardado sin reserva previa',
			'option_editorial_rechazada'             => 'Ajuste de descuentos rechazado',
			'option_editorial_reparada'              => 'Ajuste de descuentos reparado',
			'deuda_editorial_a_medias'               => 'Cambio de descuentos a medio aplicar',
			'marco_editorial_sin_cerrar'             => 'Cambio de descuentos sin cerrar',
			'rebuild_editorial_retirado'             => 'Reconstrucción por descuentos retirada',
			'recibo_editorial_superado'              => 'Comprobante de descuentos superado',
			'receipt_resellado'                      => 'Comprobante de precios renovado',
			'receipt_resellado_sin_cambio_de_precio' => 'Comprobante de precios renovado (sin cambio de precio)',
			'receipt_reproof_rechazado'              => 'Comprobación de precios rechazada',
			'receipt_reproof_diferido'               => 'Comprobación de precios aplazada',
			'receipt_reproof_carrera'                => 'Comprobación de precios repetida (dos a la vez)',
			'receipt_reproof_valvula'                => 'Comprobación de precios pausada',
		);
		return $labels[ $code ] ?? $code;
	}

	/**
	 * @return void
	 */
	private function render_searches(): void {
		global $wpdb;

		$qlog  = Install::qlog_table();
		$since = QueryLogger::day( 30 );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$qlog} sale de Install::qlog_table() ($wpdb->prefix + literal fijo).
		$top = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT query_norm, SUM(searches) AS s, MAX(hits) AS h FROM {$qlog}
				WHERE day >= %s GROUP BY query_norm ORDER BY s DESC LIMIT 30",
				$since
			)
		);
		$zero = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT query_norm, SUM(searches) AS s, MAX(day) AS last_day FROM {$qlog}
				WHERE day >= %s AND hits = 0 GROUP BY query_norm ORDER BY s DESC LIMIT 20",
				$since
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$reales      = DemandGaps::rows( $wpdb, DemandGaps::DEFAULT_DAYS, 8 );
		$descartadas = GapDismissals::all();
		$rescues     = array();

		if ( ! empty( $zero ) ) {
			$queries = array_map( static fn( $row ): string => (string) $row->query_norm, $zero );
			$rescues = self::zero_result_rescues( $queries );

			usort(
				$zero,
				static function ( $a, $b ) use ( $rescues ): int {
					$rescued_a = (int) ( $rescues[ (string) $a->query_norm ]['hits'] ?? 0 ) > 0 ? 1 : 0;
					$rescued_b = (int) ( $rescues[ (string) $b->query_norm ]['hits'] ?? 0 ) > 0 ? 1 : 0;

					return $rescued_a === $rescued_b ? (int) $b->s <=> (int) $a->s : $rescued_a <=> $rescued_b;
				}
			);
		}

		$this->render_template(
			'tab-busquedas.php',
			array(
				'top'         => (array) $top,
				'zero'        => (array) $zero,
				'rescues'     => $rescues,
				'reales'      => $reales,
				'descartadas' => $descartadas,
			)
		);
	}

	/**
	 * Procesa la actualización de estado y notas de una solicitud de tomo por encargo.
	 *
	 * @return void
	 */
	public function handle_update_request_status(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'No autorizado.' );
		}
		check_admin_referer( 'akb_update_request_status' );

		$id     = isset( $_POST['request_id'] ) ? (int) $_POST['request_id'] : 0;
		$status = isset( $_POST['new_status'] ) ? sanitize_key( wp_unslash( (string) $_POST['new_status'] ) ) : '';
		$notes  = isset( $_POST['request_notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['request_notes'] ) ) : '';

		if ( $id > 0 && in_array( $status, TitleRequests::STATUSES, true ) ) {
			TitleRequests::update_status( $id, $status, $notes );
		}

		$status_filter = isset( $_POST['current_filter'] ) ? sanitize_key( wp_unslash( (string) $_POST['current_filter'] ) ) : 'pending';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'sub'     => 'encargos',
					'status'  => $status_filter,
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Pestaña de administración: Solicitudes de Tomos por Encargo.
	 *
	 * Permite ver las solicitudes de tomos no disponibles en bodega registradas por clientes
	 * desde el modal de búsqueda, contactarlos por WhatsApp/Email y gestionar su ciclo de vida.
	 *
	 * @return void
	 */
	private function render_encargos(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sólo elige el filtro de estado en la vista.
		$filter_status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : 'pending';
		$valid_filters = array_merge( array( 'all' ), TitleRequests::STATUSES );
		if ( ! in_array( $filter_status, $valid_filters, true ) ) {
			$filter_status = 'pending';
		}

		$counts = TitleRequests::count_by_status();
		$rows   = TitleRequests::list_requests( $filter_status, 100 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- parámetro de redirección tras guardar.
		$updated = isset( $_GET['updated'] ) && '1' === sanitize_key( wp_unslash( (string) $_GET['updated'] ) );

		$this->render_template(
			'tab-encargos.php',
			array(
				'filter_status' => $filter_status,
				'counts'        => $counts,
				'rows'          => $rows,
				'updated'       => $updated,
			)
		);
	}

	/**
	 * Evalúa si las búsquedas sin resultados registrados devuelven actualmente coincidencias
	 * en el motor al aplicar corrección tipográfica (fuzzy).
	 *
	 * Permite discriminar entre huecos reales de demanda y errores de tipeo ya subsanados.
	 * El resultado se cachea transitoriamente con degradación abierta si el transient no responde.
	 *
	 * @param array<int, string> $queries Búsquedas normalizadas.
	 * @return array<string, array{hits: int, fix: string}>
	 */
	public static function zero_result_rescues( array $queries ): array {
		if ( empty( $queries ) ) {
			return array();
		}

		// La clave incorpora la generación activa del índice: garantiza que la foto
		// de rescate expire de forma natural cuando se publica una nueva versión del catálogo.
		$key    = 'akb_search_zero_rescue_' . md5( (string) get_option( Install::OPTION_INDEX_GEN, '' ) . "\n" . implode( "\n", $queries ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$engine = new QueryEngine( $wpdb );
		$out    = array();
		foreach ( $queries as $query ) {
			Fuzzy::flush();
			$hits = count( $engine->search( $query, false, QueryEngine::MAX_RESULTS, true ) );
			// La disponibilidad se consulta DESPUÉS de la búsqueda, no antes: un
			// error de lectura devuelve cero resultados, y anotar ese cero como
			// «hueco real» convertiría una falla del índice en una tarea de
			// contenido inventada. Mismo orden que SuggestController.
			if ( ! $engine->is_available() ) {
				return $out; // Sin veredicto inventado y sin cachear una foto rota.
			}
			$map = Fuzzy::last_correction();
			$fix = '';
			if ( ! empty( $map ) ) {
				$tokens = array();
				foreach ( explode( ' ', Normalizer::normalize( $query ) ) as $token ) {
					if ( '' !== $token ) {
						$tokens[] = $map[ $token ] ?? $token;
					}
				}
				$fix = implode( ' ', $tokens );
			}
			$out[ $query ] = array(
				'hits' => $hits,
				'fix'  => $fix,
			);
		}
		Fuzzy::flush(); // El side-channel es de request; no dejarlo sucio.

		set_transient( $key, $out, HOUR_IN_SECONDS );

		return $out;
	}

	/**
	 * Veredicto por fila de «sin resultados». HTML ya escapado.
	 *
	 * @param array{hits: int, fix: string}|null $rescue Medición del motor, o null si no se pudo medir.
	 * @return string
	 */
	private function zero_result_verdict_html( ?array $rescue ): string {
		if ( null === $rescue ) {
			return '<span style="color:#646970">—</span>';
		}
		$hits = (int) $rescue['hits'];
		if ( 0 === $hits ) {
			return '<strong style="color:#b32d2e">No</strong> — hueco real';
		}
		if ( '' !== (string) $rescue['fix'] ) {
			return sprintf(
				'<span style="color:#996800">Sí, corrigiendo a «%s»</span> — %s resultados',
				esc_html( (string) $rescue['fix'] ),
				esc_html( number_format_i18n( $hits ) )
			);
		}

		// Sin corrección y con resultados: el catálogo cambió DESPUÉS de ese cero.
		return sprintf( '<span style="color:#16803d">Sí</span> — %s resultados hoy', esc_html( number_format_i18n( $hits ) ) );
	}

	/**
	 * Ranking «Los más buscados que no tienes» — la respuesta rápida del panel.
	 *
	 * Recibe los huecos ya filtrados por DemandGaps (typos rescatados y
	 * descartes del dueño quedaron fuera ANTES del recorte). Cada entrada lleva
	 * su acción principal, crear la ficha con el título ya escrito, porque ese
	 * es el flujo del dueño (las fichas las crea él).
	 *
	 * @param array<int, object> $reales Huecos reales `{query_norm, s, s30}`.
	 */
	private function render_missing_titles( array $reales ): void {
		// El log queda intacto por si el dueño cambia de opinión: los descartes
		// se listan aparte, con su botón de vuelta.
		$descartadas = GapDismissals::all();
		if ( empty( $reales ) && empty( $descartadas ) ) {
			return;
		}

		echo '<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:16px 20px;margin:8px 0 20px;max-width:760px;overflow-x:auto;min-width:0">';
		echo '<h2 style="margin:0 0 4px">Los más buscados que no tienes</h2>';
		echo '<p class="description" style="margin-top:0">Búsquedas de los últimos 90 días sin ningún resultado, ya descartados los errores de tipeo de títulos que sí existen. Es tu lista corta de compra y fichas.</p>';
		if ( array() !== $reales ) :
		echo '<table class="widefat striped"><thead><tr><th scope="col">Título buscado</th><th scope="col">90 días</th><th scope="col">Este mes</th><th scope="col"></th></tr></thead><tbody>';
		foreach ( $reales as $row ) {
			$query = (string) $row->query_norm;
			$s30   = (int) ( $row->s30 ?? 0 );
			printf(
				'<tr><td data-label="Título buscado" style="font-size:14px"><strong>%s</strong></td><td data-label="90 días"><strong>%s</strong></td><td data-label="Este mes">%s</td><td data-label="Acciones">%s</td></tr>',
				esc_html( $query ),
				esc_html( number_format_i18n( (int) $row->s ) ),
				$s30 > 0 ? esc_html( number_format_i18n( $s30 ) ) : '<span style="color:#787c82">—</span>',
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper con HTML ya escapado.
				$this->create_product_link_html( $query ) . ' · ' . $this->zero_result_actions_html( $query ) . ' · ' . $this->gap_dismiss_link_html( $query )
			);
		}
		echo '</tbody></table>';
		endif;
		echo '<p class="description">«Este mes» en guion = nadie lo buscó en 30 días: demanda antigua, prioridad baja. «Descartar» la oculta del radar (recuperable); el log se poda solo a 180 días.</p>';
		$this->render_dismissed_gaps( $descartadas );
		echo '</div>';
	}

	/**
	 * URL nonceada de una acción del radar. Puente para CUALQUIER superficie
	 * —incluido el Centro de Mando de business, que vive en otro riel— para
	 * que nadie tenga que reproducir a mano el par acción/nonce.
	 *
	 * @param string $action ACTION_GAP_DISMISS o ACTION_GAP_RESTORE.
	 * @param string $query  Búsqueda normalizada.
	 * @return string URL cruda (sin escapar para atributo).
	 */
	public static function gap_action_url( string $action, string $query ): string {
		if ( ! in_array( $action, array( self::ACTION_GAP_DISMISS, self::ACTION_GAP_RESTORE ), true ) ) {
			return '';
		}

		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => $action,
					'query'  => rawurlencode( $query ),
				),
				admin_url( 'admin-post.php' )
			),
			$action
		);
	}

	/**
	 * Enlace «Descartar» de una búsqueda del radar (admin-post con nonce).
	 *
	 * @param string $query Búsqueda normalizada.
	 */
	private function gap_dismiss_link_html( string $query ): string {
		return sprintf(
			'<a href="%s" style="color:#b32d2e">Descartar<span class="screen-reader-text"> «%s» del radar</span></a>',
			esc_url( self::gap_action_url( self::ACTION_GAP_DISMISS, $query ) ),
			esc_html( $query )
		);
	}

	/**
	 * Bloque plegable de descartadas con su «Restaurar».
	 *
	 * @param array<string,int> $descartadas Mapa query → timestamp.
	 * @return void
	 */
	private function render_dismissed_gaps( array $descartadas ): void {
		if ( array() === $descartadas ) {
			return;
		}

		arsort( $descartadas );
		printf( '<details style="margin-top:8px"><summary style="cursor:pointer">Descartadas (%d)</summary><ul style="margin:8px 0 0">', count( $descartadas ) );
		foreach ( $descartadas as $query => $ts ) {
			printf(
				'<li><code>%s</code> <span style="color:#787c82">(%s)</span> — <a href="%s">Restaurar</a></li>',
				esc_html( (string) $query ),
				esc_html( date_i18n( 'd M', (int) $ts ) ),
				esc_url( self::gap_action_url( self::ACTION_GAP_RESTORE, (string) $query ) )
			);
		}
		echo '</ul></details>';
	}

	/**
	 * Descarta una búsqueda del radar y vuelve a la pantalla de origen.
	 *
	 * @return void
	 */
	public function handle_gap_dismiss(): void {
		$this->guard( self::ACTION_GAP_DISMISS );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended -- SÍ se sanea: `sanitize_text_field()` envuelve la expresión, y el sniff se pierde con el `rawurldecode()` intermedio, necesario porque el enlace del panel llega codificado. El nonce lo verificó `guard()` en la línea de arriba.
		$query = isset( $_GET['query'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['query'] ) ) ) : '';
		if ( '' !== $query ) {
			GapDismissals::dismiss( $query );
		}

		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=akibara-search&sub=busquedas' ) );
		exit;
	}

	/**
	 * Restaura una búsqueda descartada.
	 *
	 * @return void
	 */
	public function handle_gap_restore(): void {
		$this->guard( self::ACTION_GAP_RESTORE );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended -- SÍ se sanea: `sanitize_text_field()` envuelve la expresión, y el sniff se pierde con el `rawurldecode()` intermedio, necesario porque el enlace del panel llega codificado. El nonce lo verificó `guard()` en la línea de arriba.
		$query = isset( $_GET['query'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['query'] ) ) ) : '';
		if ( '' !== $query ) {
			GapDismissals::restore( $query );
		}

		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=akibara-search&sub=busquedas' ) );
		exit;
	}

	/**
	 * Enlace a crear la ficha con el título ya pre-escrito.
	 *
	 * @param string $query Búsqueda normalizada.
	 */
	private function create_product_link_html( string $query ): string {
		return sprintf(
			'<a class="button button-small button-primary" href="%s">Crear ficha<span class="screen-reader-text"> para «%s»</span></a>',
			esc_url(
				add_query_arg(
					array(
						'post_type'  => 'product',
						'post_title' => rawurlencode( $query ),
					),
					admin_url( 'post-new.php' )
				)
			),
			esc_html( $query )
		);
	}

	/**
	 * Acciones por fila: probar la búsqueda en la tienda y arrancar un sinónimo
	 * con el texto ya cargado. Antes había que retipear la búsqueda en otra
	 * pestaña para cualquiera de las dos cosas.
	 *
	 * @param string $query Búsqueda normalizada.
	 * @return string HTML ya escapado.
	 */
	private function zero_result_actions_html( string $query ): string {
		$links = array();

		$shop = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : '';
		if ( '' !== $shop ) {
			// `_buscar` es la búsqueda propia de la tienda (is_shop), no el `?s=`
			// genérico de WordPress. add_query_arg NO codifica los valores nuevos.
			$links[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">Probar<span class="screen-reader-text"> «%s» en la tienda (abre en otra pestaña)</span></a>',
				esc_url( add_query_arg( FilterSet::PARAM_SEARCH, rawurlencode( $query ), $shop ) ),
				esc_html( $query )
			);
		}

		$links[] = sprintf(
			'<a href="%s">Crear sinónimo<span class="screen-reader-text"> para «%s»</span></a>',
			esc_url(
				add_query_arg(
					array(
						'page'            => self::PAGE_SLUG,
						'sub'             => 'sinonimos',
						self::PARAM_PREFILL => rawurlencode( $query ),
					),
					admin_url( 'admin.php' )
				)
			),
			esc_html( $query )
		);

		return implode( ' · ', $links );
	}

	/**
	 * Día del qlog (`YYYY-MM-DD`) en formato legible; vacío o inválido → «—».
	 *
	 * @param string $day Valor crudo de la columna `day`.
	 * @return string
	 */
	private function qlog_day_label( string $day ): string {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $day ) ) {
			return '—';
		}
		$stamp = strtotime( $day . ' 12:00:00 +0000' );

		return false === $stamp ? $day : (string) date_i18n( 'j M Y', $stamp );
	}

	/**
	 * @return void
	 */
	private function render_facets(): void {
		$defs       = FacetRegistry::definitions();
		$seed_names = FacetRegistry::seed_names();

		$orders = FacetsView::effective_orders( $defs );
		uksort( $defs, static fn( $a, $b ): int => $orders[ (string) $a ] <=> $orders[ (string) $b ] );

		$datalist_taxes = array();
		foreach ( $defs as $def ) {
			if ( 'taxonomy' === ( $def['type'] ?? '' ) && '' !== (string) ( $def['taxonomy'] ?? '' ) ) {
				$datalist_taxes[ (string) $def['taxonomy'] ] = true;
			}
		}
		foreach ( array_keys( self::eligible_taxonomies() ) as $tax ) {
			$datalist_taxes[ $tax ] = true;
		}
		$capped = $this->render_parent_datalists( array_keys( $datalist_taxes ) );

		$this->render_template(
			'tab-facetas.php',
			array(
				'defs'           => $defs,
				'seed_names'     => $seed_names,
				'eligible_taxes' => self::eligible_taxonomies(),
				'capped'         => $capped,
			)
		);
	}

	// -----------------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------------

	/**
	 * Opciones de UI válidas para una faceta.
	 *
	 * @param array<string, mixed> $def Definición de faceta.
	 * @return array<string, string>
	 */
	public static function facet_ui_choices( array $def ): array {
		// El texto entre paréntesis es la GUÍA de cuándo usar cada control (regla
		// del proyecto: casillas por defecto para combinar, radio solo si la
		// faceta es realmente excluyente, lista con buscador para taxonomías
		// largas). Restaurado tras el gate adversario P2 — sin el hint, el
		// operador podía poner radio en una faceta combinable o alpha en una corta.
		$choices = array(
			'checkboxes' => 'Casillas (combinables)',
			'radio'      => 'Radio (excluyente)',
		);

		if ( 'taxonomy' === ( $def['type'] ?? '' ) ) {
			$choices['alpha'] = 'Lista con buscador (muchos términos)';
		}

		return $choices;
	}

	/**
	 * Sanitiza la UI de una faceta sin permitir combinaciones inválidas.
	 *
	 * @param string               $ui  Valor recibido del formulario.
	 * @param array<string, mixed> $def Definición de faceta.
	 * @return string
	 */
	private static function sanitize_facet_ui( string $ui, array $def ): string {
		$choices = self::facet_ui_choices( $def );
		if ( isset( $choices[ $ui ] ) ) {
			return $ui;
		}

		$current = (string) ( $def['ui'] ?? 'checkboxes' );
		return isset( $choices[ $current ] ) ? $current : 'checkboxes';
	}

	/**
	 * Label corto para auditoría y notices.
	 *
	 * @param string $ui Slug de UI.
	 * @return string
	 */
	private static function facet_ui_label( string $ui ): string {
		$labels = array(
			'checkboxes' => 'casillas',
			'radio'      => 'radio',
			'alpha'      => 'lista con buscador',
		);

		return $labels[ $ui ] ?? $ui;
	}

	/**
	 * @param string $nonce_action Acción del nonce.
	 * @return void
	 */
	private function guard( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Sub-tab "Sinónimos" (paridad con la tab Synonyms de Relevanssi, en
	 * español): un grupo por línea, términos equivalentes separados por coma.
	 * La expansión es query-time (Synonyms) — no requiere reindexar.
	 *
	 * @return void
	 */
	private function render_synonyms(): void {
		$lines = array();
		foreach ( Synonyms::groups() as $group ) {
			$lines[] = implode( ', ', array_map( 'strval', (array) $group ) );
		}

		// Precarga desde «Búsquedas → Sin resultados»: antes, crear el sinónimo de
		// una búsqueda huérfana obligaba a memorizarla y retipearla acá.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sólo precarga texto en un formulario; el guardado tiene su propio nonce.
		$prefill = isset( $_GET[ self::PARAM_PREFILL ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::PARAM_PREFILL ] ) ) : '';
		$prefill = trim( mb_substr( $prefill, 0, (int) Synonyms::MAX_TERM_LENGTH ) );
		if ( '' !== $prefill ) {
			$lines[] = $prefill . ', ';
		}

		$shared = Synonyms::shared_terms( Synonyms::groups() );

		$this->render_template(
			'tab-sinonimos.php',
			array(
				'lines'   => $lines,
				'prefill' => $prefill,
				'shared'  => $shared,
			)
		);
	}

	/**
	 * Guarda los grupos de sinónimos (option akb_search_synonyms). Solo se
	 * conservan grupos con ≥2 términos; los términos se sanitizan.
	 *
	 * @return void
	 */
	public function handle_save_synonyms(): void {
		$this->guard( self::NONCE_SYNONYMS );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() ya validó; textarea multilínea: sanearlo entero aquí se llevaría los saltos que separan los grupos; se sanea línea a línea al recorrerlo.
		$raw    = isset( $_POST['synonyms'] ) ? (string) wp_unslash( $_POST['synonyms'] ) : '';
		$groups = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$terms = array_values(
				array_filter(
					array_map(
						static fn( string $t ): string => trim( sanitize_text_field( $t ) ),
						explode( ',', (string) $line )
					),
					static fn( string $t ): bool => '' !== $t
				)
			);
			$groups[] = $terms;
		}

		$truncated = false;
		$groups    = Synonyms::sanitize_groups( $groups, $truncated );
		if ( ! $this->persist_options_exact( array( Synonyms::OPTION => $groups ) ) ) {
			$this->redirect( 'sinonimos', 'save_failed' );
		}
		Synonyms::flush();

		$this->redirect( 'sinonimos', $truncated ? 'synonyms_saved_limited' : 'synonyms_saved' );
	}

	/**
	 * Sub-tab "Palabras ignoradas" (stopwords): una palabra por línea o
	 * separadas por coma/espacio. En español; se ignoran en la búsqueda.
	 *
	 * @return void
	 */
	private function render_stopwords(): void {
		$this->render_template(
			'tab-stopwords.php',
			array(
				'vigentes' => Stopwords::all(),
				'defaults' => Stopwords::defaults(),
			)
		);
	}

	/**
	 * Guarda la lista de stopwords (option akb_search_stopwords).
	 *
	 * @return void
	 */
	public function handle_save_stopwords(): void {
		$this->guard( self::NONCE_STOPWORDS );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() ya validó; textarea multilínea: se sanea palabra a palabra al recorrerlo.
		$raw       = isset( $_POST['stopwords'] ) ? (string) wp_unslash( $_POST['stopwords'] ) : '';
		$truncated = false;
		$words     = Stopwords::sanitize_words( $raw, $truncated );

		if ( ! $this->persist_options_exact( array( Stopwords::OPTION => $words ) ) ) {
			$this->redirect( 'stopwords', 'save_failed' );
		}
		Stopwords::flush();
		Synonyms::flush();

		$this->redirect( 'stopwords', $truncated ? 'stopwords_saved_limited' : 'synonyms_saved' );
	}

	/**
	 * Sub-tab "Ajustes": palancas booleanas del motor. Aplican de inmediato, sin
	 * reconstruir el índice.
	 *
	 * Delegado al template templates/admin/tab-ajustes.php.
	 *
	 * @return void
	 */
	private function render_settings(): void {
		$this->render_template(
			'tab-ajustes.php',
			array(
				'current' => $this->settings_current_values(),
			)
		);
	}

	/**
	 * Fila de texto libre para la tabla de Ajustes.
	 *
	 * Mismo contrato accesible que `settings_toggle()`: la etiqueta REAL en el
	 * `label for` del `th` y la descripción por `aria-describedby`.
	 *
	 * @param string $name  Slug (el name del input es texto_<slug>).
	 * @param string $label Etiqueta.
	 * @param string $desc  Descripción.
	 * @param string $value Valor actual.
	 * @return void
	 */
	private function settings_text( string $name, string $label, string $desc, string $value ): void {
		$id = 'akb-text-' . $name;
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="text" class="large-text" id="%1$s" name="texto_%3$s" value="%4$s" maxlength="%6$d" aria-describedby="%1$s-desc"><p class="description" id="%1$s-desc">%5$s</p></td></tr>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( $value ),
			// El tope se ve mientras se escribe: el guardado recorta a esta misma
			// cifra, y un aviso cortado a media frase es peor que uno corto.
			esc_html( $desc . sprintf( ' Máximo %d caracteres.', EmptyStateCopy::MAX_LENGTH ) ),
			(int) EmptyStateCopy::MAX_LENGTH
		);
	}

	/**
	 * Fila de lista de palabras para la tabla de Ajustes: una por línea.
	 *
	 * Mismo contrato accesible que `settings_text()`. Se guarda con
	 * `Stopwords::sanitize_words()`, el validador de Palabras ignoradas, y el
	 * motor la lee con el mismo (`EmptyStateSuggestions::tail_words()`).
	 *
	 * Cuando la lista vigente difiere de los valores predeterminados de fábrica,
	 * la interfaz expone la opción de restaurarla e indica las diferencias.
	 * La comparación se basa en la clave normalizada del motor (`EmptyStateSuggestions::tail_words_key()`).
	 *
	 * @param string             $name    Slug (el name del textarea es palabras_<slug>).
	 * @param string             $label   Etiqueta.
	 * @param string             $desc    Descripción.
	 * @param array<int, string> $words   Lista vigente.
	 * @param array<int, string> $factory Lista de fábrica.
	 * @return void
	 */
	private function settings_words( string $name, string $label, string $desc, array $words, array $factory ): void {
		$id = 'akb-words-' . $name;
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><textarea class="large-text code" rows="6" spellcheck="false" id="%1$s" name="palabras_%3$s" aria-describedby="%1$s-desc">%4$s</textarea><p class="description" id="%1$s-desc">%5$s</p>%6$s</td></tr>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $name ),
			esc_textarea( implode( "\n", $words ) ),
			esc_html( $desc ),
			$this->settings_words_reset( $id, $name, $words, $factory ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- settings_words_reset() escapa cada tramo.
		);
	}

	/**
	 * Casilla «Volver a la lista de fábrica» y el resumen de la diferencia, o
	 * nada si la lista vigente ya es la de fábrica.
	 *
	 * @param string             $id      Id del textarea.
	 * @param string             $name    Slug (el name de la casilla es fabrica_<slug>).
	 * @param array<int, string> $words   Lista vigente.
	 * @param array<int, string> $factory Lista de fábrica.
	 * @return string HTML escapado.
	 */
	private function settings_words_reset( string $id, string $name, array $words, array $factory ): string {
		if ( EmptyStateSuggestions::tail_words_key( $words ) === EmptyStateSuggestions::tail_words_key( $factory ) ) {
			return '';
		}

		$vigentes = '' === EmptyStateSuggestions::tail_words_key( $words ) ? array() : explode( ', ', EmptyStateSuggestions::tail_words_key( $words ) );
		$fabrica  = explode( ', ', EmptyStateSuggestions::tail_words_key( $factory ) );
		$partes   = self::word_groups(
			array(
				array( array_values( array_diff( $fabrica, $vigentes ) ), 'faltan de la de fábrica: ', array( 'faltan ', ' palabras de la de fábrica' ) ),
				array( array_values( array_diff( $vigentes, $fabrica ) ), 'agregaste: ', array( 'agregaste ', ' palabras' ) ),
			)
		);

		$reset_id = $id . '-fabrica';

		return sprintf(
			'<p><label for="%1$s"><input type="checkbox" id="%1$s" name="fabrica_%2$s" value="1" aria-describedby="%1$s-desc"> Volver a la lista de fábrica</label></p><p class="description" id="%1$s-desc">%3$s</p>',
			esc_attr( $reset_id ),
			esc_attr( $name ),
			esc_html(
				'Tu lista no es la de fábrica (' . implode( '; ', $partes ) . '). Marca la casilla y guarda para volver a ella: lo escrito en el cuadro se descarta y, desde entonces, cada mejora de la lista de fábrica te llega sola.'
			)
		);
	}

	/**
	 * Cada grupo de palabras de una diferencia, en una frase: enumerado hasta
	 * `WORDS_LISTED_MAX` palabras y contado desde ahí, porque cien palabras
	 * seguidas no se leen (una lista vaciada entera, por ejemplo). Un grupo
	 * vacío no se dice.
	 *
	 * La comparten la fila de «Palabras de formato» (qué la separa de la de
	 * fábrica) y el registro de cambios (qué cambió al guardar).
	 *
	 * @param array<int, array{0: array<int, string>, 1: string, 2: array{0: string, 1: string}}> $grupos
	 *        Cada grupo: [palabras, prefijo con la lista, [prefijo, sufijo] con la cuenta].
	 * @return array<int, string>
	 */
	private static function word_groups( array $grupos ): array {
		$partes = array();
		foreach ( $grupos as list( $lista, $con_lista, $con_cuenta ) ) {
			if ( array() === $lista ) {
				continue;
			}
			$partes[] = count( $lista ) > self::WORDS_LISTED_MAX
				? $con_cuenta[0] . count( $lista ) . $con_cuenta[1]
				: $con_lista . implode( ', ', $lista );
		}

		return $partes;
	}

	/**
	 * Valor GUARDADO de cada palanca de Ajustes, con el default de su consumidor
	 * real. Una sola fuente para el checkbox y para el diff del registro de
	 * cambios: si difieren, el panel inventa cambios que nadie hizo.
	 *
	 * Los valores predeterminados coinciden con los de los módulos consumidores
	 * (como sku_fastpath = 'yes' en QueryEngine), asegurando que el diff previo
	 * al guardado no registre modificaciones espurias sobre opciones no persistidas.
	 *
	 * @return array<string, string>
	 */
	private function settings_current_values(): array {
		return array(
			'fuzzy'        => (string) get_option( Fuzzy::OPTION, 'no' ),
			'sku_fastpath' => (string) get_option( 'akb_search_sku_fastpath', 'yes' ),
			'novedad'      => (string) get_option( Novedad::OPTION, 'no' ),
			'indexing'     => (string) get_option( Search::OPTION_INDEXING, 'no' ),
			'suggest'      => (string) get_option( Search::OPTION_SUGGEST, 'no' ),
			'facets'       => (string) get_option( Search::OPTION_FACETS, 'no' ),
			// Default 'yes': corresponde a `PopularQueries::enabled()`.
			'popular'      => (string) get_option( PopularQueries::OPTION_ENABLED, 'yes' ),
			// Los textos entran al mismo array para calcular el diferencial unificado de auditoría.
			'empty_hint_catalog' => EmptyStateCopy::catalog(),
			'empty_hint_filters' => EmptyStateCopy::filters(),
			'empty_hint_suggest' => EmptyStateCopy::suggest(),
			'relaxed_notice'     => BrandRelax::template(),
			// Default 'yes', el mismo de `EmptyStateSuggestions::enabled()`.
			'empty_suggestions'   => (string) get_option( EmptyStateSuggestions::OPTION_ENABLED, 'yes' ),
			'empty_series_label'  => EmptyStateCopy::series_label(),
			'empty_popular_label' => EmptyStateCopy::popular_label(),
			// Clave normalizada de palabras de formato (sin orden ni tildes) para comparación determinista:
			'empty_tail_words'    => EmptyStateSuggestions::tail_words_key( EmptyStateSuggestions::tail_words() ),
			'network_notice'      => EmptyStateCopy::network(),
		);
	}

	/**
	 * Fila de toggle (checkbox) para la tabla de Ajustes.
	 *
	 * @param string $name  Slug (el name del input es toggle_<slug>).
	 * @param string $label Etiqueta.
	 * @param string $desc  Descripción.
	 * @param bool   $on    Estado actual.
	 * @return void
	 */
	private function settings_toggle( string $name, string $label, string $desc, bool $on ): void {
		// Accesibilidad: el nombre accesible proviene del th asociado mediante label for,
		// y la descripción se vincula mediante aria-describedby sin duplicar etiquetas.
		$id = 'akb-toggle-' . $name;
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="checkbox" id="%1$s" name="toggle_%3$s" value="yes" aria-describedby="%1$s-desc"%4$s><p class="description" id="%1$s-desc">%5$s</p></td></tr>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $desc )
		);
	}

	/**
	 * Guarda Ajustes: los interruptores del motor, los servicios de la tienda y
	 * los mensajes de «sin resultados». Checkbox ausente = 'no' (semántica HTML
	 * estándar), salvo el de las salidas, que exige su marcador de presencia; un
	 * texto o una lista ausentes conservan el valor vigente.
	 *
	 * @return void
	 */
	public function handle_save_settings(): void {
		$this->guard( self::NONCE_SETTINGS );

		// Captura del estado previo antes de procesar el formulario:
		// alimenta el diff para el registro de auditoría (AuditLog) y preserva campos no enviados.
		$before = $this->settings_current_values();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ya validó.
		$fuzzy = isset( $_POST['toggle_fuzzy'] ) ? 'yes' : 'no';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ya validó.
		$sku = isset( $_POST['toggle_sku_fastpath'] ) ? 'yes' : 'no';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ya validó.
		$novedad = isset( $_POST['toggle_novedad'] ) ? 'yes' : 'no';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ya validó.
		$indexing = isset( $_POST['toggle_indexing'] ) ? 'yes' : 'no';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ya validó.
		$suggest = isset( $_POST['toggle_suggest'] ) ? 'yes' : 'no';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ya validó.
		$facets = isset( $_POST['toggle_facets'] ) ? 'yes' : 'no';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ya validó.
		$popular = isset( $_POST['toggle_popular'] ) ? 'yes' : 'no';
		// Un formulario sin el campo (enviado a mano, o de una versión anterior
		// del panel) conserva el valor vigente en vez de pisarlo: vacío es una
		// decisión del dueño, no un campo ausente.
		// `$avisos` registra divergencias cuando el valor guardado debió ser adaptado
		// (por ejemplo si un texto debió recortarse al límite o despojarse de tags HTML).
		$avisos         = array();
		$hint_catalog   = $this->posted_settings_text( 'empty_hint_catalog', EmptyStateCopy::DEFAULT_CATALOG, $before, $avisos );
		$hint_filters   = $this->posted_settings_text( 'empty_hint_filters', EmptyStateCopy::DEFAULT_FILTERS, $before, $avisos );
		$hint_suggest   = $this->posted_settings_text( 'empty_hint_suggest', EmptyStateCopy::DEFAULT_SUGGEST, $before, $avisos );
		$relaxed_notice = $this->posted_settings_text( 'relaxed_notice', BrandRelax::DEFAULT_NOTICE, $before, $avisos );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ya validó.
		$suggestions_painted = isset( $_POST['presente_empty_suggestions'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ya validó.
		$suggestions_checked = isset( $_POST['toggle_empty_suggestions'] ) ? 'yes' : 'no';
		$empty_suggestions   = $suggestions_painted ? $suggestions_checked : $before['empty_suggestions'];
		$series_label  = $this->posted_settings_text( 'empty_series_label', EmptyStateCopy::DEFAULT_SERIES_LABEL, $before, $avisos );
		$popular_label = $this->posted_settings_text( 'empty_popular_label', EmptyStateCopy::DEFAULT_POPULAR_LABEL, $before, $avisos );
		$network_notice = $this->posted_settings_text( 'network_notice', EmptyStateCopy::DEFAULT_NETWORK, $before, $avisos );
		$tail_truncated = false;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ya validó.
		if ( isset( $_POST['fabrica_empty_tail_words'] ) ) {
			// «Volver a la lista de fábrica» gana a lo escrito en el cuadro: es
			// la única forma de volver sin copiar 170 palabras a mano.
			$tail_words = EmptyStateSuggestions::default_tail_words();
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() ya validó; textarea multilínea: Stopwords::sanitize_words() la sanea palabra a palabra.
			$tail_words = isset( $_POST['palabras_empty_tail_words'] ) ? Stopwords::sanitize_words( (string) wp_unslash( $_POST['palabras_empty_tail_words'] ), $tail_truncated ) : EmptyStateSuggestions::tail_words();
		}
		if ( $tail_truncated ) {
			$avisos[] = 'De las palabras de formato se descartaron las de más de 64 caracteres.';
		}

		$after = array(
			'fuzzy'        => $fuzzy,
			'sku_fastpath' => $sku,
			'novedad'      => $novedad,
			'indexing'     => $indexing,
			'suggest'      => $suggest,
			'facets'       => $facets,
			'popular'      => $popular,
			'empty_hint_catalog' => $hint_catalog,
			'empty_hint_filters' => $hint_filters,
			'empty_hint_suggest' => $hint_suggest,
			'relaxed_notice'     => $relaxed_notice,
			'empty_suggestions'   => $empty_suggestions,
			'empty_series_label'  => $series_label,
			'empty_popular_label' => $popular_label,
			'empty_tail_words'    => EmptyStateSuggestions::tail_words_key( $tail_words ),
			'network_notice'      => $network_notice,
		);

		// Los interruptores del motor se escriben siempre: son options de la
		// publicación semántica (`SemanticPublication::options()`), con su propio
		// protocolo, y en producción ya tienen fila.
		$writes = array(
			Fuzzy::OPTION             => $fuzzy,
			'akb_search_sku_fastpath' => $sku,
			Novedad::OPTION           => $novedad,
			Search::OPTION_INDEXING   => $indexing,
			Search::OPTION_SUGGEST    => $suggest,
			Search::OPTION_FACETS     => $facets,
			PopularQueries::OPTION_ENABLED => $popular,
		);

		// Opciones respaldadas por valores predeterminados de fábrica:
		// Si el valor ingresado coincide con el default, no se persiste fila en wp_options
		// para permitir que futuras mejoras del código se adopten sin migraciones de base de datos.
		$factory_backed = array(
			'empty_hint_catalog'  => array( EmptyStateCopy::OPTION_CATALOG, $hint_catalog, EmptyStateCopy::DEFAULT_CATALOG ),
			'empty_hint_filters'  => array( EmptyStateCopy::OPTION_FILTERS, $hint_filters, EmptyStateCopy::DEFAULT_FILTERS ),
			'empty_hint_suggest'  => array( EmptyStateCopy::OPTION_SUGGEST, $hint_suggest, EmptyStateCopy::DEFAULT_SUGGEST ),
			'relaxed_notice'      => array( BrandRelax::OPTION_NOTICE, $relaxed_notice, BrandRelax::DEFAULT_NOTICE ),
			// 'yes': el default de `EmptyStateSuggestions::enabled()`.
			'empty_suggestions'   => array( EmptyStateSuggestions::OPTION_ENABLED, $empty_suggestions, 'yes' ),
			'empty_series_label'  => array( EmptyStateCopy::OPTION_SERIES_LABEL, $series_label, EmptyStateCopy::DEFAULT_SERIES_LABEL ),
			'empty_popular_label' => array( EmptyStateCopy::OPTION_POPULAR_LABEL, $popular_label, EmptyStateCopy::DEFAULT_POPULAR_LABEL ),
			// Normalización de lista para comparar equivalencia contra valores de fábrica:
			'empty_tail_words'    => array( EmptyStateSuggestions::OPTION_TAIL_WORDS, $tail_words, EmptyStateSuggestions::tail_words_key( EmptyStateSuggestions::default_tail_words() ) ),
			'network_notice'      => array( EmptyStateCopy::OPTION_NETWORK, $network_notice, EmptyStateCopy::DEFAULT_NETWORK ),
		);
		$releases = array();
		foreach ( $factory_backed as $key => $write ) {
			if ( $write[2] === $after[ $key ] ) {
				$releases[] = $write[0];
			} elseif ( $before[ $key ] !== $after[ $key ] ) {
				$writes[ $write[0] ] = $write[1];
			}
		}

		if ( ! $this->persist_options_exact( $writes, $releases ) ) {
			$this->redirect( 'ajustes', 'save_failed' );
		}

		$changes = $this->settings_changes( $before, $after );
		if ( ! empty( $changes ) ) {
			AuditLog::record( 'ajustes_guardados', implode( ', ', $changes ) );

			// Purgado selectivo de caché en shutdown: asegura la actualización inmediata
			// de fragmentos HTML cacheados cuando existen modificaciones efectivas.
			// La purga completa o parcial se determina según SETTINGS_TEXT_ONLY.
			$changed_keys = array_keys( array_diff_assoc( $after, $before ) );
			$purge_all    = array() !== array_diff( $changed_keys, self::SETTINGS_TEXT_ONLY );
			add_action(
				'shutdown',
				static function () use ( $purge_all ): void {
					if ( $purge_all ) {
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de LiteSpeed: es SU purga.
						do_action( 'litespeed_purge_all' );
					} else {
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de LiteSpeed: es SU purga.
						do_action( 'litespeed_purge', '*' );
					}
				},
				99
			);
		}

		if ( array() !== $avisos ) {
			set_transient( self::NOTICE_DETAIL_TRANSIENT . get_current_user_id(), implode( ' ', $avisos ), 120 );
			$this->redirect( 'ajustes', 'settings_saved_altered' );
		}
		$this->redirect( 'ajustes', 'synonyms_saved' );
	}

	/**
	 * Texto de Ajustes tal como llegó en el POST, saneado; el vigente si el
	 * formulario no trae el campo.
	 *
	 * Vacío cae al literal de fábrica (`EmptyStateCopy::sanitize()`): así se
	 * vuelve a fábrica desde el panel. Ausente NO: eso es un formulario que no
	 * pintó el campo, no un dueño que lo borró.
	 *
	 * Si lo guardado no es lo que se escribió —recortado al tope o sin sus
	 * etiquetas—, lo anota en `$avisos` con la etiqueta de la fila para que el
	 * aviso informativo de la pantalla lo reporte explícitamente.
	 *
	 * @param string                $name     Slug (el name del input es texto_<slug>).
	 * @param string                $fallback Literal de fábrica.
	 * @param array<string, string> $before   Valores vigentes (`settings_current_values()`).
	 * @param array<int, string>    $avisos   Se le añade una frase por cada texto alterado.
	 * @return string
	 */
	private function posted_settings_text( string $name, string $fallback, array $before, array &$avisos ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_save_settings() ya pasó por guard().
		if ( ! isset( $_POST[ 'texto_' . $name ] ) ) {
			return $before[ $name ];
		}

		$recortado     = false;
		$sin_etiquetas = false;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce en guard(); EmptyStateCopy::sanitize() limpia el texto (quita etiquetas y lo aplana a una línea).
		$value = EmptyStateCopy::sanitize( wp_unslash( $_POST[ 'texto_' . $name ] ), $fallback, $recortado, $sin_etiquetas );

		$label = '«' . ( self::SETTINGS_TEXT_LABELS[ $name ] ?? $name ) . '»';
		if ( $recortado ) {
			$avisos[] = $label . ' quedó recortado a ' . EmptyStateCopy::MAX_LENGTH . ' caracteres.';
		}
		if ( $sin_etiquetas ) {
			$avisos[] = $label . ' se guardó sin las etiquetas HTML (o lo que empieza por «<»): el aviso es texto plano.';
		}

		return $value;
	}

	/**
	 * Diff legible de los toggles de Ajustes para el AuditLog: solo lo que
	 * CAMBIÓ, en formato `clave: antes→después`. Vacío = guardado sin cambios
	 * (no se registra — el log es de cambios, no de clicks) [P2-E].
	 *
	 * @param array<string, string> $before Valores antes del save.
	 * @param array<string, string> $after  Valores guardados.
	 * @return array<int, string>
	 */
	private function settings_changes( array $before, array $after ): array {
		// El registro se lee en pantalla: `sku_fastpath: no→yes` obligaba a
		// traducir slugs mentalmente. Clave desconocida cae al slug crudo.
		$labels = array(
			'fuzzy'        => 'Corrección de errores de tipeo',
			'sku_fastpath' => 'Búsqueda por código (SKU / ISBN)',
			'novedad'      => 'Filtro «Novedades»',
			'indexing'     => 'Indexación automática',
			'suggest'      => 'Autocompletado',
			'popular'      => 'Bloque «Populares» del desplegable',
			'empty_hint_catalog' => 'Pista sin resultados (catálogo)',
			'empty_hint_filters' => 'Pista sin resultados (filtros sobre una búsqueda)',
			'empty_hint_suggest' => 'Pista sin resultados (desplegable)',
			'relaxed_notice'     => 'Aviso «no tenemos esa editorial»',
			'empty_suggestions'   => 'Salidas en «sin resultados»',
			'empty_series_label'  => 'Rótulo de la serie sugerida',
			'empty_popular_label' => 'Rótulo de lo más buscado',
			'empty_tail_words'    => 'Palabras de formato de «Quizá buscabas»',
			'network_notice'      => 'Aviso de conexión del catálogo',
			'facets'       => 'Filtros del catálogo',
		);

		$changes = array();
		foreach ( $after as $key => $value ) {
			if ( ( $before[ $key ] ?? '' ) !== $value ) {
				// El formato de auditoría se determina según el tipo de clave:
				// palabras de formato (resumen sintético), textos personalizados o interruptores booleanos.
				if ( 'empty_tail_words' === $key ) {
					$what = self::tail_words_change( (string) ( $before[ $key ] ?? '' ), $value );
				} elseif ( isset( self::SETTINGS_TEXT_LABELS[ $key ] ) ) {
					$what = sprintf( 'se reescribió («%s»)', $value );
				} else {
					$what = 'yes' === $value ? 'se activó' : 'se apagó';
				}
				$changes[] = sprintf( '%s: %s', $labels[ (string) $key ] ?? (string) $key, $what );
			}
		}
		return $changes;
	}

	/**
	 * Genera un resumen compacto de cambios en palabras de formato para el registro de auditoría.
	 * Describe las diferencias específicas de adición o remoción sin volcar la huella completa.
	 *
	 * @param string $before Huella anterior (`EmptyStateSuggestions::tail_words_key()`).
	 * @param string $after  Huella guardada; distinta de la anterior.
	 * @return string
	 */
	private static function tail_words_change( string $before, string $after ): string {
		if ( EmptyStateSuggestions::tail_words_key( EmptyStateSuggestions::default_tail_words() ) === $after ) {
			return 'volvió a la de fábrica';
		}
		if ( '' === $after ) {
			return 'se vació';
		}

		$antes   = '' === $before ? array() : explode( ', ', $before );
		$despues = explode( ', ', $after );

		return implode(
			'; ',
			self::word_groups(
				array(
					array( array_values( array_diff( $despues, $antes ) ), 'agregaste ', array( 'agregaste ', ' palabras' ) ),
					array( array_values( array_diff( $antes, $despues ) ), 'quitaste ', array( 'quitaste ', ' palabras' ) ),
				)
			)
		);
	}

	/**
	 * Sub-tab "Precios": editor de los rangos de la faceta Precio, derivados del
	 * catálogo (nada hardcodeado) y editables. Los rangos se resuelven query-time
	 * con BETWEEN sobre value_num, así que todo cambio aplica de inmediato SIN
	 * reconstruir el índice. Rangos SEMI-ABIERTOS `[min, max)` contiguos.
	 *
	 * Cada fila muestra el CORTE EFECTIVO y el count real (preview honesto). La
	 * etiqueta SIEMPRE se deriva del rango y se exhibe como texto estático — no
	 * hay input de texto libre, garantizando total consistencia con el corte numérico.
	 * Filas vacías se descartan al guardar (agregar = llenar una fila en blanco;
	 * quitar = vaciarla). Sin jQuery.
	 *
	 * @return void
	 */
	private function render_precios(): void {
		$has     = FacetRegistry::has_precio_buckets();
		$buckets = $has ? FacetRegistry::precio_buckets() : array();
		$counts  = $has ? $this->precio_row_counts( $buckets ) : array();
		$auto    = PriceBuckets::is_auto();

		$max_rows = 8;
		$rows     = $buckets;
		for ( $i = count( $buckets ); $i < $max_rows; $i++ ) {
			$rows[] = array(
				'min'   => null,
				'max'   => null,
				'label' => '',
			);
		}

		$this->render_template(
			'tab-precios.php',
			array(
				'has'      => $has,
				'buckets'  => $buckets,
				'counts'   => $counts,
				'auto'     => $auto,
				'rows'     => $rows,
				'real'     => count( $buckets ),
				'max_rows' => $max_rows,
			)
		);
	}

	/**
	 * Counts por bucket para el preview del editor — la MISMA base que ve el
	 * comprador (espejo de facetas + visible=1 + publish), así el preview no
	 * miente respecto del front.
	 *
	 * @param array<int, array{min: int|null, max: int|null, label: string}> $buckets Buckets vigentes.
	 * @return array<int, int> Índice de bucket → count.
	 */
	private function precio_row_counts( array $buckets ): array {
		global $wpdb;
		if ( empty( $buckets ) || ! isset( $wpdb ) ) {
			return array();
		}
		$facets = Install::facets_table();
		$docs   = Install::docs_table();

		$selects = array();
		$params  = array();
		foreach ( $buckets as $i => $bucket ) {
			$cond = array();
			if ( null !== $bucket['min'] ) {
				$cond[]   = 'fx.value_num >= %f';
				$params[] = (float) $bucket['min'];
			}
			if ( null !== $bucket['max'] ) {
				$cond[]   = 'fx.value_num < %f';
				$params[] = (float) $bucket['max'];
			}
			$test      = empty( $cond ) ? '1=1' : implode( ' AND ', $cond );
			$selects[] = "COUNT(DISTINCT CASE WHEN {$test} THEN fx.post_id END) AS b{$i}";
		}

		$sql = 'SELECT ' . implode( ', ', $selects ) . "
			FROM {$facets} fx
			JOIN {$docs} d ON d.post_id = fx.post_id AND d.visible = 1
			JOIN {$wpdb->posts} p ON p.ID = d.post_id AND p.post_status = 'publish'
			WHERE fx.facet = 'precio'";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- preparado acá.
		$row = $wpdb->get_row( empty( $params ) ? $sql : $wpdb->prepare( $sql, ...$params ), ARRAY_A );

		$out = array();
		foreach ( $buckets as $i => $bucket ) {
			$out[ $i ] = (int) ( $row[ 'b' . $i ] ?? 0 );
		}
		return $out;
	}

	/**
	 * Guarda los rangos de precio (option `akb_search_precio_buckets`). Valida
	 * con `FacetRegistry::validate_precio_buckets()` — si falla NO persiste (la
	 * faceta nunca queda en estado inválido). La etiqueta SIEMPRE se deriva del
	 * rango (el `precio_label` del POST se ignora), así no puede mentir. Si
	 * cambió un mínimo/máximo respecto de lo persistido, el modo pasa a MANUAL
	 * (el reindex ya no lo pisa); «Recalcular» persiste el modo AUTO y re-deriva
	 * del catálogo. Todo camino que persiste pasa por el outbox pre/post de
	 * SemanticPublication (los counts no incluyen los buckets en su clave).
	 *
	 * @return void
	 */
	public function handle_save_precios(): void {
		$this->guard( self::NONCE_PRECIOS );

		// Recalcular desde el catálogo: deriva primero y conmuta el par completo a
		// AUTO dentro de la transacción de PriceBuckets::refresh().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ya validó.
		if ( isset( $_POST['recalc'] ) ) {
			$n = PriceBuckets::refresh();
			if ( false === $n ) {
				$this->redirect( 'precios', 'save_failed' );
			}
			$this->redirect( 'precios', $n > 0 ? 'precios_recalc' : 'precios_recalc_empty' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() ya validó; array saneado elemento a elemento más abajo.
		$mins = isset( $_POST['precio_min'] ) ? (array) wp_unslash( $_POST['precio_min'] ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() ya validó; array saneado elemento a elemento más abajo.
		$maxs = isset( $_POST['precio_max'] ) ? (array) wp_unslash( $_POST['precio_max'] ) : array();
		// Nota: `precio_label` del POST se IGNORA deliberadamente — la etiqueta
		// nunca proviene de entrada libre, siempre se deriva del rango numérico,
		// garantizando paridad matemática. El seam POST→rows se procesa
		// en build_precio_rows() de manera pura y determinista.
		$rows = $this->build_precio_rows( $mins, $maxs );

		// Un valor no-numérico (parse devolvió string sentinela) o cualquier otra
		// violación del contrato → el validador lo rechaza y NO se persiste.
		if ( null !== FacetRegistry::validate_precio_buckets( $rows ) ) {
			$this->redirect( 'precios', 'precios_invalid' );
		}

		// PriceBuckets compara bounds sobre la foto SQL bloqueada y persiste
		// mode+buckets en un único UPSERT transaccional. Ninguna decisión de modo
		// depende de una lectura PHP previa que pueda quedar obsoleta.
		if ( ! PriceBuckets::save_manual( array_values( $rows ) ) ) {
			$this->redirect( 'precios', 'save_failed' );
		}

		$this->redirect( 'precios', 'precios_saved' );
	}

	/**
	 * Convierte un campo de monto a su valor de contrato: '' → null (sin tope);
	 * entero no-negativo → int; cualquier otra cosa (decimal, negativo, texto) →
	 * el string crudo SIN convertir, para que el validador lo rechace (nunca
	 * coercer basura a 0 en silencio).
	 *
	 * Construye las filas de buckets desde los arrays crudos min/max del POST.
	 * Seam POST→rows: la etiqueta SIEMPRE se deriva del rango (no se lee ningún
	 * `precio_label`), asegurando que la etiqueta refleje fielmente el corte numérico.
	 * Filas totalmente vacías se descartan (para permitir eliminar tramos sin JS).
	 * Si un bound es inválido (`parse_price_bound` devuelve el string sentinela),
	 * la etiqueta queda vacía y el validador rechaza el conjunto completo.
	 *
	 * @param array<int, mixed> $mins Valores crudos del campo `precio_min[]`.
	 * @param array<int, mixed> $maxs Valores crudos del campo `precio_max[]`.
	 * @return array<int, array{min: int|string|null, max: int|string|null, label: string}>
	 */
	private function build_precio_rows( array $mins, array $maxs ): array {
		$count = max( count( $mins ), count( $maxs ) );
		$rows  = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$raw_min = isset( $mins[ $i ] ) ? trim( (string) $mins[ $i ] ) : '';
			$raw_max = isset( $maxs[ $i ] ) ? trim( (string) $maxs[ $i ] ) : '';

			if ( '' === $raw_min && '' === $raw_max ) {
				continue;
			}

			$min   = $this->parse_price_bound( $raw_min );
			$max   = $this->parse_price_bound( $raw_max );
			$label = ( ! is_string( $min ) && ! is_string( $max ) )
				? PriceBuckets::derive_label( $min, $max )
				: '';

			$rows[] = array(
				'min'   => $min,
				'max'   => $max,
				'label' => $label,
			);
		}
		return $rows;
	}

	/**
	 * @param string $raw Valor crudo, ya trimmeado.
	 * @return int|string|null
	 */
	private function parse_price_bound( string $raw ) {
		if ( '' === $raw ) {
			return null;
		}
		// Entero no-negativo con techo de sanidad: el máximo real del catálogo
		// ronda los $82.000, así que $10.000.000 es holgado. El límite de 8
		// dígitos garantiza que el cast a int no desborde antes de comparar
		// (sin esto, un string > PHP_INT_MAX clampeaba en silencio a un bucket
		// degenerado con label absurdo). Fuera de rango (decimal, negativo,
		// texto o absurdamente grande) → se devuelve el string crudo para que
		// el validador rechace el set; nunca coercer basura a int en silencio.
		if ( 1 === preg_match( '/^\d+$/', $raw ) && strlen( $raw ) <= 8 && (int) $raw <= self::PRICE_BOUND_MAX ) {
			return (int) $raw;
		}
		return $raw;
	}

	/**
	 * Persiste un grupo y relee cada valor exacto. `update_option()` devuelve
	 * false tanto en no-op como cuando un pre-hook fail-closed conserva el valor
	 * anterior; por eso el booleano solo no puede autorizar un notice de éxito.
	 * Ante un fallo se intenta restaurar todo el grupo en orden inverso.
	 *
	 * `$releases` son options cuya fila se borra para que su lector caiga al
	 * valor de fábrica. Van en el mismo grupo: la misma foto antes, la misma
	 * relectura después y la misma restauración si algo falla —también de lo
	 * escrito antes—. Una fila que ya no existe es un no-op legítimo.
	 *
	 * @param array<string, mixed> $values   Valores esperados por option.
	 * @param array<int, string>   $releases Options que tienen que quedar sin fila.
	 * @return bool
	 */
	private function persist_options_exact( array $values, array $releases = array() ): bool {
		$snapshots = $this->option_snapshots( array_merge( array_keys( $values ), $releases ) );
		if ( null === $snapshots ) {
			return false;
		}
		$attempted = array();
		$states    = array();
		foreach ( $values as $option => $value ) {
			$option            = (string) $option;
			$attempted[]       = $option;
			$expected          = $this->expected_option_state( $option, $value );
			$states[ $option ] = $expected;
			try {
				$updated = update_option( $option, $value, false );
			} catch ( \Throwable $throwable ) {
				$this->restore_option_snapshots( $snapshots, $attempted, $states );
				return false;
			}

			$current = $this->option_snapshot( $option );
			if ( null === $current ) {
				$this->restore_option_snapshots( $snapshots, $attempted, $states );
				return false;
			}
			if ( ! $updated && $this->option_value_equals( $current, $value ) ) {
				// No-op legítimo: este grupo no escribió la fila y por tanto tampoco
				// adquiere derecho a compensarla si una opción posterior falla.
				array_pop( $attempted );
				unset( $states[ $option ] );
			} elseif ( ! $this->option_states_equal( $current, $expected ) ) {
				$this->restore_option_snapshots( $snapshots, $attempted, $states );
				return false;
			}
		}

		$absent = array(
			'present'  => false,
			'value'    => null,
			'raw'      => '',
			'autoload' => '',
		);
		foreach ( $releases as $option ) {
			$option = (string) $option;
			if ( empty( $snapshots[ $option ]['present'] ) ) {
				continue;
			}
			$attempted[]       = $option;
			$states[ $option ] = $absent;
			try {
				delete_option( $option );
			} catch ( \Throwable $throwable ) {
				$this->restore_option_snapshots( $snapshots, $attempted, $states );
				return false;
			}

			// `delete_option()` devuelve false también si otro proceso la borró
			// primero; lo que decide es la relectura, no el booleano.
			$current = $this->option_snapshot( $option );
			if ( null === $current || ! $this->option_states_equal( $current, $absent ) ) {
				$this->restore_option_snapshots( $snapshots, $attempted, $states );
				return false;
			}
		}
		return true;
	}

	/**
	 * Toma fotos exactas de un conjunto de options.
	 *
	 * @param array<int, string> $options Options a fotografiar.
	 * @return array<string, array{present: bool, value: mixed, raw: string, autoload: string}>|null
	 */
	private function option_snapshots( array $options ): ?array {
		$snapshots = array();
		foreach ( $options as $option ) {
			$option   = (string) $option;
			$snapshot = $this->option_snapshot( $option );
			if ( null === $snapshot ) {
				return null;
			}
			$snapshots[ $option ] = $snapshot;
		}
		return $snapshots;
	}

	/**
	 * Lee valor+autoload en una sola foto DB-direct. Un error de metadata nunca
	 * se convierte en el autoload inventado de un rollback.
	 *
	 * @param string $option Option a fotografiar.
	 * @return array{present: bool, value: mixed, raw: string, autoload: string}|null
	 */
	private function option_snapshot( string $option ): ?array {
		global $wpdb;
		if ( isset( $wpdb->options ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'get_row' ) ) {
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- foto raw intencional para CAS; no puede usar object cache.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
					$option
				)
			);
			if ( '' !== (string) ( $wpdb->last_error ?? '' ) ) {
				return null;
			}
			if ( null === $row ) {
				return array(
					'present'  => false,
					'value'    => null,
					'raw'      => '',
					'autoload' => '',
				);
			}
			if ( ! is_object( $row ) && ! is_array( $row ) ) {
				return null;
			}
			$raw      = (string) ( is_object( $row ) ? ( $row->option_value ?? '' ) : ( $row['option_value'] ?? '' ) );
			$autoload = (string) ( is_object( $row ) ? ( $row->autoload ?? '' ) : ( $row['autoload'] ?? '' ) );
			if ( '' === $autoload ) {
				return null;
			}
			$value = function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $raw ) : $raw;
			return array(
				'present'  => true,
				'value'    => $value,
				'raw'      => $raw,
				'autoload' => $autoload,
			);
		}

		// Harness defensivo; WordPress real siempre toma la foto SQL anterior.
		$missing = new \stdClass();
		$value   = get_option( $option, $missing );
		return $missing === $value
			? array(
				'present'  => false,
				'value'    => null,
				'raw'      => '',
				'autoload' => '',
			)
			: array(
				'present'  => true,
				'value'    => $value,
				'raw'      => Support\OptionCodec::encode( $value ),
				'autoload' => self::expected_autoload( $option, $value ),
			);
	}

	/**
	 * Rollback compensatorio best-effort; el caller siempre mostrará error y no
	 * auditará éxito aunque la base deje de aceptar también la compensación.
	 *
	 * @param array<string, array{present: bool, value: mixed, raw: string, autoload: string}>      $snapshots Fotos.
	 * @param array<int, string>|null                                                               $only      Subset intentado.
	 * @param array<string, array{present: bool, value: mixed, raw: string, autoload: string}>|null $attempted Estado escrito por este grupo.
	 * @return bool
	 */
	private function restore_option_snapshots( array $snapshots, ?array $only = null, ?array $attempted = null ): bool {
		$options          = null === $only ? array_keys( $snapshots ) : $only;
		$ok               = true;
		$changed          = false;
		$semantic_changed = false;
		foreach ( array_reverse( $options ) as $option ) {
			if ( ! isset( $snapshots[ $option ] ) || ! isset( $attempted[ $option ] ) ) {
				$ok = false;
				continue;
			}
			$snapshot = $snapshots[ $option ];
			try {
				$restored = $this->restore_option_snapshot_cas( $option, $snapshot, $attempted[ $option ] );
				$changed  = $restored['changed'] || $changed;
				if ( $restored['changed'] && in_array( (string) $option, SemanticPublication::options(), true ) ) {
					$semantic_changed = true;
				}
				$ok = $restored['ok'] && $ok;
			} catch ( \Throwable $throwable ) {
				$ok = false;
			}
		}
		if ( $changed && $semantic_changed ) {
			// El CAS directo evita lost-update pero no dispara hooks de Core. El
			// fingerprint DB-direct vuelve a publicar ahora o conserva deuda para init.
			$ok = ( new SemanticPublication() )->reconcile_fingerprint() && $ok;
		}
		return $ok;
	}

	/**
	 * Compensa sólo si la option todavía contiene exactamente el valor que este
	 * grupo intentó escribir. Otro writer que ya ganó nunca es sobrescrito.
	 *
	 * @param string                                                            $option    Option.
	 * @param array{present: bool, value: mixed, raw: string, autoload: string} $snapshot  Estado original.
	 * @param array{present: bool, value: mixed, raw: string, autoload: string} $attempted Estado propio esperado.
	 * @return array{ok: bool, changed: bool}
	 */
	private function restore_option_snapshot_cas( string $option, array $snapshot, array $attempted ): array {
		$current = $this->option_snapshot( $option );
		if ( null === $current ) {
			return array(
				'ok'      => false,
				'changed' => false,
			);
		}
		if ( $this->option_states_equal( $current, $snapshot ) ) {
			return array(
				'ok'      => true,
				'changed' => false,
			);
		}
		if ( ! $this->option_states_equal( $current, $attempted ) ) {
			return array(
				'ok'      => false,
				'changed' => false,
			);
		}

		global $wpdb;
		if ( ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) ) {
			// Harness sin SQL: conserva la semántica, aunque producción siempre usa CAS.
			if ( $snapshot['present'] ) {
				update_option( $option, $snapshot['value'], false );
			} else {
				delete_option( $option );
			}
			$observed = $this->option_snapshot( $option );
			return array(
				'ok'      => null !== $observed && $this->option_states_equal( $observed, $snapshot ),
				'changed' => true,
			);
		}

		$wpdb->last_error = '';
		if ( $attempted['present'] && $snapshot['present'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CAS raw compensatorio; se limpia cache y relee debajo.
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s, autoload = %s
					 WHERE option_name = %s
					 AND BINARY option_value = BINARY %s
					 AND BINARY autoload = BINARY %s",
					(string) $snapshot['raw'],
					(string) $snapshot['autoload'],
					$option,
					(string) $attempted['raw'],
					(string) $attempted['autoload']
				)
			);
		} elseif ( $attempted['present'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CAS raw compensatorio; se limpia cache y relee debajo.
			$result = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s
					 AND BINARY option_value = BINARY %s
					 AND BINARY autoload = BINARY %s",
					$option,
					(string) $attempted['raw'],
					(string) $attempted['autoload']
				)
			);
		} elseif ( $snapshot['present'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CAS raw compensatorio; se limpia cache y relee debajo.
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
					$option,
					(string) $snapshot['raw'],
					(string) $snapshot['autoload']
				)
			);
		} else {
			return array(
				'ok'      => true,
				'changed' => false,
			);
		}

		$this->clear_option_cache( $option );
		$observed = $this->option_snapshot( $option );
		$ok       = false !== $result
			&& '' === (string) ( $wpdb->last_error ?? '' )
			&& null !== $observed
			&& $this->option_states_equal( $observed, $snapshot );
		return array(
			'ok'      => $ok,
			'changed' => $ok && 0 < (int) $result,
		);
	}

	/**
	 * Construye el estado raw que debe dejar un write real de Core.
	 *
	 * @param string $option Option objetivo.
	 * @param mixed  $value  Valor objetivo.
	 * @return array{present: bool, value: mixed, raw: string, autoload: string}
	 */
	private function expected_option_state( string $option, $value ): array {
		return array(
			'present'  => true,
			'value'    => $value,
			'raw'      => Support\OptionCodec::encode( $value ),
			'autoload' => self::expected_autoload( $option, $value ),
		);
	}

	/**
	 * Compara presencia, bytes y autoload de dos estados.
	 *
	 * @param array{present: bool, value: mixed, raw: string, autoload: string} $a Estado.
	 * @param array{present: bool, value: mixed, raw: string, autoload: string} $b Estado.
	 * @return bool
	 */
	private function option_states_equal( array $a, array $b ): bool {
		return (bool) $a['present'] === (bool) $b['present']
			&& ( ! $a['present']
				|| ( (string) $a['raw'] === (string) $b['raw']
					&& (string) $a['autoload'] === (string) $b['autoload'] ) );
	}

	/**
	 * Compara sólo el valor raw para reconocer un no-op legítimo de Core.
	 *
	 * @param array{present: bool, value: mixed, raw: string, autoload: string} $state    Estado.
	 * @param mixed                                                             $expected Valor esperado.
	 * @return bool
	 */
	private function option_value_equals( array $state, $expected ): bool {
		return ! empty( $state['present'] ) && Support\OptionCodec::encode( $expected ) === (string) $state['raw'];
	}

	/**
	 * Obtiene el valor de autoload que Core escribirá con `$autoload=false`.
	 *
	 * @param string $option Option objetivo.
	 * @param mixed  $value  Valor objetivo.
	 * @return string
	 */
	private static function expected_autoload( string $option, $value ): string {
		$serialized = Support\OptionCodec::encode( $value );
		if ( function_exists( 'wp_determine_option_autoload_value' ) ) {
			try {
				$autoload = wp_determine_option_autoload_value( $option, $value, $serialized, false );
				if ( is_string( $autoload ) && '' !== $autoload ) {
					return $autoload;
				}
			} catch ( \Throwable $throwable ) {
				// Harness sin Core completo; compatibilidad pre-6.6 debajo.
				unset( $throwable );
			}
		}
		return 'no';
	}

	/**
	 * Invalida todas las variantes del object cache de una option.
	 *
	 * @param string $option Option.
	 * @return void
	 */
	private function clear_option_cache( string $option ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $option, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}

	/**
	 * @param string $sub    Sub-tab destino.
	 * @param string $notice Código de aviso.
	 * @return void
	 */
	private function redirect( string $sub, string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::PAGE_SLUG,
					'sub'         => $sub,
					'akb_search'  => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * @return void
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- no hay formulario que verificar: el parámetro sólo elige cuál de los avisos fijos del mapa se pinta tras una redirección propia. Pasa por sanitize_key() y se usa únicamente como clave de ese mapa.
		$code = isset( $_GET['akb_search'] ) ? sanitize_key( (string) $_GET['akb_search'] ) : '';
		$map  = array(
			'rebuild_queued'  => array( 'updated', 'Reconstrucción del índice encolada. Corre en segundo plano.' ),
			'rebuild_running' => array( 'notice-warning', 'Ya hay una reconstrucción en curso — no se encoló otra. Si queda detenida sin avanzar, el botón de Mantenimiento cambia a «Reintentar».' ),
			// Antes este caso caía en 'rebuild_running', cuyo texto prometía un
			// botón «Reintentar» que en fase terminal no aparece nunca.
			'rebuild_blocked' => array( 'error', 'La reconstrucción anterior quedó bloqueada y este botón no puede reanudarla. La tienda sigue sirviendo el índice anterior; revisa el bloque Mantenimiento y «Diagnóstico técnico» en la pestaña Índice.' ),
			'reconcile_queued'        => array( 'updated', 'Verificación de consistencia encolada. Corre en segundo plano; el resultado queda en «Estado».' ),
			'reconcile_unavailable'   => array( 'error', 'No se pudo encolar la verificación: Action Scheduler no está disponible.' ),
			'facets_saved'    => array( 'updated', 'Facetas guardadas.' ),
			'facets_saved_reindex'    => array( 'notice-warning', 'Facetas guardadas. El cambio altera el espejo del índice: reconstrúyelo desde la pestaña Índice para que se refleje.' ),
			'facets_invalid_parent'   => array( 'error', 'El contexto indicado no existe en la taxonomía de la faceta (nombre o ID). Se conservó el valor anterior; el resto de los cambios sí se guardó.' ),
			'facets_ambiguous_parent' => array( 'error', 'El contexto indicado coincide con más de un término de la taxonomía. Usa el ID numérico para elegir el correcto; se conservó el valor anterior.' ),
			'facet_new_incomplete'    => array( 'error', 'Para agregar una faceta se necesitan al menos nombre y taxonomía. El resto de los cambios sí se guardó.' ),
			'facet_new_exists'        => array( 'error', 'Ya existe una faceta con ese nombre. El resto de los cambios sí se guardó.' ),
			'facet_new_bad_taxonomy'  => array( 'error', 'La taxonomía elegida para la faceta nueva no existe. El resto de los cambios sí se guardó.' ),
			'facet_new_bad_name'      => array( 'error', sprintf( 'El nombre del filtro no puede pasar de %d caracteres: sólo minúsculas, números, guiones y guiones bajos. No se agregó. El resto de los cambios sí se guardó.', FacetRegistry::MAX_NAME_LENGTH ) ),
			'facet_new_limit'         => array( 'error', sprintf( 'No se pueden tener más de %d filtros. No se agregó. El resto de los cambios sí se guardó.', FacetRegistry::MAX_FACETS ) ),
			// Guardado genérico (Sinónimos, Palabras ignoradas, Ajustes redirigen con
			// este código). Sin esta entrada el guardado era MUDO en los tres tabs.
			'synonyms_saved'          => array( 'updated', 'Cambios guardados.' ),
			'synonyms_saved_limited'  => array( 'notice-warning', 'Sinónimos guardados, pero se descartaron términos que no cumplen los límites (por ejemplo, más de 4 palabras contando «de», «la», «los»). Un grupo que quedó con un solo término se descartó entero: revísalos antes de continuar.' ),
			'stopwords_saved_limited' => array( 'notice-warning', 'Palabras ignoradas guardadas, pero se descartaron las de más de 64 caracteres.' ),
			// Qué texto no quedó como se escribió viaja en el detalle.
			'settings_saved_altered'   => array( 'notice-warning', 'Ajustes guardados, pero no todo quedó como lo escribiste.' ),
			'save_failed'              => array( 'error', 'No se pudo verificar el guardado. Se intentó conservar o restaurar el estado anterior; revisa el estado del buscador antes de repetir.' ),
			// Editor de rangos de precio (bloque 2).
			'precios_saved'           => array( 'updated', 'Rangos de precio guardados. La faceta Precio se actualizó al instante, sin reconstruir el índice.' ),
			'precios_recalc'          => array( 'updated', 'Rangos recalculados desde los precios reales del catálogo (modo automático: se actualizarán solos en cada reconstrucción del índice).' ),
			'precios_recalc_empty'    => array( 'notice-warning', 'No hay suficientes precios distintos en el índice para derivar rangos — la faceta Precio queda oculta en la tienda hasta que el catálogo lo permita.' ),
			'precios_invalid'         => array( 'error', 'Los rangos no son válidos: deben ser contiguos (el fin de cada rango es el inicio del siguiente, sin huecos ni solapes) y con montos en pesos enteros, sin puntos de miles. Solo el primer rango puede no tener mínimo y solo el último puede no tener máximo. No se guardó nada.' ),
		);
		if ( ! isset( $map[ $code ] ) ) {
			return;
		}

		// El detalle (diff de facetas, textos alterados en Ajustes) viaja por
		// transient: no cabe en la query string del redirect.
		$detail_prefix = array(
			'facets_saved'           => 'Cambios: ',
			'facets_saved_reindex'   => 'Cambios: ',
			'settings_saved_altered' => '',
		);
		$detail        = '';
		if ( isset( $detail_prefix[ $code ] ) ) {
			$key    = self::NOTICE_DETAIL_TRANSIENT . get_current_user_id();
			$detail = (string) get_transient( $key );
			delete_transient( $key );
		}

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p>%s</div>',
			esc_attr( $map[ $code ][0] ),
			esc_html( $map[ $code ][1] ),
			'' !== $detail ? '<p class="description">' . esc_html( $detail_prefix[ $code ] . $detail ) . '</p>' : ''
		);
	}

	/**
	 * Imprime un <datalist> por taxonomía con los términos padre candidatos
	 * (términos CON hijos: un término hoja no puede ser contexto). Cap 100
	 * por lista [gate DISEÑO §5, riesgo datalist gigante].
	 *
	 * @param array<int, string> $taxes Slugs de taxonomías.
	 * @return array<int, string> Taxonomías cuya lista quedó recortada.
	 */
	private function render_parent_datalists( array $taxes ): array {
		$capped = array();
		foreach ( $taxes as $tax ) {
			$candidates = $this->parent_candidates( $tax );
			if ( count( $candidates ) > 100 ) {
				$candidates = array_slice( $candidates, 0, 100 );
				$capped[]   = $tax;
			}
			$tax_obj   = get_taxonomy( $tax );
			$tax_label = $tax_obj instanceof \WP_Taxonomy ? (string) $tax_obj->label : $tax;
			printf( '<datalist id="akb-parents-%s">', esc_attr( $tax ) );
			foreach ( $candidates as $term ) {
				printf(
					'<option value="%s" label="%s"></option>',
					esc_attr( $term->name ),
					esc_attr( $term->name . ' — ' . $tax_label )
				);
			}
			echo '</datalist>';
		}
		return $capped;
	}

	/**
	 * Términos con hijos de una taxonomía, ordenados por nombre.
	 *
	 * @param string $taxonomy Slug de la taxonomía.
	 * @return array<int, \WP_Term>
	 */
	private function parent_candidates( string $taxonomy ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Consulta directa para identificar términos con hijos en la taxonomía indicada sobre $wpdb->term_taxonomy.
		$parent_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT parent FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND parent > 0",
				$taxonomy
			)
		);
		if ( empty( $parent_ids ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'include'    => array_map( 'intval', $parent_ids ),
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * Resuelve la entrada de contexto (picker) a un term_id. Acepta ID
	 * numérico (backcompat) o nombre exacto contra la taxonomía de ESA
	 * faceta; el datalist puede traer «Nombre — Taxonomía», así que también
	 * se intenta sin el sufijo. Nombre ambiguo (duplicado en la taxonomía) o
	 * inexistente = error — NUNCA first-match silencioso [gate DISEÑO §5].
	 *
	 * @param string $raw      Valor crudo del input.
	 * @param string $taxonomy Taxonomía de la faceta.
	 * @return array{id: int, error: string} error '' = ok (id 0 = sin contexto).
	 */
	public static function resolve_parent_term( string $raw, string $taxonomy ): array {
		$raw = trim( $raw );
		if ( '' === $raw || '0' === $raw ) {
			return array(
				'id'    => 0,
				'error' => '',
			);
		}

		if ( ctype_digit( $raw ) ) {
			$term = get_term( (int) $raw, $taxonomy );
			return $term instanceof \WP_Term
				? array(
					'id'    => (int) $raw,
					'error' => '',
				)
				: array(
					'id'    => 0,
					'error' => 'facets_invalid_parent',
				);
		}

		$variants = array( $raw );
		$dash     = strrpos( $raw, ' — ' );
		if ( false !== $dash ) {
			$variants[] = trim( substr( $raw, 0, $dash ) );
		}

		foreach ( $variants as $name ) {
			$found = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'name'       => $name,
					'hide_empty' => false,
				)
			);
			if ( ! is_array( $found ) || empty( $found ) ) {
				continue;
			}
			if ( count( $found ) > 1 ) {
				return array(
					'id'    => 0,
					'error' => 'facets_ambiguous_parent',
				);
			}
			return array(
				'id'    => (int) $found[0]->term_id,
				'error' => '',
			);
		}

		return array(
			'id'    => 0,
			'error' => 'facets_invalid_parent',
		);
	}

	/**
	 * Resumen legible de un cambio de facetas (para AuditLog y el notice).
	 * PURA (arrays → string): pineada con tests unit.
	 *
	 * @param array<string, array<string, mixed>> $before Definiciones previas.
	 * @param array<string, array<string, mixed>> $after  Definiciones nuevas.
	 * @return string '' si no cambió nada relevante.
	 */
	public static function facets_diff_summary( array $before, array $after ): string {
		$parts = array();

		foreach ( array_diff_key( $after, $before ) as $name => $def ) {
			$parts[] = sprintf( 'alta %s (%s)', $name, (string) ( $def['taxonomy'] ?? $def['type'] ?? '' ) );
		}
		foreach ( array_keys( array_diff_key( $before, $after ) ) as $name ) {
			$parts[] = 'baja ' . $name;
		}

		foreach ( array_intersect_key( $after, $before ) as $name => $def ) {
			$prev = $before[ $name ];

			$label_before = (string) ( $prev['label'] ?? $name );
			$label_after  = (string) ( $def['label'] ?? $name );
			if ( $label_before !== $label_after ) {
				$parts[] = sprintf( '%s: etiqueta «%s»→«%s»', $name, $label_before, $label_after );
			}

			$vis_before = ! isset( $prev['visible'] ) || $prev['visible'];
			$vis_after  = ! isset( $def['visible'] ) || $def['visible'];
			if ( $vis_before !== $vis_after ) {
				$parts[] = $name . ( $vis_after ? ': ahora visible' : ': ahora oculta' );
			}

			$ui_before = (string) ( $prev['ui'] ?? 'checkboxes' );
			$ui_after  = (string) ( $def['ui'] ?? 'checkboxes' );
			if ( $ui_before !== $ui_after ) {
				$parts[] = sprintf(
					'%s: control %s→%s',
					$name,
					self::facet_ui_label( $ui_before ),
					self::facet_ui_label( $ui_after )
				);
			}

			$parent_before = (int) ( $prev['parent_term'] ?? 0 );
			$parent_after  = (int) ( $def['parent_term'] ?? 0 );
			if ( $parent_before !== $parent_after ) {
				$parts[] = sprintf(
					'%s: contexto %s→%s',
					$name,
					$parent_before > 0 ? (string) $parent_before : '—',
					$parent_after > 0 ? (string) $parent_after : '—'
				);
			}
		}

		// Reorden puro: mismas claves comunes, secuencia distinta.
		$common_before = array_values( array_intersect( array_keys( $before ), array_keys( $after ) ) );
		$common_after  = array_values( array_intersect( array_keys( $after ), array_keys( $before ) ) );
		if ( $common_before !== $common_after ) {
			$parts[] = 'orden actualizado';
		}

		$summary = implode( ' · ', $parts );
		if ( function_exists( 'mb_substr' ) && mb_strlen( $summary ) > 280 ) {
			return mb_substr( $summary, 0, 277 ) . '…';
		}
		return $summary;
	}

	/**
	 * Taxonomías aptas para registrar como faceta nueva: públicas y con UI de WordPress.
	 * Aplica la misma regla uniforme al renderizar el selector y al validar la petición POST (`public && show_ui`),
	 * asegurando que sólo taxonomías legítimas de catálogo sean configurables.
	 *
	 * @return array<string, \WP_Taxonomy>
	 */
	private static function eligible_taxonomies(): array {
		$eligible = array();
		foreach ( get_object_taxonomies( 'product', 'objects' ) as $tax ) {
			if ( $tax->public && $tax->show_ui ) {
				$eligible[ $tax->name ] = $tax;
			}
		}
		return $eligible;
	}

	/**
	 * Fecha exacta combinada con antigüedad relativa para claridad en métricas administrativas.
	 *
	 * @param int $ts Timestamp Unix.
	 * @return string
	 */
	private function stamp( int $ts ): string {
		if ( $ts <= 0 ) {
			return 'nunca';
		}
		return wp_date( 'd-m-Y H:i', $ts ) . ' (hace ' . human_time_diff( $ts, time() ) . ')';
	}

	/**
	 * @param int $start Inicio (Unix).
	 * @param int $end   Fin (Unix).
	 * @return string
	 */
	private function duration( int $start, int $end ): string {
		$s = max( 0, $end - $start );
		if ( $s < 60 ) {
			return $s . ' s';
		}
		return floor( $s / 60 ) . ' min ' . ( $s % 60 ) . ' s';
	}
}
