<?php
/**
 * Contrato de información de campañas y límites de la captación de bienvenida.
 *
 * @package Akibara\Business\Tests
 */
declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\WelcomeCoupon;

use Akibara\Business\Modules\AnnouncementBar\AnnouncementBar;
use Akibara\Business\Modules\DiscountCopy\DiscountCopy;
use Akibara\Business\Modules\WelcomeCoupon\CouponService;
use Akibara\Business\Modules\WelcomeCoupon\PopupRenderer;
use Akibara\Business\Modules\WelcomeCoupon\RestController;
use Akibara\Business\Modules\WelcomeCoupon\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class InformativeCampaignTest extends TestCase {

	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array(
			'akb_welcome_coupon_enabled' => 'no',
			'akb_welcome_promo_enabled' => 'yes',
			'akb_welcome_promo_code' => '',
			'akibara_taxonomy_discount_enabled' => 'yes',
			'akibara_taxonomy_discount_from' => '2026-10-25',
			'akibara_taxonomy_discount_to' => '2026-10-27',
			'akibara_announcement_promo_follow_discount' => 'yes',
			'akibara_announcement_promo_text' => 'Halloween: hasta [akb_descuento_pct] en [akb_descuento_editorial]',
		);
		Functions\when( 'get_option' )->alias( fn( string $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
		Functions\when( 'current_time' )->justReturn( '2026-10-26' );
		Functions\when( 'get_transient' )->justReturn( array( 'pct' => 25, 'term_name' => 'Ivrea Argentina' ) );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_embed' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_page' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_theme_mod' )->justReturn( 0 );
		Functions\when( 'get_stylesheet_directory' )->justReturn( '' );
		Functions\when( 'get_stylesheet_directory_uri' )->justReturn( '' );
		Functions\when( 'esc_url' )->alias( static fn( $value ) => (string) $value );
		Functions\when( 'esc_attr' )->alias( static fn( $value ) => htmlspecialchars( (string) $value, ENT_QUOTES ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
		$this->reset_rule();
	}

	protected function tearDown(): void {
		$this->reset_rule();
		unset( $_GET[ Settings::PREVIEW_QUERY_ARG ], $_COOKIE[ Settings::PREVIEW_COOKIE ] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function reset_rule(): void {
		( new ReflectionProperty( DiscountCopy::class, 'rule_memo' ) )->setValue( null, false );
	}

	/** @testdox The information popup follows the same active campaign as the announcement bar. */
	public function test_popup_and_bar_share_current_campaign(): void {
		self::assertTrue( Settings::is_promo_enabled() );
		self::assertTrue( ( new AnnouncementBar() )->promo_active() );
		$this->options['akibara_taxonomy_discount_enabled'] = 'no';
		$this->reset_rule();
		self::assertFalse( Settings::is_promo_enabled() );
		self::assertFalse( ( new AnnouncementBar() )->promo_active() );
		self::assertSame( '', ( new PopupRenderer() )->active_mode() );
	}

	/** @testdox Before the campaign starts or after its end no information popup promises a discount. */
	public function test_informative_popup_follows_inclusive_dates(): void {
		foreach ( array( '2026-10-24' => false, '2026-10-25' => true, '2026-10-27' => true, '2026-10-28' => false ) as $day => $expected ) {
			Functions\when( 'current_time' )->justReturn( $day );
			$this->reset_rule();
			self::assertSame( $expected, Settings::is_promo_enabled(), $day );
		}
	}

	/** @testdox The copy resolves the live percentage and editorial without claiming the entire store. */
	public function test_informative_copy_uses_existing_campaign_tokens(): void {
		$this->options['akb_copy_welcome_promo_heading'] = 'Halloween: hasta [akb_descuento_pct] en [akb_descuento_editorial]';
		self::assertSame( 'Halloween: hasta 25% en Ivrea Argentina', Settings::promo_copies()['heading'] );
	}

	/** @testdox Saved legacy defaults cannot invite the visitor to apply an empty coupon. */
	public function test_saved_coupon_defaults_fall_back_to_information_when_code_is_empty(): void {
		$this->options['akb_copy_welcome_promo_heading'] = '¡USA EL CÓDIGO {codigo} EN TU COMPRA!';
		$this->options['akb_copy_welcome_promo_cta'] = 'Aplicar código a mi carrito';
		$this->options['akb_copy_welcome_promo_legal'] = 'Promoción por tiempo limitado sujeta a las condiciones del cupón en el carrito.';
		$copy = Settings::promo_copies();
		self::assertStringNotContainsString( 'USA EL CÓDIGO', $copy['heading'] );
		self::assertSame( 'Ver promociones', $copy['cta'] );
		self::assertStringNotContainsString( 'cupón en el carrito', $copy['legal'] );
	}

	/** @testdox An information popup has no form, mail field, coupon code or Turnstile widget. */
	public function test_information_has_no_capture_or_coupon_markup(): void {
		ob_start();
		( new PopupRenderer() )->render_modal();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'is-informative', $html );
		foreach ( array( '<form', 'type="email"', 'data-akb-welcome-copy', 'akb_cupon=', 'data-akb-welcome-turnstile' ) as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $html );
		}
	}

	/** @testdox With both switches off neither the popup nor launcher is rendered. */
	public function test_both_off_produces_no_markup(): void {
		$this->options['akb_welcome_promo_enabled'] = 'no';
		ob_start();
		( new PopupRenderer() )->render_modal();
		self::assertSame( '', ob_get_clean() );
	}

	/** @testdox Public visitors cannot turn an off welcome flow on by adding a preview query. */
	public function test_preview_is_not_a_public_switch(): void {
		$_GET[ Settings::PREVIEW_QUERY_ARG ] = '1';
		self::assertFalse( Settings::is_preview() );
		self::assertFalse( Settings::is_enabled() );
	}

	/** @testdox Authorized UI preview does not reopen real coupon issuance while the switch is off. */
	public function test_ui_preview_cannot_issue_a_coupon_while_off(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_GET[ Settings::PREVIEW_QUERY_ARG ] = '1';
		$result = ( new RestController( new CouponService() ) )->subscribe( 'Visitante', 'qa@example.test' );
		self::assertSame( 'disabled', $result['code'] );
	}

	/** @testdox Missing private configuration blocks verification without any request or coupon issue event. */
	public function test_missing_secret_fails_closed_before_external_request(): void {
		\Patchwork\redefine( array( Settings::class, 'turnstile_secret' ), static fn(): string => '' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\expect( 'wp_remote_post' )->never();
		Functions\expect( 'do_action' )->never();
		self::assertFalse( ( new RestController( new CouponService() ) )->verify_turnstile( 'unverified-client-token' ) );
	}

	/** @testdox Missing configuration cannot issue a coupon through the real REST handler. */
	public function test_missing_secret_blocks_rest_issuance_without_events(): void {
		$this->options['akb_welcome_coupon_enabled'] = 'yes';
		\Patchwork\redefine( array( Settings::class, 'turnstile_secret' ), static fn(): string => '' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\expect( 'wp_remote_post' )->never();
		Functions\expect( 'do_action' )->never();
		$request = new \WP_REST_Request( array( 'name' => 'Visitante', 'email' => 'qa@example.test', 'cf-turnstile-response' => 'unverified-client-token' ) );
		$response = ( new RestController( new CouponService() ) )->handle( $request );
		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'turnstile_failed', $response->get_data()['code'] );
	}

	/** @testdox A provider network error cannot count as successful CAPTCHA verification. */
	public function test_network_failure_fails_closed(): void {
		\Patchwork\redefine( array( Settings::class, 'turnstile_secret' ), static fn(): string => 'test-only-private-key-without-real-access' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_remote_post' )->justReturn( new \WP_Error( 'connection_failed', 'Test provider unavailable' ) );
		Functions\when( 'is_wp_error' )->justReturn( true );
		self::assertFalse( ( new RestController( new CouponService() ) )->verify_turnstile( 'unverified-client-token' ) );
	}
}
