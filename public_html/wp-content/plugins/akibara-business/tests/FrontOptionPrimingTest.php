<?php
/**
 * Las options que la unidad lee en cada petición del front se calientan juntas.
 *
 * Business mete muy pocas consultas SQL en un request de visitante, y las que
 * mete eran todas del mismo tipo: `get_option()` de una option que no viaja en
 * `alloptions` —tres de ellas nunca se han escrito siquiera—, o sea un SELECT
 * por nombre. Esta lista tiene que seguir nombrando exactamente las options que
 * el front lee, o el priming deja de servir sin que nada falle.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests;

use Akibara\Business\Modules\BrandsDirectory\BrandLandings;
use Akibara\Business\Plugin;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

final class FrontOptionPrimingTest extends TestCase {

	/** @return array<int, string> */
	private function primed(): array {
		return (array) ( new ReflectionClassConstant( Plugin::class, 'FRONT_READ_OPTIONS' ) )->getValue();
	}

	private function plugin_root(): string {
		return dirname( __DIR__ );
	}

	/**
	 * Cada option de la lista tiene que leerse de verdad en el módulo que la
	 * declara: una entrada de más calienta una fila que nadie pide.
	 *
	 * @dataProvider frontOptions
	 */
	public function test_every_primed_option_is_read_by_its_module( string $option, string $archivo ): void {
		self::assertContains( $option, $this->primed() );

		$fuente = (string) file_get_contents( $this->plugin_root() . '/' . $archivo );
		self::assertStringContainsString(
			$option,
			$fuente,
			sprintf( '%s ya no lee «%s»: sácala del priming.', $archivo, $option )
		);
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function frontOptions(): array {
		return array(
			'esquema de lista de espera' => array( 'akb_bis_db_version', 'src/Modules/BackInStock/Install.php' ),
			'esquema de sigue serie'     => array( 'akb_sf_db_version', 'src/Modules/SeriesFollow/Install.php' ),
			'captura de lista de espera' => array( 'akb_bis_capture_enabled', 'src/Modules/BackInStock/BackInStock.php' ),
			'recordatorio T-7'           => array( 'akb_preventa_t7_enabled', 'src/Modules/OrderPreventa/Settings.php' ),
			'color primario'             => array( 'akb_brand_color_primary', 'src/Modules/ThemeCustomizer/ThemeCustomizer.php' ),
			'color de superficie'        => array( 'akb_brand_color_surface', 'src/Modules/ThemeCustomizer/ThemeCustomizer.php' ),
			'crossorigin'                => array( 'akb_crossorigin_scripts_enabled', 'src/Modules/ScriptDiagnostics/CrossOriginScripts.php' ),
			'GA4 y Clarity tras la carga' => array( 'akb_analytics_after_load', 'src/Modules/SiteSpeed/AnalyticsAfterLoad.php' ),
			'carrito liviano'            => array( 'akb_lean_cart', 'src/Modules/SiteSpeed/LeanCart.php' ),
			'aviso del corazón'          => array( 'akb_wishlist_guest_prompt', 'src/Modules/Wishlist/GuestPrompt.php' ),
			'título del aviso'           => array( 'akb_copy_wl_guest_title', 'src/Modules/Wishlist/GuestPrompt.php' ),
			'bajada del aviso'           => array( 'akb_copy_wl_guest_body', 'src/Modules/Wishlist/GuestPrompt.php' ),
			'botón para entrar'          => array( 'akb_copy_wl_guest_cta', 'src/Modules/Wishlist/GuestPrompt.php' ),
			'botón para seguir'          => array( 'akb_copy_wl_guest_dismiss', 'src/Modules/Wishlist/GuestPrompt.php' ),
			'relacionados de la ficha'   => array( 'akb_reco_relacionados', 'src/Modules/Recommendations/Settings.php' ),
			'upsells con serie agotada'  => array( 'akb_reco_serie_agotada', 'src/Modules/Recommendations/Settings.php' ),
			'más vendidos por serie'     => array( 'akb_reco_mas_vendidos', 'src/Modules/Recommendations/Settings.php' ),
			'más vendidos en la búsqueda vacía' => array( 'akb_reco_mas_vendidos_busqueda', 'src/Modules/Recommendations/Settings.php' ),
			'título de upsells'          => array( 'akb_copy_reco_upsells_heading', 'src/Modules/Recommendations/Headings.php' ),
			'título de relacionados'     => array( 'akb_copy_reco_related_heading', 'src/Modules/Recommendations/Headings.php' ),
			'enlace del resumen de serie' => array( 'akb_copy_series_summary_link', 'src/Modules/SeriesFacts/SeriesFacts.php' ),
			'atajo al último tomo'        => array( 'akb_copy_series_latest_volume', 'src/Modules/SeriesFacts/SeriesFacts.php' ),
			'gancho del pack'            => array( 'akb_copy_series_offer_hook', 'src/Modules/SeriesBundles/LandingOffer.php' ),
			'condiciones del pack'       => array( 'akb_copy_series_offer_rules', 'src/Modules/SeriesBundles/LandingOffer.php' ),
			'fallo del armador'          => array( 'akb_copy_pack_error', 'src/Modules/SeriesBundles/SelectionCart.php' ),
			// Revisión B3-D3: se lee en cada landing de serie y la fila no existe
			// mientras el dueño no guarde un valor distinto de 48.
			'tomos por página de serie'  => array( 'akb_series_landing_per_page', 'src/Modules/SeriesCatalog/LandingPageSize.php' ),
			'fecha en la barra de compra' => array( 'akb_copy_eta_bar', 'src/Modules/EstimatedDelivery/BannerRenderer.php' ),
			'botón de vista rápida'      => array( 'akb_copy_quick_view_label', 'src/Modules/QuickView/QuickView.php' ),
			'enlace a la serie de la ficha' => array( 'akb_copy_pdp_series_link', 'src/Modules/PdpEnhancer/PdpSeriesEnhancer.php' ),
			'otras ediciones de la obra' => array( 'akb_copy_series_editions_label', 'src/Modules/SeriesEditions/SeriesEditions.php' ),
			'pista del buscador (tema)'  => array( 'akb_copy_search_placeholder', 'src/Modules/CopyManager/CopyManager.php' ),
			'cierre del blog (tema)'     => array( 'akb_copy_guide_cta_text', 'src/Modules/CopyManager/CopyManager.php' ),
			'botón a la tienda (tema)'   => array( 'akb_copy_guide_cta_shop', 'src/Modules/CopyManager/CopyManager.php' ),
			'botón a la serie (tema)'    => array( 'akb_copy_guide_cta_series', 'src/Modules/CopyManager/CopyManager.php' ),
			'antetítulo de la tarjeta (tema)' => array( 'akb_copy_guide_card_kicker', 'src/Modules/CopyManager/CopyManager.php' ),
			'botón de la tarjeta (tema)' => array( 'akb_copy_guide_card_cta', 'src/Modules/CopyManager/CopyManager.php' ),
			// QA integral 2026-09-24 (QA-DIFF-4 = BIZ-QA-6): sin fila en producción
			// y leídos en páginas de visitante, fuera del priming.
			'más vendidos en los vacíos (tema)' => array( 'akb_copy_search_empty_popular', 'src/Modules/CopyManager/CopyManager.php' ),
			'contacto: campo vacío'      => array( 'akb_copy_form_value_missing', 'src/Modules/Forms/Settings.php' ),
			'contacto: correo inválido'  => array( 'akb_copy_form_type_mismatch', 'src/Modules/Forms/Settings.php' ),
			'contacto: demasiado largo'  => array( 'akb_copy_form_too_long', 'src/Modules/Forms/Settings.php' ),
			'cuotas (tema)'              => array( 'akb_copy_promise_installments', 'src/Services/CheckoutTrustSignals.php' ),
			'horario del pie (tema)'     => array( 'akb_copy_footer_support_hours', 'src/Modules/CopyManager/CopyManager.php' ),
			'teléfono del pie (tema)'    => array( 'akb_copy_footer_phone', 'src/Modules/CopyManager/CopyManager.php' ),
			'botón de preventa'          => array( 'akb_copy_cta_preventa', 'src/Modules/OrderPreventa/PreventaUX.php' ),
			'nombre del carrusel de la portada' => array( 'akb_copy_hero_label', 'src/Modules/HomeHero/Renderer.php' ),
			'popup primera compra activo' => array( 'akb_welcome_coupon_enabled', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup primera compra porcentaje' => array( 'akb_welcome_coupon_pct', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup primera compra mínimo' => array( 'akb_welcome_coupon_min_amount', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup primera compra vigencia' => array( 'akb_welcome_coupon_validity_days', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup primera compra espera' => array( 'akb_welcome_coupon_delay_seconds', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup primera compra scroll' => array( 'akb_welcome_coupon_scroll_pct', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup primera compra enfriamiento' => array( 'akb_welcome_coupon_dismiss_days', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup primera compra intención salida' => array( 'akb_welcome_coupon_exit_intent', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup primera compra distintivo reapertura' => array( 'akb_welcome_coupon_launcher', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup primera compra en portada' => array( 'akb_welcome_coupon_show_home', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup primera compra en catálogo' => array( 'akb_welcome_coupon_show_catalog', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup primera compra en ficha' => array( 'akb_welcome_coupon_show_product', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup primera compra en serie' => array( 'akb_welcome_coupon_show_series', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup primera compra turnstile activo' => array( 'akb_welcome_turnstile_enabled', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup primera compra turnstile sitekey' => array( 'akb_welcome_turnstile_sitekey', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup primera compra antetítulo' => array( 'akb_copy_welcome_kicker', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup primera compra título' => array( 'akb_copy_welcome_heading', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup primera compra bajada' => array( 'akb_copy_welcome_subtitle', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup primera compra botón' => array( 'akb_copy_welcome_cta', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup primera compra cierre' => array( 'akb_copy_welcome_dismiss', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup primera compra nota de confianza' => array( 'akb_copy_welcome_trust_note', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup primera compra legal' => array( 'akb_copy_welcome_legal', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup promo estático activo' => array( 'akb_welcome_promo_enabled', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup promo estático código' => array( 'akb_welcome_promo_code', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup promo estático audiencia' => array( 'akb_welcome_promo_audience', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup promo estático espera' => array( 'akb_welcome_promo_delay_seconds', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup promo estático enfriamiento' => array( 'akb_welcome_promo_dismiss_days', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup promo estático en portada' => array( 'akb_welcome_promo_show_home', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup promo estático en catálogo' => array( 'akb_welcome_promo_show_catalog', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup promo estático en ficha' => array( 'akb_welcome_promo_show_product', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup promo estático en serie' => array( 'akb_welcome_promo_show_series', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup cupón época de cookie' => array( 'akb_welcome_coupon_cookie_epoch', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup promo estático antetítulo' => array( 'akb_copy_welcome_promo_kicker', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup promo estático título' => array( 'akb_copy_welcome_promo_heading', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup promo estático bajada' => array( 'akb_copy_welcome_promo_subtitle', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup promo estático botón' => array( 'akb_copy_welcome_promo_cta', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'popup promo estático legal' => array( 'akb_copy_welcome_promo_legal', 'src/Modules/WelcomeCoupon/Settings.php' ),
			'barra anuncios rotación' => array( 'akibara_announcement_rotate', 'src/Modules/AnnouncementBar/AnnouncementBar.php' ),
			'barra anuncios segundos rotación' => array( 'akibara_announcement_rotate_secs', 'src/Modules/AnnouncementBar/AnnouncementBar.php' ),
			'disponibilidad ficha a pedido' => array( 'akb_copy_eta_single_pedido', 'src/Modules/Stock/Availability.php' ),
			'disponibilidad ficha preventa' => array( 'akb_copy_eta_single_preventa', 'src/Modules/Stock/Availability.php' ),
			'upsells automáticos activos' => array( 'akibara_upsells_enabled', 'src/Modules/UpsellsAutofill/UpsellsAutofill.php' ),
			'sitemap series cambios pendientes' => array( 'akb_pa_serie_sitemap_pending_changes', 'src/Modules/SeoIndexing/SeriesSitemapLastmod.php' ),
			'sitemap series estado renderer' => array( 'akb_pa_serie_renderer_state', 'src/Modules/SeoIndexing/SeriesSitemapLastmod.php' ),
			'home destacados generación caché' => array( 'akb_home_destacados_generation', 'src/Modules/HomeDestacados/HomeDestacados.php' ),
			'drawer carrito aviso envío' => array( 'akibara_blocks_shipping_hint_text', 'src/Modules/NativeCart/Settings.php' ),
			'drawer carrito aviso colección' => array( 'akibara_blocks_collection_notice_text', 'src/Modules/NativeCart/Settings.php' ),
		);
	}

	/**
	 * El otro sentido (QA-DIFF-4 = BIZ-QA-6): cada copy que lee el TEMA tiene que
	 * estar en el lote. El tema no puede calentarlo —es sólo UI y no sabe qué
	 * options existen—, y `akb_copy_search_empty_popular` llegó al tema sin entrar
	 * al lote: la prueba de arriba sólo miraba que lo calentado tuviera lector.
	 */
	public function test_every_copy_the_theme_reads_is_primed(): void {
		$tema = (string) file_get_contents( dirname( $this->plugin_root(), 3 ) . '/wp-content/themes/akibara-gp/inc/site-copy.php' );
		self::assertNotSame( '', $tema, 'Fixture: se lee el site-copy.php del tema.' );

		preg_match_all( "~\\b(?:akibara_gp_copy|get_option)\\(\\s*'(akb_copy_[a-z0-9_]+)'~", $tema, $m );
		$leidas = array_values( array_unique( $m[1] ) );
		self::assertContains( 'akb_copy_search_empty_popular', $leidas, 'Fixture: el tema lee el título de los vacíos.' );

		self::assertSame( array(), array_values( array_diff( $leidas, $this->primed() ) ), 'Copys que lee el tema y no se calientan.' );
	}

	/**
	 * Y cada mensaje del formulario de contacto: `Forms\Settings::messages()` los
	 * lee al encolar el formulario, con la fila vacía hasta que el dueño los edita.
	 */
	public function test_every_contact_form_message_is_primed(): void {
		$opciones = array_column( \Akibara\Business\Modules\Forms\Settings::MESSAGES, 'option' );

		self::assertCount( 3, $opciones );
		self::assertSame( array(), array_values( array_diff( $opciones, $this->primed() ) ) );
	}

	/**
	 * Cada option calentada tiene su fila arriba, con el fichero que la lee: si
	 * no, una entrada de la lista escapa a la comprobación de que alguien la
	 * lee de verdad (las siete que sumaron B5 e I1 no tenían fila).
	 */
	public function test_every_primed_option_names_its_reader(): void {
		$con_fila = array_column( self::frontOptions(), 0 );

		self::assertSame( array(), array_values( array_diff( $this->primed(), $con_fila ) ), 'Options calentadas sin fila en frontOptions().' );
	}

	/**
	 * El priming ocurre ANTES de registrar los módulos: si se hiciera después,
	 * cada módulo ya habría pagado su SELECT.
	 */
	public function test_priming_happens_before_the_modules_register(): void {
		$fuente = (string) file_get_contents( $this->plugin_root() . '/src/Plugin.php' );

		$prime  = strpos( $fuente, 'wp_prime_option_caches( array_merge( self::FRONT_READ_OPTIONS, BrandLandings::front_read_options() ) )' );
		$primer = strpos( $fuente, "\$this->modules['mail_from'] = new MailFrom();" );

		self::assertNotFalse( $prime, 'El priming del front tiene que existir.' );
		self::assertNotFalse( $primer );
		self::assertLessThan(
			$primer,
			$prime,
			'Calentar las options después de registrar los módulos no ahorra ninguna consulta.'
		);
	}

	/**
	 * Las etiquetas de familia van en el mismo lote: el menú de Editoriales las
	 * lee en cada página (MenuInjector → BrandLandings::all()).
	 */
	public function test_family_labels_are_primed_because_the_menu_reads_them(): void {
		// `BrandLandings::all()` pasa por `apply_filters()`. Sin este fixture la
		// prueba sólo pasaba cuando otra lo había cargado antes; corrida sola caía
		// con «undefined function» (2026-09-19). Mismo require que usan las demás
		// pruebas de servicio que no montan Brain Monkey.
		require_once __DIR__ . '/fixtures/wordpress-functions.php';

		$menu = (string) file_get_contents( $this->plugin_root() . '/src/Modules/BrandsDirectory/MenuInjector.php' );
		self::assertStringContainsString( 'BrandLandings::all()', $menu );

		$options = BrandLandings::front_read_options();
		self::assertSame(
			array(
				'akb_brand_landing_ivrea_menu_label',
				'akb_brand_landing_ivrea_callout_label',
				'akb_brand_landing_panini_menu_label',
				'akb_brand_landing_panini_callout_label',
			),
			$options
		);
		// Se calienta lo que all() lee de verdad: cada option escrita reaparece en la fila.
		$GLOBALS['akibara_test_options'] = array();
		foreach ( $options as $i => $option ) {
			$GLOBALS['akibara_test_options'][ $option ] = 'valor ' . $i;
		}
		$landings = BrandLandings::all();
		$vistos   = array();
		foreach ( $landings as $key => $fila ) {
			foreach ( array_keys( BrandLandings::label_fields() ) as $field ) {
				$vistos[] = (string) ( $fila[ $field ] ?? '' );
			}
		}
		unset( $GLOBALS['akibara_test_options'] );

		self::assertSame( array( 'valor 0', 'valor 1', 'valor 2', 'valor 3' ), $vistos, 'Cada option calentada es una etiqueta que el menú pinta.' );
	}

	/**
	 * Y sigue siendo una sola llamada: priming por lotes, no uno por option.
	 */
	public function test_the_front_priming_is_a_single_batched_call(): void {
		$fuente = (string) file_get_contents( $this->plugin_root() . '/src/Plugin.php' );

		self::assertSame(
			1,
			substr_count( $fuente, 'wp_prime_option_caches( array_merge( self::FRONT_READ_OPTIONS, BrandLandings::front_read_options() ) )' )
		);
		self::assertNotSame( array(), $this->primed() );
	}
}
