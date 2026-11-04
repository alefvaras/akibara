<?php
/**
 * UpsellsAutofill — pobla `_upsell_ids` de productos manga sin upsells, usando
 * los siguientes tomos consecutivos de la misma `pa_serie` ordenados por
 * post_title (padding numérico: vol 1 → 01).
 *
 * Caso de uso: el catálogo histórico (2020) vino con upsells en el CSV de
 * importación. Cargas manuales posteriores los olvidaron en algunas series
 * (Tokyo Revengers, Demon Slayer, etc.). Este módulo cierra ese gap y queda
 * re-ejecutable tras cada carga grande, vía:
 *
 *   1. WP-CLI:  wp akibara:upsells-fill [--dry-run] [--limit=N] [--force]
 *   2. GUI:     wp-admin → Akibara → tab "Upsells"
 *   3. Cron:    evento diario `akb_upsells_autofill_cron` (desde 1.99.7), para
 *      que las cargas de tomos nuevos no dependan de que alguien recuerde el
 *      botón. Mismo core que el botón: solo llena vacíos, nunca pisa manuales.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\UpsellsAutofill;

use Akibara\Business\Services\VolumeTitle;
use Akibara\Business\Utilities\PluginAssetVersion;

defined( 'ABSPATH' ) || exit;

final class UpsellsAutofill {

	/**
	 * Evento recurrente del relleno automático. Registrado en deactivation y
	 * uninstall del plugin (patrón ReadyToShipCron/Retention).
	 */
	public const CRON_HOOK = 'akb_upsells_autofill_cron';

	private const CRON_SCHEDULE     = 'daily';
	private const UPSELL_COUNT      = 3;
	private const ADMIN_BATCH_LIMIT = 100;
	private const PREVIEW_LIMIT     = 20;
	private const PRIME_CHUNK       = 500;
	private const LAST_RUN_OPTION   = 'akb_upsells_autofill_last_run';
	private const LAST_REPAIR_OPTION = 'akb_upsells_autofill_last_repair';
	private const COVERAGE_CACHE    = 'akb_upsells_coverage_v2';
	private const HEALTH_CACHE      = 'akb_upsells_health_v2';
	private const COVERAGE_TTL      = 5 * MINUTE_IN_SECONDS;

	/**
	 * Firma (hash de la lista) de la última escritura HECHA POR ESTE MÓDULO.
	 * Si el meta coincide con el estado actual de `_upsell_ids`, la lista es
	 * del bot y nadie la editó después; si no coincide (o no existe), es
	 * curaduría humana. Principio: la automatización solo puede deshacer lo
	 * que la automatización escribió.
	 *
	 * @since 1.99.9
	 */
	private const AUTOFILL_SIG_META = '_akb_upsells_autofill_sig';

	/**
	 * Interruptor del rellenado diario.
	 *
	 * Nace ENCENDIDO —es lo que llevaba haciendo— y existe porque hasta ahora la
	 * única forma de pararlo era desactivar el plugin entero, o sea apagar la
	 * tienda para apagar una recomendación. La regla de la casa es que todo lo
	 * operable tenga pantalla.
	 */
	public const ENABLED_OPTION = 'akibara_upsells_enabled';

	/**
	 * ¿Está encendido el rellenado diario?
	 *
	 * Sólo un «no» explícito lo apaga: una option ausente —sitio recién
	 * desplegado, o base restaurada de antes de este interruptor— sigue
	 * comportándose como siempre.
	 */
	public static function is_enabled(): bool {
		return 'no' !== (string) get_option( self::ENABLED_OPTION, 'yes' );
	}

	/** Resultados de {@see self::classify_link()}. */
	public const LINK_OK           = 'ok';
	public const LINK_DEAD         = 'dead';
	public const LINK_CROSS_SERIES = 'cross_series';
	public const LINK_RETRO        = 'retro';

	/**
	 * Secuencias elegibles memoizadas por término durante el request.
	 *
	 * @var array<int, array<int, array{id:int,title:string,volume_number:int}>>
	 */
	private array $sequence_cache = array();

	public function register(): void {
		// Fuera de is_admin(): WP-Cron ejecuta los callbacks en contexto front.
		add_action( self::CRON_HOOK, array( $this, 'cron_fill' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'akibara:upsells-fill', array( $this, 'cli_fill' ) );
		}

		if ( is_admin() ) {
			add_action( 'admin_post_akibara_upsells_run', array( $this, 'handle_admin_run' ) );
			add_action( 'admin_post_akibara_upsells_repair', array( $this, 'handle_admin_repair' ) );
			add_action( 'admin_post_akibara_upsells_toggle', array( $this, 'handle_admin_toggle' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		}
	}

	/**
	 * Auto-scheduling: si el evento no está en el cron de WP, lo agenda.
	 * Garantiza que exista tras activación o tras `wp cron event delete`
	 * (patrón ReadyToShipCron::maybe_schedule()).
	 *
	 * @since 1.99.7
	 */
	public function maybe_schedule(): void {
		// Apagado significa apagado: se retira el evento del cron en vez de
		// dejarlo girando para que el callback salga en la primera línea. Un
		// evento agendado sin trabajo que hacer es ruido en el panel de tareas y
		// una promesa falsa de que algo va a pasar.
		if ( ! self::is_enabled() ) {
			// `clear_schedule()` retira TODAS las ocurrencias del hook. Con
			// `wp_unschedule_event()` se retira una por petición, así que un array
			// de cron con duplicados tardaba varias visitas en converger — y de
			// paso este método deja de ser uno sin llamador.
			self::clear_schedule();

			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Retira el evento recurrente cuando el interruptor está apagado (lo llama
	 * `maybe_schedule()`). La desactivación y la desinstalación NO pasan por
	 * aquí —no pueden: corren sin autoloader—, sino por `Utilities\OwnCrons`.
	 *
	 * @since 1.99.7
	 */
	public static function clear_schedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Pasada diaria del relleno: mismo core y mismo tope de lote que el botón
	 * del panel, sin interacción. Solo llena productos SIN upsells, así que es
	 * seguro correrlo a ciegas; si no hay candidatos, es un no-op barato.
	 *
	 * @since 1.99.7
	 */
	public function cron_fill(): void {
		// Cinturón además del tirante: `maybe_schedule()` retira el evento al
		// apagarlo, pero un evento ya encolado —o uno que sobreviva a una
		// restauración de base— no puede rellenar fichas a espaldas del dueño.
		if ( ! self::is_enabled() ) {
			return;
		}

		$stats           = $this->run_fill( false, 0, false, self::ADMIN_BATCH_LIMIT );
		$stats['ts']     = time();
		$stats['user']   = 0;
		$stats['source'] = 'cron';

		// Sin candidatos ni cambios: no reescribir la option para conservar la
		// última corrida CON trabajo como referencia visible en el panel.
		if ( 0 === $stats['filled'] && 0 === $stats['candidates'] ) {
			return;
		}

		update_option( self::LAST_RUN_OPTION, $stats, false );
		$this->invalidate_caches();
		$this->purge_product_pages( $stats['updated_ids'] );
	}

	public function enqueue_admin_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sólo se mira en qué pestaña del panel estamos para encolar assets.
		$pagina = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ídem.
		$pestana = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( 'akibara-panel' !== $pagina || 'upsells_autofill' !== $pestana ) {
			return;
		}

		wp_enqueue_style(
			'akibara-upsells-admin',
			AKIBARA_BUSINESS_URL . 'assets/css/upsells-admin.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/upsells-admin.css' )
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	//  CLI
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * @param array<int,string>     $args
	 * @param array<string,string>  $assoc_args
	 */
	public function cli_fill( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );
		$force   = isset( $assoc_args['force'] );
		$limit   = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;

		$candidates = $this->find_candidates( $force, $limit );

		if ( empty( $candidates ) ) {
			\WP_CLI::success( 'Sin candidatos. Todos los productos con pa_serie ya tienen upsells.' );
			return;
		}

		\WP_CLI::log( sprintf(
			'Candidatos: %d%s%s%s',
			count( $candidates ),
			$dry_run ? ' (dry-run)' : '',
			$force ? ' [force]' : '',
			$limit > 0 ? " [limit={$limit}]" : ''
		) );

		$filled      = 0;
		$skipped     = 0;
		$updated_ids = array();

		foreach ( $candidates as $product_id ) {
			$upsell_ids = $this->find_next_volumes( $product_id, self::UPSELL_COUNT );
			$title      = get_the_title( $product_id );

			if ( empty( $upsell_ids ) ) {
				\WP_CLI::log( sprintf( '  [SKIP]    #%d %s — sin tomos siguientes en la misma serie', $product_id, $title ) );
				++$skipped;
				continue;
			}

			$upsells_titles = array_map( 'get_the_title', $upsell_ids );

			\WP_CLI::log( sprintf(
				'  [%s] #%d %s → %s',
				$dry_run ? 'PREVIEW' : 'SET    ',
				$product_id,
				$title,
				implode( ' | ', $upsells_titles )
			) );

			if ( ! $dry_run && ! $this->apply( $product_id, $upsell_ids ) ) {
				\WP_CLI::warning( sprintf( '  No se pudo guardar #%d', $product_id ) );
				continue;
			}

			++$filled;
			if ( ! $dry_run ) {
				$updated_ids[] = $product_id;
			}
		}

		if ( ! $dry_run ) {
			update_option(
				self::LAST_RUN_OPTION,
				array(
					'candidates'  => count( $candidates ),
					'filled'      => $filled,
					'skipped'     => $skipped,
					'updated_ids' => $updated_ids,
					'ts'          => time(),
					'user'        => 0,
					'source'      => 'cli',
				),
				false
			);
			$this->invalidate_caches();
			$this->purge_product_pages( $updated_ids );
		}

		\WP_CLI::success( sprintf(
			'%s: %d %s, %d skipped',
			$dry_run ? 'Dry-run' : 'Done',
			$filled,
			$dry_run ? 'preview' : 'filled',
			$skipped
		) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	//  Admin POST handler — disparado por el botón "Ejecutar ahora" del tab.
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Enciende o apaga el rellenado diario.
	 *
	 * @return void
	 */
	public function handle_admin_toggle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos.', 'akibara', array( 'response' => 403 ) );
		}

		check_admin_referer( 'akibara_upsells_toggle' );

		// CON autoload: la lee `maybe_schedule()` en cada `init`, así que sin
		// autoload sería una consulta por petición para leer tres letras.
		update_option( self::ENABLED_OPTION, self::is_enabled() ? 'no' : 'yes', true );

		// Agendar o desagendar en el acto: si el dueño lo apaga y el evento
		// siguiera en el cron, la pantalla diría una cosa y la cola otra.
		$this->maybe_schedule();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'akibara-panel',
					'tab'  => 'upsells_autofill',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_admin_run(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos.', 'akibara', array( 'response' => 403 ) );
		}

		check_admin_referer( 'akibara_upsells_run' );
		if ( empty( $_POST['confirm_fill'] ) ) {
			wp_die( 'Debes confirmar la ejecución.', 'akibara', array( 'response' => 400 ) );
		}

		$stats           = $this->run_fill( false, 0, false, self::ADMIN_BATCH_LIMIT );
		$stats['ts']     = time();
		$stats['user']   = get_current_user_id();
		$stats['source'] = 'manual';
		update_option( self::LAST_RUN_OPTION, $stats, false );

		$this->invalidate_caches();
		$this->purge_product_pages( $stats['updated_ids'] );

		wp_safe_redirect( add_query_arg(
			array(
				'page' => 'akibara-panel',
				'tab'  => 'upsells_autofill',
				'done' => '1',
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Repara solo relaciones estructuralmente inválidas, en lotes acotados.
	 */
	public function handle_admin_repair(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos.', 'akibara', array( 'response' => 403 ) );
		}

		check_admin_referer( 'akibara_upsells_repair' );
		if ( empty( $_POST['confirm_repair'] ) ) {
			wp_die( 'Debes confirmar la reparación.', 'akibara', array( 'response' => 400 ) );
		}

		$stats         = $this->repair_invalid_batch( self::ADMIN_BATCH_LIMIT );
		$stats['ts']   = time();
		$stats['user'] = get_current_user_id();
		update_option( self::LAST_REPAIR_OPTION, $stats, false );

		$this->invalidate_caches();
		$this->purge_product_pages( $stats['repaired_ids'] );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'akibara-panel',
					'tab'      => 'upsells_autofill',
					'repaired' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// ─────────────────────────────────────────────────────────────────────────
	//  Render del tab "Upsells" en el panel Akibara.
	// ─────────────────────────────────────────────────────────────────────────

	public function render_admin_section(): void {
		$stats     = $this->get_coverage_stats();
		$health    = $this->get_health_stats();
		$last_run  = get_option( self::LAST_RUN_OPTION );
		$last_repair = get_option( self::LAST_REPAIR_OPTION );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Sólo deciden qué AVISO se pinta tras el redirect del panel; no guardan nada.
		$just_done = isset( $_GET['done'] ) && '1' === $_GET['done'];
		$just_repaired = isset( $_GET['repaired'] ) && '1' === $_GET['repaired'];
		$show_preview = isset( $_GET['preview'] ) && '1' === $_GET['preview'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$pct                 = (float) ( $stats['pct'] ?? 0 );
		$total               = (int) ( $stats['total'] ?? 0 );
		$with                = (int) ( $stats['with_upsells'] ?? 0 );
		$processable         = (int) ( $stats['processable'] ?? 0 );
		$unprocessable       = (int) ( $stats['unprocessable'] ?? 0 );
		$unprocessable_items = isset( $stats['unprocessable_items'] ) && is_array( $stats['unprocessable_items'] )
			? $stats['unprocessable_items']
			: array();

		// Si todos los faltantes son no-procesables, la cobertura actual ES
		// el techo natural — UX debe reflejar eso (no es "incompleto").
		$is_at_max = ( 0 === $processable );

		?>
		<div class="akb-upsells-tab">

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 18px;padding:12px 14px;border:1px solid #dcdcde;background:#fff;border-radius:4px">
				<input type="hidden" name="action" value="akibara_upsells_toggle">
				<?php wp_nonce_field( 'akibara_upsells_toggle' ); ?>
				<p style="margin:0 0 8px">
					<strong>Rellenar recomendaciones cada día:</strong>
					<?php if ( self::is_enabled() ) : ?>
						<span style="color:#007a3d;font-weight:600">encendido</span>
					<?php else : ?>
						<span style="color:#b32d2e;font-weight:600">apagado</span>
					<?php endif; ?>
				</p>
				<button type="submit" class="button">
					<?php echo self::is_enabled() ? 'Apagar' : 'Encender'; ?>
				</button>
				<span class="description" style="margin-left:8px">
					<?php if ( self::is_enabled() ) : ?>
						Una vez al día se rellenan los «siguientes tomos» de las fichas que no tienen recomendaciones. Nunca pisa una lista hecha a mano.
					<?php else : ?>
						Las fichas nuevas se quedan sin «siguientes tomos» hasta que lo enciendas o pulses «Rellenar ahora». Lo ya puesto no se toca.
					<?php endif; ?>
				</span>
			</form>

			<?php if ( $just_done && is_array( $last_run ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						Listo. <strong><?php echo (int) ( $last_run['filled'] ?? 0 ); ?></strong> productos
						actualizados<?php echo (int) ( $last_run['skipped'] ?? 0 ) > 0
							? ', ' . (int) $last_run['skipped'] . ' sin cambios (no aplican).'
							: '.'; ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $just_repaired && is_array( $last_repair ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						Limpieza terminada. <strong><?php echo (int) ( $last_repair['repaired_products'] ?? 0 ); ?></strong>
						productos revisados y <strong><?php echo (int) ( $last_repair['removed_links'] ?? 0 ); ?></strong>
						enlaces rotos retirados.
					</p>
				</div>
			<?php endif; ?>

			<div class="akb-upsells-card">
				<h3>Recomendaciones automáticas</h3>

				<p class="akb-upsells-intro">
					Completa los productos recomendados de cada tomo con los siguientes
					3 volúmenes disponibles de la misma serie. Solo actúa cuando el producto
					no tiene recomendaciones: las configuradas manualmente se conservan.
				</p>

				<div class="akb-upsells-stats">
					<div class="akb-upsells-stat-main">
						<span class="akb-upsells-pct"><?php echo esc_html( number_format( $pct, 2 ) ); ?>%</span>
						<span class="akb-upsells-pct-label">
							<?php echo $is_at_max ? 'Cobertura máxima' : 'Cobertura actual'; ?>
						</span>
					</div>
					<div class="akb-upsells-stat-detail">
						<div><strong><?php echo esc_html( number_format_i18n( $with ) ); ?></strong> con recomendaciones</div>
						<div class="<?php echo $processable > 0 ? 'akb-upsells-stat-pending' : ''; ?>">
							<strong><?php echo esc_html( number_format_i18n( $processable ) ); ?></strong> pendientes
						</div>
						<div><strong><?php echo esc_html( number_format_i18n( $unprocessable ) ); ?></strong> no aplican</div>
						<div class="akb-upsells-stat-total">
							<strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong> productos con serie
						</div>
					</div>
				</div>

				<?php if ( $is_at_max && $unprocessable > 0 ) : ?>
					<p class="akb-upsells-explain">
						La cobertura está al máximo posible. Los <strong><?php echo esc_html( number_format_i18n( $unprocessable ) ); ?></strong>
						productos restantes son el último tomo de su serie, ediciones standalone
						o packs especiales — no aplica el patrón "siguientes 3 tomos".
					</p>
				<?php endif; ?>

				<?php if ( $unprocessable > 0 ) : ?>
					<details class="akb-upsells-unprocessable">
						<summary>Ver los <?php echo esc_html( number_format_i18n( $unprocessable ) ); ?> que no aplican</summary>
						<ul>
							<?php foreach ( $unprocessable_items as $item ) : ?>
								<li>
									<?php if ( ! empty( $item['edit_url'] ) ) : ?>
										<a href="<?php echo esc_url( $item['edit_url'] ); ?>" target="_blank" rel="noopener">
											<?php echo esc_html( $item['title'] ); ?>
										</a>
									<?php else : ?>
										<?php echo esc_html( $item['title'] ); ?>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</details>
				<?php endif; ?>

				<?php if ( is_array( $last_run ) && ! empty( $last_run['ts'] ) ) : ?>
					<p class="akb-upsells-lastrun">
						Última ejecución (<?php echo esc_html( $this->source_label( (string) ( $last_run['source'] ?? '' ) ) ); ?>):
						<strong><?php echo esc_html( $this->format_datetime( (int) $last_run['ts'] ) ); ?></strong>
						— <?php echo (int) ( $last_run['filled'] ?? 0 ); ?> actualizados,
						<?php echo (int) ( $last_run['skipped'] ?? 0 ); ?> sin cambios.
					</p>
				<?php endif; ?>

				<?php $next_cron = wp_next_scheduled( self::CRON_HOOK ); ?>
				<p class="akb-upsells-cron">
					<?php if ( false !== $next_cron ) : ?>
						Relleno automático diario <strong>activo</strong> — próxima pasada:
						<strong><?php echo esc_html( $this->format_datetime( (int) $next_cron ) ); ?></strong>.
						Los tomos que cargues quedan enlazados solos en esa pasada; el botón
						de abajo sigue disponible para no esperar.
					<?php elseif ( self::is_enabled() ) : ?>
						Relleno automático diario <strong>sin agendar</strong> — se agenda solo
						en la próxima visita al sitio.
					<?php else : ?>
						Relleno automático diario <strong>apagado</strong> — no está agendado y no
						se agendará hasta que lo enciendas arriba. Las fichas nuevas se quedan sin
						«siguientes tomos»; el botón de abajo sigue funcionando.
					<?php endif; ?>
				</p>

				<section class="akb-upsells-health" aria-labelledby="akb-upsells-health-title">
					<h3 id="akb-upsells-health-title">Salud de recomendaciones existentes</h3>

					<p>
						<strong><?php echo esc_html( number_format_i18n( (int) $health['dead_links'] ) ); ?></strong>
						enlaces rotos (producto eliminado o despublicado) en
						<strong><?php echo esc_html( number_format_i18n( (int) $health['dead_products'] ) ); ?></strong>
						productos — es lo único que el botón de reparación limpia.
						<strong><?php echo esc_html( number_format_i18n( (int) $health['temporarily_unavailable_links'] ) ); ?></strong>
						enlaces están temporalmente sin stock; no se tocan y vuelven a mostrarse solos al reponer.
					</p>

					<?php if ( ! empty( $health['dead_examples'] ) ) : ?>
						<details>
							<summary>Ver enlaces rotos a limpiar</summary>
							<ul>
								<?php foreach ( $health['dead_examples'] as $example ) : ?>
									<li>
										<strong><?php echo esc_html( $example['product'] ); ?></strong>
										→ <?php echo esc_html( $example['upsell'] ); ?>
									</li>
								<?php endforeach; ?>
							</ul>
						</details>
					<?php endif; ?>

					<?php if ( (int) $health['dead_products'] > 0 ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'akibara_upsells_repair' ); ?>
							<input type="hidden" name="action" value="akibara_upsells_repair">
							<label class="akb-upsells-confirm">
								<input type="checkbox" name="confirm_repair" value="1" required>
								Confirmo la limpieza del lote. Solo se retiran enlaces a productos
								eliminados o despublicados; el resto de cada lista queda intacto.
							</label>
							<button type="submit" class="button">
								<?php
								echo esc_html(
									sprintf(
										'Limpiar enlaces rotos (%s de %s productos)',
										number_format_i18n( min( self::ADMIN_BATCH_LIMIT, (int) $health['dead_products'] ) ),
										number_format_i18n( (int) $health['dead_products'] )
									)
								);
								?>
							</button>
						</form>
					<?php else : ?>
						<p class="akb-upsells-health-ok">No hay enlaces rotos.</p>
					<?php endif; ?>

					<p class="akb-upsells-note">
						<strong><?php echo esc_html( number_format_i18n( (int) $health['retro_products'] ) ); ?></strong>
						productos recomiendan tomos anteriores de su misma serie
						(<?php echo esc_html( number_format_i18n( (int) $health['retro_links'] ) ); ?> enlaces).
						Es el patrón «completa tu colección» del catálogo — típico del último
						tomo — y se respeta: ni el relleno ni la limpieza lo modifican.
					</p>

					<?php if ( (int) $health['cross_links'] > 0 ) : ?>
						<details class="akb-upsells-cross">
							<summary>
								<?php echo esc_html( number_format_i18n( (int) $health['cross_links'] ) ); ?>
								recomendaciones apuntan a otra serie — revisar a mano si son intencionales
							</summary>
							<ul>
								<?php foreach ( $health['cross_examples'] as $example ) : ?>
									<li>
										<?php if ( ! empty( $example['edit_url'] ) ) : ?>
											<a href="<?php echo esc_url( $example['edit_url'] ); ?>" target="_blank" rel="noopener">
												<strong><?php echo esc_html( $example['product'] ); ?></strong>
											</a>
										<?php else : ?>
											<strong><?php echo esc_html( $example['product'] ); ?></strong>
										<?php endif; ?>
										→ <?php echo esc_html( $example['upsell'] ); ?>
									</li>
								<?php endforeach; ?>
							</ul>
							<p class="description">
								Puede ser curaduría deliberada (otra edición de la misma obra) o un
								resto de importación. Se decide producto a producto desde su ficha;
								ningún botón las toca en masa.
							</p>
						</details>
					<?php endif; ?>
				</section>

				<?php if ( $show_preview && $processable > 0 ) : ?>
					<?php $preview = $this->preview_changes( self::PREVIEW_LIMIT ); ?>
					<div class="akb-upsells-preview">
						<h3>Vista previa sin cambios</h3>
						<?php if ( empty( $preview ) ) : ?>
							<p>No se encontraron recomendaciones aplicables en esta muestra.</p>
						<?php else : ?>
							<p>Primeros <?php echo esc_html( number_format_i18n( count( $preview ) ) ); ?> productos que se actualizarían:</p>
							<div class="akb-upsells-preview-scroll">
								<table class="widefat striped">
									<thead>
										<tr>
											<th>Producto</th>
											<th>Upsells propuestos</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $preview as $row ) : ?>
											<tr>
												<td><?php echo esc_html( $row['product_title'] ); ?></td>
												<td><?php echo esc_html( implode( ' · ', $row['upsell_titles'] ) ); ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php endif; ?>
					</div>
				<?php elseif ( $processable > 0 ) : ?>
					<p>
						<a class="button" href="<?php echo esc_url( add_query_arg( 'preview', '1' ) ); ?>">
							Ver vista previa
						</a>
					</p>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'akibara_upsells_run' ); ?>
					<input type="hidden" name="action" value="akibara_upsells_run">
					<label class="akb-upsells-confirm">
						<input type="checkbox" name="confirm_fill" value="1" required>
						Confirmo el procesamiento del lote. Las recomendaciones configuradas manualmente no se modificarán.
					</label>
					<button type="submit" class="button button-primary button-large akb-upsells-run-btn"
							<?php disabled( $is_at_max ); ?>>
						<?php
						if ( $is_at_max ) {
							echo 'Todo al día — sin pendientes';
						} else {
							echo esc_html(
								sprintf(
									'Procesar siguiente lote (%s de %s)',
									number_format_i18n( min( self::ADMIN_BATCH_LIMIT, $processable ) ),
									number_format_i18n( $processable )
								)
							);
						}
						?>
					</button>
					<p class="description">
						Procesa como máximo <?php echo esc_html( number_format_i18n( self::ADMIN_BATCH_LIMIT ) ); ?> productos por ejecución para evitar timeouts.
						Es idempotente y además corre solo una vez al día, así que este botón
						es solo para adelantar la pasada tras una carga de tomos.
						Los administradores técnicos también pueden usar <code>wp akibara:upsells-fill</code>.
					</p>
				</form>
			</div>

		</div>

		<?php
	}

	// ─────────────────────────────────────────────────────────────────────────
	//  Core compartido CLI + Admin
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Ejecuta el fill silencioso (sin output). Usado por el handler admin y el
	 * cron. `updated_ids` alimenta la purga por-PDP del caller: LiteSpeed NO
	 * escucha guardados de solo-meta (solo stock), así que sin purga explícita
	 * la sección de recomendaciones quedaría stale en el HTML cacheado.
	 *
	 * @return array{candidates: int, filled: int, skipped: int, updated_ids: array<int,int>}
	 */
	private function run_fill(
		bool $dry_run,
		int $limit = 0,
		bool $force = false,
		int $max_updates = 0
	): array {
		$candidates  = $this->find_candidates( $force, $limit );
		$filled      = 0;
		$skipped     = 0;
		$procesados  = 0;
		$updated_ids = array();

		foreach ( $candidates as $product_id ) {
			// El tope acota lo PROCESADO, no lo rellenado. Contando rellenos, un
			// candidato sin tomo siguiente no consumía cupo: el cron recorría la
			// lista ENTERA todos los días —con su `find_next_volumes()` por
			// producto— y el tope sólo limitaba las escrituras. Los candidatos
			// que no se alcanzan quedan para la corrida siguiente, que es
			// exactamente lo que el tope promete.
			if ( $max_updates > 0 && $procesados >= $max_updates ) {
				break;
			}
			++$procesados;

			$upsell_ids = $this->find_next_volumes( $product_id, self::UPSELL_COUNT );

			if ( empty( $upsell_ids ) ) {
				++$skipped;
				continue;
			}

			if ( ! $dry_run && ! $this->apply( $product_id, $upsell_ids ) ) {
				continue;
			}

			++$filled;
			if ( ! $dry_run ) {
				$updated_ids[] = $product_id;
			}
		}

		return array(
			'candidates'  => count( $candidates ),
			'filled'      => $filled,
			'skipped'     => $skipped,
			'updated_ids' => $updated_ids,
		);
	}

	/**
	 * Purga la caché de página de las PDPs modificadas vía la API pública de
	 * LiteSpeed (`litespeed_purge_post`, no-op si el plugin no está activo).
	 * Tercero solo por hook, nunca llamando sus clases.
	 *
	 * @since 1.99.7
	 *
	 * @param array<int,int> $product_ids
	 */
	private function purge_product_pages( array $product_ids ): void {
		foreach ( array_unique( array_map( 'intval', $product_ids ) ) as $product_id ) {
			if ( $product_id > 0 ) {
				do_action( 'litespeed_purge_post', $product_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook público oficial de LiteSpeed Cache (tercero): el nombre lo fija ese plugin.
			}
		}
	}

	/**
	 * Vista previa segura de las próximas asignaciones.
	 *
	 * @return array<int, array{product_id:int,product_title:string,upsell_ids:array<int,int>,upsell_titles:array<int,string>}>
	 */
	private function preview_changes( int $limit ): array {
		$rows = array();

		foreach ( $this->find_candidates( false, 0 ) as $product_id ) {
			$upsell_ids = $this->find_next_volumes( $product_id, self::UPSELL_COUNT );
			if ( empty( $upsell_ids ) ) {
				continue;
			}

			$rows[] = array(
				'product_id'     => $product_id,
				'product_title'  => (string) get_the_title( $product_id ),
				'upsell_ids'     => $upsell_ids,
				'upsell_titles'  => array_map(
					static fn( int $id ): string => (string) get_the_title( $id ),
					$upsell_ids
				),
			);

			if ( count( $rows ) >= $limit ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * Audita relaciones existentes. Tres categorías con destinos distintos:
	 * `dead_*` alimenta el botón Reparar; `cross_*` es lista de decisión
	 * humana; `retro_*` es una métrica del patrón "completa tu colección"
	 * (válido en este catálogo). La indisponibilidad temporal se cuenta aparte.
	 *
	 * @return array{
	 *   products:int,
	 *   links:int,
	 *   dead_products:int,
	 *   dead_links:int,
	 *   cross_links:int,
	 *   retro_products:int,
	 *   retro_links:int,
	 *   temporarily_unavailable_links:int,
	 *   dead_examples:array<int,array{product:string,upsell:string}>,
	 *   cross_examples:array<int,array{product:string,upsell:string,edit_url:string}>
	 * }
	 */
	public function get_health_stats(): array {
		$cached = get_transient( self::HEALTH_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$stats = array(
			'products'                      => 0,
			'links'                         => 0,
			'dead_products'                 => 0,
			'dead_links'                    => 0,
			'cross_links'                   => 0,
			'retro_products'                => 0,
			'retro_links'                   => 0,
			'temporarily_unavailable_links' => 0,
			'dead_examples'                 => array(),
			'cross_examples'                => array(),
		);

		$upsell_map = $this->products_with_upsell_meta();
		$this->prime_caches_for_inspection( $upsell_map );

		foreach ( array_keys( $upsell_map ) as $product_id ) {
			$inspection = $this->inspect_product_upsells( $product_id );
			if ( 0 === $inspection['links'] ) {
				continue;
			}

			++$stats['products'];
			$stats['links'] += $inspection['links'];
			$stats['temporarily_unavailable_links'] += $inspection['temporarily_unavailable_links'];
			$stats['cross_links']                   += count( $inspection['cross'] );

			if ( ! empty( $inspection['retro'] ) ) {
				++$stats['retro_products'];
				$stats['retro_links'] += count( $inspection['retro'] );
			}

			if ( ! empty( $inspection['dead'] ) ) {
				++$stats['dead_products'];
				$stats['dead_links'] += count( $inspection['dead'] );
			}

			foreach ( $inspection['dead_examples'] as $example ) {
				if ( count( $stats['dead_examples'] ) < 20 ) {
					$stats['dead_examples'][] = $example;
				}
			}
			foreach ( $inspection['cross_examples'] as $example ) {
				if ( count( $stats['cross_examples'] ) < 20 ) {
					$stats['cross_examples'][] = $example;
				}
			}
		}

		set_transient( self::HEALTH_CACHE, $stats, self::COVERAGE_TTL );
		return $stats;
	}

	/**
	 * Repara el siguiente lote de productos con enlaces ROTOS (destino
	 * eliminado o no publicado) — nada más. Retrospectivos y cross-serie son
	 * curaduría o política del catálogo y este método no los toca; ver
	 * classify_link(). Conserva el resto de la lista tal cual y solo rellena
	 * con tomos siguientes si quedó espacio.
	 *
	 * @return array{repaired_products:int,removed_links:int,repaired_ids:array<int,int>}
	 */
	private function repair_invalid_batch( int $limit ): array {
		$repaired     = 0;
		$removed      = 0;
		$repaired_ids = array();

		$upsell_map = $this->products_with_upsell_meta();
		$this->prime_caches_for_inspection( $upsell_map );

		foreach ( array_keys( $upsell_map ) as $product_id ) {
			if ( $repaired >= $limit ) {
				break;
			}

			$inspection = $this->inspect_product_upsells( $product_id );
			if ( empty( $inspection['dead'] ) ) {
				continue;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$dead_lookup    = array_fill_keys( $inspection['dead'], true );
			$valid_existing = array_values(
				array_filter(
					array_map( 'intval', $product->get_upsell_ids() ),
					static fn( int $id ): bool => ! isset( $dead_lookup[ $id ] )
				)
			);
			$canonical      = $this->find_next_volumes( $product_id, self::UPSELL_COUNT );
			$replacement    = array_slice(
				array_values( array_unique( array_merge( $valid_existing, $canonical ) ) ),
				0,
				self::UPSELL_COUNT
			);

			$product->set_upsell_ids( $replacement );
			$product->update_meta_data( self::AUTOFILL_SIG_META, self::signature( $replacement ) );
			$product->save();

			++$repaired;
			$repaired_ids[] = $product_id;
			$removed       += count( $inspection['dead'] );
		}

		return array(
			'repaired_products' => $repaired,
			'removed_links'     => $removed,
			'repaired_ids'      => $repaired_ids,
		);
	}

	/**
	 * Productos publicados con upsells no vacíos, junto a los IDs referenciados
	 * (deserializados del meta). Una sola query: el mapa alimenta el priming
	 * batch y evita el N+1 que hacía lento el primer render del tab.
	 *
	 * @return array<int, array<int, int>> product_id => upsell_ids.
	 */
	private function products_with_upsell_meta(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT p.ID AS id, pm.meta_value AS upsells
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE p.post_type = 'product'
			   AND p.post_status = 'publish'
			   AND pm.meta_key = '_upsell_ids'
			   AND pm.meta_value NOT IN ('', 'a:0:{}')
			 ORDER BY p.ID ASC",
			ARRAY_A
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$upsell_ids = maybe_unserialize( (string) ( $row['upsells'] ?? '' ) );
			$upsell_ids = is_array( $upsell_ids )
				? array_values( array_filter( array_map( 'intval', $upsell_ids ) ) )
				: array();

			$map[ (int) $row['id'] ] = $upsell_ids;
		}

		return $map;
	}

	/**
	 * Prima post/term/meta caches de productos e upsells referenciados en
	 * chunks acotados. Con esto `inspect_product_upsells()` trabaja contra
	 * caché: sin priming eran ~6-10 queries POR producto con upsells.
	 *
	 * @since 1.99.7
	 *
	 * @param array<int, array<int, int>> $upsell_map product_id => upsell_ids.
	 */
	private function prime_caches_for_inspection( array $upsell_map ): void {
		$all_ids = array_keys( $upsell_map );
		foreach ( $upsell_map as $upsell_ids ) {
			foreach ( $upsell_ids as $upsell_id ) {
				$all_ids[] = $upsell_id;
			}
		}

		$all_ids = array_values( array_unique( array_filter( array_map( 'intval', $all_ids ) ) ) );
		foreach ( array_chunk( $all_ids, self::PRIME_CHUNK ) as $chunk ) {
			_prime_post_caches( $chunk, true, true );
		}
	}

	/**
	 * Inspecciona los upsells de un producto con el clasificador calibrado.
	 * `dead` es lo único que el botón Reparar puede tocar; `cross` y `retro`
	 * son información para el dueño.
	 *
	 * @return array{
	 *   links:int,
	 *   dead:array<int,int>,
	 *   cross:array<int,int>,
	 *   retro:array<int,int>,
	 *   temporarily_unavailable_links:int,
	 *   dead_examples:array<int,array{product:string,upsell:string}>,
	 *   cross_examples:array<int,array{product:string,upsell:string,edit_url:string}>
	 * }
	 */
	private function inspect_product_upsells( int $product_id ): array {
		$result = array(
			'links'                         => 0,
			'dead'                          => array(),
			'cross'                         => array(),
			'retro'                         => array(),
			'temporarily_unavailable_links' => 0,
			'dead_examples'                 => array(),
			'cross_examples'                => array(),
		);

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return $result;
		}

		$current_number = VolumeTitle::extract_number( $product->get_name() );
		$current_terms  = $this->serie_term_taxonomy_ids( $product_id );

		foreach ( array_map( 'intval', $product->get_upsell_ids() ) as $upsell_id ) {
			++$result['links'];
			$upsell       = wc_get_product( $upsell_id );
			$upsell_alive = $upsell instanceof \WC_Product && 'publish' === get_post_status( $upsell_id );

			if ( $upsell_alive
				&& ( ! $upsell->is_purchasable() || ! $upsell->is_in_stock() ) ) {
				++$result['temporarily_unavailable_links'];
			}

			$verdict = self::classify_link(
				$upsell_alive,
				$current_number,
				$upsell_alive ? VolumeTitle::extract_number( $upsell->get_name() ) : null,
				$current_terms,
				$upsell_alive ? $this->serie_term_taxonomy_ids( $upsell_id ) : array()
			);

			switch ( $verdict ) {
				case self::LINK_DEAD:
					$result['dead'][]          = $upsell_id;
					$result['dead_examples'][] = array(
						'product' => $product->get_name(),
						'upsell'  => $upsell instanceof \WC_Product ? $upsell->get_name() : '#' . $upsell_id,
					);
					break;
				case self::LINK_CROSS_SERIES:
					$result['cross'][]          = $upsell_id;
					$result['cross_examples'][] = array(
						'product'  => $product->get_name(),
						'upsell'   => $upsell->get_name(),
						'edit_url' => get_edit_post_link( $product_id, 'raw' ) ?? '',
					);
					break;
				case self::LINK_RETRO:
					$result['retro'][] = $upsell_id;
					break;
			}
		}

		$result['dead']  = array_values( array_unique( $result['dead'] ) );
		$result['cross'] = array_values( array_unique( $result['cross'] ) );
		$result['retro'] = array_values( array_unique( $result['retro'] ) );
		return $result;
	}

	/**
	 * HPOS-safe write vía WC product API. Devuelve false si el producto no
	 * existe o no es una variable WC_Product. Firma la lista escrita: así el
	 * futuro puede distinguir "lo escribió el bot y nadie lo tocó" de
	 * curaduría humana.
	 *
	 * @param array<int,int> $upsell_ids
	 */
	private function apply( int $product_id, array $upsell_ids ): bool {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return false;
		}
		// La firma viaja DENTRO del mismo save: escribirla con `update_post_meta`
		// justo después dejaba al objeto WC_Product en memoria sin ella y pagaba
		// una segunda escritura por producto, saltándose la capa CRUD que el
		// resto del módulo usa.
		$product->set_upsell_ids( $upsell_ids );
		$product->update_meta_data( self::AUTOFILL_SIG_META, self::signature( $upsell_ids ) );
		$product->save();
		return true;
	}

	/**
	 * Hash estable de una lista de upsells (orden incluido).
	 *
	 * @since 1.99.9
	 *
	 * @param array<int,int> $upsell_ids
	 */
	public static function signature( array $upsell_ids ): string {
		return sha1( implode( ',', array_map( 'intval', $upsell_ids ) ) );
	}

	/**
	 * Clasificador puro de un enlace de upsell. Criterio calibrado con el
	 * catálogo real (medición: 211 enlaces "inválidos" según el
	 * criterio anterior eran 210 retrospectivos deliberados + 1 cross-serie
	 * deliberado + 0 rotos):
	 *
	 * - DEAD: el destino no existe o no está publicado — lo único
	 *   objetivamente roto; el botón Reparar SOLO limpia esto.
	 * - CROSS_SERIES: destino sin serie en común — puede ser error histórico
	 *   o curaduría (Berserk ME → Maximum Berserk); se reporta, no se repara.
	 * - RETRO: tomo anterior o igual de la MISMA serie — política comercial
	 *   del catálogo ("completa tu colección" en últimos tomos); es VÁLIDO.
	 * - OK: siguiente tomo u otro caso sin objeción.
	 *
	 * @since 1.99.9
	 *
	 * @param bool           $upsell_alive   Destino publicado y cargable.
	 * @param int|null       $current_number Tomo del producto (null = especial).
	 * @param int|null       $upsell_number  Tomo del destino (null = especial).
	 * @param array<int,int> $current_tt_ids Series (tt_ids) del producto.
	 * @param array<int,int> $upsell_tt_ids  Series (tt_ids) del destino.
	 */
	public static function classify_link(
		bool $upsell_alive,
		?int $current_number,
		?int $upsell_number,
		array $current_tt_ids,
		array $upsell_tt_ids
	): string {
		if ( ! $upsell_alive ) {
			return self::LINK_DEAD;
		}

		if ( null === $current_number ) {
			return self::LINK_OK;
		}

		if ( empty( array_intersect( $current_tt_ids, $upsell_tt_ids ) ) ) {
			return self::LINK_CROSS_SERIES;
		}

		if ( null !== $upsell_number && $upsell_number <= $current_number ) {
			return self::LINK_RETRO;
		}

		return self::LINK_OK;
	}

	/**
	 * Productos con `pa_serie` que no tienen `_upsell_ids` (o vacío).
	 * Si $force, devuelve TODOS los productos con `pa_serie`.
	 *
	 * @return array<int,int>
	 */
	private function find_candidates( bool $force, int $limit ): array {
		global $wpdb;

		$where_meta = $force
			? ''
			: 'AND ( pm.meta_value IS NULL OR pm.meta_value IN ( "", "a:0:{}" ) )';

		$limit_sql = $limit > 0 ? $wpdb->prepare( 'LIMIT %d', $limit ) : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- taxonomy y meta_key son constantes.
		$sql = "SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
				JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_upsell_ids'
				WHERE p.post_type = 'product'
				  AND p.post_status = 'publish'
				  AND tt.taxonomy = 'pa_serie'
				  {$where_meta}
				ORDER BY p.ID ASC
				{$limit_sql}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- $sql se arma arriba con literales de este método y nombres de tabla de $wpdb, y el único valor variable (el LIMIT) ya viene de $wpdb->prepare(); el sniff exige ver el prepare() en esta línea.
		$ids = $wpdb->get_col( $sql );
		return array_map( 'intval', $ids );
	}

	/**
	 * Próximos N tomos de la misma `pa_serie`, por número de lectura real.
	 *
	 * Solo considera productos visibles, comprables y con stock. Los especiales
	 * sin número inequívoco se excluyen para no recomendar novelas, fanbooks o
	 * packs como si fueran el tomo siguiente.
	 *
	 * @return array<int,int>
	 */
	private function find_next_volumes( int $product_id, int $count ): array {
		$serie_tt_ids = $this->serie_term_taxonomy_ids( $product_id );
		if ( empty( $serie_tt_ids ) ) {
			return array();
		}

		$current = get_post( $product_id );
		if ( ! $current instanceof \WP_Post ) {
			return array();
		}

		$current_number = VolumeTitle::extract_number( $current->post_title );

		$candidates = array();
		foreach ( $serie_tt_ids as $term_taxonomy_id ) {
			foreach ( $this->sequence_for_term( $term_taxonomy_id ) as $record ) {
				$candidates[] = $record;
			}
		}

		if ( null !== $current_number ) {
			$next = VolumeTitle::next_records( $candidates, $current_number, $count, array( $product_id ) );
			if ( ! empty( $next ) ) {
				return array_map(
					static fn( array $record ): int => (int) $record['id'],
					$next
				);
			}
		}

		// Fallback 1: Si no hay tomos siguientes (tomo final de serie o número especial),
		// recomendar los primeros tomos de la misma serie (Vol 01, Vol 02, etc.) disponibles.
		$sorted_candidates = VolumeTitle::sort_records( $candidates );
		$fallback_series   = array();
		foreach ( $sorted_candidates as $record ) {
			$id = (int) $record['id'];
			if ( $id !== $product_id && ! in_array( $id, $fallback_series, true ) ) {
				$fallback_series[] = $id;
				if ( count( $fallback_series ) >= $count ) {
					break;
				}
			}
		}
		if ( ! empty( $fallback_series ) ) {
			return $fallback_series;
		}

		// Fallback 2: Serie de tomo único (standalone / one-shot) -> obras del mismo autor.
		$author_fallback = $this->find_author_fallback( $product_id, $count );
		if ( ! empty( $author_fallback ) ) {
			return $author_fallback;
		}

		// Fallback 3: Obras del mismo género o demografía.
		return $this->find_genre_fallback( $product_id, $count );
	}

	/**
	 * Fallback para productos sin otros tomos en su serie (ej. tomos únicos):
	 * recomienda otros productos del mismo autor (pa_autor) que estén publicados y en catálogo.
	 *
	 * @param int $product_id ID del producto.
	 * @param int $count      Cantidad de recomendaciones deseadas.
	 * @return array<int,int>
	 */
	private function find_author_fallback( int $product_id, int $count ): array {
		$author_terms = get_the_terms( $product_id, 'pa_autor' );
		if ( ! is_array( $author_terms ) || empty( $author_terms ) ) {
			return array();
		}

		$author_ids = array_map(
			static fn( \WP_Term $t ): int => (int) $t->term_id,
			$author_terms
		);

		$posts = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => $count + 5,
				'exclude'        => array( $product_id ),
				'tax_query'      => array(
					array(
						'taxonomy' => 'pa_autor',
						'field'    => 'term_id',
						'terms'    => $author_ids,
					),
				),
			)
		);

		$results = array();
		foreach ( $posts as $p ) {
			if ( function_exists( 'wc_get_product' ) ) {
				$prod = wc_get_product( $p->ID );
				if ( $prod instanceof \WC_Product ) {
					if ( ! $prod->is_visible() || ! $prod->is_purchasable() || ! $prod->is_in_stock() ) {
						continue;
					}
				}
			}
			$results[] = (int) $p->ID;
			if ( count( $results ) >= $count ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * Fallback por género cuando no hay tomos de la misma serie ni del mismo autor.
	 *
	 * @param int $product_id ID del producto.
	 * @param int $count      Cantidad de recomendaciones deseadas.
	 * @return array<int,int>
	 */
	private function find_genre_fallback( int $product_id, int $count ): array {
		$genre_terms = get_the_terms( $product_id, 'pa_genero' );
		if ( ! is_array( $genre_terms ) || empty( $genre_terms ) ) {
			return array();
		}

		$genre_ids = array_map(
			static fn( \WP_Term $t ): int => (int) $t->term_id,
			$genre_terms
		);

		$posts = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => $count + 5,
				'exclude'        => array( $product_id ),
				'tax_query'      => array(
					array(
						'taxonomy' => 'pa_genero',
						'field'    => 'term_id',
						'terms'    => $genre_ids,
					),
				),
			)
		);

		$results = array();
		foreach ( $posts as $p ) {
			if ( function_exists( 'wc_get_product' ) ) {
				$prod = wc_get_product( $p->ID );
				if ( $prod instanceof \WC_Product ) {
					if ( ! $prod->is_visible() || ! $prod->is_purchasable() || ! $prod->is_in_stock() ) {
						continue;
					}
				}
			}
			$results[] = (int) $p->ID;
			if ( count( $results ) >= $count ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * `term_taxonomy_id`s de `pa_serie` de un post, leyendo del term cache.
	 *
	 * `get_the_terms()` consulta `get_object_term_cache()` primero — que los
	 * callers batch ya priman vía `_prime_post_caches(..., true,...)` — y solo
	 * cae a query para posts fuera del set primado. `wp_get_post_terms()`, que
	 * se usaba antes, ignora ese caché y siempre consulta: era el N+1.
	 *
	 * @since 1.99.7
	 *
	 * @return array<int, int>
	 */
	private function serie_term_taxonomy_ids( int $post_id ): array {
		$terms = get_the_terms( $post_id, 'pa_serie' );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_map(
			static fn( \WP_Term $term ): int => (int) $term->term_taxonomy_id,
			$terms
		);
	}

	/**
	 * Secuencia comprable de un término de serie.
	 *
	 * @return array<int, array{id:int,title:string,volume_number:int}>
	 */
	private function sequence_for_term( int $term_taxonomy_id ): array {
		if ( isset( $this->sequence_cache[ $term_taxonomy_id ] ) ) {
			return $this->sequence_cache[ $term_taxonomy_id ];
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID AS id, p.post_title AS title
				 FROM {$wpdb->posts} p
				 JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
				 WHERE p.post_type = 'product'
				   AND p.post_status = 'publish'
				   AND tr.term_taxonomy_id = %d",
				$term_taxonomy_id
			),
			ARRAY_A
		);

		$ids = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		if ( ! empty( $ids ) ) {
			_prime_post_caches( $ids, true, true );
		}

		$records = array();
		foreach ( $rows as $row ) {
			$id     = (int) $row['id'];
			$title  = (string) $row['title'];
			$number = VolumeTitle::extract_number( $title );
			if ( null === $number ) {
				continue;
			}

			$product = wc_get_product( $id );
			if ( ! $product instanceof \WC_Product
				|| ! $product->is_visible()
				|| ! $product->is_purchasable()
				|| ! $product->is_in_stock() ) {
				continue;
			}

			$records[] = array(
				'id'            => $id,
				'title'         => $title,
				'volume_number' => $number,
			);
		}

		/** @var array<int, array{id:int,title:string,volume_number:int}> $records */
		$records = VolumeTitle::sort_records( $records );
		$this->sequence_cache[ $term_taxonomy_id ] = $records;

		return $records;
	}

	/**
	 * Devuelve candidatos con al menos un siguiente tomo realmente comprable.
	 *
	 * Las secuencias se memoizan por término, de modo que el coste queda acotado
	 * por cantidad de series y no por cantidad de productos.
	 *
	 * @param array<int,int> $ids
	 * @return array<int,int> Subconjunto de $ids que tiene tomo siguiente.
	 */
	private function filter_ids_with_next_volume( array $ids ): array {
		if ( empty( $ids ) ) {
			return array();
		}

		$ids = array_map( 'intval', $ids );
		// terms=true: find_next_volumes() lee pa_serie vía get_the_terms(), que
		// sin term cache primado degrada a una query por candidato.
		foreach ( array_chunk( $ids, self::PRIME_CHUNK ) as $chunk ) {
			_prime_post_caches( $chunk, true, true );
		}

		$has_next = array();
		foreach ( $ids as $id ) {
			if ( ! empty( $this->find_next_volumes( $id, 1 ) ) ) {
				$has_next[] = $id;
			}
		}

		return $has_next;
	}

	// ─────────────────────────────────────────────────────────────────────────
	//  Coverage stats (cacheado para no hammer DB en cada render).
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * @return array{
	 *   total: int,
	 *   with_upsells: int,
	 *   without_upsells: int,
	 *   processable: int,
	 *   unprocessable: int,
	 *   unprocessable_items: array<int, array{id:int, title:string, edit_url:string}>,
	 *   pct: float
	 * }
	 */
	public function get_coverage_stats(): array {
		$cached = get_transient( self::COVERAGE_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		// El JOIN a term_relationships/term_taxonomy duplica la fila del producto
		// una vez por término `pa_serie`. `COUNT(DISTINCT p.ID)` dedup el total,
		// pero un `SUM(CASE...)` contaba la misma fila N veces → with_upsells se
		// sobrecontaba (cobertura podía pasar 100%) para productos con ≥2 series.
		// `COUNT(DISTINCT CASE WHEN... THEN p.ID END)` dedup por producto: NULL
		// (rama ELSE ausente) no cuenta, y cada ID se cuenta una sola vez.
		$row = $wpdb->get_row(
			"SELECT COUNT(DISTINCT p.ID) AS total,
					COUNT(DISTINCT CASE WHEN pm.meta_value NOT IN ('', 'a:0:{}') AND pm.meta_value IS NOT NULL THEN p.ID END) AS with_upsells
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
			 JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_upsell_ids'
			 WHERE p.post_type = 'product'
			   AND p.post_status = 'publish'
			   AND tt.taxonomy = 'pa_serie'",
			ARRAY_A
		);

		$total = (int) ( $row['total'] ?? 0 );
		$with  = (int) ( $row['with_upsells'] ?? 0 );

		// Clasificar productos sin upsells: los que tienen siguiente tomo
		// (processable, listos para llenar) vs los que no (último de serie,
		// standalone, packs — techo natural).
		$candidate_ids = $this->find_candidates( false, 0 );

		// Antes: find_next_volumes() por candidato → N queries (una por producto
		// sin upsells). Ahora una sola query batch resuelve qué candidatos tienen
		// tomo siguiente; los que no aparecen ahí son los "unprocessable".
		$processable_set   = array_fill_keys( $this->filter_ids_with_next_volume( $candidate_ids ), true );
		$unprocessable_ids = array_values(
			array_filter( $candidate_ids, static fn( int $id ): bool => ! isset( $processable_set[ $id ] ) )
		);

		// Primar post caches SOLO de los no-procesables (los únicos que muestran
		// título/edit_url). La clasificación ya no lee terms por ítem.
		if ( ! empty( $unprocessable_ids ) ) {
			_prime_post_caches( $unprocessable_ids, false, false );
		}

		$unprocessable_items = array();
		foreach ( $unprocessable_ids as $cid ) {
			$unprocessable_items[] = array(
				'id'       => $cid,
				'title'    => html_entity_decode( get_the_title( $cid ), ENT_QUOTES, 'UTF-8' ),
				'edit_url' => get_edit_post_link( $cid, 'raw' ) ?? '',
			);
		}
		// Orden alfabético por título para presentación.
		usort( $unprocessable_items, static fn( $a, $b ) => strcmp( $a['title'], $b['title'] ) );

		$unprocessable = count( $unprocessable_items );
		$processable   = max( 0, count( $candidate_ids ) - $unprocessable );

		$stats = array(
			'total'               => $total,
			'with_upsells'        => $with,
			'without_upsells'     => max( 0, $total - $with ),
			'processable'         => $processable,
			'unprocessable'       => $unprocessable,
			'unprocessable_items' => $unprocessable_items,
			'pct'                 => $total > 0 ? round( 100 * $with / $total, 2 ) : 0.0,
		);

		set_transient( self::COVERAGE_CACHE, $stats, self::COVERAGE_TTL );
		return $stats;
	}

	private function invalidate_caches(): void {
		delete_transient( self::COVERAGE_CACHE );
		delete_transient( self::HEALTH_CACHE );
		$this->sequence_cache = array();
	}

	private function format_datetime( int $ts ): string {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		return wp_date( $format, $ts );
	}

	/**
	 * Etiqueta humana del origen de la última corrida. Corridas anteriores a
	 * 1.99.7 no guardaban `source`: se asumen manuales (solo existía el botón).
	 */
	private function source_label( string $source ): string {
		switch ( $source ) {
			case 'cron':
				return 'automática';
			case 'cli':
				return 'por CLI';
			default:
				return 'manual';
		}
	}
}
