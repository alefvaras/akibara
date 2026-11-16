<?php
/**
 * AccountRewards — el endpoint `rewards` de Mi cuenta: saldo, historial y
 * canje (Fase 1b).
 *
 * Mismo slug que registraba SPAR a propósito: el dashboard de business y el
 * menú móvil (tercera posición, medida en 375px) ya lo enlazan — al cutover
 * nada queda en 404 (spec 37). La tarjeta del dashboard consulta directamente
 * `Ledger::balance()`.
 *
 * El canje se FIJA aquí (intención en sesión) y el fee aparece en el
 * carrito/checkout: el cliente ve el descuento antes de pagar. UI liviana:
 * HTML del servidor, ayuda progresiva de canje, targets 44px, es-CL.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Reviews\ReviewEligibility;
use Akibara\Rewards\Reviews\RewardMessage;
use Akibara\Rewards\Support\Assets;
use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\Schema;
use Akibara\Rewards\Points\Expiry;
use Akibara\Rewards\Points\ProgramPage;
use Akibara\Rewards\Points\WelcomeBonus;
use Akibara\Rewards\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 * @api
 * @psalm-suppress HookInvalidArgs
 * @psalm-suppress InvalidOperand
 * @psalm-suppress RedundantCast
 * @psalm-suppress RedundantCondition
 */
// @phpstan-ignore complexity.classLike
final class AccountRewards {

	public const ENDPOINT      = 'rewards';
	public const REDEEM_ACTION = 'akb_rewards_redeem';
	/** Pública: la migración de autoload de Support\Schema la nombra. */
	public const FLUSH_OPTION   = 'akb_rewards_rewrite_version';

	/** Una reparación de reglas perdidas por hora, como mucho. */
	private const HEAL_TRANSIENT = 'akb_rewards_rewrite_heal';
	public const FLUSH_VERSION   = '3';

	/**
	 * Cuelga endpoint, menú, render y el receptor del canje.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		// Persiste las reglas después de registrar los alias de catálogo del tema.
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 999 );
		// Añade el elemento antes del reordenador de MyAccountUx (prioridad 20).
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ), 5 );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'register_query_var' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'endpoint_title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'handle_redeem' ) );
		add_action( 'template_redirect', array( $this, 'maybe_redirect_legacy_endpoint' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

	}

	/** Ayuda progresiva sólo en la cuenta de puntos autenticada. */
	public function enqueue_assets(): void {
		if ( get_current_user_id() <= 0 || ! is_wc_endpoint_url( self::ENDPOINT ) ) {
			return;
		}
		wp_enqueue_style( 'akb-rw-account', Assets::url( 'assets/css/account-rewards.css' ), array(), Assets::version( 'assets/css/account-rewards.css' ) );
		wp_enqueue_script( 'akb-rw-account', Assets::url( 'assets/js/account-rewards.js' ), array(), Assets::version( 'assets/js/account-rewards.js' ), true );
	}

	/**
	 * Registra el endpoint y hace UN flush de rewrites al estrenarlo.
	 *
	 * @return void
	 */
	public function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Firma del RULESET, no un número suelto: las reglas de /resena/{token}
	 * sólo se registran cuando el motor propio de reseñas está despierto
	 * (duerme mientras CusRev viva). Con una versión fija, el candado se
	 * sellaba SIN esas reglas y, al despertar en el cutover, no había flush
	 * que las persistiera: todos los enlaces /resena/{token} ya enviados por
	 * correo caían en 404. Al cambiar el motor cambia la firma y entra UN
	 * flush más.
	 *
	 * @return string
	 */
	private static function rules_signature(): string {
		return self::FLUSH_VERSION . ( Options::reviews_engine_on() ? '-rf' : '' );
	}

	/**
	 * Valor con el que nace el campo «Puntos a canjear». Sólo se llama en la
	 * rama donde ya se probó `$disponible >= $minimo_canje`, acotando el
	 * valor inicial estrictamente al rango `[$minimo_canje, $disponible]`.
	 *
	 * @param int $disponible   Máximo del campo (`max`).
	 * @param int $minimo_canje Mínimo del campo (`min`).
	 * @param int $intent       Intención de canje guardada en la sesión.
	 * @return int
	 */
	private static function valor_inicial_de_canje( int $disponible, int $minimo_canje, int $intent ): int {
		return min( $disponible, max( $minimo_canje, $intent ) );
	}

	/**
	 * Flush de rewrites UNA vez por firma de reglas, cuando ya todo el
	 * mundo (tema incluido) registró las suyas.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrites(): void {
		$signature = self::rules_signature();

		if ( $signature === (string) get_option( self::FLUSH_OPTION ) && ! self::rules_lost() ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::FLUSH_OPTION, $signature, true );
	}

	/**
	 * Valida si las reglas de reescritura activas perdieron los endpoints del programa.
	 *
	 * Permite regenerar dinámicamente las rutas si otra operación del ciclo de vida
	 * reescribió `rewrite_rules` sin incluir las de este módulo.
	 *
	 * @return bool
	 */
	private static function rules_lost(): bool {
		// No ejecuta en peticiones asíncronas ni REST para evitar sobrecarga en endpoints livianos.
		if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		$reglas = get_option( 'rewrite_rules' );
		if ( ! is_array( $reglas ) || array() === $reglas ) {
			return false; // Sin reglas guardadas WordPress las regenera él mismo, con las nuestras.
		}

		// Comprueba reglas de reescritura de raíz y subpáginas del endpoint.
		$faltan = ! isset( $reglas[ '(.?.+?)/' . self::ENDPOINT . '(/(.*))?/?$' ] )
			|| ! isset( $reglas[ self::ENDPOINT . '(/(.*))?/?$' ] )
			|| ( Options::reviews_engine_on() && ! isset( $reglas[ \Akibara\Rewards\Reviews\ReviewForm::RULE ] ) );

		if ( ! $faltan || false !== get_transient( self::HEAL_TRANSIENT ) ) {
			return false;
		}

		set_transient( self::HEAL_TRANSIENT, 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Registra el endpoint en el query-vars de WooCommerce: así
	 * is_wc_endpoint_url('rewards') y el título del endpoint funcionan.
	 *
	 * @param array<string,string> $vars Query vars de WC.
	 * @return array<string,string>
	 */
	public function register_query_var( $vars ): array {
		$vars                    = is_array( $vars ) ? $vars : array();
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Título de la pantalla del endpoint.
	 *
	 * @return string
	 */
	public function endpoint_title(): string {
		return 'Puntos Akibara';
	}

	/**
	 * Ítem del menú de Mi cuenta, antes de «Salir».
	 *
	 * @param array<string,string> $items Ítems actuales.
	 * @return array<string,string>
	 */
	public function menu_item( $items ): array {
		$items = is_array( $items ) ? $items : array();

		$logout = null;
		if ( isset( $items['customer-logout'] ) ) {
			$logout = $items['customer-logout'];
			unset( $items['customer-logout'] );
		}

		$items[ self::ENDPOINT ] = 'Puntos Akibara';

		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}

		return $items;
	}

	/**
	 * Render del endpoint: saldo, canje y últimos movimientos.
	 *
	 * @return void
	 */
	public function render(): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$balance = Ledger::balance( $user_id );

		// Presenta el canje efectivo, limitado por saldo y productos del carrito.
		$cart = function_exists( 'WC' ) && ( WC()->cart ?? null ) instanceof \WC_Cart ? WC()->cart : null; // @phpstan-ignore nullCoalesce.property
		if ( null !== $cart ) {
			$cart->calculate_totals();
		}
		$intent = Redemption::intent_points();
		$limits = Redemption::redemption_limits( $cart, $user_id );
		$vivo   = null !== $cart ? Redemption::effective_points( $cart ) : 0;

		echo '<div class="akb-rw-account">';

		printf(
			'<div class="akb-rw-balance-card">
				<p class="akb-rw-balance-card__label">Tu saldo</p>
				<p class="akb-rw-balance-card__value">%s <span class="akb-rw-balance-card__unit">Puntos Akibara</span></p>
				<p class="akb-rw-balance-card__equiv">Equivalencia potencial: %s (1 punto = %s). El descuento aplicable depende de tu carrito.</p>
			</div>',
			esc_html( number_format_i18n( $balance ) ),
			wp_kses_post( wc_price( $balance * Options::redeem_value() ) ),
			esc_html( Options::redeem_label() )
		);

		$tier = $this->render_tier_progress_card( $user_id );

		$disponible = $limits['available'];
		$usables    = $disponible - max( 0, $balance );
		$apartados  = max( $usables, Redemption::held_in_unpaid_orders( $user_id ) );

		$this->render_account_callouts( $user_id, $apartados, $usables, $intent, $vivo, (float) $limits['value'] );
		$this->render_redeem_form_section( $disponible, $apartados, $intent, $limits );

		$this->render_review_guide();
		$this->render_history( $user_id );

		$this->render_how_it_works_accordion( $tier );

		/**
		 * Bloques extra del endpoint (referidos, futuros niveles).
		 *
		 * @param int $user_id Usuario dueño de la pantalla.
		 */
		do_action( 'akb_rewards_account_extra', $user_id );

		$programa_url = ProgramPage::url();
		if ( '' !== $programa_url ) {
			printf(
				'<p class="akb-rw-program-link"><a href="%s">Cómo funciona el programa →</a></p>',
				esc_url( $programa_url )
			);
		}

		echo '</div>';
	}

	/** Explica el recorrido de invitación sin crear enlaces ni prometer premios. */
	private function render_review_guide(): void {
		if ( ! Options::reviews_lane_on() ) {
			return;
		}
		echo '<section class="akb-rw-review-guide" aria-labelledby="akb-rw-review-guide-title">';
		echo '<h2 id="akb-rw-review-guide-title">Reseñas con Puntos Akibara</h2>';
		echo '<ol class="akb-rw-review-guide__steps">';
		echo '<li><strong>Abre tu invitación</strong><p>Si recibiste una invitación por correo, puedes recuperar su enlace vigente desde Mis pedidos.</p></li>';
		echo '<li><strong>Comparte tu experiencia</strong><p>Escribe tu opinión y adjunta una foto real de tu compra en el formulario de la invitación.</p></li>';
		echo '<li><strong>Espera la revisión</strong><p>Una reseña aprobada que cumpla los requisitos suma el avance o el premio que corresponda.</p></li>';
		echo '</ol>';
		echo '<p>Las reseñas voluntarias en la ficha del producto admiten foto opcional y no generan Puntos Akibara.</p>';
		printf(
			'<details><summary>Ver condiciones y premio vigente</summary><p>%s</p></details><a class="button akb-rw-pending-callout__cta" href="%s">Ver mis pedidos →</a>',
			esc_html( RewardMessage::text( false ) ),
			esc_url( wc_get_account_endpoint_url( 'orders' ) )
		);
		echo '</section>';
	}

	/**
	 * Renderiza la tarjeta de progreso de nivel VIP si los niveles están habilitados.
	 *
	 * @param int $user_id Usuario.
	 * @return array<string,mixed>|null Datos del nivel actual o null si está desactivado.
	 */
	private function render_tier_progress_card( int $user_id ): ?array {
		if ( ! Options::tiers_enabled() ) {
			return null;
		}

		$tier          = Tiers::for_user( $user_id );
		$total_earned  = Ledger::total_earned( $user_id );
		$badge_html    = Tiers::badge_html( $tier['slug'], $tier['name'], $tier['icon'], true );
		$mult_fmt      = Tiers::format_multiplier( (float) $tier['multiplier'], ',' );
		$pct_fmt       = (int) round( ( (float) $tier['multiplier'] - 1.0 ) * 100 );
		$validity_days = Options::tier_validity_days();
		$shop_url      = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : '';

		if ( ! empty( $tier['soft_landed'] ) ) {
			$badge_html    .= ' <span class="akb-tier-badge akb-tier-badge--paused">En pausa · Vigencia vencida</span>';
			$cta_tienda     = '' !== $shop_url
				? sprintf( ' <a class="button akb-rw-tier-card__cta" href="%s">Ir a la tienda →</a>', esc_url( $shop_url ) )
				: '';
			$progreso_texto = sprintf(
				'Tu nivel <strong>%s</strong> venció por inactividad y pasó suavemente a <strong>%s</strong>. Realiza una nueva compra para reactivarlo y renovar tu vigencia.%s',
				esc_html( (string) ( $tier['expired_from'] ?? '' ) ),
				esc_html( $tier['name'] ),
				$cta_tienda
			);
		} elseif ( null !== $tier['next_tier'] ) {
			$next_mult      = Tiers::TIER_LECTOR === $tier['slug']
				? Options::tier_coleccionista_multiplier()
				: Options::tier_residente_multiplier();
			$next_mult_str  = sprintf(
				'%sx (+%d%%)',
				Tiers::format_multiplier( $next_mult, ',' ),
				(int) round( ( $next_mult - 1.0 ) * 100 )
			);
			$progreso_texto = sprintf(
				'Llevas %s puntos ganados históricamente. Te faltan <strong>%s puntos</strong> para ascender a <strong>%s</strong> (y ganar %s en todas tus compras).',
				esc_html( number_format_i18n( $total_earned ) ),
				esc_html( number_format_i18n( $tier['pts_to_next'] ) ),
				esc_html( (string) $tier['next_tier'] ),
				esc_html( $next_mult_str )
			);
		} else {
			$progreso_texto = sprintf(
				'¡Máximo nivel alcanzado! Eres %s con el multiplicador máximo de <strong>%sx (+%d%% de puntos)</strong> en todas tus compras.',
				esc_html( $tier['name'] ),
				esc_html( $mult_fmt ),
				$pct_fmt
			);
		}

		if ( empty( $tier['soft_landed'] ) && ! empty( $tier['expires_at'] ) && (int) $tier['expires_at'] > 0 ) {
			$expiry_dt       = Dates::to_datetime( (int) $tier['expires_at'] );
			$fecha_vigencia  = Dates::format_human( $expiry_dt );
			$progreso_texto .= sprintf(
				' Vigencia de tu nivel hasta el <time datetime="%s"><strong>%s</strong></time> (se renueva con cada compra).',
				esc_attr( $expiry_dt->format( 'Y-m-d' ) ),
				esc_html( $fecha_vigencia )
			);
		} elseif ( empty( $tier['soft_landed'] ) && $validity_days <= 0 && Tiers::TIER_LECTOR !== $tier['slug'] ) {
			$progreso_texto .= ' <strong>Estatus vitalicio:</strong> tu nivel no vence.';
		}

		printf(
			'<div class="akb-rw-tier-card">
				<div class="akb-rw-tier-card__header">
					<div class="akb-rw-tier-card__current">
						<span class="akb-rw-tier-card__eyebrow">Tu nivel en Akibara</span>
						<div class="akb-rw-tier-card__badge-row">%s <span class="akb-rw-tier-card__multiplier">%sx en compras</span></div>
					</div>
					%s
				</div>
				<progress class="akb-rw-tier-card__bar-wrap" value="%d" max="100" aria-label="Progreso de nivel"></progress>
				<p class="akb-rw-tier-card__desc">%s</p>
			</div>',
			$badge_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- construído con Tiers::badge_html y esc_html.
			esc_html( $mult_fmt ),
			null !== $tier['next_tier']
				? sprintf( '<span class="akb-rw-tier-card__next">Siguiente nivel: <strong>%s</strong></span>', esc_html( (string) $tier['next_tier'] ) )
				: '<span class="akb-rw-tier-card__next akb-rw-tier-card__next--max"><span aria-hidden="true">👑</span> Nivel Máximo</span>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado interno.
			(int) $tier['progress_pct'],
			$progreso_texto // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fragmentos escapados con esc_html/esc_url.
		);

		return $tier;
	}

	/**
	 * Renderiza avisos de puntos apartados, bono de bienvenida, reseñas pendientes, vencimiento e intención activa.
	 *
	 * @param int   $user_id   Usuario.
	 * @param int   $apartados Puntos retenidos en pedidos sin pagar.
	 * @param int   $usables   Puntos disponibles por encima del saldo libre.
	 * @param int   $intent    Intención de canje actual.
	 * @param int   $vivo      Puntos efectivos en el carrito actual.
	 * @param float $value     Valor de cada punto en moneda.
	 * @return void
	 */
	private function render_account_callouts( int $user_id, int $apartados, int $usables, int $intent, int $vivo, float $value ): void {
		if ( $apartados > 0 ) {
			$minutos = Redemption::unpaid_cancel_minutes();
			printf(
				'<p class="akb-rw-unpaid-note">Además tienes %s puntos apartados en un pedido sin pagar. %s</p>',
				esc_html( number_format_i18n( $apartados ) ),
				esc_html(
					( $usables >= $apartados
						? 'Su pago no se completó: puedes usarlos al volver a intentar esa compra, y si no la completas'
						: 'Si pagas ese pedido se usan ahí; si no' )
					. ( $minutos > 0
						? sprintf( ', vuelven solos a tu saldo en unos %d minutos.', $minutos )
						: ', vuelven a tu saldo cuando el pedido se anule.' )
				)
			);
		}

		if ( WelcomeBonus::is_pending_for( $user_id ) ) {
			printf(
				'<p class="akb-rw-welcome-callout">🎁 Tienes <strong>%s Puntos Akibara esperándote</strong>: se activan con tu primera compra%s.</p>',
				esc_html( number_format_i18n( Promise::welcome_bonus() ) ),
				Options::first_purchase_min() > 0
					? esc_html( sprintf( ' desde $%s en productos', number_format_i18n( Options::first_purchase_min() ) ) )
					: ''
			);
		}

		if ( Options::reviews_lane_on() ) {
			$rw_summary = ReviewEligibility::summary_for_user( $user_id );
			if ( $rw_summary['pending_count'] > 0 ) {
				$pending_cnt = (int) $rw_summary['pending_count'];
				$pot_points  = (int) $rw_summary['total_potential_points'];
				printf(
					'<div class="akb-rw-pending-callout">
						<div class="akb-rw-pending-callout__body">
							<strong class="akb-rw-pending-callout__title">📸 Tienes %s %s sin reseñar</strong>
							<p class="akb-rw-pending-callout__desc">Sube una foto real de tus tomos comprados%s al aprobarse.</p>
						</div>
						<a class="button akb-rw-pending-callout__cta" href="%s">Ver mis pedidos para reseñar →</a>
					</div>',
					esc_html( number_format_i18n( $pending_cnt ) ),
					esc_html( 1 === $pending_cnt ? 'tomo comprado' : 'tomos comprados' ),
					$pot_points > 0
						? ' y suma hasta <strong>+' . esc_html( number_format_i18n( $pot_points ) ) . ' Puntos Akibara (' . esc_html( Options::points_label( $pot_points ) ) . ')</strong>'
						: ' para compartir tu opinión verificada',
					esc_url( $rw_summary['collection_url'] )
				);
			}
		}

		$next_expiry = Expiry::next_expiry( $user_id );
		if ( null !== $next_expiry ) {
			$expiry_date = Dates::parse_or_null( $next_expiry['date'], 'Y-m-d' ) ?? Dates::to_datetime( $next_expiry['date'] );
			printf(
				'<p class="akb-rw-expiry">%s puntos pueden vencer desde el <time datetime="%s">%s</time> si no los usas.%s</p>',
				esc_html( number_format_i18n( $next_expiry['points'] ) ),
				esc_attr( $next_expiry['date'] ),
				esc_html( Dates::format_human( $expiry_date ) ),
				$next_expiry['pending'] ? ' Ya son elegibles; el vencimiento está pendiente de procesarse.' : ''
			);
		}

		if ( $intent > 0 ) {
			$tope = $vivo < $intent
				? sprintf( ' (tu carrito actual aplica %s puntos por %s de descuento)', esc_html( number_format_i18n( $vivo ) ), wp_kses_post( wc_price( $vivo * $value ) ) )
				: '';

			printf(
				'<p class="akb-rw-active-intent">Tienes un canje guardado de <strong>%s puntos</strong>%s. <a href="%s" class="akb-rw-active-intent__link">Ir al carrito →</a></p>
				<form method="post" class="akb-rw-remove-redeem">%s<input type="hidden" name="akb_rw_action" value="%s"><input type="hidden" name="akb_rw_points" value="0"><button type="submit" class="button akb-rw-remove-redeem__btn">Quitar canje</button></form>',
				esc_html( number_format_i18n( $intent ) ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $tope ya sale escapado de arriba.
				$tope,
				esc_url( wc_get_cart_url() ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() devuelve marcado seguro de core.
				wp_nonce_field( self::REDEEM_ACTION, '_akbnonce', true, false ),
				esc_attr( self::REDEEM_ACTION )
			);
		}
	}

	/**
	 * Renderiza el formulario de canje o el mensaje de saldo vacío.
	 *
	 * @param int                $disponible Puntos disponibles.
	 * @param int                $apartados  Puntos apartados.
	 * @param int                $intent     Intención de canje actual.
	 * @param array<string,mixed> $limits     Límites calculados para el carrito.
	 * @return void
	 */
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
	private function render_redeem_form_section( int $disponible, int $apartados, int $intent, array $limits ): void { // @phpstan-ignore complexity.functionLike
		$minimo_canje = (int) $limits['min_points'];
		$maximum      = (int) $limits['max_points'];
		$eligible     = $maximum > 0;
		if ( $disponible > 0 ) {
			$initial = $eligible ? self::valor_inicial_de_canje( $maximum, $minimo_canje, $intent ) : 0;
			$message = Redemption::unavailable_message( $limits );
			$preview = $eligible
				? sprintf( '%s puntos: %s de descuento aplicable en tu carrito actual.', number_format_i18n( $initial ), wp_strip_all_tags( wc_price( $initial * $limits['value'] ) ) )
				: sprintf( 'Descuento aplicable en tu carrito actual: %s. %s', wp_strip_all_tags( wc_price( 0 ) ), $message );
			$config  = array(
				'value'    => $limits['value'],
				'eligible' => $eligible,
				'decimals' => wc_get_price_decimals(),
				'decimal'  => wc_get_price_decimal_separator(),
				'thousand' => wc_get_price_thousand_separator(),
				'format'   => get_woocommerce_price_format(),
				'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			);
			$rules   = sprintf( 'Mínimo: %s puntos. Máximo aplicable en este carrito: %s puntos.', number_format_i18n( $minimo_canje ), number_format_i18n( $maximum ) );
			if ( Options::redeem_min_cart() > 0 ) {
				$rules .= ' Compra mínima en productos: ' . wp_strip_all_tags( wc_price( Options::redeem_min_cart() ) ) . '.';
			}
			$ceiling = Redemption::techo_humano();
			if ( '' !== $ceiling ) {
				$rules .= ' Tope por compra: ' . $ceiling . '.';
			}
			$rules .= ' Se calcula sobre productos tras cupones, con IVA y sin despacho. Se vuelve a comprobar al confirmar.';

			printf(
				'<form method="post" action="%s" class="akb-rw-redeem" data-akb-redeem="%s">
					%s
					<input type="hidden" name="akb_rw_action" value="%s">
					<label for="akb-rw-points">Puntos a canjear</label>
					<div class="akb-rw-redeem-controls">
						<input id="akb-rw-points" type="number" name="akb_rw_points" min="%d" max="%d" step="1" value="%d" required aria-describedby="akb-rw-preview akb-rw-rules"%s>
						<button type="button" class="button akb-rw-max" hidden%s>Usar el máximo disponible</button>
					</div>
					<p id="akb-rw-preview" role="status" aria-live="polite" aria-atomic="true">%s</p>
					<p id="akb-rw-rules">%s</p>
					<button type="submit" class="button akb-rw-redeem-submit"%s>Confirmar canje en este carrito</button>
				</form>',
				esc_url( wc_get_account_endpoint_url( self::ENDPOINT ) ),
				esc_attr( wp_json_encode( $config ) ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- marcado del nonce generado por core.
				wp_nonce_field( self::REDEEM_ACTION, '_akbnonce', true, false ),
				esc_attr( self::REDEEM_ACTION ),
				$eligible ? (int) $minimo_canje : 0,
				(int) $maximum,
				(int) $initial,
				$eligible ? '' : ' disabled',
				$eligible ? '' : ' disabled',
				esc_html( $preview ),
				esc_html( $rules ),
				$eligible ? '' : ' disabled'
			);
			if ( ! $eligible ) {
				printf( '<p><a class="button" href="%s">Ir al carrito →</a></p>', esc_url( wc_get_cart_url() ) );
			}
		} elseif ( $apartados <= 0 ) {
			// Comparte con la ficha y la caja la promesa vigente del programa.
			$tramo  = Promise::rate();
			$compra = $tramo['points'] > 0 && $tramo['amount'] > 0
				? sprintf( ' se suman solos con cada compra (%s por cada $%s)', number_format_i18n( $tramo['points'] ), number_format_i18n( $tramo['amount'] ) )
				: '';
			$resena = Options::reviews_lane_on() ? ( '' !== $compra ? ' y puedes ganarlos con tus reseñas con foto aprobadas que cumplan los requisitos' : ' puedes ganarlos con tus reseñas con foto aprobadas que cumplan los requisitos' ) : '';
			$frase  = $compra . $resena;
			if ( '' === $frase ) {
				$frase = ' llegan con el programa Puntos Akibara';
			}
			printf( '<p>Todavía no tienes puntos:%s.</p>', esc_html( $frase ) );
		}
	}

	/**
	 * Renderiza el acordeón informativo "¿Cómo funcionan mis puntos?".
	 *
	 * @param array<string,mixed>|null $tier Datos del nivel actual si aplica.
	 * @return void
	 */
	private function render_how_it_works_accordion( ?array $tier ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
		$favoritos_url = function_exists( 'wc_get_account_endpoint_url' ) ? (string) wc_get_account_endpoint_url( 'favoritos' ) : '';
		$series_url    = function_exists( 'home_url' ) ? (string) home_url( '/series/' ) : '/series/';

		// Anuncia únicamente los carriles activos y con beneficio positivo.
		$carriles   = array();
		$tramo_vivo = Promise::rate();
		if ( $tramo_vivo['points'] > 0 && $tramo_vivo['amount'] > 0 ) {
			$carriles[] = sprintf( 'con cada compra (%s por cada $%s)', number_format_i18n( $tramo_vivo['points'] ), number_format_i18n( $tramo_vivo['amount'] ) );
		}
		$bono_vivo = Promise::welcome_bonus();
		if ( $bono_vivo > 0 ) {
			$carriles[] = Options::first_purchase_min() > 0
				? sprintf( 'con tu primera compra desde $%s (bono de %s, uno por persona)', number_format_i18n( Options::first_purchase_min() ), number_format_i18n( $bono_vivo ) )
				: sprintf( 'con tu primera compra (bono de %s, uno por persona)', number_format_i18n( $bono_vivo ) );
		}
		if ( Options::reviews_lane_on() ) {
			$every      = max( 1, Options::review_every_n() );
			$carriles[] = $every > 1
				? sprintf( 'con tus reseñas con foto aprobadas (%s cada %d reseñas), si cumplen los requisitos', number_format_i18n( Options::review_points() ), $every )
				: sprintf( 'con tus reseñas con foto aprobadas (%s), si cumplen los requisitos', number_format_i18n( Options::review_points() ) );
		}
		if ( Promise::referral_points() > 0 ) {
			$carriles[] = sprintf( 'invitando amigos que reciban su primera compra (%s)', number_format_i18n( Promise::referral_points() ) );
		}
		if ( Options::birthday_points() > 0 ) {
			$carriles[] = sprintf( 'en tu cumpleaños (%s)', number_format_i18n( Options::birthday_points() ) );
		}

		$ultimo = array_pop( $carriles );
		$ganas  = null === $ultimo ? '' : ( array() === $carriles ? $ultimo : implode( ', ', $carriles ) . ' y ' . $ultimo );

		$tier_summary_li = '';
		if ( null !== $tier ) {
			$validity_days   = Options::tier_validity_days();
			$regla_niveles   = $validity_days > 0
				? sprintf( 'Vigencia móvil de %d días que se renueva con cada compra.', $validity_days )
				: 'Estatus vitalicio (sin vencimiento).';
			$tier_summary_li = sprintf(
				'<li><strong>Tu nivel:</strong> %s (%sx en compras). %s</li>',
				esc_html( (string) $tier['name'] ),
				esc_html( Tiers::format_multiplier( (float) $tier['multiplier'], ',' ) ),
				esc_html( $regla_niveles )
			);
		}

		$favoritos_link = '' !== $favoritos_url
			? sprintf( '<a href="%s">Ver mis favoritos →</a> · ', esc_url( $favoritos_url ) )
			: '';

		printf(
			'<details class="akb-rw-how-details"><summary class="akb-rw-how-summary">¿Cómo funcionan mis puntos? 📖</summary>
			<ul class="akb-rw-how-list">
				%s%s<li><strong>Gastas</strong> canjeándolos aquí arriba o en el checkout: cada punto es %s de descuento%s — ideal para el siguiente tomo de tu colección.</li>
				%s
			</ul>
			<p class="akb-rw-how-links">%s<a href="%s">Explorar catálogo de series →</a> (marca ✓ Comprado, favoritos y alertas Avísame cuando vuelva).</p></details>',
			'' !== $ganas ? sprintf( '<li><strong>Ganas</strong> %s.</li>', esc_html( $ganas ) ) : '',
			$tier_summary_li, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado interno con esc_html.
			esc_html( Options::redeem_label() ),
			Options::redeem_min_cart() > 0
				? esc_html( sprintf( ' en compras desde $%s en productos', number_format_i18n( Options::redeem_min_cart() ) ) )
				: ' en tu próxima compra',
			Expiry::months() > 0 ? sprintf( '<li><strong>Duran</strong> %d meses: los más antiguos se usan primero.</li>', Expiry::months() ) : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- %d entero.
			$favoritos_link, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- URL escapada con esc_url arriba.
			esc_url( $series_url )
		);
	}

	/**
	 * Historial privado, diez movimientos por página y orden estable.
	 *
	 * @param int $user_id Usuario.
	 * @return void
	 */
	private function render_history( int $user_id ): void {
		global $wpdb;

		// El COUNT privado permite acotar la página ANTES del OFFSET, incluso
		// con una URL enorme. No consulta movimientos de otras cuentas.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', Schema::ledger_table(), $user_id ) );
		if ( $total <= 0 ) {
			echo '<p>Todavía no tienes movimientos de puntos.</p>';
			return;
		}
		$pages = (int) ceil( $total / 10 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET de lectura sin mutación.
		$raw   = isset( $_GET['akb_rw_page'] ) ? sanitize_text_field( wp_unslash( $_GET['akb_rw_page'] ) ) : '1';
		$page  = is_scalar( $raw ) && preg_match( '/^[1-9][0-9]*$/D', (string) $raw ) ? min( $pages, (int) $raw ) : 1;
		$page  = max( 1, $page );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla interno.
				'SELECT delta, note, created_at FROM ' . Schema::ledger_table() . ' WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT 10 OFFSET %d',
				$user_id,
				( $page - 1 ) * 10
			)
		);

		echo '<h3 id="akb-rw-history" class="akb-rw-history-title">Historial de puntos</h3><div class="akb-rw-history-table"><table class="shop_table akb-rw-history-table__grid"><thead><tr><th scope="col">Fecha</th><th scope="col">Motivo</th><th scope="col" class="akb-rw-history-col-num">Puntos</th></tr></thead><tbody>';
		foreach ( (array) $rows as $row ) {
			$delta     = (int) $row->delta;
			$row_dt    = Dates::to_datetime( (string) $row->created_at );
			printf(
				'<tr><td><time datetime="%s">%s</time></td><td>%s</td><td class="akb-rw-history-col-num akb-rw-history-delta %s">%s%s</td></tr>',
				esc_attr( $row_dt->format( 'Y-m-d' ) ),
				esc_html( Dates::format_short( $row_dt ) ),
				esc_html( (string) $row->note ),
				$delta >= 0 ? 'akb-rw-history-delta--pos' : 'akb-rw-history-delta--neg',
				$delta >= 0 ? '+' : '−',
				esc_html( number_format_i18n( abs( $delta ) ) )
			);
		}
		echo '</tbody></table></div>';
		if ( $pages > 1 ) {
			$base = wc_get_account_endpoint_url( self::ENDPOINT );
			echo '<nav class="akb-rw-pages" aria-label="Páginas del historial de puntos">';
			if ( $page > 1 ) {
				printf( '<a class="button" rel="prev" href="%s">← Anterior</a>', esc_url( add_query_arg( 'akb_rw_page', $page - 1, $base ) . '#akb-rw-history' ) );
			}
			printf( '<span aria-current="page">Página %s de %s</span>', esc_html( number_format_i18n( $page ) ), esc_html( number_format_i18n( $pages ) ) );
			if ( $page < $pages ) {
				printf( '<a class="button" rel="next" href="%s">Siguiente →</a>', esc_url( add_query_arg( 'akb_rw_page', $page + 1, $base ) . '#akb-rw-history' ) );
			}
			echo '</nav>';
		}
	}

	/**
	 * Redirige URLs heredadas o alternativas como /mi-cuenta/puntos/ al endpoint oficial /mi-cuenta/rewards/.
	 *
	 * @return void
	 */
	public function maybe_redirect_legacy_endpoint(): void {
		if ( is_admin() || ( function_exists( 'is_404' ) && ! is_404() ) || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$raw_uri  = sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) );
		$raw_path = function_exists( 'wp_parse_url' )
			? (string) wp_parse_url( $raw_uri, PHP_URL_PATH )
			: (string) wp_parse_url( $raw_uri, PHP_URL_PATH );
		$path     = trim( $raw_path, '/' );
		if ( 'mi-cuenta/puntos' !== $path && ! str_ends_with( $path, '/mi-cuenta/puntos' ) ) {
			return;
		}
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ), 301 );
			exit;
		}
	}

	/**
	 * Recibe el formulario de canje: valida y fija los puntos aplicables al carrito.
	 *
	 * @return void
	 */
	public function handle_redeem(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce se verifica dos líneas abajo.
		$accion = isset( $_POST['akb_rw_action'] ) ? sanitize_text_field( wp_unslash( $_POST['akb_rw_action'] ) ) : '';
		if ( self::REDEEM_ACTION !== $accion ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		check_admin_referer( self::REDEEM_ACTION, '_akbnonce' );

		$raw = isset( $_POST['akb_rw_points'] ) ? sanitize_text_field( wp_unslash( $_POST['akb_rw_points'] ) ) : null;
		$this->process_redeem_input( $user_id, $raw );

		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
	}

	/**
	 * Valida la entrada del formulario antes de convertirla en una solicitud.
	 *
	 * @param int   $user_id Usuario.
	 * @param mixed $raw     Entrada sin convertir recibida del formulario.
	 * @return int Puntos efectivamente fijados.
	 */
	public function process_redeem_input( int $user_id, $raw ): int {
		$requested = filter_var( $raw, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		if ( false === $requested ) {
			self::invalid_points_notice();
			return 0;
		}
		return $this->process_redeem( $user_id, $requested );
	}

	/**
	 * Rechazar una entrada inválida conserva el canje anterior.
	 *
	 * @return void
	 */
	private static function invalid_points_notice(): void {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( 'Ingresa un número entero de puntos mayor o igual a cero.', 'error' );
		}
	}

	/**
	 * Núcleo del canje, separado del wrapper (nonce/redirect/exit) para
	 * poder probarlo: vuelve a medir el carrito y el saldo, fija la intención y avisa.
	 *
	 * @param int $user_id   Usuario.
	 * @param int $requested Puntos pedidos.
	 * @return int Puntos efectivamente fijados.
	 */
	public function process_redeem( int $user_id, int $requested ): int {
		if ( $requested < 0 ) {
			self::invalid_points_notice();
			return 0;
		}
		if ( 0 === $requested ) {
			Redemption::set_intent( 0 );
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( 'Canje retirado: tus puntos quedan intactos en tu saldo.', 'notice' );
			}
			return 0;
		}

		$cart = function_exists( 'WC' ) && ( WC()->cart ?? null ) instanceof \WC_Cart ? WC()->cart : null; // @phpstan-ignore nullCoalesce.property
		// Totales de sesión pueden haber quedado anteriores a un precio o cupón.
		// Recalcular fuera del helper evita recursión desde calculate_fees.
		if ( null !== $cart ) {
			$cart->calculate_totals();
		}
		$limits = Redemption::redemption_limits( $cart, $user_id );
		$error  = Redemption::requested_error( $requested, $limits );
		if ( '' !== $error ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $error, 'error' );
			}
			return 0; // Carrito o saldo cambiados: conservar la intención previa.
		}

		Redemption::set_intent( $requested );
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice(
				sprintf( 'Canje confirmado: %s puntos (%s de descuento) en tu carrito actual.', number_format_i18n( $requested ), wp_strip_all_tags( wc_price( $requested * $limits['value'] ) ) ),
				'success'
			);
		}
		return $requested;
	}

}
