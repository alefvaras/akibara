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

use Akibara\Search\Admin\DemandGapsAdminView;
use Akibara\Search\Admin\DictionaryAdminView;
use Akibara\Search\Admin\IndexAdminView;
use Akibara\Search\Admin\FacetsAdminView;
use Akibara\Search\Admin\OptionStorage;
use Akibara\Search\Admin\PriceBucketsAdminView;
use Akibara\Search\Admin\SettingsAdminView;

/**
 * @since 1.55.0
 */
final class SearchAdminSection {

	/** Slug de la página de admin PROPIA del plugin (menú standalone, no el panel de business). */
	public const PAGE_SLUG = 'akibara-search';

	public const ACTION_REBUILD   = 'akb_search_rebuild';
	public const ACTION_FACETS    = 'akb_search_save_facets';
	public const ACTION_RECONCILE = 'akb_search_reconcile_run';
	public const NONCE_REBUILD    = 'akb_search_rebuild_nonce';
	public const NONCE_FACETS     = 'akb_search_facets_nonce';
	public const NONCE_RECONCILE  = 'akb_search_reconcile_nonce';
	public const ACTION_SYNONYMS  = 'akb_search_save_synonyms';
	public const NONCE_SYNONYMS   = 'akb_search_synonyms_nonce';
	public const ACTION_STOPWORDS = 'akb_search_save_stopwords';
	public const NONCE_STOPWORDS  = 'akb_search_stopwords_nonce';
	public const ACTION_SETTINGS  = 'akb_search_save_settings';
	public const NONCE_SETTINGS   = 'akb_search_settings_nonce';
	public const ACTION_PRECIOS   = 'akb_search_save_precios';
	/**
	 * Acciones del descarte de huecos del radar. PÚBLICAS a propósito: el
	 * Centro de Mando de akibara-business pinta el mismo control desde OTRO
	 * riel de despliegue, y con la constante privada tenía que duplicar el
	 * literal —acción Y nonce— a mano. Consumir `gap_action_url()` es mejor
	 * todavía: el nonce sale de acá y no puede desincronizarse del guard.
	 */
	public const ACTION_GAP_DISMISS = 'akb_search_gap_dismiss';
	public const ACTION_GAP_RESTORE = 'akb_search_gap_restore';
	public const NONCE_PRECIOS    = 'akb_search_precios_nonce';

	/** Identificadores de rescate guiado para la guardia operativa. */
	public const RESCUE_ORPHAN_TOPOLOGY = 'orphan_topology_without_state';
	public const RESCUE_RUNBOOK_CMD     = 'bash ops/rescate-buscador.sh produccion';

	/** Techo de sanidad para un corte de precio (el máximo real del catálogo ronda $82.000). */
	public const PRICE_BOUND_MAX = 10000000;

	/** Transient por-usuario con el detalle del último notice (diffs largos no viajan por URL). */
	private const NOTICE_DETAIL_TRANSIENT = 'akb_search_notice_detail_';

	/** Palabras que una diferencia de listas enumera; más allá, las cuenta (`word_groups()`). */
	public const WORDS_LISTED_MAX = 20;

	/**
	 * Etiqueta de cada texto de Ajustes, tal como la pinta la pantalla. Una sola
	 * fuente para la fila y para el aviso que dice qué texto no quedó como se
	 * escribió: si el aviso nombrara otra cosa, el dueño no sabría cuál mirar.
	 */
	public const SETTINGS_TEXT_LABELS = array(
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
	public const SETTINGS_TEXT_ONLY = array(
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
	 * Query arg que precarga una línea nueva en el textarea de Sinónimos.
	 */
	public const PARAM_PREFILL = 'akb_prefill';

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
	public function render_template( string $template_name, array $view_data = array() ): void {
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
	/**
	 * Banda de veredicto: primero la conclusión, después los números.
	 *
	 * @param array<string, mixed> $data    Insumos de HealthReport::collect().
	 * @param array<string, mixed> $verdict Salida de HealthReport::verdict().
	 * @return void
	 */
	private function render_health_band( array $data, array $verdict ): void {
		IndexAdminView::render_health_band( $data, $verdict );
	}

	/**
	 * CTA contextual de una razón.
	 *
	 * @param string $cta Código de CTA del veredicto.
	 * @return string
	 */
	private function reason_cta( string $cta ): string {
		return IndexAdminView::reason_cta( $cta );
	}

	/**
	 * Muestra el estado individual de cada servicio del motor de búsqueda.
	 *
	 * @param array<string, mixed> $flags     Flags de HealthReport::collect().
	 * @param array<string, mixed> $editorial Estado de la publicación editorial.
	 * @param array<string, mixed> $semantic  Autoridad y publicación de la foto semántica.
	 * @return void
	 */
	private function render_services( array $flags, array $editorial = array(), array $semantic = array() ): void {
		IndexAdminView::render_services( $flags, $editorial, $semantic );
	}

	/**
	 * Razón por la que un servicio configurado como 'yes' no está efectivo.
	 *
	 * @param string               $key       Clave de servicio.
	 * @param array<string, mixed> $flag      Estado del flag.
	 * @param array<string, mixed> $editorial Estado editorial.
	 * @param array<string, mixed> $semantic  Estado semántico.
	 * @return string
	 */
	private function service_gate_reason( string $key, array $flag, array $editorial, array $semantic ): string {
		return IndexAdminView::service_gate_reason( $key, $flag, $editorial, $semantic );
	}

	/**
	 * Explica por qué la publicación editorial está bloqueada.
	 *
	 * @param array<string, mixed> $editorial Salida de EditorialDiscountPublication::collect_status().
	 * @return string
	 */
	private function editorial_gate_reason( array $editorial ): string {
		return IndexAdminView::editorial_gate_reason( $editorial );
	}

	/**
	 * Tabla de estado del índice.
	 *
	 * @param array<string, mixed> $data Datos de HealthReport::collect().
	 * @return void
	 */
	private function render_state_table( array $data ): void {
		IndexAdminView::render_state_table( $data );
	}

	/**
	 * Fila con la próxima reconstrucción encolada si existe.
	 *
	 * @return void
	 */
	private function render_pending_rebuild_row(): void {
		IndexAdminView::render_pending_rebuild_row();
	}

	/**
	 * Etiqueta legible de la razón de una reconstrucción.
	 *
	 * @param string $reason Código técnico de la razón.
	 * @return string
	 */
	private function rebuild_reason_label( string $reason ): string {
		return IndexAdminView::rebuild_reason_label( $reason );
	}

	/**
	 * Sección de mantenimiento.
	 *
	 * @param array<string, mixed> $data    Datos de HealthReport.
	 * @param array<string, mixed> $verdict Veredicto de salud.
	 * @return void
	 */
	private function render_maintenance( array $data, array $verdict ): void {
		IndexAdminView::render_maintenance( $data, $verdict );
	}

	/**
	 * Evalúa si el estado de rebuild requiere intervención manual.
	 *
	 * @param array<string, mixed>|null $rebuild Estado del rebuild.
	 * @return bool
	 */
	private static function rebuild_state_is_blocked( ?array $rebuild ): bool {
		return IndexAdminView::rebuild_state_is_blocked( $rebuild );
	}

	/**
	 * Diagnósticos técnicos colapsables.
	 *
	 * @param array<string, mixed> $data Datos de HealthReport.
	 * @return void
	 */
	private function render_diagnostics( array $data ): void {
		IndexAdminView::render_diagnostics( $data );
	}

	/**
	 * Formatea el resultado de una corrida histórica.
	 *
	 * @param array<string, mixed> $entry Entrada del historial.
	 * @return string
	 */
	private function history_result_html( array $entry ): string {
		return IndexAdminView::history_result_html( $entry );
	}

	/**
	 * Etiqueta legible de un código de error de rebuild.
	 *
	 * @param string $code Código técnico de error.
	 * @return string
	 */
	private function error_label( string $code ): string {
		return IndexAdminView::error_label( $code );
	}

	/**
	 * Detalle de una fila del registro, con su contador si se repitió.
	 *
	 * @param array<string,mixed> $entry Fila de AuditLog::entries().
	 * @return string Texto plano.
	 */
	public static function audit_detail( array $entry ): string {
		return IndexAdminView::audit_detail( $entry );
	}

	/**
	 * Etiqueta legible para la acción de auditoría.
	 *
	 * @param string $code Código de acción del AuditLog.
	 * @return string
	 */
	private function audit_action_label( string $code ): string {
		return IndexAdminView::audit_action_label( $code );
	}

	/**
	 * Formatea una marca de tiempo en fecha legible.
	 *
	 * @param int $ts Marca de tiempo Unix.
	 * @return string
	 */
	private function stamp( int $ts ): string {
		return IndexAdminView::stamp( $ts );
	}

	/**
	 * Formatea la duración entre dos marcas de tiempo.
	 *
	 * @param int $start Inicio (Unix).
	 * @param int $end   Fin (Unix).
	 * @return string
	 */
	private function duration( int $start, int $end ): string {
		return IndexAdminView::duration( $start, $end );
	}

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
	/**
	 * Evalúa si las búsquedas sin resultados devuelven coincidencias mediante corrección tipográfica.
	 *
	 * @param array<int, string> $queries Búsquedas normalizadas.
	 * @return array<string, array{hits: int, fix: string}>
	 */
	public static function zero_result_rescues( array $queries ): array {
		return DemandGapsAdminView::zero_result_rescues( $queries );
	}

	/**
	 * Veredicto por fila de «sin resultados».
	 *
	 * @param array{hits: int, fix: string}|null $rescue Medición del motor.
	 * @return string
	 */
	private function zero_result_verdict_html( ?array $rescue ): string {
		return DemandGapsAdminView::zero_result_verdict_html( $rescue );
	}

	/**
	 * Ranking «Los más buscados que no tienes».
	 *
	 * @param array<int, object> $reales Huecos reales.
	 * @return void
	 */
	private function render_missing_titles( array $reales ): void {
		DemandGapsAdminView::render_missing_titles( $reales );
	}

	/**
	 * URL nonceada de una acción del radar.
	 *
	 * @param string $action ACTION_GAP_DISMISS o ACTION_GAP_RESTORE.
	 * @param string $query  Búsqueda normalizada.
	 * @return string
	 */
	public static function gap_action_url( string $action, string $query ): string {
		return DemandGapsAdminView::gap_action_url( $action, $query );
	}

	/**
	 * Enlace «Descartar» de una búsqueda del radar.
	 *
	 * @param string $query Búsqueda normalizada.
	 * @return string
	 */
	private function gap_dismiss_link_html( string $query ): string {
		return DemandGapsAdminView::gap_dismiss_link_html( $query );
	}

	/**
	 * Tabla plegada de búsquedas descartadas.
	 *
	 * @param array<string, string> $descartadas Búsquedas descartadas.
	 * @return void
	 */
	private function render_dismissed_gaps( array $descartadas ): void {
		DemandGapsAdminView::render_dismissed_gaps( $descartadas );
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
	 * Restaura una búsqueda al radar y vuelve a la pantalla de origen.
	 *
	 * @return void
	 */
	public function handle_gap_restore(): void {
		$this->guard( self::ACTION_GAP_RESTORE );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended -- SÍ se sanea: sanitize_text_field() envuelve la expresión.
		$query = isset( $_GET['query'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['query'] ) ) ) : '';
		if ( '' !== $query ) {
			GapDismissals::restore( $query );
		}

		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=akibara-search&sub=busquedas' ) );
		exit;
	}

	/**
	 * Enlace «Crear ficha» hacia post-new.php con el título precargado.
	 *
	 * @param string $query Búsqueda normalizada.
	 * @return string
	 */
	private function create_product_link_html( string $query ): string {
		return DemandGapsAdminView::create_product_link_html( $query );
	}

	/**
	 * Acciones rápidas para una búsqueda sin resultados.
	 *
	 * @param string $query Búsqueda normalizada.
	 * @return string
	 */
	private function zero_result_actions_html( string $query ): string {
		return DemandGapsAdminView::zero_result_actions_html( $query );
	}

	/**
	 * Formatea el día del query log en formato legible.
	 *
	 * @param string $day Fecha ISO YYYY-MM-DD.
	 * @return string
	 */
	private function qlog_day_label( string $day ): string {
		return DemandGapsAdminView::qlog_day_label( $day );
	}

	/**
	 * Pestaña Facetas: configuración de filtros del catálogo.
	 *
	 * @return void
	 */
	private function render_facets(): void {
		FacetsAdminView::render_facets( $this );
	}

	/**
	 * Opciones de UI disponibles para una faceta según su tipo.
	 *
	 * @param array<string, mixed> $def Definición de faceta.
	 * @return array<string, string>
	 */
	public static function facet_ui_choices( array $def ): array {
		return FacetsAdminView::facet_ui_choices( $def );
	}

	/**
	 * Sanea el control de UI elegido contra las opciones permitidas.
	 *
	 * @param string               $ui  Control elegido.
	 * @param array<string, mixed> $def Definición.
	 * @return string
	 */
	private static function sanitize_facet_ui( string $ui, array $def ): string {
		return FacetsAdminView::sanitize_facet_ui( $ui, $def );
	}

	/**
	 * Etiqueta legible de un control de UI de faceta.
	 *
	 * @param string $ui Control de UI.
	 * @return string
	 */
	private static function facet_ui_label( string $ui ): string {
		return FacetsAdminView::facet_ui_label( $ui );
	}

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
		DictionaryAdminView::render_synonyms( $this );
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
		DictionaryAdminView::render_stopwords( $this );
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
	/**
	 * Pestaña Ajustes: palancas del motor.
	 *
	 * @return void
	 */
	private function render_settings(): void {
		SettingsAdminView::render_settings( $this );
	}

	/**
	 * Fila de texto de Ajustes.
	 *
	 * @param string $name  Slug del ajuste.
	 * @param string $label Etiqueta.
	 * @param string $desc  Descripción.
	 * @param string $value Valor actual.
	 * @return void
	 */
	public function settings_text( string $name, string $label, string $desc, string $value ): void {
		SettingsAdminView::settings_text( $name, $label, $desc, $value );
	}

	/**
	 * Fila de lista de palabras.
	 *
	 * @param string             $name    Slug del ajuste.
	 * @param string             $label   Etiqueta.
	 * @param string             $desc    Descripción.
	 * @param array<int, string> $words   Palabras vigentes.
	 * @param array<int, string> $factory Palabras de fábrica.
	 * @return void
	 */
	public function settings_words( string $name, string $label, string $desc, array $words, array $factory ): void {
		SettingsAdminView::settings_words( $name, $label, $desc, $words, $factory );
	}

	/**
	 * Enlace de reseteo para listas de palabras.
	 *
	 * @param string             $id      ID del control.
	 * @param string             $name    Nombre del ajuste.
	 * @param array<int, string> $words   Palabras vigentes.
	 * @param array<int, string> $factory Palabras de fábrica.
	 * @return string
	 */
	public function settings_words_reset( string $id, string $name, array $words, array $factory ): string {
		return SettingsAdminView::settings_words_reset( $id, $name, $words, $factory );
	}

	/**
	 * Formatea grupos de palabras.
	 *
	 * @param array<int, mixed> $grupos Grupos de palabras.
	 * @return array<int, string>
	 */
	private static function word_groups( array $grupos ): array {
		return SettingsAdminView::word_groups( $grupos );
	}

	/**
	 * Valores vigentes de todos los campos de Ajustes.
	 *
	 * @return array<string, string>
	 */
	private function settings_current_values(): array {
		return SettingsAdminView::settings_current_values();
	}

	/**
	 * Fila de toggle (checkbox) para la tabla de Ajustes.
	 *
	 * @param string $name  Slug.
	 * @param string $label Etiqueta.
	 * @param string $desc  Descripción.
	 * @param bool   $on    Estado actual.
	 * @return void
	 */
	public function settings_toggle( string $name, string $label, string $desc, bool $on ): void {
		SettingsAdminView::settings_toggle( $name, $label, $desc, $on );
	}

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
	/**
	 * Texto de Ajustes tal como llegó en el POST.
	 *
	 * @param string                $name     Slug.
	 * @param string                $fallback Literal de fábrica.
	 * @param array<string, string> $before   Valores vigentes.
	 * @param array<int, string>    $avisos   Avisos.
	 * @return string
	 */
	private function posted_settings_text( string $name, string $fallback, array $before, array &$avisos ): string {
		return SettingsAdminView::posted_settings_text( $name, $fallback, $before, $avisos );
	}

	/**
	 * Diff legible de los toggles de Ajustes para el AuditLog.
	 *
	 * @param array<string, string> $before Valores antes del save.
	 * @param array<string, string> $after  Valores guardados.
	 * @return array<int, string>
	 */
	private function settings_changes( array $before, array $after ): array {
		return SettingsAdminView::settings_changes( $before, $after );
	}

	/**
	 * Resumen compacto de cambios en palabras de formato.
	 *
	 * @param string $before Huella anterior.
	 * @param string $after  Huella guardada.
	 * @return string
	 */
	private static function tail_words_change( string $before, string $after ): string {
		return SettingsAdminView::tail_words_change( $before, $after );
	}

	/**
	 * Pestaña Precios: editor de rangos de la faceta Precio.
	 *
	 * @return void
	 */
	private function render_precios(): void {
		PriceBucketsAdminView::render_precios( $this );
	}

	/**
	 * Conteos reales por tramo desde la base de datos.
	 *
	 * @param array<int, array{min: ?int, max: ?int}> $buckets Tramos de precio.
	 * @return array<int, int>
	 */
	private function precio_row_counts( array $buckets ): array {
		return PriceBucketsAdminView::precio_row_counts( $buckets );
	}

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
	/**
	 * Construye y valida los tramos de precio enviados en el formulario.
	 *
	 * @param array<int, mixed> $mins Mínimos enviados.
	 * @param array<int, mixed> $maxs Máximos enviados.
	 * @return array{ok: bool, rows?: array<int, array{min: ?int, max: ?int}>, error?: string}
	 */
	private function build_precio_rows( array $mins, array $maxs ): array {
		return PriceBucketsAdminView::build_precio_rows( $mins, $maxs );
	}

	/**
	 * Parsea una cota de precio asegurando límites numéricos y formato entero.
	 *
	 * @param string $raw Cadena sin sanear del POST.
	 * @return int|null|false null = abierto · int = cota válida · false = inválido.
	 */
	private function parse_price_bound( string $raw ) {
		return PriceBucketsAdminView::parse_price_bound( $raw );
	}

	private function persist_options_exact( array $values, array $releases = array() ): bool {
		return OptionStorage::persist_options_exact( $values, $releases );
	}

	/**
	 * Toma fotos exactas de un conjunto de options.
	 *
	 * @param array<int, string> $options Options a fotografiar.
	 * @return array<string, array{present: bool, value: mixed, raw: string, autoload: string}>|null
	 */
	private function option_snapshots( array $options ): ?array {
		return OptionStorage::option_snapshots( $options );
	}

	/**
	 * Lee valor+autoload en una sola foto DB-direct.
	 *
	 * @param string $option Option a fotografiar.
	 * @return array{present: bool, value: mixed, raw: string, autoload: string}|null
	 */
	private function option_snapshot( string $option ): ?array {
		return OptionStorage::option_snapshot( $option );
	}

	/**
	 * Rollback compensatorio best-effort.
	 *
	 * @param array<string, array{present: bool, value: mixed, raw: string, autoload: string}>      $snapshots Fotos.
	 * @param array<int, string>|null                                                               $only      Subset intentado.
	 * @param array<string, array{present: bool, value: mixed, raw: string, autoload: string}>|null $attempted Estado escrito por este grupo.
	 * @return bool
	 */
	private function restore_option_snapshots( array $snapshots, ?array $only = null, ?array $attempted = null ): bool {
		return OptionStorage::restore_option_snapshots( $snapshots, $only, $attempted );
	}

	/**
	 * Compensa sólo si la option todavía contiene exactamente el valor que este grupo intentó escribir.
	 *
	 * @param string                                                            $option    Option.
	 * @param array{present: bool, value: mixed, raw: string, autoload: string} $snapshot  Estado original.
	 * @param array{present: bool, value: mixed, raw: string, autoload: string} $attempted Estado propio esperado.
	 * @return array{ok: bool, changed: bool}
	 */
	private function restore_option_snapshot_cas( string $option, array $snapshot, array $attempted ): array {
		return OptionStorage::restore_option_snapshot_cas( $option, $snapshot, $attempted );
	}

	/**
	 * Construye el estado raw que debe dejar un write real de Core.
	 *
	 * @param string $option Option objetivo.
	 * @param mixed  $value  Valor objetivo.
	 * @return array{present: bool, value: mixed, raw: string, autoload: string}
	 */
	private function expected_option_state( string $option, $value ): array {
		return OptionStorage::expected_option_state( $option, $value );
	}

	/**
	 * Compara presencia, bytes y autoload de dos estados.
	 *
	 * @param array{present: bool, value: mixed, raw: string, autoload: string} $a Estado.
	 * @param array{present: bool, value: mixed, raw: string, autoload: string} $b Estado.
	 * @return bool
	 */
	private function option_states_equal( array $a, array $b ): bool {
		return OptionStorage::option_states_equal( $a, $b );
	}

	/**
	 * Compara sólo el valor raw para reconocer un no-op legítimo de Core.
	 *
	 * @param array{present: bool, value: mixed, raw: string, autoload: string} $state    Estado.
	 * @param mixed                                                             $expected Valor esperado.
	 * @return bool
	 */
	private function option_value_equals( array $state, $expected ): bool {
		return OptionStorage::option_value_equals( $state, $expected );
	}

	/**
	 * Obtiene el valor de autoload que Core escribirá con `$autoload=false`.
	 *
	 * @param string $option Option objetivo.
	 * @param mixed  $value  Valor objetivo.
	 * @return string
	 */
	private static function expected_autoload( string $option, $value ): string {
		return OptionStorage::expected_autoload( $option, $value );
	}

	/**
	 * Invalida todas las variantes del object cache de una option.
	 *
	 * @param string $option Option.
	 * @return void
	 */
	private function clear_option_cache( string $option ): void {
		OptionStorage::clear_option_cache( $option );
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
	 * Pinta los datalists HTML con los términos padre sugeridos por taxonomía.
	 *
	 * @param array<string, \WP_Taxonomy> $taxes Taxonomías elegibles.
	 * @return array<string, bool> Taxonomías con datalist truncado al tope.
	 */
	public function render_parent_datalists( array $taxes ): array {
		return FacetsAdminView::render_parent_datalists( $taxes );
	}

	/**
	 * Candidatos a término padre para una taxonomía jerárquica.
	 *
	 * @param string $taxonomy Slug de la taxonomía.
	 * @return array<int, \WP_Term>
	 */
	private function parent_candidates( string $taxonomy ): array {
		return FacetsAdminView::parent_candidates( $taxonomy );
	}

	/**
	 * Resuelve una entrada de término padre (ID o nombre) a un ID válido.
	 *
	 * @param string $raw      Texto ingresado por el operador.
	 * @param string $taxonomy Taxonomía objetivo.
	 * @return array{id: int, error: string}
	 */
	public static function resolve_parent_term( string $raw, string $taxonomy ): array {
		return FacetsAdminView::resolve_parent_term( $raw, $taxonomy );
	}

	/**
	 * Resumen conciso de cambios en facetas para el registro de auditoría.
	 *
	 * @param array<string, array<string, mixed>> $before Overrides previos.
	 * @param array<string, array<string, mixed>> $after  Overrides nuevos.
	 * @return string
	 */
	public static function facets_diff_summary( array $before, array $after ): string {
		return FacetsAdminView::facets_diff_summary( $before, $after );
	}

	/**
	 * Taxonomías elegibles para crear facetas nuevas.
	 *
	 * @return array<string, \WP_Taxonomy>
	 */
	private static function eligible_taxonomies(): array {
		return FacetsAdminView::eligible_taxonomies();
	}
}
