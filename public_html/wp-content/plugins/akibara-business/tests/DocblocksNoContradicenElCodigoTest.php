<?php
/**
 * Candados de fuente para comentarios/docblocks que se demostraron
 * inconsistentes con el código que documentan (lote 6 del inventario QA,
 * 2026-09-19). Ninguno de estos cuatro cambia el comportamiento en runtime
 * — PHP no ejecuta comentarios — así que montar una prueba de comportamiento
 * sería desproporcionado: el candado verifica el TEXTO publicado, que es
 * exactamente lo que el hallazgo reporta como roto.
 *
 * - S-c7b7e7c9: el ejemplo del docblock de `ChileanPhone::format()` recortaba
 *   el `9` que el código antepone siempre.
 * - S-6e5466b9: el docblock de `forget_series_medium()` decía TTL de 12 h
 *   mientras la constante `SERIES_MEDIUM_TTL` dice 36.
 * - S-fa09cca0: el comentario de `SeoSocial::prefer_jpeg_social_image()`
 *   prometía que `ensure_for_slug()` convierte «aquí mismo», y ese método ya
 *   no convierte nada de forma síncrona.
 * - S-deea9976: el docblock de `apply_preventa()` afirmaba PreOrder para
 *   TODO onbackorder, el bug histórico que el propio código de abajo
 *   describe como corregido desde 1.109.0.
 * - B4-2-R2 (2026-09-23): la copia del contrato de funciones puente en
 *   `akibara-business.php` seguía diciendo «Son OCHO» y omitía
 *   `akibara_search_empty_suggestions()`, la novena que declara
 *   `akibara-search.php`. Esa copia ya se había corregido una vez a mano
 *   (R10-puente-mal-contado); el candado la ata a lo que declara el buscador.
 * - B3-D4 (2026-09-23): SeriesFacts seguía describiendo la ficha «bajo el
 *   hero», con «las cards de abajo» y el total «dos líneas más abajo», cuando
 *   desde B3 la ficha se pinta en `woocommerce_after_shop_loop`, bajo la
 *   rejilla y la paginación, y el total va en la barra de arriba.
 * - Sesión SEO/GEO (2026-09-23): el docblock de ProductSchemaShopping citaba el
 *   envío gratis «sobre $55.000»; el umbral real es de $60.000.
 * - RZN-3 (2026-09-25): el límite conocido de `announcement-bar.css` decía que
 *   el puntito del emoji sólo sale con mensajes que el contador del panel ya
 *   marca como largos. El contador está medido a 360px; a 320 caben de 71 a 78
 *   caracteres y un mensaje aceptado llega a la tercera línea.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests;

use Akibara\Business\Modules\AnnouncementBar\AnnouncementBar;
use Akibara\Business\Utilities\ChileanPhone;
use PHPUnit\Framework\TestCase;

final class DocblocksNoContradicenElCodigoTest extends TestCase {

	private function plugin_root(): string {
		return __DIR__;
	}

	private function source( string $relative_path ): string {
		return (string) file_get_contents( $this->plugin_root() . '/../' . $relative_path );
	}

	/**
	 * @testdox El ejemplo del docblock de ChileanPhone::format() debe coincidir con lo que el método realmente produce (S-c7b7e7c9).
	 */
	public function test_chilean_phone_format_docblock_example_matches_real_output(): void {
		$actual = ChileanPhone::format( '11111111' );
		self::assertSame( '+56911111111', $actual, 'El método siempre antepone el 9 — si esto cambia, el docblock también debe cambiar.' );

		$source = $this->source( 'src/Utilities/ChileanPhone.php' );
		self::assertStringContainsString( '`' . $actual . '`', $source );
		self::assertStringNotContainsString( '+5611111111', $source, 'S-c7b7e7c9: ejemplo viejo con el 9 recortado.' );
	}

	/**
	 * @testdox El docblock de forget_series_medium() debe citar el mismo TTL que SERIES_MEDIUM_TTL (S-6e5466b9).
	 */
	public function test_forget_series_medium_docblock_cites_the_real_ttl(): void {
		$source = $this->source( 'src/Modules/SeoEnhancements/SeoEnhancements.php' );

		self::assertMatchesRegularExpression(
			'/SERIES_MEDIUM_TTL\s*=\s*36\s*\*\s*HOUR_IN_SECONDS/',
			$source,
			'La constante debe seguir en 36 h para que esta prueba tenga sentido.'
		);
		self::assertStringContainsString( 'TTL de 36 h', $source );
		self::assertStringNotContainsString( 'TTL de 12 h', $source, 'S-6e5466b9: el docblock decía 12 h con la constante en 36.' );
	}

	/**
	 * @testdox El comentario de SeoSocial no debe prometer que og:image se convierte "aquí mismo" (S-fa09cca0).
	 */
	public function test_seo_social_comment_does_not_claim_synchronous_conversion(): void {
		$source = $this->source( 'src/Modules/SeoSocial/SeoSocial.php' );

		self::assertStringNotContainsString(
			'se genera aquí mismo',
			$source,
			'S-fa09cca0: ensure_for_slug() ya no convierte de forma síncrona, solo encola.'
		);
		self::assertStringContainsString( 'ENCOLA', $source );
	}

	/**
	 * @testdox El docblock de apply_preventa() no debe afirmar PreOrder para TODO onbackorder (S-deea9976).
	 */
	public function test_apply_preventa_docblock_does_not_claim_preorder_for_every_onbackorder(): void {
		$source = $this->source( 'src/Modules/SchemaProductRankMath/SchemaProductRankMath.php' );

		self::assertStringNotContainsString(
			'para todo onbackorder',
			$source,
			'S-deea9976: desde 1.109.0 PreOrder es solo para preventa, BackOrder para a pedido.'
		);
	}

	/**
	 * @testdox La copia del contrato de funciones puente en akibara-business.php nombra y cuenta todas las que declara akibara-search.php (B4-2-R2).
	 */
	public function test_la_copia_del_contrato_de_funciones_puente_esta_al_dia(): void {
		$search = (string) file_get_contents( dirname( $this->plugin_root(), 2 ) . '/akibara-search/akibara-search.php' );
		preg_match_all( "/if \\( ! function_exists\\( 'akibara_search_([a-z_]+)' \\) \\)/", $search, $matches );
		$declared = $matches[1];
		self::assertGreaterThanOrEqual( 9, count( $declared ), 'El inventario se lee de akibara-search.php: si no encuentra las funciones, el patrón quedó viejo.' );

		$source = $this->source( 'akibara-business.php' );
		$start  = strpos( $source, '// Contrato del buscador:' );
		self::assertNotFalse( $start, 'El bloque del contrato desapareció de akibara-business.php.' );
		$block = substr( $source, (int) $start, (int) strpos( $source, '// ----', (int) $start ) - (int) $start );

		preg_match_all( '/`(?:akibara_search)?_([a-z_]+)`/', $block, $listed );
		$expected = $declared;
		$actual   = $listed[1];
		sort( $expected );
		sort( $actual );
		self::assertSame( $expected, $actual, 'La lista de la copia tiene que ser exactamente la que declara el buscador.' );

		$numbers = array( 1 => 'una', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce' );
		$number  = $numbers[ count( $declared ) ] ?? (string) count( $declared );
		self::assertStringContainsString( 'Son ' . mb_strtoupper( $number ) . ',', $block );
		self::assertStringContainsString( 'De esas ' . $number . ',', $block );
	}

	/**
	 * @testdox Los comentarios de SeriesFacts describen la ficha bajo la rejilla, que es donde se pinta (B3-D4).
	 */
	public function test_series_facts_no_describe_la_posicion_vieja_de_la_ficha(): void {
		$source = $this->source( 'src/Modules/SeriesFacts/SeriesFacts.php' );

		self::assertStringContainsString( 'add_action( \'woocommerce_after_shop_loop\', array( $this, \'render\' ), 20 );', $source, 'Si la ficha vuelve arriba, este candado y los comentarios cambian juntos.' );
		foreach ( array( 'bajo el hero', 'cards de abajo', 'dos líneas más abajo', 'dos centímetros más arriba' ) as $frase ) {
			self::assertStringNotContainsString( $frase, $source, "«{$frase}» describe la ficha sobre la rejilla." );
		}
	}

	/**
	 * @testdox El docblock de ProductSchemaShopping cita el umbral de envío gratis vigente, no el de antes.
	 */
	public function test_product_schema_shopping_cita_el_umbral_vigente(): void {
		$source = $this->source( 'src/Modules/ProductSchemaShopping/ProductSchemaShopping.php' );

		self::assertStringNotContainsString( '$55.000', $source );
		self::assertStringContainsString( '$60.000', $source );
	}

	/**
	 * @testdox El límite conocido del emoji en la barra de anuncios no promete que el contador del panel lo evita a 320 px (RZN-3).
	 */
	public function test_el_limite_del_emoji_no_promete_lo_que_el_contador_no_cubre(): void {
		$css = $this->source( 'assets/css/announcement-bar.css' );
		$php = $this->source( 'src/Modules/AnnouncementBar/AnnouncementBar.php' );

		self::assertStringContainsString( '(a 320px, de 71 a 78)', $php, 'Si se vuelve a medir, este candado y los dos comentarios cambian juntos.' );
		self::assertGreaterThan( 71, (int) AnnouncementBar::LINE_MAX, 'Con el contador a 71 o menos, a 320 px ya no se llega a la tercera línea y el comentario puede cambiar.' );
		self::assertStringNotContainsString( 'ya marca', $css, 'RZN-3: el contador no marca lo que cabe a 360 px y no a 320.' );
		self::assertStringContainsString( 'medido a 360px', $css );
		self::assertStringContainsString( 'a 320px', $css );
		self::assertStringContainsString( 'no a 320', $php );
	}
}
