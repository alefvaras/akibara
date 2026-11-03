<?php
/**
 * Candado del aviso de colección del checkout: qué texto sale, cuándo se apaga,
 * para quién es verdad y por qué canal llega al bloque.
 *
 * Clon deliberado de `ShippingHintCopyTest`: los dos avisos comparten contrato
 * (option en Akibara → Carrito, default literal del dueño, vacío guardado =
 * apagado) y si uno de los dos se desvía del otro el panel miente en uno.
 *
 * Además fija tres cosas que la primera versión dejó a la costumbre (revisión
 * independiente 2026-09-11): que el aviso se calle con el interruptor maestro
 * apagado y para el cliente que ya apagó sus avisos en Mi cuenta; que el copy
 * editable entre en el `<script>` inlinado por `wp_json_encode` y no por
 * comillas a mano; y que el default de este plugin sea byte a byte el mismo
 * que el del panel de akibara-business, porque hasta que la option exista el
 * panel muestra uno y el checkout sirve el otro.
 *
 * @package Akibara\CartCheckoutBlocks\Tests
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks\Tests;

use Akibara\CartCheckoutBlocks\Assets;
use Akibara\CartCheckoutBlocks\CheckoutBlockIntegration;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class CollectionNoticeCopyTest extends TestCase {

	private const DEFAULT_COPY = 'Con tu cuenta Akibara te avisamos por correo cuando salga el siguiente tomo de las series que compras. No es publicidad: lo apagas cuando quieras en Mi cuenta, pestaña Colección.';

	/** Nombre de la option maestra de SeriesFollow (akibara-business), copiado por nombre como hace el plugin. */
	private const MASTER_OPTION = 'akb_sf_enabled';

	/** Usermeta de la baja global (FollowRegistry::META_OPTOUT_ALL), idem. */
	private const OPTOUT_ALL_META = '_akb_sf_optout_all';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$GLOBALS['akibara_test_options'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_test_options'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Sin nada guardado, el checkout dice exactamente el copy del dueño
	 */
	public function test_the_default_is_the_owner_copy(): void {
		self::assertSame( self::DEFAULT_COPY, Assets::collection_notice_copy() );
	}

	/**
	 * @testdox El texto guardado en Akibara → Carrito es el que se sirve
	 */
	public function test_the_saved_copy_wins(): void {
		$GLOBALS['akibara_test_options']['akibara_blocks_collection_notice_text'] = 'Al comprar activas los avisos de tu colección.';

		self::assertSame( 'Al comprar activas los avisos de tu colección.', Assets::collection_notice_copy() );
	}

	/**
	 * @testdox Vaciar el campo apaga el aviso en vez de reponer el de fábrica
	 */
	public function test_a_saved_blank_switches_the_notice_off(): void {
		$GLOBALS['akibara_test_options']['akibara_blocks_collection_notice_text'] = '   ';

		// El bundle trata la cadena vacía como «no pintar» (contrato fijado en
		// checkout-inner-blocks.test.js); acá se fija que el servidor la deje
		// llegar vacía en vez de rellenarla con el default.
		self::assertSame( '', Assets::collection_notice_copy() );
	}

	/**
	 * @testdox El copy del dueño no lleva marcadores de printf ni emojis ni exclamaciones
	 *
	 * Un «%» en un texto editable ya fue un fatal en este repo (memoria: copys
	 * del dueño nunca como sprintf); y el aviso va en `<small>` como aclaración,
	 * no como reclamo.
	 */
	public function test_the_default_copy_is_a_plain_literal(): void {
		self::assertStringNotContainsString( '%', self::DEFAULT_COPY );
		self::assertStringNotContainsString( '!', self::DEFAULT_COPY );
		self::assertSame( 0, preg_match( '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', self::DEFAULT_COPY ) );
	}

	/**
	 * @testdox El default del checkout es byte a byte el del panel de akibara-business, para los dos avisos
	 *
	 * El mismo literal vive en dos plugins sin ninguna clase en común (este
	 * `src/` no puede importar módulos de Business: lo prohíbe el gate). Hasta
	 * que la fila de la option exista —el estado justo después de desplegar—
	 * el panel muestra el default de Business y el checkout sirve el de
	 * Blocks: si uno se edita sin el otro, el dueño ve un texto en la
	 * textarea y el cliente lee otro, con las dos suites en verde. Se lee el
	 * FUENTE del otro plugin, no su clase, para no cargar Business en esta
	 * suite ni depender de su autoload.
	 */
	public function test_the_defaults_match_the_business_panel_byte_for_byte(): void {
		$settings = dirname( __DIR__, 3 ) . '/akibara-business/src/Modules/NativeCart/Settings.php';
		self::assertFileExists( $settings, 'Business movió NativeCart\Settings: relee dónde vive el campo del panel.' );
		$source = (string) file_get_contents( $settings );

		foreach (
			array(
				'DEFAULT_BLOCKS_COLLECTION_NOTICE' => 'COLLECTION_NOTICE_DEFAULT',
				'DEFAULT_BLOCKS_SHIPPING_HINT'     => 'SHIPPING_HINT_DEFAULT',
			) as $business_constant => $blocks_constant
		) {
			self::assertSame(
				1,
				preg_match( '/const\s+' . $business_constant . "\s*=\s*'((?:[^'\\\\]|\\\\.)*)';/", $source, $match ),
				"Business ya no declara {$business_constant} como literal entre comillas simples."
			);
			// Literal PHP entre comillas simples: sólo \' y \\ son escapes.
			$business_value = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $match[1] );

			$blocks_value = ( new \ReflectionClassConstant( Assets::class, $blocks_constant ) )->getValue();

			self::assertSame( $business_value, $blocks_value, "{$business_constant} (Business) y {$blocks_constant} (Blocks) se separaron." );
		}
	}

	/**
	 * @testdox Con el interruptor maestro apagado, o sin la fila, el aviso no aplica para nadie
	 *
	 * `akb_sf_enabled` es quien decide si SE ENVÍA algo; su default es 'no'.
	 * Prometer el correo con él apagado es mentirle al 100% de los compradores.
	 */
	public function test_it_does_not_apply_while_the_master_switch_is_off(): void {
		Functions\expect( 'get_user_meta' )->never();

		foreach ( array( null, 'no', '', '0', 0, false, 'off' ) as $value ) {
			$GLOBALS['akibara_test_options'] = array();
			if ( null !== $value ) {
				$GLOBALS['akibara_test_options'][ self::MASTER_OPTION ] = $value;
			}

			self::assertFalse( Assets::collection_notice_applies( 0 ), 'invitado con maestro ' . var_export( $value, true ) );
			self::assertFalse( Assets::collection_notice_applies( 7 ), 'cliente con maestro ' . var_export( $value, true ) );
		}
	}

	/**
	 * @testdox Con el maestro encendido (en cualquiera de sus formatos) aplica al invitado y al cliente sin baja
	 *
	 * Mismo conjunto de valores que `SeriesFollow::option_is_enabled()`: el
	 * formato histórico (1/on) y el de WooCommerce (yes). Si Business ampliara
	 * ese conjunto sin que este lo siga, el checkout callaría con avisos vivos.
	 */
	public function test_it_applies_with_the_master_switch_on(): void {
		Functions\when( 'get_user_meta' )->justReturn( '' );

		foreach ( array( 'yes', '1', 1, 'on', true ) as $value ) {
			$GLOBALS['akibara_test_options'][ self::MASTER_OPTION ] = $value;

			self::assertTrue( Assets::collection_notice_applies( 0 ), 'invitado con maestro ' . var_export( $value, true ) );
			self::assertTrue( Assets::collection_notice_applies( 7 ), 'cliente con maestro ' . var_export( $value, true ) );
		}
	}

	/**
	 * @testdox Para el cliente que apagó los avisos de su colección el aviso no aplica, y la meta se lee por su nombre
	 *
	 * La meta la escriben «Apagar los avisos» de Mi cuenta → Colección y el
	 * List-Unsubscribe del correo, siempre con '1'. Es la única verdad y el
	 * digest la respeta; el checkout no puede decir lo contrario.
	 */
	public function test_it_does_not_apply_to_a_customer_who_opted_out_of_everything(): void {
		$GLOBALS['akibara_test_options'][ self::MASTER_OPTION ] = 'yes';
		Functions\expect( 'get_user_meta' )
			->once()
			->with( 7, self::OPTOUT_ALL_META, true )
			->andReturn( '1' );

		self::assertFalse( Assets::collection_notice_applies( 7 ) );
	}

	/**
	 * @testdox Una meta ausente o con otro valor no cuenta como baja
	 */
	public function test_only_the_literal_one_counts_as_an_opt_out(): void {
		$GLOBALS['akibara_test_options'][ self::MASTER_OPTION ] = 'yes';

		foreach ( array( '', false, null, '0', 'yes', array() ) as $stored ) {
			Functions\when( 'get_user_meta' )->justReturn( $stored );

			self::assertTrue( Assets::collection_notice_applies( 7 ), 'meta ' . var_export( $stored, true ) );
		}
	}

	/**
	 * @testdox La integración inlina el aviso antes del bundle y deja pasar el filtro
	 *
	 * Es el MISMO canal que `shippingHint` en `Assets::enqueue()`: global en
	 * `window`, `wp_add_inline_script` en posición `before`, filtro con el
	 * nombre literal de la option. Si alguien lo moviera a `get_script_data()`
	 * —que WooCommerce imprime pero ningún JS nuestro lee— el bloque quedaría
	 * mudo sin un solo error; por eso se fija el handle, la posición y el
	 * contenido, y no sólo «se llamó a algo».
	 */
	public function test_initialize_inlines_the_notice_for_the_frontend_bundle(): void {
		$GLOBALS['akibara_test_options'][ self::MASTER_OPTION ]                      = 'yes';
		$GLOBALS['akibara_test_options']['akibara_blocks_collection_notice_text'] = 'Texto guardado en el panel.';

		$data = $this->inline_from_initialize( 7, '' );

		self::assertSame( array( 'collectionNotice' => 'Texto guardado en el panel. (filtrado)' ), self::decode( $data ) );
	}

	/**
	 * @testdox El invitado recibe el aviso sin que se consulte ninguna usermeta
	 */
	public function test_initialize_inlines_the_notice_for_a_guest(): void {
		$GLOBALS['akibara_test_options'][ self::MASTER_OPTION ] = 'yes';

		$data = $this->inline_from_initialize( 0, null );

		self::assertSame( array( 'collectionNotice' => self::DEFAULT_COPY . ' (filtrado)' ), self::decode( $data ) );
	}

	/**
	 * @testdox Con el maestro apagado se inlina la cadena vacía y el filtro ni siquiera corre
	 *
	 * El filtro decide el texto, no si hay algo que decir: un mu-plugin no
	 * puede reencender un aviso que el dueño apagó en el panel.
	 */
	public function test_initialize_inlines_an_empty_notice_while_the_master_switch_is_off(): void {
		$GLOBALS['akibara_test_options']['akibara_blocks_collection_notice_text'] = 'Texto guardado en el panel.';

		// Con el maestro apagado la usermeta ni se consulta (null = never).
		$data = $this->inline_from_initialize( 7, null, false );

		self::assertSame( array( 'collectionNotice' => '' ), self::decode( $data ) );
	}

	/**
	 * @testdox Para el cliente con la baja global se inlina la cadena vacía aunque el panel tenga texto
	 *
	 * Reproduce el hallazgo: Mi cuenta → Colección dice «Apagados» y el
	 * checkout decía «te avisamos por correo». Ahora el bundle recibe '' y no
	 * pinta el `<p.akb-checkout-collection-notice>`.
	 */
	public function test_initialize_inlines_an_empty_notice_for_an_opted_out_customer(): void {
		$GLOBALS['akibara_test_options'][ self::MASTER_OPTION ]                      = 'yes';
		$GLOBALS['akibara_test_options']['akibara_blocks_collection_notice_text'] = 'Texto guardado en el panel.';

		$data = $this->inline_from_initialize( 7, '1', false );

		self::assertSame( array( 'collectionNotice' => '' ), self::decode( $data ) );
	}

	/**
	 * @testdox El copy editable entra en el script por wp_json_encode: comillas y </script> no rompen el bloque
	 *
	 * El texto lo puede escribir el dueño (el panel lo sanea) o un filtro (que
	 * no se sanea). `wp_json_encode` escapa las comillas y las barras, así que
	 * un `</script>` guardado sale como `<\/script>` y no cierra el bloque
	 * inlinado. Sustituir la concatenación por `esc_js()` o por comillas a
	 * mano dejaría `\'` —que no es JSON— y este round-trip lo delata; un
	 * `</script>` crudo o un salto de línea sin escapar también.
	 */
	public function test_the_inlined_notice_survives_quotes_and_script_tags(): void {
		$hostile = 'Aviso "x" con \'comilla\', barra \\ y' . "\n" . 'salto </script><script>alert(1)</script>';

		$GLOBALS['akibara_test_options'][ self::MASTER_OPTION ]                      = 'yes';
		$GLOBALS['akibara_test_options']['akibara_blocks_collection_notice_text'] = $hostile;

		$data = $this->inline_from_initialize( 7, '' );

		self::assertStringNotContainsString( '</script>', $data, 'Un </script> crudo cerraría el bloque inlinado.' );
		self::assertStringContainsString( '<\/script>', $data );
		self::assertSame( 0, substr_count( $data, "\n" ), 'El global tiene que caber en una línea: el salto va escapado.' );
		self::assertSame( array( 'collectionNotice' => $hostile . ' (filtrado)' ), self::decode( $data ) );
	}

	/**
	 * Ejecuta `initialize()` con la sesión indicada y devuelve el JS inlinado.
	 *
	 * Fija en cada llamada lo que no cambia entre casos: exactamente UN global,
	 * en el handle del bundle frontend, en posición `before`.
	 *
	 * @param int         $user_id      Usuario de la sesión (0 = invitado).
	 * @param string|null $optout_meta  Valor de `_akb_sf_optout_all`; null = no debe consultarse.
	 * @param bool        $filter_runs  Si el filtro del copy debe ejecutarse.
	 * @return string
	 */
	private function inline_from_initialize( int $user_id, ?string $optout_meta, bool $filter_runs = true ): string {
		$plugin_dir = dirname( __DIR__, 2 ) . '/';
		defined( 'AKIBARA_CART_CHECKOUT_BLOCKS_DIR' ) || define( 'AKIBARA_CART_CHECKOUT_BLOCKS_DIR', $plugin_dir );
		defined( 'AKIBARA_CART_CHECKOUT_BLOCKS_URL' ) || define( 'AKIBARA_CART_CHECKOUT_BLOCKS_URL', 'https://akibara.test/wp-content/plugins/akibara-cart-checkout-blocks/' );
		defined( 'AKIBARA_CART_CHECKOUT_BLOCKS_VERSION' ) || define( 'AKIBARA_CART_CHECKOUT_BLOCKS_VERSION', '0.0.0-test' );

		// La interfaz REAL de WooCommerce, como hace PluginContractTest: la
		// clase la implementa y sin ella ni siquiera se puede instanciar.
		$vendor = dirname( __DIR__, 3 ) . '/woocommerce/src/Blocks/Integrations/IntegrationInterface.php';
		self::assertFileExists( $vendor );
		require_once $vendor;

		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( $user_id );

		if ( null === $optout_meta ) {
			Functions\expect( 'get_user_meta' )->never();
		} else {
			Functions\expect( 'get_user_meta' )
				->once()
				->with( $user_id, self::OPTOUT_ALL_META, true )
				->andReturn( $optout_meta );
		}

		if ( $filter_runs ) {
			Functions\when( 'apply_filters' )->alias(
				static function ( string $hook, $value ) {
					// Un tema o mu-plugin puede reescribir el aviso por el filtro
					// con el nombre literal de la option, igual que el de despacho.
					return 'akibara_blocks_collection_notice_text' === $hook ? $value . ' (filtrado)' : $value;
				}
			);
		} else {
			Functions\expect( 'apply_filters' )->never();
		}

		$inlined = array();
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( string $handle, string $data, string $position = 'after' ) use ( &$inlined ): bool {
				$inlined[] = array( $handle, $data, $position );

				return true;
			}
		);

		( new CheckoutBlockIntegration() )->initialize();

		self::assertCount( 1, $inlined, 'initialize() debe inlinar exactamente un global.' );
		self::assertSame( 'akibara-checkout-inner-blocks-frontend', $inlined[0][0] );
		self::assertSame( 'before', $inlined[0][2], 'El bundle lee el global al montar: tiene que existir antes.' );

		return $inlined[0][1];
	}

	/**
	 * Extrae y decodifica el objeto del global inlinado.
	 *
	 * @param string $data JS inlinado.
	 * @return array<string,mixed>
	 */
	private static function decode( string $data ): array {
		self::assertSame( 1, preg_match( '/^window\.akbCheckoutInnerBlocks = (\{.*\});$/s', $data, $json ), $data );
		$decoded = json_decode( $json[1], true );
		self::assertIsArray( $decoded, 'El global tiene que ser JSON válido: ' . $json[1] );

		return $decoded;
	}
}
