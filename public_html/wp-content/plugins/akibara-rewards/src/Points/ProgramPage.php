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

use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\OrderStates;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
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
	 * Registra el shortcode.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'akb_puntos_resumen', array( $this, 'render' ) );
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
			'processing'        => ' Es el momento en que confirmamos tu pago.',
			'shipping-progress' => ' Es cuando ya verificamos tu pago y el pedido queda preparado, aunque todavía no salga.',
			'completed'         => ' Es cuando el pedido queda cerrado.',
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

		return (string) wp_date( 'j \d\e F \d\e Y', $start->getTimestamp() );
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
	public function render( $atts = array() ): string {
		$atts = shortcode_atts( array( 'solo' => '' ), is_array( $atts ) ? $atts : array() );
		if ( 'vencimiento' === $atts['solo'] ) {
			return esc_html( self::expiry_sentence() );
		}
		if ( 'canje' === $atts['solo'] ) {
			return esc_html( self::redeem_sentence() );
		}
		// Fragmentos dinámicos para incrustar en el contenido editorial de la página,
		// garantizando que las cifras se actualicen automáticamente ante cambios de configuración.
		if ( 'valor_punto' === $atts['solo'] ) {
			return esc_html( self::point_value_sentence() );
		}
		if ( 'acreditacion' === $atts['solo'] ) {
			return esc_html( self::award_sentence() );
		}
		if ( 'estado_acreditacion' === $atts['solo'] ) {
			return esc_html( self::award_status_label() );
		}
		if ( 'tasa' === $atts['solo'] ) {
			return esc_html( self::rate_label() );
		}
		if ( 'ratio' === $atts['solo'] ) {
			return esc_html( self::ratio_sentence() );
		}
		if ( 'bienvenida' === $atts['solo'] ) {
			// Con la fecha ilegible no acredita nadie, y el bono cuelga de esa
			// misma acreditación: la cifra suelta sería una promesa sin dueño.
			return esc_html( number_format_i18n( Accrual::PROGRAMA_ILEGIBLE === Accrual::program_state() ? 0 : Options::welcome_bonus() ) );
		}
		// Las dos cifras que gobiernan las reglas nuevas, para que la prosa de
		// la página no las escriba a mano y caduque al primer cambio de panel.
		if ( 'minimo_primera_compra' === $atts['solo'] ) {
			return esc_html( Options::first_purchase_min() > 0 ? '$' . number_format_i18n( Options::first_purchase_min() ) : 'cualquier monto' );
		}
		if ( 'minimo_canje' === $atts['solo'] ) {
			return esc_html( Options::redeem_min_cart() > 0 ? '$' . number_format_i18n( Options::redeem_min_cart() ) : 'cualquier monto' );
		}
		if ( 'desde' === $atts['solo'] ) {
			return esc_html( self::start_label() );
		}
		if ( 'ejemplos' === $atts['solo'] ) {
			return self::examples_table();
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
					' (hasta %s premiadas = $%s)',
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
				$detalles[] = sprintf( 'hasta %s invitados', number_format_i18n( Options::referral_max_per_user() ) );
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
}
