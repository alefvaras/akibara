<?php
/**
 * Pruebas unitarias para la cuenta regresiva (Countdown Timer) de la barra de anuncios
 * y su sincronización con la campaña de Akibara → Descuentos.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AnnouncementBar;

use Akibara\Business\Modules\AnnouncementBar\AnnouncementBar;
use Akibara\Business\Modules\AnnouncementBar\PromoSchedule;
use Akibara\Business\Modules\DiscountCopy\DiscountCopy;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class AnnouncementBarCountdownTest extends TestCase {

	private function dynamic_dates(): array {
		$tz = new \DateTimeZone( 'America/Santiago' );
		$today    = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
		$tomorrow = ( new \DateTimeImmutable( '+1 day', $tz ) )->format( 'Y-m-d' );
		$end_5d   = ( new \DateTimeImmutable( '+5 days', $tz ) )->format( 'Y-m-d' );
		return array( $today, $tomorrow, $end_5d );
	}

	private function seed_discount_campaign( string $from, string $to, int $pct = 15, string $term_name = 'Ivrea Argentina' ): void {
		$GLOBALS['akibara_test_options']['akibara_taxonomy_discount_enabled']  = 'yes';
		$GLOBALS['akibara_test_options']['akibara_taxonomy_discount_from']     = $from;
		$GLOBALS['akibara_test_options']['akibara_taxonomy_discount_to']       = $to;
		$GLOBALS['akibara_test_options']['akibara_taxonomy_discount_apply_to'] = 'all';

		$prop = new ReflectionProperty( DiscountCopy::class, 'rule_memo' );
		$prop->setAccessible( true );
		$prop->setValue(
			null,
			array(
				'v'           => 2,
				'pct'         => $pct,
				'term_name'   => $term_name,
				'term_id'     => 230,
				'taxonomy'    => 'product_brand',
				'term_path'   => '/marca/ivrea-argentina/',
				'rules_count' => 1,
			)
		);
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$GLOBALS['akibara_test_options'] = array();

		$tz = new \DateTimeZone( 'America/Santiago' );
		$today = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
		$now_ts = time();

		Functions\when( 'wp_timezone' )->justReturn( $tz );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( static fn( string $u, int $c = -1 ) => parse_url( $u, $c ) );
		Functions\when( 'home_url' )->alias( static fn( string $p = '' ): string => 'https://akibara.cl' . $p );
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ): string => trim( strip_tags( (string) $v ) ) );
		Functions\when( 'current_time' )->alias(
			static function ( string $type ) use ( $today, $now_ts ) {
				if ( 'timestamp' === $type ) {
					return $now_ts;
				}
				if ( 'Y-m-d' === $type ) {
					return $today;
				}
				return $today . ' 12:00:00';
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_test_options'] );
		$_POST = array();
		$prop = new ReflectionProperty( DiscountCopy::class, 'rule_memo' );
		$prop->setAccessible( true );
		$prop->setValue( null, false );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Si la cuenta regresiva está apagada y no hay token manual, el texto no se altera.
	 */
	public function test_countdown_disabled_returns_clean_line_without_badge(): void {
		list( $today, , $end_5d ) = $this->dynamic_dates();
		$this->seed_discount_campaign( $today, $end_5d );
		$GLOBALS['akibara_test_options'][ AnnouncementBar::OPTION_COUNTDOWN_ENABLED ] = 'no';

		$bar = new AnnouncementBar();
		$m   = new \ReflectionMethod( $bar, 'promo_lines' );
		$m->setAccessible( true );

		$GLOBALS['akibara_test_options']['akibara_announcement_promo_text'] = '¡Promo especial!';
		$lines = $m->invoke( $bar );

		self::assertCount( 1, $lines );
		self::assertSame( '¡Promo especial!', $lines[0] );
		self::assertStringNotContainsString( 'akb-ann-countdown', $lines[0] );
	}

	/**
	 * @testdox Cuando se activa la cuenta regresiva, se adjunta automáticamente el badge respetando tanto pipe links como <a href="..."> literal.
	 */
	public function test_countdown_auto_attached_when_enabled(): void {
		list( $today, , $end_5d ) = $this->dynamic_dates();
		$this->seed_discount_campaign( $today, $end_5d );
		$GLOBALS['akibara_test_options'][ AnnouncementBar::OPTION_COUNTDOWN_ENABLED ] = 'yes';
		$GLOBALS['akibara_test_options'][ AnnouncementBar::OPTION_COUNTDOWN_TIME ]    = '23:59';
		$GLOBALS['akibara_test_options'][ AnnouncementBar::OPTION_COUNTDOWN_LABEL ]   = 'Termina en';
		$GLOBALS['akibara_test_options']['akibara_announcement_promo_text'] = 'Gran venta Manga | /ofertas/';

		$bar = new AnnouncementBar();
		$m   = new \ReflectionMethod( $bar, 'promo_lines' );
		$m->setAccessible( true );

		$lines = $m->invoke( $bar );
		self::assertCount( 1, $lines );
		self::assertStringContainsString( 'class="akb-ann-countdown"', $lines[0] );
		self::assertStringContainsString( 'class="akb-ann-countdown__icon"', $lines[0] );
		self::assertStringContainsString( 'data-akb-countdown-epoch=', $lines[0] );
		self::assertStringContainsString( 'Termina en', $lines[0] );
		self::assertStringEndsWith( '| /ofertas/', $lines[0] );

		$GLOBALS['akibara_test_options']['akibara_announcement_promo_text'] = '<a href="https://akibara.cl/marca/ivrea/">Este finde en Ivrea</a>';
		$html_lines = $m->invoke( $bar );
		self::assertCount( 1, $html_lines );
		self::assertStringEndsWith( '</a>', $html_lines[0], 'En <a href="..."> literal, el badge debe ir antes del cierre </a> para que wp_kses lo conserve clickable.' );
		self::assertStringContainsString( 'class="akb-ann-countdown"', $html_lines[0] );
	}

	/**
	 * @testdox El token legado [akb_cuenta_regresiva] se limpia pasivamente sin ignorar el interruptor del contador.
	 */
	public function test_legacy_manual_countdown_token_is_stripped_without_bypass(): void {
		list( $today, , $end_5d ) = $this->dynamic_dates();
		$this->seed_discount_campaign( $today, $end_5d );
		$GLOBALS['akibara_test_options'][ AnnouncementBar::OPTION_COUNTDOWN_ENABLED ] = 'no';

		$raw = 'Atención: [akb_cuenta_regresiva] para aprovechar el descuento.';
		$m   = new \ReflectionMethod( AnnouncementBar::class, 'split_lines' );
		$m->setAccessible( true );

		$lines = $m->invoke( null, $raw );
		self::assertCount( 1, $lines );
		self::assertSame( 'Atención: para aprovechar el descuento.', $lines[0] );
		self::assertStringNotContainsString( 'akb-ann-countdown', $lines[0] );
		self::assertStringNotContainsString( '[akb_cuenta_regresiva]', $lines[0] );
	}

	/**
	 * @testdox El panel no expone selectores de color, fechas duplicadas, follow_discount ni shortcodes redundantes de contador.
	 */
	public function test_settings_exclude_color_pickers_and_legacy_shortcode(): void {
		$settings = ( new AnnouncementBar() )->get_settings();
		$ids      = array_column( $settings, 'id' );
		self::assertNotContains( 'akibara_announcement_bg', $ids );
		self::assertNotContains( 'akibara_announcement_color', $ids );
		self::assertNotContains( 'akibara_announcement_border', $ids );
		self::assertNotContains( 'akibara_announcement_promo_from', $ids );
		self::assertNotContains( 'akibara_announcement_promo_to', $ids );
		self::assertNotContains( 'akibara_announcement_promo_follow_discount', $ids );

		$encoded = (string) wp_json_encode( $settings );
		self::assertStringNotContainsString( 'akb_cuenta_regresiva', $encoded );
	}

	/**
	 * @testdox PromoSchedule rechaza horas fuera de rango y fechas de calendario imposibles, manteniendo zona Chile.
	 */
	public function test_promo_schedule_strict_time_and_calendar_validation(): void {
		self::assertFalse( PromoSchedule::is_valid_time_hm( '25:99' ) );
		self::assertFalse( PromoSchedule::is_valid_time_hm( '24:00' ) );
		self::assertTrue( PromoSchedule::is_valid_time_hm( '23:59' ) );
		self::assertSame( '23:59', PromoSchedule::parse_time_hm( '25:99' ) );
		self::assertNull( PromoSchedule::closing_timestamp( '2026-02-31', '23:59' ) );

		$fallback_ts = PromoSchedule::closing_timestamp( '2026-11-10', '25:99' );
		self::assertNotNull( $fallback_ts );
		$dt = ( new \DateTimeImmutable( '@' . $fallback_ts ) )->setTimezone( new \DateTimeZone( 'America/Santiago' ) );
		self::assertSame( '2026-11-10 23:59:59', $dt->format( 'Y-m-d H:i:s' ) );

		$_POST[ AnnouncementBar::OPTION_COUNTDOWN_TIME ] = '25:99';
		$errors = ( new AnnouncementBar() )->validate_save( array(), 'announcement' );
		self::assertCount( 1, $errors );
	}

	/**
	 * @testdox El objetivo del countdown lee únicamente la fecha fin de Akibara → Descuentos combinada con la hora de cierre.
	 */
	public function test_countdown_target_uses_taxonomy_discount_end_and_cutoff(): void {
		list( $today, , $end_5d ) = $this->dynamic_dates();
		$this->seed_discount_campaign( $today, $end_5d );
		$GLOBALS['akibara_test_options'][ AnnouncementBar::OPTION_COUNTDOWN_TIME ] = '20:00';

		$epoch = AnnouncementBar::get_countdown_target();
		self::assertNotNull( $epoch );

		$dt = new \DateTimeImmutable( '@' . $epoch );
		$dt = $dt->setTimezone( new \DateTimeZone( 'America/Santiago' ) );
		self::assertSame( $end_5d . ' 20:00:59', $dt->format( 'Y-m-d H:i:s' ) );
		self::assertStringContainsString( 'class="akb-ann-countdown"', AnnouncementBar::countdown_markup() );
	}

	/**
	 * @testdox Sin campaña activa (programada para mañana, sin reglas > 0%, o sin fecha fin) devuelve null y suprime chip expirado.
	 */
	public function test_countdown_target_returns_null_without_active_campaign_or_valid_date(): void {
		list( $today, $tomorrow, $end_5d ) = $this->dynamic_dates();

		// 1) Sin fecha fin aunque la campaña esté encendida.
		$this->seed_discount_campaign( $today, '' );
		self::assertNull( AnnouncementBar::get_countdown_target() );
		self::assertSame( '', AnnouncementBar::countdown_markup() );

		// 2) Campaña programada para mañana.
		$this->seed_discount_campaign( $tomorrow, $end_5d );
		self::assertNull( AnnouncementBar::get_countdown_target() );

		// 3) Fecha fin pasada: countdown_markup no renderiza chip expirado SSR.
		$past_epoch = time() - 3600;
		self::assertSame( '', AnnouncementBar::countdown_markup( $past_epoch ) );
	}
}
