<?php
/**
 * AccountRewards — el endpoint `rewards` de Mi cuenta: saldo, historial y
 * canje (Fase 1b).
 *
 * Mismo slug que registraba SPAR a propósito: el dashboard de business y el
 * menú móvil (tercera posición, medida en 375px) ya lo enlazan — al cutover
 * nada queda en 404 (spec 37). El shim `spar_get_user_points()` mantiene
 * viva la tarjeta del dashboard sin tocar business.
 *
 * El canje se FIJA aquí (intención en sesión) y el fee aparece en el
 * carrito/checkout: el cliente ve el descuento antes de pagar. UI liviana:
 * HTML del servidor, ayuda progresiva de canje, targets 44px, es-CL.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Assets;
use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\Schema;
use Akibara\Rewards\Points\Expiry;
use Akibara\Rewards\Points\ProgramPage;
use Akibara\Rewards\Points\WelcomeBonus;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class AccountRewards {

	public const ENDPOINT      = 'rewards';
	public const REDEEM_ACTION = 'akb_rewards_redeem';
	/** Pública: la migración de autoload de Support\Schema la nombra. */
	public const FLUSH_OPTION   = 'akb_rewards_rewrite_version';

	/** Una reparación de reglas perdidas por hora, como mucho. */
	private const HEAL_TRANSIENT = 'akb_rewards_rewrite_heal';
	private const FLUSH_VERSION = '2';

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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// El shim global spar_get_user_points se declara en akibara-rewards.php.
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
		return (int) min( $disponible, max( $minimo_canje, $intent ) );
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
		$cart = function_exists( 'WC' ) && ( WC()->cart ?? null ) instanceof \WC_Cart ? WC()->cart : null;
		if ( null !== $cart ) {
			$cart->calculate_totals();
		}
		$intent = Redemption::intent_points();
		$limits = Redemption::redemption_limits( $cart, $user_id );
		$vivo   = null !== $cart ? Redemption::effective_points( $cart ) : 0;

		echo '<div class="akb-rw-account" style="--akb-acc:var(--aki-red,#E60012)">';

		printf(
			'<p style="font-size:15px;margin:0 0 4px">Tu saldo</p>
			<p style="font-size:34px;font-weight:800;margin:0 0 2px">%s <span style="font-size:16px;font-weight:600">Puntos Akibara</span></p>
			<p style="margin:0 0 8px;opacity:.8">Equivalencia potencial: %s (1 punto = %s). El descuento aplicable depende de tu carrito.</p>',
			esc_html( number_format_i18n( $balance ) ),
			wp_kses_post( wc_price( $balance * Options::redeem_value() ) ),
			esc_html( Options::redeem_label() )
		);

		// Notificación de puntos apartados en pedidos pendientes de pago.
		$disponible = $limits['available'];
		$usables    = $disponible - max( 0, $balance );
		$apartados  = max( $usables, Redemption::held_in_unpaid_orders( $user_id ) );
		if ( $apartados > 0 ) {
			$minutos = Redemption::unpaid_cancel_minutes();
			printf(
				'<p style="margin:0 0 8px;opacity:.85">Además tienes %s puntos apartados en un pedido sin pagar. %s</p>',
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
				'<p style="margin:0 0 8px;padding:10px 12px;border-left:3px solid #E60012;background:rgba(230,0,18,.08)">🎁 Tienes <strong>%s Puntos Akibara esperándote</strong>: se activan con tu primera compra%s.</p>',
				esc_html( number_format_i18n( Promise::welcome_bonus() ) ),
				Options::first_purchase_min() > 0
					? esc_html( sprintf( ' desde $%s en productos', number_format_i18n( Options::first_purchase_min() ) ) )
					: ''
			);
		}

		$next_expiry = Expiry::next_expiry( $user_id );
		if ( null !== $next_expiry ) {
			$expiry_date = new \DateTimeImmutable( $next_expiry['date'], wp_timezone() );
			printf(
				'<p class="akb-rw-expiry">%s puntos pueden vencer desde el <time datetime="%s">%s</time> si no los usas.%s</p>',
				esc_html( number_format_i18n( $next_expiry['points'] ) ),
				esc_attr( $next_expiry['date'] ),
				esc_html( wp_date( 'j \d\e F \d\e Y', $expiry_date->getTimestamp(), wp_timezone() ) ),
				$next_expiry['pending'] ? ' Ya son elegibles; el vencimiento está pendiente de procesarse.' : ''
			);
		}

		if ( $intent > 0 ) {
			$tope = $vivo < $intent
				? sprintf( ' (tu carrito actual aplica %s puntos por %s de descuento)', esc_html( number_format_i18n( $vivo ) ), wp_kses_post( wc_price( $vivo * $limits['value'] ) ) )
				: '';

			printf(
				'<p style="background:#f0f6e8;border-left:3px solid #46b450;padding:10px 14px;border-radius:4px;color:#0d0d0d">Tienes un canje guardado de <strong>%s puntos</strong>%s. <a href="%s" style="color:#0d0d0d;text-decoration:underline">Ir al carrito →</a></p>
				<form method="post" style="margin:0 0 16px">%s<input type="hidden" name="akb_rw_action" value="%s"><input type="hidden" name="akb_rw_points" value="0"><button type="submit" class="button" style="min-height:44px">Quitar canje</button></form>',
				esc_html( number_format_i18n( $intent ) ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $tope ya sale escapado de arriba: su única parte dinámica pasa por esc_html() en el sprintf que lo construye, y el resto es texto literal de este fichero. Volver a escaparlo aquí sería doble escape.
				$tope,
				esc_url( wc_get_cart_url() ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() con $echo = false DEVUELVE el marcado que core ya genera seguro; el sniff sólo la reconoce cuando imprime ella misma. Escaparla dejaría el nonce visible como texto y tumbaría el formulario.
				wp_nonce_field( self::REDEEM_ACTION, '_akbnonce', true, false ),
				esc_attr( self::REDEEM_ACTION )
			);
		}

		$minimo_canje = $limits['min_points'];
		$maximum      = $limits['max_points'];
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
				(int) ( $eligible ? $minimo_canje : 0 ),
				(int) $maximum,
				(int) $initial,
				$eligible ? '' : ' disabled',
				$eligible ? '' : ' disabled',
				esc_html( $preview ),
				esc_html( $rules ),
				$eligible ? '' : ' disabled'
			);
			if ( ! $eligible ) {
				if ( ( ! empty( $limits['points_exceed_bundle'] ) || ! empty( $limits['can_switch_from_bundle'] ) ) && ! empty( $limits['max_redeemable_points'] ) ) {
					$switch_url  = add_query_arg( 'akb_prefer_points_switch', '1', wc_get_cart_url() );
					$pts_clp_fmt = html_entity_decode( wp_strip_all_tags( wc_price( $limits['max_redeemable_points_clp'] ?? 0 ) ), ENT_QUOTES, 'UTF-8' );
					printf(
						'<p><a class="button akb-switch-points-btn" href="%s">Usar mis %s en puntos en lugar del descuento de pack</a></p>',
						esc_url( $switch_url ),
						esc_html( $pts_clp_fmt )
					);
				}
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

		$this->render_history( $user_id );
		if ( Options::reviews_lane_on() ) {
			printf( '<p>%s</p>', esc_html( 'Hay dos recorridos: la reseña voluntaria en la ficha del producto admite foto opcional y no genera puntos. La invitación por correo permite reseñar y solicitar puntos con foto obligatoria; puedes recuperar su enlace vigente desde Mis pedidos. ' . \Akibara\Rewards\Reviews\RewardMessage::text( false ) ) );
		}

		// Sección informativa de acumulación, canje y catálogo de beneficios.
		$coleccion_url = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'coleccion' ) : '';

		// Anuncia únicamente los carriles activos y con beneficio positivo.
		$carriles = array();
		$tramo_vivo = Promise::rate();
		if ( $tramo_vivo['points'] > 0 && $tramo_vivo['amount'] > 0 ) {
			$carriles[] = sprintf( 'con cada compra (%s por cada $%s)', number_format_i18n( $tramo_vivo['points'] ), number_format_i18n( $tramo_vivo['amount'] ) );
		}
		// El bono y el premio al padrino cuelgan de la MISMA compuerta de
		// vigencia que la compra: fuera de ella tampoco se prometen.
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

		printf(
			'<details style="margin:20px 0"><summary style="cursor:pointer;font-weight:700;min-height:44px;display:flex;align-items:center">¿Cómo funcionan mis puntos? 📖</summary>
			<ul style="margin:10px 0 0;line-height:1.7">
				%s<li><strong>Gastas</strong> canjeándolos aquí arriba: cada punto es %s de descuento%s — ideal para el siguiente tomo de tu colección.</li>
				%s
			</ul>
			%s</details>',
			'' !== $ganas ? sprintf( '<li><strong>Ganas</strong> %s.</li>', esc_html( $ganas ) ) : '',
			esc_html( Options::redeem_label() ),
			Options::redeem_min_cart() > 0
				? esc_html( sprintf( ' en compras desde $%s en productos', number_format_i18n( Options::redeem_min_cart() ) ) )
				: ' en tu próxima compra',
			Expiry::months() > 0 ? sprintf( '<li><strong>Duran</strong> %d meses: los más antiguos se usan primero.</li>', (int) Expiry::months() ) : '',
			'' !== $coleccion_url ? sprintf( '<p style="margin:10px 0 0"><a href="%s" style="font-weight:600">Ver mi colección de mangas y cómics →</a> ¿Te falta un tomo? Tus puntos lo acercan.</p>', esc_url( $coleccion_url ) ) : ''
		);

		/**
		 * Bloques extra del endpoint (referidos, futuros niveles).
		 *
		 * @param int $user_id Usuario dueño de la pantalla.
		 */
		do_action( 'akb_rewards_account_extra', $user_id );

		$programa_url = ProgramPage::url();
		if ( '' !== $programa_url ) {
			printf(
				'<p style="margin-top:16px"><a href="%s" style="color:var(--aki-red-text, #ff4d5e);font-weight:600;text-decoration:underline">Cómo funciona el programa →</a></p>',
				esc_url( $programa_url )
			);
		}

		echo '</div>';
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
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::ledger_table() . ' WHERE user_id = %d', $user_id ) );
		if ( $total <= 0 ) {
			echo '<p>Todavía no tienes movimientos de puntos.</p>';
			return;
		}
		$pages = (int) ceil( $total / 10 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET de lectura sin mutación.
		$raw   = $_GET['akb_rw_page'] ?? '1';
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

		echo '<h3 id="akb-rw-history" style="margin:0 0 8px">Historial de puntos</h3><div class="akb-rw-history-table"><table class="shop_table" style="font-size:14px"><thead><tr><th scope="col">Fecha</th><th scope="col">Motivo</th><th scope="col" style="text-align:right">Puntos</th></tr></thead><tbody>';
		foreach ( (array) $rows as $row ) {
			$delta = (int) $row->delta;
			printf(
				'<tr><td>%s</td><td>%s</td><td style="text-align:right;font-weight:700;color:%s">%s%s</td></tr>',
				esc_html( date_i18n( 'j M Y', strtotime( (string) $row->created_at ) ) ),
				esc_html( (string) $row->note ),
				$delta >= 0 ? '#8ce99a' : '#ffa8a8', // Tonos legibles sobre el dark del tema (AA).
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

		$raw = isset( $_POST['akb_rw_points'] ) ? wp_unslash( $_POST['akb_rw_points'] ) : null;
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

		$cart = function_exists( 'WC' ) && ( WC()->cart ?? null ) instanceof \WC_Cart ? WC()->cart : null;
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
