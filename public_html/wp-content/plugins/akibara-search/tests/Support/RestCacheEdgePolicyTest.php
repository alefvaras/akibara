<?php
/**
 * Candados de la política de caché del borde para los endpoints REST del buscador.
 *
 * Nació de la auditoría del 2026-08-23: el fichero declaraba `no-store` desde
 * 0.1.14 y aun así Cloudflare almacenaba y servía `/suggest` (`cf-cache-status:
 * HIT`, `age: 3`), con `price_html` y el sello de stock dentro. Lo que se fija
 * aquí NO es un literal de cabecera bonito, sino dos invariantes que ya costaron
 * incidentes:
 *
 *   1. La negativa a almacenar tiene que llegar al CDN, no sólo al navegador.
 *      Medido en prod ese día sobre la MISMA página y el mismo 200: `/series/`
 *      con `Cache-Control: no-store` dio HIT (age 2) y `/series/?buscar=naruto`,
 *      que añade `CDN-Cache-Control: no-store`, dio DYNAMIC. El único lever real
 *      es ese par de cabeceras.
 *   2. La purga de este riel jamás puede escalar a `litespeed_purge_all`.
 *      `purge()` cuelga de `update_option_akb_search_index_gen`, que bumpea en
 *      cada escritura live —cada venta o cambio de stock—, y el flush total del
 *      borde se midió el 2026-08-22 en 48 → 1.057 ms la landing de serie y
 *      54 → 710 ms el blog.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace {
	// WP_REST_Response vive como doble ÚNICO en Support/wp-function-stubs.php: las copias
	// por archivo hacían que la forma ganadora dependiera del orden de ejecución.
}

namespace Akibara\Search\Tests\Support {

	use Akibara\Search\Support\RestCache;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use WP_REST_Response;

	require_once dirname( __DIR__, 2 ) . '/src/Support/RestCache.php';

	/**
	 * @covers \Akibara\Search\Support\RestCache
	 */
	final class RestCacheEdgePolicyTest extends TestCase {

		/** @var array<string, mixed> */
		private array $options = array();

		/** @var array<int, string> */
		private array $hooks = array();

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();

			$this->options = array();
			$this->hooks   = array();

			$options = &$this->options;
			$hooks   = &$this->hooks;

			Functions\when( 'get_option' )->alias(
				static function ( string $name, $default = false ) use ( &$options ) {
					return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
				}
			);
			Functions\when( 'update_option' )->alias(
				static function ( string $name, $value ) use ( &$options ): bool {
					$options[ $name ] = $value;
					return true;
				}
			);
			Functions\when( 'do_action' )->alias(
				static function ( string $hook ) use ( &$hooks ): void {
					$hooks[] = $hook;
				}
			);
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * @testdox La negativa a almacenar llega al CDN, no sólo al navegador.
		 */
		public function test_no_store_reaches_the_cdn_and_not_only_the_browser(): void {
			$response = new WP_REST_Response( array( 'items' => array() ) );

			RestCache::no_store( $response );

			// Se lee por `get_headers()` y no por la propiedad: el stub de
			// WP_REST_Response lo declara el primer test que cargue, y el de
			// RuntimeReadersFailClosedTest lo tiene privado.
			$headers = $response->get_headers();

			// El navegador y LiteSpeed obedecen esta; el borde de la zona no.
			self::assertStringContainsString( 'no-store', $headers['Cache-Control'] );

			// Y estas son las que el borde sí obedece. Si alguna desaparece, el
			// autocomplete vuelve a servirse desde una copia de edge que nadie
			// invalida cuando cambia un precio o se agota un tomo.
			self::assertSame( 'no-store', $headers['Cloudflare-CDN-Cache-Control'] ?? '' );
			self::assertSame( 'no-store', $headers['CDN-Cache-Control'] ?? '' );
		}

		/**
		 * @testdox Ningún camino de éxito autoriza almacenamiento compartido.
		 */
		public function test_apply_never_authorises_shared_storage(): void {
			$response = new WP_REST_Response( array( 'items' => array() ) );

			RestCache::apply( $response );

			$headers = $response->get_headers();

			// 0.1.14: sin epoch monotónico común a todos los writers, la carrera
			// final-check→store puede almacenar una respuesta de la generación
			// vieja DESPUÉS de la purga. Nada cacheable, en ninguna capa.
			foreach ( array( 'Cache-Control', 'Cloudflare-CDN-Cache-Control', 'CDN-Cache-Control' ) as $header ) {
				$value = strtolower( $headers[ $header ] ?? '' );
				self::assertStringContainsString( 'no-store', $value, $header . ' debe negar el almacenamiento.' );
				self::assertStringNotContainsString( 'public', $value, $header . ' no puede autorizar caché compartida.' );
				self::assertDoesNotMatchRegularExpression( '/max-age=[1-9]/', $value, $header . ' no puede traer un TTL positivo.' );
			}

			self::assertArrayNotHasKey( 'Expires', $headers );

			// La palanca de TTL ya no existe: `ttl()`, `DEFAULT_TTL`, el filtro
			// `akb_search_rest_cache_ttl` y el cap por frontera de campaña
			// sobrevivían «para admin/diagnóstico» sin un solo lector. Una
			// palanca que no puede encender nada es peor que no tenerla.
			self::assertFalse( method_exists( RestCache::class, 'ttl' ) );
			self::assertFalse( defined( RestCache::class . '::DEFAULT_TTL' ) );
		}

		/**
		 * @testdox La purga del riel no escala a un flush total del borde.
		 */
		public function test_purge_does_not_escalate_to_a_full_edge_flush(): void {
			self::assertTrue( RestCache::purge( 'generation-42' ) );

			self::assertContains( 'litespeed_purge', $this->hooks );
			self::assertNotContains(
				'litespeed_purge_all',
				$this->hooks,
				'purge() corre en cada bump de generación (cada venta): un flush total del borde costó 48 → 1.057 ms en la landing de serie el 2026-08-22.'
			);

			// El receipt sigue declarando su alcance real: despacho local, no ACK del edge.
			self::assertSame( 'local_dispatch_only', $this->options[ RestCache::OPTION_PURGE_RECEIPT ]['ack_scope'] );
		}
	}
}
