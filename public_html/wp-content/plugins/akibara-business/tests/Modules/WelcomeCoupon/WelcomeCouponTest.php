<?php
/**
 * Pruebas unitarias para el módulo WelcomeCoupon (Beneficio primera compra y popup promocional).
 *
 * Valida:
 *   - Cálculo best-wins exclusivamente sobre regular_price (precio base normal).
 *   - No apilamiento cuando el descuento editorial o pack ya supera el 10%.
 *   - Sanitización de opciones, interpolación de copys y épocas de cookie.
 *   - Generación de códigos únicos, cálculo de vigencia y normalización de identidades (RUT y teléfono).
 *   - Respuestas de seguridad del endpoint REST (honeypot y envíos ultrarrápidos).
 *   - Resolución de superficies y modos del renderizador del modal.
 *
 * @package Akibara\Business\Tests\Modules\WelcomeCoupon
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\WelcomeCoupon;

use Akibara\Business\Modules\WelcomeCoupon\CouponService;
use Akibara\Business\Modules\WelcomeCoupon\PopupRenderer;
use Akibara\Business\Modules\WelcomeCoupon\RestController;
use Akibara\Business\Modules\WelcomeCoupon\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class WelcomeCouponTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! class_exists( '\WC_Coupon' ) ) {
			eval(
				'namespace { class WC_Coupon {
					public int $id = 0;
					public string $code = "";
					public array $meta = array();
					public int $usage_count = 0;
					public array $email_restrictions = array();
					public static array $saved_instances = array();
					public function __construct( $code = "" ) {
						$this->code = (string) $code;
						if ( "HOLAPRUEBA9X" === $this->code ) {
							$this->id = 1234;
							$this->meta["_akb_is_welcome_coupon"] = "1";
							$this->meta["_akb_welcome_used_notified"] = "1";
							$this->meta["_akb_welcome_recipient_email"] = "lector@akibara.cl";
							$this->usage_count = 1;
						}
						self::$saved_instances[] = $this;
					}
					public function get_id(): int { return $this->id; }
					public function get_code(): string { return $this->code; }
					public function set_code( string $c ): void { $this->code = $c; }
					public function set_description( string $d ): void {}
					public function set_discount_type( string $t ): void {}
					public function set_amount( $a ): void {}
					public function set_individual_use( bool $i ): void {}
					public function set_usage_limit( int $l ): void {}
					public function set_usage_limit_per_user( int $l ): void {}
					public function set_minimum_amount( string $m ): void {}
					public function set_date_expires( $ts ): void {}
					public function get_meta( string $k, bool $s = true ) { return $this->meta[$k] ?? ""; }
					public function update_meta_data( string $k, $v ): void { $this->meta[$k] = $v; }
					public function delete_meta_data( string $k ): void { unset( $this->meta[$k] ); }
					public function get_usage_count(): int { return $this->usage_count; }
					public function set_usage_count( int $count ): void { $this->usage_count = $count; }
					public function set_email_restrictions( array $e ): void { $this->email_restrictions = $e; }
					public function get_email_restrictions(): array { return $this->email_restrictions; }
					public function save(): int { if ($this->id === 0) { $this->id = 9999; } return $this->id; }
				} }'
			);
		}

		Functions\when( 'get_option' )->alias( static fn( string $o, $d = false ) => $d );
		Functions\when( 'apply_filters' )->alias( static fn( string $tag, $val ) => $val );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
		Functions\when( 'sanitize_email' )->alias( static fn( $v ) => strtolower( trim( (string) $v ) ) );
		Functions\when( 'is_email' )->alias( static fn( $v ) => false !== filter_var( $v, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'wp_unslash' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => strip_tags( (string) $v ) );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_embed' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_page' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wc_get_orders' )->justReturn( array() );
		Functions\when( 'wc_get_coupon_id_by_code' )->justReturn( 0 );
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.cl/tienda/' );
		Functions\when( 'wc_get_price_decimals' )->justReturn( 0 );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n, $d = 0 ) => number_format( (float) $n, $d, ',', '.' ) );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Santiago' ) );
		Functions\when( 'add_query_arg' )->alias( static function ( $k, $v, $url ) {
			return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// 1. Regla Best-Wins: Descuento exclusivamente sobre el precio base (regular_price)
	// -------------------------------------------------------------------------

	public function test_unit_welcome_discount_gives_exact_pct_on_regular_price(): void {
		// Tomo precio normal $10.000, sin rebaja previa: 10% da $1.000.
		$discount = CouponService::unit_welcome_discount_on_base_price( 10000.0, 10000.0, 10.0 );
		self::assertSame( 1000.0, $discount );
	}

	public function test_unit_welcome_discount_completes_editorial_discount_up_to_welcome_pct(): void {
		// Tomo precio normal $10.000 con rebaja previa a $9.500 (5% off).
		// El cupón de 10% sólo debe descontar la diferencia para alcanzar los $9.000 ($500 de descuento adicional).
		$discount = CouponService::unit_welcome_discount_on_base_price( 10000.0, 9500.0, 10.0 );
		self::assertSame( 500.0, $discount );
	}

	public function test_unit_welcome_discount_yields_zero_when_current_discount_equals_or_exceeds_coupon(): void {
		// Tomo precio normal $10.000 ya rebajado al 10% ($9.000): el cupón aporta $0.
		$discount_equal = CouponService::unit_welcome_discount_on_base_price( 10000.0, 9000.0, 10.0 );
		self::assertSame( 0.0, $discount_equal );

		// Tomo precio normal $10.000 con oferta de 25% ($7.500): el cupón aporta $0 (no perjudica al cliente).
		$discount_greater = CouponService::unit_welcome_discount_on_base_price( 10000.0, 7500.0, 10.0 );
		self::assertSame( 0.0, $discount_greater );
	}

	public function test_unit_welcome_discount_handles_zero_and_negative_prices_gracefully(): void {
		self::assertSame( 0.0, CouponService::unit_welcome_discount_on_base_price( 0.0, 0.0, 10.0 ) );
		self::assertSame( 0.0, CouponService::unit_welcome_discount_on_base_price( 10000.0, 10000.0, 0.0 ) );
		self::assertSame( 0.0, CouponService::unit_welcome_discount_on_base_price( 10000.0, 0.0, 10.0 ) );
	}

	// -------------------------------------------------------------------------
	// 2. Settings & Sanitizadores
	// -------------------------------------------------------------------------

	public function test_settings_default_values(): void {
		self::assertSame( 10, Settings::discount_pct() );
		self::assertSame( 25000, Settings::min_amount() );
		self::assertSame( 3, Settings::validity_days() );
		self::assertSame( 20, Settings::delay_seconds() );
		self::assertSame( 45, Settings::scroll_pct() );
		self::assertSame( 30, Settings::dismiss_days() );
		self::assertTrue( Settings::is_enabled() );
		self::assertFalse( Settings::is_promo_enabled() );
	}

	public function test_settings_sanitizers_clamp_values_within_safe_bounds(): void {
		$settings = new Settings();

		// Porcentaje: 1 a 50
		self::assertSame( 1, $settings->sanitize_discount_pct( 1, array(), 0 ) );
		self::assertSame( 15, $settings->sanitize_discount_pct( 15, array(), 15 ) );
		self::assertSame( 50, $settings->sanitize_discount_pct( 50, array(), 99 ) );

		// Compra mínima: 0 a 500000
		self::assertSame( 0, $settings->sanitize_min_amount( 0, array(), 0 ) );
		self::assertSame( 30000, $settings->sanitize_min_amount( 30000, array(), 30000 ) );
		self::assertSame( 500000, $settings->sanitize_min_amount( 500000, array(), 9999999 ) );

		// Vigencia: 1 a 30 días
		self::assertSame( 1, $settings->sanitize_validity_days( 1, array(), 0 ) );
		self::assertSame( 7, $settings->sanitize_validity_days( 7, array(), 7 ) );
		self::assertSame( 30, $settings->sanitize_validity_days( 30, array(), 60 ) );

		// Espera: 0 a 120 segundos
		self::assertSame( 0, $settings->sanitize_delay_seconds( 0, array(), -5 ) );
		self::assertSame( 15, $settings->sanitize_delay_seconds( 15, array(), 15 ) );
		self::assertSame( 120, $settings->sanitize_delay_seconds( 120, array(), 500 ) );

		// Audiencia promo: solo 'all' o 'guests'
		self::assertSame( 'guests', $settings->sanitize_promo_audience( 'guests', array(), 'guests' ) );
		self::assertSame( 'all', $settings->sanitize_promo_audience( 'all', array(), 'cualquiera' ) );
	}

	public function test_settings_copies_interpolation(): void {
		$copies = Settings::copies();
		self::assertStringContainsString( '10%', $copies['heading'] );
		self::assertStringContainsString( '$25.000', $copies['legal'] );
		self::assertStringContainsString( '3 días', $copies['legal'] );
		self::assertStringContainsString( 'Cero spam', $copies['trust_note'] );
		self::assertStringContainsString( 'catálogo', $copies['subtitle'] );
		self::assertStringContainsString( 'preferenciales', $copies['trust_note'] );
	}

	public function test_settings_cookie_epoch_increments_on_reset(): void {
		$settings = new Settings();
		$updated  = false;
		Functions\when( 'update_option' )->alias( function ( $opt, $val ) use ( &$updated ) {
			if ( 'akb_welcome_coupon_cookie_epoch' === $opt && 2 === $val ) {
				$updated = true;
			}
			return true;
		} );

		$res = $settings->sanitize_reset_cookies( 'yes', array(), 'yes' );
		self::assertSame( 'no', $res, 'El checkbox de reset se desmarca automáticamente tras ejecutarse.' );
		self::assertTrue( $updated, 'Se incrementó la época de cookies en la base de datos.' );
	}

	// -------------------------------------------------------------------------
	// 3. Generación de Códigos y Helpers de CouponService
	// -------------------------------------------------------------------------

	public function test_clean_subscriber_name_normalizes_and_limits_length(): void {
		self::assertSame( 'Alejandro', CouponService::clean_subscriber_name( '  <b>Alejandro</b>  ' ) );
		self::assertSame( 'Juan Pablo', CouponService::clean_subscriber_name( "Juan\n  Pablo" ) );
	}

	public function test_unique_code_format(): void {
		$service = new CouponService();
		Functions\when( 'wc_get_coupon_id_by_code' )->justReturn( 0 );

		$code = $service->generate_unique_code( 'Alejandro' );
		self::assertStringStartsWith( 'HOLAALEJ', $code );
		self::assertSame( 13, strlen( $code ) );

		$code_short = $service->generate_unique_code( 'Al' );
		self::assertStringStartsWith( 'HOLAAKIB', $code_short );
		self::assertSame( 12, strlen( $code_short ) );
	}

	public function test_calculate_expiration_timestamp_falls_at_end_of_day(): void {
		$now = strtotime( '2026-10-20 12:00:00' );
		$exp = CouponService::calculate_expiration_timestamp( $now, 3 );
		$dt  = ( new \DateTimeImmutable( '@' . $exp ) )->setTimezone( new \DateTimeZone( 'America/Santiago' ) );

		self::assertSame( '23:59:59', $dt->format( 'H:i:s' ) );
		self::assertSame( '23/10/2026', $dt->format( 'd/m/Y' ) );
	}

	public function test_identity_variants_chile(): void {
		$rut_variants = CouponService::rut_variants( '19.876.543-k' );
		self::assertContains( '19.876.543-K', $rut_variants );
		self::assertContains( '19876543-K', $rut_variants );
		self::assertContains( '19876543K', $rut_variants );

		$phone_variants = CouponService::phone_variants( '+56 9 8765 4321' );
		self::assertContains( '+56987654321', $phone_variants );
		self::assertContains( '987654321', $phone_variants );
		self::assertContains( '56987654321', $phone_variants );
	}

	public function test_normalize_email_anti_fraud(): void {
		// Gmail con puntos y etiquetas plus
		self::assertSame( 'juan@gmail.com', CouponService::normalize_email( 'j.u.a.n@gmail.com' ) );
		self::assertSame( 'juan@gmail.com', CouponService::normalize_email( 'j.u.a.n+promo123@gmail.com' ) );
		self::assertSame( 'juan@gmail.com', CouponService::normalize_email( 'juan+descuento@googlemail.com' ) );

		// Outlook / Microsoft con etiquetas plus
		self::assertSame( 'maria@outlook.com', CouponService::normalize_email( 'maria+cupon@outlook.com' ) );

		// Dominios propios: conserva puntos de nombre pero remueve etiquetas plus
		self::assertSame( 'pedro.perez@empresa.cl', CouponService::normalize_email( 'pedro.perez+test@empresa.cl' ) );
		self::assertSame( 'contacto@akibara.cl', CouponService::normalize_email( 'contacto@akibara.cl' ) );
	}

	public function test_is_disposable_email_detection(): void {
		self::assertTrue( CouponService::is_disposable_email( 'spam@mailinator.com' ) );
		self::assertTrue( CouponService::is_disposable_email( 'test@tempmail.com' ) );
		self::assertTrue( CouponService::is_disposable_email( 'hacker@guerrillamail.com' ) );
		self::assertTrue( CouponService::is_disposable_email( 'bot@yopmail.com' ) );

		self::assertFalse( CouponService::is_disposable_email( 'real.user@gmail.com' ) );
		self::assertFalse( CouponService::is_disposable_email( 'cliente@outlook.com' ) );
		self::assertFalse( CouponService::is_disposable_email( 'soporte@akibara.cl' ) );
	}

	// -------------------------------------------------------------------------
	// 4. Seguridad en RestController (Honeypot, Velocidad y Validación)
	// -------------------------------------------------------------------------

	public function test_rest_controller_rejects_empty_name_or_invalid_email(): void {
		$service    = new CouponService();
		$controller = new RestController( $service );

		$res_name = $controller->subscribe( '', 'prospecto@akibara.cl', 0 );
		self::assertFalse( $res_name['ok'] );
		self::assertSame( 'invalid_name', $res_name['code'] );

		$res_email = $controller->subscribe( 'Matias', 'correo-invalido', 0 );
		self::assertFalse( $res_email['ok'] );
		self::assertSame( 'invalid_email', $res_email['code'] );

		$res_disp = $controller->subscribe( 'Spammer', 'bot@mailinator.com', 0 );
		self::assertFalse( $res_disp['ok'] );
		self::assertSame( 'disposable_email', $res_disp['code'] );
		self::assertSame( 400, $res_disp['status'] );
		self::assertStringContainsString( 'correo', $res_disp['message'] );

		// Sugerencia ante error común de tipeo
		$res_typo = $controller->subscribe( 'Valentina', 'vale@gamil.com', 0 );
		self::assertFalse( $res_typo['ok'] );
		self::assertSame( 'email_domain_typo', $res_typo['code'] );
		self::assertSame( 400, $res_typo['status'] );
		self::assertStringContainsString( '@gmail.com', $res_typo['message'] );
	}

	// -------------------------------------------------------------------------
	// 5. PopupRenderer (Modos y Reglas de Superficie)
	// -------------------------------------------------------------------------

	public function test_popup_renderer_active_mode_selection(): void {
		$renderer = new PopupRenderer();

		// Con configuración de fábrica: welcome activo, promo inactivo
		self::assertSame( 'welcome', $renderer->active_mode() );

		// Con promo activo con código: promo tiene prioridad
		Functions\when( 'get_option' )->alias( function ( string $opt, $default = false ) {
			if ( 'akb_welcome_promo_enabled' === $opt ) {
				return 'yes';
			}
			if ( 'akb_welcome_promo_code' === $opt ) {
				return 'CYBERAKIB';
			}
			return $default;
		} );

		self::assertSame( 'promo', $renderer->active_mode() );

		// Con promo activo informativo (sin código, para descuentos automáticos en tienda)
		Functions\when( 'get_option' )->alias( function ( string $opt, $default = false ) {
			if ( 'akb_welcome_promo_enabled' === $opt ) {
				return 'yes';
			}
			if ( 'akb_welcome_promo_code' === $opt ) {
				return '';
			}
			if ( 'akb_welcome_promo_follow_discount' === $opt ) {
				return 'no';
			}
			return $default;
		} );

		self::assertSame( 'promo', $renderer->active_mode() );
		self::assertTrue( Settings::is_promo_enabled() );
		self::assertSame( '', Settings::promo_code() );
		self::assertSame( '/tienda/', Settings::promo_url() );

		$copies = Settings::promo_copies();
		self::assertSame( 'PROMOCIONES AKIBARA', $copies['heading'] );
		self::assertSame( 'Ver promociones', $copies['cta'] );
	}

	public function test_popup_renderer_ucss_whitelist_contains_necessary_selectors(): void {
		$renderer  = new PopupRenderer();
		$whitelist = $renderer->allowlist_ucss_selectors( array() );

		self::assertContains( '.akb-welcome', $whitelist );
		self::assertContains( '.akb-welcome.is-open', $whitelist );
		self::assertContains( '.akb-welcome.is-promo', $whitelist );
		self::assertContains( '.akb-welcome__existing-buyer', $whitelist );
		self::assertContains( '.akb-welcome__copy-btn.is-copied', $whitelist );
	}

	public function test_welcome_pivot_triggers_for_existing_buyer_without_issuing_coupon(): void {
		$service = new CouponService();

		// Simular que el cliente ya tiene un pedido previo en la tienda
		Functions\when( 'wc_get_orders' )->justReturn( array( 9999 ) );

		$action_fired = false;
		Functions\when( 'do_action' )->alias( function ( $tag, $payload = null ) use ( &$action_fired ) {
			if ( 'akb_welcome_existing_buyer_subscribed' === $tag && isset( $payload['email'] ) && 'antiguo@akibara.cl' === $payload['email'] ) {
				$action_fired = true;
			}
		} );

		$res = $service->issue_for_subscriber( 'Ignacio', 'antiguo@akibara.cl', 0 );

		self::assertTrue( $res['ok'] );
		self::assertSame( 'subscribed_existing_buyer', $res['code'] );
		self::assertSame( '', $res['coupon_code'] );
		self::assertTrue( $action_fired, 'Dispara la acción akb_welcome_existing_buyer_subscribed para Brevo.' );
		self::assertStringContainsString( 'Akibara Puntos', $res['message'] );
	}

	public function test_popup_renderer_surface_exclusions(): void {
		$renderer = new PopupRenderer();

		// En carrito nunca debe renderizarse
		Functions\when( 'is_cart' )->justReturn( true );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		self::assertFalse( $renderer->should_render(), 'No debe renderizarse en el carrito.' );

		// En portada debe renderizarse si la opción está activa
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( true );
		self::assertTrue( $renderer->should_render(), 'Debe renderizarse en la portada.' );
	}

	public function test_popup_renderer_promo_audience_filtering(): void {
		$renderer = new PopupRenderer();

		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		// Activar modo promo con audiencia exclusiva para 'guests'
		Functions\when( 'get_option' )->alias( function ( string $opt, $default = false ) {
			if ( 'akb_welcome_promo_enabled' === $opt ) {
				return 'yes';
			}
			if ( 'akb_welcome_promo_code' === $opt ) {
				return 'CYBERAKIB';
			}
			if ( 'akb_welcome_promo_audience' === $opt ) {
				return 'guests';
			}
			if ( 'akb_welcome_promo_show_home' === $opt ) {
				return 'yes';
			}
			return $default;
		} );

		self::assertFalse( $renderer->should_render(), 'Promo para guests no debe renderizarse a clientes con sesión iniciada.' );

		// Cambiar audiencia a 'all': ahora debe renderizarse incluso con sesión iniciada
		Functions\when( 'get_option' )->alias( function ( string $opt, $default = false ) {
			if ( 'akb_welcome_promo_enabled' === $opt ) {
				return 'yes';
			}
			if ( 'akb_welcome_promo_code' === $opt ) {
				return 'CYBERAKIB';
			}
			if ( 'akb_welcome_promo_audience' === $opt ) {
				return 'all';
			}
			if ( 'akb_welcome_promo_show_home' === $opt ) {
				return 'yes';
			}
			return $default;
		} );

		self::assertTrue( $renderer->should_render(), 'Promo para all debe renderizarse a todos los clientes.' );
	}

	public function test_turnstile_settings_and_defaults(): void {
		Functions\when( 'get_option' )->alias( function ( string $opt, $default = false ) {
			return $default;
		} );

		self::assertTrue( Settings::turnstile_enabled() );
		self::assertSame( Settings::DEFAULT_TURNSTILE_SITEKEY, Settings::turnstile_sitekey() );
		self::assertSame( Settings::DEFAULT_TURNSTILE_SECRET, Settings::turnstile_secret() );
	}

	public function test_rest_controller_turnstile_validation(): void {
		\Patchwork\redefine( array( Settings::class, 'turnstile_secret' ), static fn(): string => '1x0000000000000000000000000000000AA' );
		$service    = new CouponService();
		$controller = new RestController( $service );

		// Token vacío debe fallar
		self::assertFalse( $controller->verify_turnstile( '' ) );

		// Token de prueba de Cloudflare debe pasar
		self::assertTrue( $controller->verify_turnstile( 'test-pass-token' ) );
		self::assertTrue( $controller->verify_turnstile( '1x00000000000000000000AA' ) );

		// Verificación con filtro de rechazo
		Functions\when( 'apply_filters' )->alias( function ( string $tag, $value = null ) {
			if ( 'akibara_turnstile_verify_result' === $tag ) {
				return false;
			}
			return $value;
		} );

		self::assertFalse( $controller->verify_turnstile( 'invalid-token' ) );
	}

	public function test_preview_mode_allows_testing_when_disabled(): void {
		// Simular opción desactivada en BD
		Functions\when( 'get_option' )->alias( function( string $o, $d = false ) {
			if ( 'akb_welcome_coupon_enabled' === $o ) {
				return 'no';
			}
			return $d;
		} );

		// Sin preview, está apagado
		self::assertFalse( Settings::is_enabled() );

		// Con query param de preview, se habilita de forma privada
		$_GET[ Settings::PREVIEW_QUERY_ARG ] = '1';
		self::assertTrue( Settings::is_preview() );
		self::assertTrue( Settings::is_enabled() );

		unset( $_GET[ Settings::PREVIEW_QUERY_ARG ] );

		// Con cookie de preview, también se habilita
		$_COOKIE[ Settings::PREVIEW_COOKIE ] = '1';
		self::assertTrue( Settings::is_preview() );
		self::assertTrue( Settings::is_enabled() );

		unset( $_COOKIE[ Settings::PREVIEW_COOKIE ] );
		self::assertFalse( Settings::is_enabled() );
	}

	public function test_trusted_mail_domains_whitelist(): void {
		self::assertTrue( CouponService::has_valid_mail_domain( 'lector@gmail.com' ) );
		self::assertTrue( CouponService::has_valid_mail_domain( 'fan@hotmail.cl' ) );
		self::assertTrue( CouponService::has_valid_mail_domain( 'estudiante@uc.cl' ) );
		self::assertTrue( CouponService::has_valid_mail_domain( 'profesor@uchile.cl' ) );
		self::assertTrue( CouponService::has_valid_mail_domain( 'usuario@outlook.com' ) );
	}

	public function test_email_normalization_in_coupon_restrictions(): void {
		$service = new CouponService();
		Functions\when( 'do_action' )->justReturn();

		$res = $service->issue_for_subscriber( 'Ignacio', 'ignacio.perez@gmail.com' );
		self::assertTrue( $res['ok'] );
		self::assertSame( 'issued', $res['code'] );

		$saved = end( \WC_Coupon::$saved_instances );
		self::assertInstanceOf( \WC_Coupon::class, $saved );
		$restrictions = $saved->get_email_restrictions();

		self::assertContains( 'ignacio.perez@gmail.com', $restrictions );
		self::assertContains( 'ignacioperez@gmail.com', $restrictions );
	}

	public function test_filter_coupon_usage_count_unlocked_when_no_paid_order(): void {
		$service = new CouponService();
		$coupon  = new \WC_Coupon( 'HOLAPRUEBA9X' );

		// Sin haber sido marcado como pagado (META_USED_NOTIFIED != 1), y sin órdenes pagadas
		$coupon->delete_meta_data( CouponService::META_USED_NOTIFIED );
		$coupon->update_meta_data( CouponService::META_IS_WELCOME, '1' );

		// WooCommerce reporta usage_count = 1 por la orden 'pending' de Flow/MercadoPago
		// El filtro debe retornar 0 para desbloquear el cupón al reintentar el pago
		$effective_usage = $service->filter_coupon_usage_count( 1, $coupon );
		self::assertSame( 0, $effective_usage );

		// Si el cupón fue efectivamente marcado como usado (pedido pagado)
		$coupon->update_meta_data( CouponService::META_USED_NOTIFIED, '1' );
		$effective_paid = $service->filter_coupon_usage_count( 1, $coupon );
		self::assertSame( 1, $effective_paid );
	}

	public function test_referral_query_and_cookie_suppress_welcome_popup(): void {
		$renderer = new PopupRenderer();
		Functions\when( 'get_option' )->alias( function ( string $opt, $default = false ) {
			if ( 'akb_welcome_coupon_enabled' === $opt ) {
				return 'yes';
			}
			return $default;
		} );

		// Sin referido, should_render es true
		self::assertTrue( $renderer->should_render() );

		// Con ?ref=amigo123 en URL, se suprime para no canibalizar el embudo de referidos
		$_GET['ref'] = 'amigo123';
		self::assertFalse( $renderer->should_render() );
		unset( $_GET['ref'] );

		// Con cookie akb_ref, también se suprime
		$_COOKIE['akb_ref'] = 'amigo123';
		self::assertFalse( $renderer->should_render() );
		unset( $_COOKIE['akb_ref'] );
	}

	public function test_rest_controller_exempts_too_fast_when_turnstile_valid(): void {
		\Patchwork\redefine( array( Settings::class, 'turnstile_secret' ), static fn(): string => '1x0000000000000000000000000000000AA' );
		$service    = new CouponService();
		$controller = new RestController( $service );

		Functions\when( 'get_option' )->alias( function ( string $opt, $default = false ) {
			if ( 'akb_welcome_coupon_enabled' === $opt ) {
				return 'yes';
			}
			if ( 'akb_welcome_turnstile_enabled' === $opt ) {
				return 'yes';
			}
			return $default;
		} );

		// Petición con envío ultrarrápido (_akb_elapsed_ms = 200 ms) pero Turnstile válido (autofill móvil)
		$params = array(
			'name'                  => 'Catalina',
			'email'                 => 'catalina@gmail.com',
			'_akb_elapsed_ms'       => 200,
			'cf-turnstile-response' => 'test-pass-token',
		);
		$request = new \WP_REST_Request( $params );

		Functions\when( 'do_action' )->justReturn();

		$response = $controller->handle( $request );
		$data     = $response->get_data();

		// Debe retornar 201 Created legítimo (no señuelo)
		self::assertSame( 201, $response->get_status() );
		self::assertTrue( $data['ok'] );
		self::assertSame( 'issued', $data['code'] );
		self::assertTrue( $data['is_new'] );
		self::assertNotEmpty( $data['coupon_code'] );

		// En entorno de producción real, el token dummy 'test-pass-token' NO se acepta como bypass
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_remote_post' )->justReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"success":false}' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"success":false}' );
		self::assertFalse( $controller->verify_turnstile( 'test-pass-token' ) );
	}
}
