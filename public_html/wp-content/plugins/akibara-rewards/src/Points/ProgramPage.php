<?php
/**
 * ProgramPage — shortcode `[akb_puntos_resumen]` para la página pública «Akibara Puntos».
 *
 * Renderiza la tabla de formas de ganar y la regla de vencimiento leyendo la configuración
 * viva para garantizar que la información pública permanezca siempre sincronizada con el panel.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Points\Tiers;
use Akibara\Rewards\Support\Assets;
use Akibara\Rewards\Support\Dates;
use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\OrderStates;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 * @api
 * @psalm-suppress HookInvalidArgs
 * @psalm-suppress InvalidGlobal
 * @psalm-suppress InvalidOperand
 * @psalm-suppress RedundantCast
 */
// @phpstan-ignore complexity.classLike
final class ProgramPage {

	public const SLUG = 'akibara-puntos';

	/**
	 * Memo por petición de la página pública: array{0:?\WP_Post} una vez
	 * resuelta, null mientras no se haya pedido.
	 *
	 * @var array{0:\WP_Post|null}|null
	 */
	private static ?array $memo = null;

	/**
	 * Registra los shortcodes y hooks de assets de la página del programa.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'akb_puntos_resumen', array( $this, 'render' ) );
		add_shortcode( 'akb_club_puntos', array( $this, 'render_club_landing' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'template_redirect', array( $this, 'redirect_legacy_slug' ) );
	}

	/**
	 * Redirige /puntos/ hacia /akibara-puntos/ con 301 para evitar 404 en accesos directos y campañas.
	 *
	 * @return void
	 */
	public function redirect_legacy_slug(): void {
		if ( ! function_exists( 'is_404' ) || ! is_404() ) {
			return;
		}
		global $wp;
		$request_path = is_object( $wp ) && isset( $wp->request ) ? trim( (string) $wp->request, '/' ) : '';
		if ( 'puntos' !== $request_path ) {
			return;
		}
		$target = self::page();
		if ( $target instanceof \WP_Post ) {
			$url = get_permalink( $target );
			if ( is_string( $url ) && '' !== $url ) {
				wp_safe_redirect( $url, 301 );
				exit;
			}
		}
	}

	/**
	 * Encola los estilos y scripts del Club de Puntos cuando se visita la página del programa.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$should_enqueue = false;

		if ( function_exists( 'is_page' ) && is_page( self::SLUG ) ) {
			$should_enqueue = true;
		} else {
			global $post;
			if ( $post instanceof \WP_Post && ( has_shortcode( (string) $post->post_content, 'akb_club_puntos' ) || has_shortcode( (string) $post->post_content, 'akb_puntos_resumen' ) ) ) {
				$should_enqueue = true;
			}
		}

		if ( ! $should_enqueue ) {
			return;
		}

		wp_enqueue_style(
			'akb-rw-program-page',
			Assets::url( 'assets/css/program-page.css' ),
			array(),
			Assets::version( 'assets/css/program-page.css' )
		);

		wp_enqueue_script(
			'akb-rw-program-page-js',
			Assets::url( 'assets/js/program-page.js' ),
			array(),
			Assets::version( 'assets/js/program-page.js' ),
			true
		);
	}

	/**
	 * La página pública /akibara-puntos/, resuelta UNA vez por petición.
	 *
	 * Vive acá porque esta es su clase dueña: la ficha de producto, Mi cuenta
	 * y el correo de puntos resolvían el slug por su cuenta, y Mi cuenta lo
	 * hacía además en la misma petición que la ficha.
	 *
	 * Solo considera la página cuando está publicada para evitar enlaces a páginas no accesibles.
	 *
	 * @return \WP_Post|null Null si la página no existe o no está publicada.
	 */
	public static function page(): ?\WP_Post {
		if ( null === self::$memo ) {
			$found      = get_page_by_path( self::SLUG );
			self::$memo = array( $found instanceof \WP_Post && 'publish' === $found->post_status ? $found : null );
		}

		return self::$memo[0];
	}

	/**
	 * URL de la página pública ('' si no existe).
	 *
	 * @return string
	 */
	public static function url(): string {
		$page = self::page();

		return $page instanceof \WP_Post ? (string) get_permalink( $page ) : '';
	}

	/**
	 * La frase del canje (mínimo vivo): `solo="canje"`.
	 *
	 * @return string
	 */
	public static function redeem_sentence(): string {
		$min = Options::redeem_min_points();

		// El techo del pedido se dice con los MISMOS números que aplica el
		// motor: `Redemption::techo_humano()` es la única voz de las dos
		// palancas, y si el dueño las pone en 0 la frase vuelve sola a la
		// versión sin tope.
		$limites = Redemption::techo_humano();
		$techo   = '' === $limites
			? ''
			: sprintf( ' En cada pedido puedes pagar con puntos hasta %s.', $limites );

		// El carrito mínimo es la condición requerida para habilitar la interfaz de canje.
		$carrito = Options::redeem_min_cart() > 0
			? sprintf( 'Los puntos se canjean en compras desde $%s en productos. ', number_format_i18n( Options::redeem_min_cart() ) )
			: '';

		// La duda que el cliente trae siempre, y que la página sólo dejaba
		// deducir de «se acreditan al quedar Listo para enviar»: los puntos de
		// una compra NO sirven para esa compra. Se dice sin nombrar el estado
		// que acredita, porque ése es configurable: «después de pagarla» es
		// cierto con cualquiera de los tres estados posibles.
		$mismo_pedido = ' Los puntos que ganas en una compra se acreditan después de pagarla, así que no se pueden usar en esa misma compra.'
			. ' Y lo que pagas con puntos no vuelve a darte puntos: esa parte del pedido no acumula.';

		if ( $min <= 0 ) {
			return $carrito . 'No hay un mínimo de puntos: usas desde 1 hasta el límite de tu saldo y de los productos elegibles.' . $techo . ' El despacho no se paga con puntos.' . $mismo_pedido;
		}

		return $carrito . sprintf(
			'El canje parte desde %s puntos y llega hasta el límite de tu saldo y de los productos elegibles.%s El despacho no se paga con puntos.',
			number_format_i18n( $min ),
			$techo
		) . $mismo_pedido;
	}

	/**
	 * «1 punto = $1», con el valor de canje configurado. `solo="valor_punto"`.
	 *
	 * Utilizado en la franja de garantías y elementos promocionales para asegurar
	 * equivalencia dinámica conforme a la opción configurada.
	 *
	 * @return string
	 */
	public static function point_value_sentence(): string {
		return sprintf( '1 punto = %s', Options::redeem_label() );
	}

	/**
	 * Nombre del estado que acredita, tal como lo ve el cliente en su pedido.
	 * `solo="estado_acreditacion"`.
	 *
	 * @return string
	 */
	public static function award_status_label(): string {
		$estado = Options::award_status();

		return function_exists( 'wc_get_order_status_name' )
			? (string) wc_get_order_status_name( $estado )
			: $estado;
	}

	/**
	 * Momento de acreditación de puntos en palabras. `solo="acreditacion"`.
	 *
	 * Deriva la explicación directamente a partir del estado configurado en el panel.
	 *
	 * @return string
	 */
	public static function award_sentence(): string {
		// Explicación orientada al cliente sobre el significado del estado terminal o de pago.
		$explica = array(
			'processing' => ' Es el momento en que confirmamos tu pago.',
			'completed'  => ' Es cuando el pedido queda cerrado.',
		);
		$estado  = Options::award_status();

		// «O a un estado posterior» aplica para estados de pago; en otros casos
		// OrderStates::is_at_or_after() requiere el estado exacto.
		$o_posterior = in_array( $estado, OrderStates::PAID, true ) ? ', o a un estado posterior' : '';

		return sprintf(
			'Los puntos entran a tu cuenta cuando tu pedido llega a «%s»%s.%s',
			self::award_status_label(),
			$o_posterior,
			$explica[ $estado ] ?? ''
		);
	}

	/**
	 * El porcentaje de vuelta como etiqueta («2%», «2,5%»): puntos por monto,
	 * con el valor del punto en pesos. `solo="tasa"`.
	 *
	 * @return string
	 */
	public static function rate_label(): string {
		$points = Options::earn_points();
		$amount = Options::earn_amount();
		// Una fecha ilegible impide prometer la tasa. Un programa agendado puede
		// mostrarla junto a su fecha mediante `solo="desde"`.
		if ( $points <= 0 || $amount <= 0 || Accrual::PROGRAMA_ILEGIBLE === Accrual::program_state() ) {
			return '0%';
		}

		$pct = ( $points * max( 1, Options::redeem_value() ) * 100 ) / $amount;

		return number_format_i18n( $pct, ( 0.0 === fmod( $pct, 1.0 ) ) ? 0 : 1 ) . '%';
	}

	/**
	 * «2 puntos por cada $100». `solo="ratio"`.
	 *
	 * @return string
	 */
	public static function ratio_sentence(): string {
		if ( Accrual::PROGRAMA_ILEGIBLE === Accrual::program_state() ) {
			return '0 puntos'; // Ver rate_label(): hoy no acredita nadie.
		}

		return sprintf(
			'%s puntos por cada $%s',
			number_format_i18n( Options::earn_points() ),
			number_format_i18n( Options::earn_amount() )
		);
	}

	/**
	 * La fecha de inicio del programa en palabras («17 de agosto de 2026»),
	 * para la prosa. `solo="desde"`.
	 *
	 * @return string
	 */
	public static function start_label(): string {
		// La fecha guardada es hora DEL SITIO, no UTC: interpretarla con
		// strtotime() la corría un día atrás en Chile (medido: «17 de agosto»
		// salía como «16 de agosto»). Mismo criterio que el validador del
		// formulario de ajustes.
		$start = Accrual::parse_program_start( Options::program_start() );
		if ( ! $start instanceof \DateTimeImmutable ) {
			return '';
		}

		return Dates::format_human( $start );
	}

	/**
	 * Tabla de ejemplos calculada desde la configuración viva.
	 * `solo="ejemplos"`. Los montos de ejemplo siguen el ticket real de la
	 * tienda y el umbral de envío gratis; los puntos y su equivalencia salen
	 * de `earn_points`/`earn_amount`/`redeem_value`.
	 *
	 * Con el programa agendado para inicio futuro, la tabla de ejemplos incorpora
	 * de forma autónoma la indicación de fecha de vigencia.
	 *
	 * @return string
	 */
	public static function examples_table(): string {
		$points = Options::earn_points();
		$amount = Options::earn_amount();
		$estado = Accrual::program_state();
		if ( $points <= 0 || $amount <= 0 || Accrual::PROGRAMA_ILEGIBLE === $estado ) {
			return '';
		}

		$value = max( 1, Options::redeem_value() );
		$html  = '<table class="akb-pts-table"><thead><tr><th scope="col">Si tu compra suma</th><th scope="col">Ganas</th><th scope="col">Que equivalen a</th></tr></thead><tbody>';
		foreach ( array( 10000, 19990, 35000, 55000 ) as $total ) {
			$earned = (int) round( ( $total / $amount ) * $points );
			$html  .= sprintf(
				'<tr><td>$%s</td><td>%s puntos</td><td>$%s</td></tr>',
				esc_html( number_format_i18n( $total ) ),
				esc_html( number_format_i18n( $earned ) ),
				esc_html( number_format_i18n( $earned * $value ) )
			);
		}
		$html .= '</tbody></table>';

		if ( Accrual::PROGRAMA_AGENDADO === $estado ) {
			$html .= sprintf( '<p class="akb-pts-note">Esta tabla rige desde el %s.</p>', esc_html( self::start_label() ) );
		}

		return $html;
	}

	public static function expiry_sentence(): string {
		$months = Expiry::months();
		if ( $months <= 0 ) {
			return 'Tus puntos no vencen: tu saldo se queda ahí hasta que decidas usarlo.';
		}

		$aviso = Expiry::warn_days() > 0
			? sprintf( ' Te avisamos por correo %d días antes de que algo venza.', Expiry::warn_days() )
			: '';

		return sprintf(
			'Tus puntos duran %d meses desde que los ganas; se usan primero los más antiguos.%s',
			$months,
			$aviso
		);
	}

	/**
	 * Tabla de formas de ganar + regla de vencimiento, desde la config viva.
	 *
	 * @param array<string,string>|string $atts Atributos del shortcode.
	 * @return string
	 */
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
	public function render( $atts = array() ): string { // @phpstan-ignore complexity.functionLike
		$atts = shortcode_atts(
			array(
				'solo'                 => '',
				'con_resenas_acordeon' => false,
			),
			is_array( $atts ) ? $atts : array(),
			'akb_puntos_resumen'
		);
		$solo_output = $this->render_solo_fragment( (string) $atts['solo'] );
		if ( null !== $solo_output ) {
			return $solo_output;
		}
		$filas = array();

		// Una fecha futura se anuncia; una fecha ilegible omite la promesa.
		$estado            = Accrual::program_state();
		$se_puede_prometer = Accrual::PROGRAMA_ILEGIBLE !== $estado;
		$desde_cuando      = Accrual::PROGRAMA_AGENDADO === $estado ? ', desde el ' . self::start_label() : '';
		if ( Options::earn_points() > 0 && Options::earn_amount() > 0 && $se_puede_prometer ) {
			$regla = sprintf(
				'%s puntos por cada $%s de productos',
				number_format_i18n( Options::earn_points() ),
				number_format_i18n( Options::earn_amount() )
			);
			$filas[] = array( 'Cada compra elegible', $regla . $desde_cuando );
		}

		// Bono y referido comparten la vigencia de la compra: se anuncia la fecha
		// futura y se omite la promesa si la fecha guardada no se puede interpretar.
		if ( Options::welcome_bonus() > 0 && $se_puede_prometer ) {
			$filas[] = array(
				Options::first_purchase_min() > 0
					? sprintf( 'Tu primera compra con cuenta, desde $%s en productos', number_format_i18n( Options::first_purchase_min() ) )
					: 'Tu primera compra con cuenta',
				sprintf(
					'+%s puntos ($%s) de bienvenida (uno por persona)',
					number_format_i18n( Options::welcome_bonus() ),
					number_format_i18n( Options::welcome_bonus() * max( 1, Options::redeem_value() ) )
				) . $desde_cuando,
			);
		}

		if ( Options::reviews_lane_on() ) {
			$every  = max( 1, Options::review_every_n() );
			$premio = $every > 1
				? sprintf( '%s puntos cada %d reseñas', number_format_i18n( Options::review_points() ), $every )
				: sprintf(
					'%s puntos por reseña ($%s)',
					number_format_i18n( Options::review_points() ),
					number_format_i18n( Options::review_points() * max( 1, Options::redeem_value() ) )
				);

			// El tope por cliente estaba sólo en el panel: quien escribía su
			// sexta reseña se enteraba cuando no le llegaron los puntos.
			$tope_resenas = Options::review_max_per_user();
			if ( $tope_resenas > 0 ) {
				$premio .= sprintf(
					' (hasta %s premiadas cada 30 días = $%s)',
					number_format_i18n( $tope_resenas ),
					number_format_i18n( $tope_resenas * Options::review_points() * max( 1, Options::redeem_value() ) )
				);
			}

			$filas[] = array( 'Reseña con foto elegible', $premio );
		}

		if ( Options::referral_points() > 0 && $se_puede_prometer ) {
			// Los dos límites que el cliente sólo descubría al toparse con ellos:
			// cuántos invitados le pagan y cuánto dura la memoria del enlace.
			$detalles = array();
			if ( Options::referral_max_per_user() > 0 ) {
				$detalles[] = sprintf( 'hasta %s invitados en total (vitalicio por cuenta)', number_format_i18n( Options::referral_max_per_user() ) );
			}
			if ( Options::referral_cookie_days() > 0 ) {
				$detalles[] = sprintf( 'el enlace recuerda %s días', number_format_i18n( Options::referral_cookie_days() ) );
			}

			$filas[] = array(
				'Invitar a un amigo: cuando recibe su primera compra',
				sprintf(
					'%s puntos por invitado ($%s)',
					number_format_i18n( Options::referral_points() ),
					number_format_i18n( Options::referral_points() * max( 1, Options::redeem_value() ) )
				)
					. ( array() === $detalles ? '' : ' (' . implode( '; ', $detalles ) . ')' )
					. $desde_cuando,
			);
		}

		if ( Options::birthday_points() > 0 ) {
			$filas[] = array(
				'Tu cumpleaños',
				sprintf(
					'%s puntos cada año ($%s)',
					number_format_i18n( Options::birthday_points() ),
					number_format_i18n( Options::birthday_points() * max( 1, Options::redeem_value() ) )
				),
			);
		}

		$html = '<div class="akb-pts-tablewrap"><table class="akb-pts-table">'
			. '<thead><tr><th scope="col">Cómo</th><th scope="col">Ganas</th></tr></thead><tbody>';
		foreach ( $filas as $fila ) {
			$html .= sprintf( '<tr><td>%s</td><td>%s</td></tr>', esc_html( $fila[0] ), esc_html( $fila[1] ) );
		}
		$html .= '</tbody></table></div>';

		if ( Options::reviews_lane_on() && ! empty( $atts['con_resenas_acordeon'] ) ) {
			$html .= '<details><summary>Condiciones de los puntos por reseñas</summary><p>'
				. esc_html( 'En el formulario de invitación puedes elegir si solicitas puntos y adjuntar una foto. También puedes opinar sin solicitarlos. ' . \Akibara\Rewards\Reviews\RewardMessage::text( false ) )
				. '</p></details>';
		}

		$html .= sprintf( '<p class="akb-pts-note">%s</p>', esc_html( self::expiry_sentence() ) );

		return $html;
	}

	/**
	 * Renderiza la experiencia completa, interactiva y 100% dinámica del Club de Puntos.
	 *
	 * Inspirado en la simpleza, claridad y dinamismo de damemimanga.cl, adaptado a la
	 * identidad visual Neo-Brutalista de Akibara («Tu rincón de Akihabara en Chile»).
	 * Todos los datos, tasas, bonos y reglas se obtienen en tiempo real de la configuración viva.
	 *
	 * @return string HTML completo de la landing del Club de Puntos.
	 */
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
	public function render_club_landing(): string { // @phpstan-ignore complexity.functionLike
		$rate               = Promise::rate();
		$earn_points        = (int) $rate['points'];
		$earn_amount        = (int) $rate['amount'];
		$redeem_val         = max( 1, Options::redeem_value() );
		$redeem_label       = Options::redeem_label();
		$rate_label         = self::rate_label();
		$welcome_bonus      = Promise::welcome_bonus();
		$first_purchase_min = Options::first_purchase_min();
		$review_points      = Options::review_points();
		$review_every_n     = max( 1, Options::review_every_n() );
		$review_max         = Options::review_max_per_user();
		$birthday_points    = Options::birthday_points();
		$referral_points    = Promise::referral_points();
		$referral_max       = Options::referral_max_per_user();
		$ref_guest_discount = max( 0, (int) get_option( 'akb_referral_guest_discount_amount', Referrals::AKB_REF_AMOUNT ) );
		$ref_guest_min      = max( 0, (int) get_option( 'akb_referral_min_cart_amount', (int) get_option( 'akb_rewards_referral_min_order', 30000 ) ) );
		$escrow_days        = Options::escrow_days();
		$tiers_enabled      = Options::tiers_enabled();
		$tier_validity_days = Options::tier_validity_days();
		$tiers              = $tiers_enabled && class_exists( Tiers::class ) ? Tiers::get_all_tiers_ascending() : array();
		$rewards_url        = function_exists( 'wc_get_account_endpoint_url' )
			? (string) wc_get_account_endpoint_url( AccountRewards::ENDPOINT )
			: ( function_exists( 'home_url' ) ? home_url( '/mi-cuenta/rewards/' ) : '/mi-cuenta/rewards/' );
		$shop_raw           = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : '';
		$shop_url           = '' !== $shop_raw
			? $shop_raw
			: ( function_exists( 'home_url' ) ? home_url( '/tienda/' ) : '/tienda/' );

		// Monto de simulación inicial en la calculadora ($35.000)
		$default_calc_amount = 35000;
		$default_calc_pts    = ( $earn_points > 0 && $earn_amount > 0 )
			? (int) round( ( $default_calc_amount / $earn_amount ) * $earn_points )
			: 0;
		$default_calc_clp    = $default_calc_pts * $redeem_val;

		ob_start();
		?>
		<div class="akb-club-wrap">
			<!-- 1. HERO SECTION -->
			<header class="akb-club-hero">
				<div class="akb-club-hero__badge">✦ Club de Puntos Akibara ✦</div>
				<h1 class="akb-club-hero__title">
					Cada compra te acerca a tu <span>próximo descuento</span>
				</h1>
				<p class="akb-club-hero__desc">
					Acumula puntos automáticamente en cada pedido, reseña y cumpleaños. Úsalos como dinero real directamente en el checkout sin códigos ni enredos.
				</p>
				<div class="akb-club-hero__pills">
					<div class="akb-club-pill">
						<span class="akb-club-pill__icon">⚡</span>
						<span>1 punto = <?php echo esc_html( $redeem_label ); ?> de descuento</span>
					</div>
					<div class="akb-club-pill">
						<span class="akb-club-pill__icon" aria-hidden="true">📦</span>
						<span>Envío Gratis protegido sobre $<?php echo esc_html( number_format_i18n( max( 0, (int) get_option( 'akibara_fsp_carrier_threshold', 60000 ) ) ) ); ?></span>
					</div>
					<div class="akb-club-pill">
						<span class="akb-club-pill__icon">🔒</span>
						<span>Acreditación 100% automática</span>
					</div>
				</div>
			</header>

			<!-- 2. CALCULADORA INTERACTIVA DINÁMICA -->
			<section class="akb-club-calc-section">
				<div class="akb-club-section-header">
					<div class="akb-club-section-label">Simulador de Recompensas</div>
					<h2 class="akb-club-section-title">¿Cuánto ganas en tu próxima compra?</h2>
					<p class="akb-club-section-desc">
						Mueve la barra para simular tu compra y ver cuántos puntos y dinero de descuento acumulas automáticamente.
					</p>
				</div>

				<div class="akb-calc-card" id="akb-club-calc" data-earn-amount="<?php echo (int) $earn_amount; ?>" data-earn-points="<?php echo (int) $earn_points; ?>" data-redeem-value="<?php echo (int) $redeem_val; ?>">
					<div class="akb-calc-slider-wrap">
						<div class="akb-calc-slider-header">
							<label for="akb-calc-range-input" class="akb-calc-slider-label">Monto de tu compra estimada:</label>
							<span class="akb-calc-amount-display" id="akb-calc-display">$<?php echo esc_html( number_format_i18n( $default_calc_amount ) ); ?></span>
						</div>
						<input id="akb-calc-range-input" type="range" class="akb-calc-range" min="10000" max="150000" step="5000" value="<?php echo (int) $default_calc_amount; ?>" aria-label="Monto de compra para calcular puntos" />
						<div class="akb-calc-range-marks">
							<span>$10.000</span>
							<span>$50.000</span>
							<span>$100.000</span>
							<span>$150.000</span>
						</div>
					</div>

					<div class="akb-calc-results">
						<div class="akb-calc-res-box">
							<span class="akb-calc-res-icon">🎁</span>
							<span class="akb-calc-res-value akb-calc-res-value--yellow" id="akb-calc-points-val"><?php echo esc_html( number_format_i18n( $default_calc_pts ) ); ?></span>
							<span class="akb-calc-res-label">Puntos que acumulas</span>
						</div>
						<div class="akb-calc-res-box">
							<span class="akb-calc-res-icon">💰</span>
							<span class="akb-calc-res-value akb-calc-res-value--red" id="akb-calc-discount-val">$<?php echo esc_html( number_format_i18n( $default_calc_clp ) ); ?></span>
							<span class="akb-calc-res-label">Descuento equivalente (CLP)</span>
						</div>
						<div class="akb-calc-res-box">
							<span class="akb-calc-res-icon">⚡</span>
							<span class="akb-calc-res-value"><?php echo esc_html( $rate_label ); ?></span>
							<span class="akb-calc-res-label">Tasa de ahorro garantizada</span>
						</div>
					</div>

					<p class="akb-calc-note">
						* <?php echo esc_html( self::award_sentence() ); ?> El costo de envío no genera puntos ni se paga con puntos.
					</p>
				</div>
			</section>

			<!-- 3. FORMAS DE GANAR PUNTOS -->
			<section class="akb-club-ways-section">
				<div class="akb-club-section-header">
					<div class="akb-club-section-label">Formas de ganar</div>
					<h2 class="akb-club-section-title">Más de una forma de sumar descuentos</h2>
					<p class="akb-club-section-desc">
						Premiamos tus compras, tus fotos y tu fidelidad como lector con puntos reales.
					</p>
				</div>

				<div class="akb-ways-grid">
					<?php if ( $earn_points > 0 && $earn_amount > 0 ) : ?>
						<!-- Compra -->
						<div class="akb-way-card">
							<div>
								<div class="akb-way-header">
									<span class="akb-way-icon">🛍️</span>
									<h3 class="akb-way-title">Por cada compra</h3>
								</div>
								<div class="akb-way-reward"><?php echo esc_html( self::ratio_sentence() ); ?></div>
								<p class="akb-way-desc">
									Cada vez que adquieres mangas o cómics en Akibara sumas puntos automáticos para tu próximo pedido.
								</p>
							</div>
							<span class="akb-way-pill"><?php echo esc_html( $rate_label ); ?> de vuelta</span>
						</div>
					<?php endif; ?>

					<!-- Reseñas -->
					<?php if ( Options::reviews_lane_on() ) : ?>
						<div class="akb-way-card">
							<div>
								<div class="akb-way-header">
									<span class="akb-way-icon" aria-hidden="true">📸</span>
									<h3 class="akb-way-title">Reseña con foto real</h3>
								</div>
								<div class="akb-way-reward">+<?php echo esc_html( number_format_i18n( $review_points ) ); ?> pts ($<?php echo esc_html( number_format_i18n( $review_points * $redeem_val ) ); ?> CLP)</div>
								<p class="akb-way-desc">
									Comparte tu foto real del tomo desde la invitación que llega tras tu compra verificada. <?php echo $review_every_n > 1 ? esc_html( sprintf( 'Premiamos 1 de cada %d reseñas con foto aprobadas', $review_every_n ) ) : 'Premiamos tus reseñas con foto aprobadas'; ?><?php echo $review_max > 0 ? esc_html( sprintf( ' (hasta %s cada 30 días)', number_format_i18n( $review_max ) ) ) : ''; ?>.
								</p>
							</div>
							<span class="akb-way-pill">Compra verificada</span>
						</div>
					<?php endif; ?>

					<!-- Cumpleaños -->
					<?php if ( $birthday_points > 0 ) : ?>
						<div class="akb-way-card">
							<div>
								<div class="akb-way-header">
									<span class="akb-way-icon">🎂</span>
									<h3 class="akb-way-title">Tu cumpleaños</h3>
								</div>
								<div class="akb-way-reward">+<?php echo esc_html( number_format_i18n( $birthday_points ) ); ?> pts ($<?php echo esc_html( number_format_i18n( $birthday_points * $redeem_val ) ); ?> CLP)</div>
								<p class="akb-way-desc">
									Registra tu fecha de nacimiento en Mi Cuenta y celebra tu día con puntos de regalo para añadir nuevos tomos a tu estantería.
								</p>
							</div>
							<span class="akb-way-pill">Una vez al año</span>
						</div>
					<?php endif; ?>

					<!-- Bienvenida -->
					<?php if ( $welcome_bonus > 0 ) : ?>
						<div class="akb-way-card">
							<div>
								<div class="akb-way-header">
									<span class="akb-way-icon">👤</span>
									<h3 class="akb-way-title">Bono de bienvenida</h3>
								</div>
								<div class="akb-way-reward">+<?php echo esc_html( number_format_i18n( $welcome_bonus ) ); ?> pts ($<?php echo esc_html( number_format_i18n( $welcome_bonus * $redeem_val ) ); ?> CLP)</div>
								<p class="akb-way-desc">
									Al completar tu primera compra con cuenta<?php echo $first_purchase_min > 0 ? esc_html( sprintf( ' desde $%s en productos', number_format_i18n( $first_purchase_min ) ) ) : ''; ?>, recibes este bono directo.
								</p>
							</div>
							<span class="akb-way-pill">Primer pedido</span>
						</div>
					<?php endif; ?>

					<!-- Referidos -->
					<?php if ( $referral_points > 0 ) : ?>
						<div class="akb-way-card">
							<div>
								<div class="akb-way-header">
									<span class="akb-way-icon" aria-hidden="true">👥</span>
									<h3 class="akb-way-title">Invita a tus amigos</h3>
								</div>
								<div class="akb-way-reward">+<?php echo esc_html( number_format_i18n( $referral_points ) ); ?> pts ($<?php echo esc_html( number_format_i18n( $referral_points * $redeem_val ) ); ?> CLP)</div>
								<p class="akb-way-desc">
									<?php if ( $ref_guest_discount > 0 ) : ?>
										Comparte tu enlace personal desde Mi Cuenta. Tu invitado obtiene $<?php echo esc_html( number_format_i18n( $ref_guest_discount ) ); ?> de descuento en su primera compra<?php echo $ref_guest_min > 0 ? esc_html( sprintf( ' desde $%s en productos a precio normal', number_format_i18n( $ref_guest_min ) ) ) : ''; ?> y tú recibes tus puntos al completarse<?php echo $referral_max > 0 ? esc_html( sprintf( ' (vitalicio por cuenta: hasta %s invitados en total)', number_format_i18n( $referral_max ) ) ) : ''; ?>.
									<?php else : ?>
										Comparte tu enlace personal desde Mi Cuenta. Cuando tu invitado complete su primera compra, sumas este premio<?php echo $referral_max > 0 ? esc_html( sprintf( ' (vitalicio por cuenta: hasta %s invitados en total)', number_format_i18n( $referral_max ) ) ) : ''; ?>.
									<?php endif; ?>
								</p>
							</div>
							<span class="akb-way-pill"><?php echo $ref_guest_discount > 0 ? 'Ganas tú y tu amigo' : 'Al completarse su pedido'; ?></span>
						</div>
					<?php endif; ?>
				</div>
			</section>

			<!-- 4. NIVELES DE COLECCIONISTA (TIERS) -->
			<?php $this->render_club_tiers_section( $tiers, $tier_validity_days ); ?>

			<!-- 5. CÓMO CANJEAR EN 3 PASOS -->
			<section class="akb-club-steps-section">
				<div class="akb-club-section-header">
					<div class="akb-club-section-label">Proceso simple</div>
					<h2 class="akb-club-section-title">¿Cómo canjear tus puntos?</h2>
					<p class="akb-club-section-desc">
						Sin cupones difíciles ni condiciones ocultas. Canjeas en 3 sencillos pasos.
					</p>
				</div>

				<div class="akb-steps-grid">
					<div class="akb-step-card">
						<span class="akb-step-num">1</span>
						<h3 class="akb-step-title">Elige tus mangas</h3>
						<p class="akb-step-desc">
							Agrega tus tomos favoritos al carrito desde la tienda o las páginas de cada serie (con marcas de tomos ya comprados, favoritos y avisos de reposición).
						</p>
					</div>
					<div class="akb-step-card">
						<span class="akb-step-num">2</span>
						<h3 class="akb-step-title">Activa tus puntos</h3>
						<p class="akb-step-desc">
							En Mi Cuenta (Puntos Akibara) o directo en el checkout, elige cuántos puntos usar y confirma el canje para aplicar tu descuento.
						</p>
					</div>
					<div class="akb-step-card">
						<span class="akb-step-num">3</span>
						<h3 class="akb-step-title">Descuento al instante</h3>
						<p class="akb-step-desc">
							El total se reduce de inmediato (1 punto = <?php echo esc_html( $redeem_label ); ?>) manteniendo intacto tu beneficio de Envío Gratis.
						</p>
					</div>
				</div>
			</section>

			<!-- 6. LO QUE DEBES SABER (FAQ / CONDICIONES) -->
			<section class="akb-club-faq-section">
				<div class="akb-club-section-header">
					<div class="akb-club-section-label">Transparencia</div>
					<h2 class="akb-club-section-title">Lo que debes saber</h2>
					<p class="akb-club-section-desc">
						Reglas claras y sin letra chica para que aproveches al máximo tus recompensas.
					</p>
				</div>

				<div class="akb-faq-list">
					<details class="akb-faq-item" open>
						<summary class="akb-faq-summary">
							<span>¿Cuánto vale cada punto y cómo se descuenta?</span>
							<span class="akb-faq-icon">+</span>
						</summary>
						<div class="akb-faq-body">
							<p>
								<strong>1 punto = <?php echo esc_html( $redeem_label ); ?> de descuento real.</strong> <?php echo esc_html( self::redeem_sentence() ); ?>
							</p>
						</div>
					</details>

					<details class="akb-faq-item">
						<summary class="akb-faq-summary">
							<span>¿Cuándo se acreditan los puntos en mi cuenta?</span>
							<span class="akb-faq-icon">+</span>
						</summary>
						<div class="akb-faq-body">
							<p>
								<?php echo esc_html( self::award_sentence() ); ?>
								<?php if ( $escrow_days > 0 ) : ?>
									Para compras habituales, se aplica un periodo de carencia de <?php echo esc_html( number_format_i18n( $escrow_days ) ); ?> días tras completarse el pedido antes de liberar los puntos a tu saldo disponible.
								<?php endif; ?>
							</p>
						</div>
					</details>

					<?php if ( $tiers_enabled ) : ?>
						<details class="akb-faq-item">
							<summary class="akb-faq-summary">
								<span>¿Cuánto dura mi nivel y cómo se mantiene?</span>
								<span class="akb-faq-icon">+</span>
							</summary>
							<div class="akb-faq-body">
								<p>
									<?php if ( $tier_validity_days > 0 ) : ?>
										Cada compra completada y acreditada renueva tu nivel por <strong><?php echo esc_html( number_format_i18n( $tier_validity_days ) ); ?> días</strong> (<?php echo 365 === $tier_validity_days ? '1 año estándar' : 'ventana móvil'; ?>). Si pasa ese plazo sin nuevas compras, aplicamos descenso gradual de un solo nivel (Soft-Landing) y lo reactivas comprando nuevamente.
									<?php else : ?>
										Tu nivel es vitalicio: nunca pierdes tu multiplicador por inactividad.
									<?php endif; ?>
								</p>
							</div>
						</details>
					<?php endif; ?>

					<details class="akb-faq-item">
						<summary class="akb-faq-summary">
							<span>¿Tienen fecha de vencimiento mis puntos?</span>
							<span class="akb-faq-icon">+</span>
						</summary>
						<div class="akb-faq-body">
							<p>
								<?php echo esc_html( self::expiry_sentence() ); ?> Además, el sistema utiliza el método FIFO (First In, First Out), por lo que siempre se descuentan automáticamente tus puntos más antiguos primero para proteger tu saldo.
							</p>
						</div>
					</details>

					<details class="akb-faq-item">
						<summary class="akb-faq-summary">
							<span>¿Pierdo mi Envío Gratis si canjeo puntos?</span>
							<span class="akb-faq-icon">+</span>
						</summary>
						<div class="akb-faq-body">
							<p>
								<strong>¡No, tu Envío Gratis está 100% protegido!</strong> Si tu compra suma $<?php echo esc_html( number_format_i18n( max( 0, (int) get_option( 'akibara_fsp_carrier_threshold', 60000 ) ) ) ); ?> o más en productos, conservas el Envío Gratis a todo Chile aunque apliques puntos y tu total a pagar baje de esa cifra. El beneficio de envío se evalúa sobre los productos antes del canje.
							</p>
						</div>
					</details>

					<details class="akb-faq-item">
						<summary class="akb-faq-summary">
							<span>¿Qué ocurre si cancelo o devuelvo un pedido?</span>
							<span class="akb-faq-icon">+</span>
						</summary>
						<div class="akb-faq-body">
							<p>
								Si un pedido es cancelado o devuelto, los puntos otorgados por esa compra se descuentan del saldo, y los puntos que hayas canjeado para pagar se devuelven íntegramente a tu cuenta en proporción exacta.
							</p>
						</div>
					</details>
				</div>
			</section>

			<!-- 7. CTA FINAL -->
			<section class="akb-club-cta-section">
				<h2 class="akb-club-cta-title">¿Listo para coleccionar y ahorrar?</h2>
				<p class="akb-club-cta-desc">
					Revisa tu saldo acumulado en Mi Cuenta o encuentra los próximos tomos de tus series favoritas.
				</p>
				<div class="akb-club-cta-btns">
					<a href="<?php echo esc_url( $rewards_url ); ?>" class="akb-club-btn akb-club-btn--primary">Ver mis puntos en Mi Cuenta</a>
					<a href="<?php echo esc_url( $shop_url ); ?>" class="akb-club-btn akb-club-btn--ghost">Explorar catálogo de manga</a>
				</div>
			</section>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Resuelve el fragmento individual solicitado mediante el atributo `solo`.
	 *
	 * @param string $solo Valor del atributo `solo`.
	 * @return string|null Fragmento renderizado, o null si no aplica.
	 */
	private function render_solo_fragment( string $solo ): ?string {
		if ( '' === $solo ) {
			return null;
		}
		if ( 'club' === $solo ) {
			return $this->render_club_landing();
		}
		if ( 'ejemplos' === $solo ) {
			return self::examples_table();
		}

		$callbacks = array(
			'vencimiento'           => array( self::class, 'expiry_sentence' ),
			'canje'                 => array( self::class, 'redeem_sentence' ),
			'valor_punto'           => array( self::class, 'point_value_sentence' ),
			'acreditacion'          => array( self::class, 'award_sentence' ),
			'estado_acreditacion'   => array( self::class, 'award_status_label' ),
			'tasa'                  => array( self::class, 'rate_label' ),
			'ratio'                 => array( self::class, 'ratio_sentence' ),
			'desde'                 => array( self::class, 'start_label' ),
		);
		if ( isset( $callbacks[ $solo ] ) ) {
			return esc_html( call_user_func( $callbacks[ $solo ] ) );
		}
		if ( 'bienvenida' === $solo ) {
			return esc_html( number_format_i18n( Accrual::PROGRAMA_ILEGIBLE === Accrual::program_state() ? 0 : Options::welcome_bonus() ) );
		}
		if ( 'minimo_primera_compra' === $solo ) {
			return esc_html( Options::first_purchase_min() > 0 ? '$' . number_format_i18n( Options::first_purchase_min() ) : 'cualquier monto' );
		}
		if ( 'minimo_canje' === $solo ) {
			return esc_html( Options::redeem_min_cart() > 0 ? '$' . number_format_i18n( Options::redeem_min_cart() ) : 'cualquier monto' );
		}
		return null;
	}

	/**
	 * Renderiza la sección de niveles de coleccionista dentro de la landing del Club.
	 *
	 * @param array<int, array<string, mixed>> $tiers              Lista de niveles.
	 * @param int                              $tier_validity_days Días de vigencia móvil.
	 * @return void
	 */
	private function render_club_tiers_section( array $tiers, int $tier_validity_days ): void {
		if ( empty( $tiers ) ) {
			return;
		}
		?>
		<section class="akb-club-tiers-section">
			<div class="akb-club-section-header">
				<div class="akb-club-section-label">Gamificación</div>
				<h2 class="akb-club-section-title">Niveles de Coleccionista</h2>
				<p class="akb-club-section-desc">
					Tus puntos acumulados históricos te hacen subir de nivel y multiplicar tus puntos ganados en cada compra.
				</p>
			</div>

			<div class="akb-tiers-grid">
				<?php foreach ( $tiers as $t ) : ?>
					<div class="akb-tier-card <?php echo ( ( $t['slug'] ?? '' ) === Tiers::TIER_RESIDENTE ) ? 'akb-tier-card--highlight' : ''; ?>">
						<span class="akb-tier-icon" aria-hidden="true"><?php echo esc_html( $t['icon'] ); ?></span>
						<h3 class="akb-tier-name"><?php echo esc_html( $t['name'] ); ?></h3>
						<div class="akb-tier-threshold">
							<?php if ( $t['min'] <= 0 ) : ?>
								Nivel inicial (0 a <?php echo esc_html( number_format_i18n( (int) ( $t['max_pts'] ?? max( 0, Options::tier_coleccionista_min() - 1 ) ) ) ); ?> pts)
							<?php elseif ( null !== ( $t['max_pts'] ?? null ) ) : ?>
								Desde <?php echo esc_html( number_format_i18n( (int) $t['min'] ) ); ?> a <?php echo esc_html( number_format_i18n( (int) $t['max_pts'] ) ); ?> pts
							<?php else : ?>
								Desde <?php echo esc_html( number_format_i18n( (int) $t['min'] ) ); ?> pts acumulados
							<?php endif; ?>
						</div>
						<span class="akb-tier-multiplier">
							Multiplicador <?php echo esc_html( Tiers::format_multiplier( (float) $t['multiplier'], ',' ) ); ?>x
							<?php if ( (float) $t['multiplier'] > 1.0 ) : ?>
								(+<?php echo esc_html( (string) (int) round( ( (float) $t['multiplier'] - 1.0 ) * 100 ) ); ?>% pts)
							<?php endif; ?>
						</span>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="akb-calc-note">
				<?php if ( $tier_validity_days > 0 ) : ?>
					Tu nivel dura <strong><?php echo esc_html( number_format_i18n( $tier_validity_days ) ); ?> días</strong> (<?php echo 365 === $tier_validity_days ? '1 año estándar · ' : ''; ?>vigencia móvil renovada con cada compra). Si vence sin compras nuevas, bajas suavemente un nivel (Soft-Landing) sin perder tu histórico hasta volver a comprar.
				<?php else : ?>
					Tus niveles son <strong>vitalicios</strong>: una vez alcanzados por puntos históricos acumulados, no vencen.
				<?php endif; ?>
			</p>
		</section>
		<?php
	}
}
