<?php
/**
 * Candado de fuente: Dominios Erradicados (ADR-010 / ADR-013) e Integridad de BusinessOptions.
 *
 * El grafo AST indexa nombres de clases, métodos y funciones, pero no el texto
 * dentro de literales HTML (`T_INLINE_HTML`) ni strings de copy comercial. Por
 * esa brecha sobrevivió «📦 Preventas y Agotados» en `Wishlist/Render.php` y
 * quedaron referencias huérfanas a constantes retiradas de `BusinessOptions`
 * en `FinancialRulesAdminPage.php`.
 *
 * Este contrato escanea con `token_get_all` todos los literales y bloques HTML
 * de runtime PHP, además de hojas CSS y scripts JS en todos los plugins y tema
 * de Akibara, bloqueando cualquier reaparición de:
 *   - `preventa` / `preventas`
 *   - `a pedido` (singular; excluye «Ir a Pedidos» del menú de cuenta)
 *   - `onbackorder` / `backorder` / `backorders`
 *   - `arma tu pack`
 * fuera de la lista blanca explícita de guardrails defensivos (forzado de
 * `_backorders = 'no'`, retiro de crons `OwnCrons::RETIRED`, inmutabilidad de
 * pedidos históricos y clasificador de búsquedas sin resultados).
 *
 * También verifica que no exista ni quede ninguna referencia huérfana a `BusinessOptions`
 * (eliminada junto con FinancialRules).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests;

use PHPUnit\Framework\TestCase;

final class ForbiddenLegacyDomainsContractTest extends TestCase {

	private const FORBIDDEN_PATTERN = '/\b(preventas?|onbackorder|backorders?|arma\s+tu\s+pack|a[\s_-]+pedido)\b/iu';

	/**
	 * Archivos que contienen referencias técnicas defensivas (forzar `_backorders = 'no'`,
	 * limpiar hooks retirados, proteger órdenes históricas o clasificar búsquedas de
	 * usuarios externos). Ninguno de ellos expone preventas ni reservas como oferta comercial.
	 *
	 * @var array<string, true>
	 */
	private const DEFENSIVE_ALLOWLIST = array(
		'plugins/akibara-business/src/Utilities/OwnCrons.php'                           => true,
		'plugins/akibara-business/src/Modules/CartQuantity/CartQuantityCeiling.php'     => true,
		'plugins/akibara-business/src/Modules/DiscountCopy/DiscountCopy.php'            => true,
		'plugins/akibara-business/src/Modules/SiteSpeed/SeriesCacheTags.php'            => true,
		'plugins/akibara-business/src/Modules/NativeCart/NativeCart.php'                => true,
		'plugins/akibara-business/src/Modules/PricingMatrix/BatchGuard.php'             => true,
		'plugins/akibara-business/src/Modules/PricingMatrix/PricingSynchronizer.php'    => true,
		'plugins/akibara-business/src/Modules/PricingMatrix/PricingSelfTest.php'        => true,
		'plugins/akibara-business/src/Modules/Stock/StockStatus.php'                   => true,
		'plugins/akibara-business/src/Modules/Stock/Availability.php'                  => true,
		'plugins/akibara-business/src/Modules/LoopEnhancer/LoopEnhancer.php'            => true,
		'plugins/akibara-business/src/Modules/PricingMatrix/PricingMatrixCli.php'       => true,
		'plugins/akibara-business/src/Modules/PricingMatrix/PricingRulesRepository.php' => true,
		'plugins/akibara-search/src/SeriesSuggestions.php'                              => true,
		'plugins/akibara-search/src/EmptyStateSuggestions.php'                          => true,
		'plugins/akibara-search/src/ProductPresenter.php'                               => true,
		'plugins/akibara-search/src/DocumentBuilder.php'                                => true,
		'plugins/akibara-search/src/Indexer.php'                                        => true,
		'plugins/akibara-search/templates/admin/tab-ajustes.php'                        => true,
	);

	/**
	 * Extrae fragmentos de literales y HTML inline de código PHP ignorando comentarios.
	 *
	 * @return array<int, array{line: int, text: string, match: string}>
	 */
	public static function find_php_forbidden_literals( string $php_code ): array {
		$tokens   = token_get_all( $php_code );
		$findings = array();
		foreach ( $tokens as $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}
			$id = $token[0];
			if ( T_CONSTANT_ENCAPSED_STRING !== $id && T_ENCAPSED_AND_WHITESPACE !== $id && T_INLINE_HTML !== $id ) {
				continue;
			}
			$text = $token[1];
			if ( preg_match( self::FORBIDDEN_PATTERN, $text, $m ) ) {
				$findings[] = array(
					'line'  => (int) $token[2],
					'text'  => trim( preg_replace( '/\s+/', ' ', $text ) ?? $text ),
					'match' => $m[0],
				);
			}
		}
		return $findings;
	}

	/**
	 * Escanea activos JS/CSS eliminando comentarios de bloque y de línea.
	 *
	 * @return array<int, array{line: int, text: string, match: string}>
	 */
	public static function find_asset_forbidden_literals( string $asset_code ): array {
		// Preservar saltos de línea al limpiar comentarios de bloque para reportar líneas exactas.
		$cleaned = preg_replace_callback(
			'#/\*.*?\*/#s',
			static fn( array $m ): string => preg_replace( '/[^\n]/', ' ', $m[0] ) ?? '',
			$asset_code
		) ?? $asset_code;

		$lines    = explode( "\n", $cleaned );
		$findings = array();
		foreach ( $lines as $idx => $line ) {
			$code_line = preg_replace( '#(^|\s)//.*$#', '', $line ) ?? $line;
			if ( preg_match( self::FORBIDDEN_PATTERN, $code_line, $m ) ) {
				$findings[] = array(
					'line'  => $idx + 1,
					'text'  => trim( $code_line ),
					'match' => $m[0],
				);
			}
		}
		return $findings;
	}

	/**
	 * @testdox [NEG] El detector caza regresiones sintéticas de Preventa, A Pedido, onbackorder y Arma tu Pack en PHP y activos
	 */
	public function test_control_negativo_detecta_regresion_sintetica(): void {
		$synthetic_php = "<?php\n// Comentario ignorado: preventa a pedido\n?>\n<button>📦 Preventas y Agotados</button>\n<?php\n\$msg = 'Tomos disponibles o a pedido en Arma tu Pack';\n\$status = 'onbackorder';\n";
		$hits          = self::find_php_forbidden_literals( $synthetic_php );
		self::assertCount( 3, $hits, 'El escáner PHP debe detectar HTML inline y strings literales, ignorando comentarios.' );

		$benign_php = "<?php\n?>\n<a href=\"/mi-cuenta/orders/\">Ir a Pedidos</a>\n<?php\n\$note = 'Invita a pedidos anteriores y para pedido actual';\n";
		self::assertSame( array(), self::find_php_forbidden_literals( $benign_php ), 'No debe producir falsos positivos con «Ir a Pedidos» ni «para pedido».' );

		$synthetic_css = "/* A pedido en comentario */\n.akb-pm-col-backorder::before { content: \"A pedido / reserva:\"; }\n";
		$asset_hits    = self::find_asset_forbidden_literals( $synthetic_css );
		self::assertCount( 1, $asset_hits, 'El escáner de activos debe detectar selectores/strings prohibidos e ignorar comentarios CSS.' );
	}

	/**
	 * @testdox Ningún archivo PHP, plantilla, JS o CSS de runtime contiene copy o selectores de dominios erradicados
	 */
	public function test_ningun_literal_ni_plantilla_contiene_dominios_erradicados(): void {
		$wp_content = dirname( __DIR__, 3 );
		$roots      = array(
			$wp_content . '/plugins/akibara-business/src',
			$wp_content . '/plugins/akibara-business/templates',
			$wp_content . '/plugins/akibara-business/assets',
			$wp_content . '/plugins/akibara-search/src',
			$wp_content . '/plugins/akibara-search/templates',
			$wp_content . '/plugins/akibara-search/assets',
			$wp_content . '/plugins/akibara-rewards/src',
			$wp_content . '/plugins/akibara-rewards/assets',
			$wp_content . '/plugins/akibara-cart-checkout-blocks/src',
			$wp_content . '/plugins/akibara-cart-checkout-blocks/build',
			$wp_content . '/themes/akibara-gp/inc',
			$wp_content . '/themes/akibara-gp/template-parts',
			$wp_content . '/themes/akibara-gp/woocommerce',
			$wp_content . '/themes/akibara-gp/assets',
			$wp_content . '/mu-plugins',
		);

		$violations   = array();
		$scanned_count = 0;

		foreach ( $roots as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo || ! $file->isFile() ) {
					continue;
				}
				$ext = strtolower( $file->getExtension() );
				if ( ! in_array( $ext, array( 'php', 'js', 'css' ), true ) ) {
					continue;
				}
				$real = $file->getRealPath();
				if ( false === $real ) {
					continue;
				}
				$rel = ltrim( str_replace( $wp_content, '', $real ), '/' );
				if ( isset( self::DEFENSIVE_ALLOWLIST[ $rel ] ) ) {
					// En tab-ajustes.php de akibara-search solo se permite la línea 121 (explicación de palabras de cola de búsqueda).
					if ( 'plugins/akibara-search/templates/admin/tab-ajustes.php' === $rel ) {
						$code = (string) file_get_contents( $real );
						foreach ( self::find_php_forbidden_literals( $code ) as $hit ) {
							if ( ! str_contains( $hit['text'], 'cómo se compra' ) ) {
								$violations[] = sprintf( '%s:%d [%s] -> %s', $rel, $hit['line'], $hit['match'], substr( $hit['text'], 0, 120 ) );
							}
						}
					}
					continue;
				}

				++$scanned_count;
				$code = (string) file_get_contents( $real );
				$hits = 'php' === $ext
					? self::find_php_forbidden_literals( $code )
					: self::find_asset_forbidden_literals( $code );

				foreach ( $hits as $hit ) {
					$violations[] = sprintf(
						'%s:%d [%s] -> %s',
						$rel,
						$hit['line'],
						$hit['match'],
						substr( $hit['text'], 0, 120 )
					);
				}
			}
		}

		self::assertGreaterThan( 200, $scanned_count, 'El candado debe escanear el árbol real de runtime.' );
		self::assertSame(
			array(),
			$violations,
			"Se detectaron literales o selectores de dominios erradicados (Preventa / A Pedido / Backorder / Pack):\n" . implode( "\n", $violations )
		);
	}

	/**
	 * @testdox BusinessOptions fue erradicada y no posee referencias ni en src ni en templates
	 */
	public function test_business_options_erradicada_sin_referencias_en_runtime(): void {
		self::assertFalse( class_exists( 'Akibara\\Business\\Support\\BusinessOptions' ), 'La clase muerta BusinessOptions no debe existir en disco.' );

		$plugin = dirname( __DIR__ );
		$dirs   = array( $plugin . '/src', $plugin . '/templates' );
		$found  = array();

		foreach ( $dirs as $dir ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo || ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
					continue;
				}
				$code = (string) file_get_contents( $file->getRealPath() );
				if ( false !== strpos( $code, 'BusinessOptions' ) ) {
					$found[] = ltrim( str_replace( $plugin, '', (string) $file->getRealPath() ), '/' );
				}
			}
		}

		self::assertSame( array(), $found, 'Referencias huérfanas a BusinessOptions en runtime.' );
	}

	/**
	 * @testdox El módulo de Favoritos renderiza exclusivamente pestañas del modelo binario (Todos, En Bodega, Agotados)
	 */
	public function test_wishlist_render_usa_pestanas_binarias_sin_preventa(): void {
		$render = (string) file_get_contents( dirname( __DIR__ ) . '/src/Modules/Wishlist/Render.php' );
		self::assertStringContainsString( 'data-wl-filter="all"', $render );
		self::assertStringContainsString( 'data-wl-filter="instock"', $render );
		self::assertStringContainsString( 'data-wl-filter="outofstock"', $render );
		self::assertStringContainsString( '🔴 Agotados', $render );
		self::assertStringNotContainsString( 'Preventas', $render );
		self::assertStringNotContainsString( 'data-wl-filter="other"', $render );
	}
}
