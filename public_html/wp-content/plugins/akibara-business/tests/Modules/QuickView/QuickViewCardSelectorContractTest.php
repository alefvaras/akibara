<?php
/**
 * Contrato: toda clase que quick-view.js busca DENTRO de la card tiene que
 * existir de verdad en el árbol.
 *
 * POR QUÉ EXISTE ESTE TEST (regresión del 2026-07-29)
 * --------------------------------------------------
 * `assets/js/quick-view.js` clonaba piezas de la card al bottom-sheet con esta
 * lista de selectores:
 *
 *     '.akb-loop-badge, .akb-brand-eyebrow, .price, .akb-eta-pill'
 *
 * y buscaba el CTA con '…, .akb-waitlist-cta'. CUATRO de esas clases no
 * existían en ninguna otra parte del proyecto: la única ocurrencia de cada una
 * era esa línea de JS. El resultado era un bug silencioso — `querySelectorAll`
 * devuelve una NodeList vacía, no un error — así que el sheet mostraba SOLO el
 * precio (sin badge de preventa/oferta/agotado, sin eyebrow de editorial, sin
 * ETA, sin disponibilidad) y en las cards agotadas el slot de acciones quedaba
 * VACÍO, justo en el estado donde el único paso útil es apuntarse al aviso.
 *
 * Ni `php -l`, ni `node --check`, ni PHPUnit sobre los renderers detectan eso:
 * cada lado está bien por separado y nadie compara los dos vocabularios. Este
 * test los compara.
 *
 * QUÉ SE VERIFICA
 * ---------------
 * 1. Se extraen las clases de TODOS los `querySelector`/`querySelectorAll` que
 *    quick-view.js ejecuta sobre el elemento de la card (el resultado de
 *    `.closest( 'li.product' )`) — piezas del meta, cover y CTA. Las consultas
 *    sobre `dialog` o `document` quedan fuera a propósito: ese DOM lo crea el
 *    propio JS.
 * 2. Cada clase tiene que estar EMITIDA por código first-party: impresa dentro
 *    de un atributo `class="…"` por PHP de los plugins/theme, o usada como
 *    selector en CSS del proyecto.
 *
 * NO cuentan como evidencia (a propósito):
 *   - Comentarios PHP/CSS. Documentar una clase no la emite; el archivo de
 *     quick-view.js tiene hoy un comentario que NOMBRA las cuatro clases
 *     fantasma, y si los comentarios contaran el contrato sería decorativo.
 *   - Otros archivos .js. Ese era exactamente el estado del bug: la clase
 *     existía SOLO en JS. Si algún día una clase se agrega por JS y se estiliza
 *     en CSS, el CSS ya la cubre.
 *   - Los directorios `tests`. Un test no puede ser su propia evidencia.
 *   - Strings de allowlist UCSS (`$selectors[] = '.akb-foo';`): son un
 *     selector, no markup. La emisión real vive en otra línea.
 *
 * DÓNDE VIVE Y POR QUÉ (no es un test Node)
 * -----------------------------------------
 * Akibara Business ya ejecuta sus `*.test.js` desde el gate, pero este contrato
 * cruza PHP, CSS y JS first-party de varios componentes. La suite PHPUnit de
 * Business es el lugar que ya monta ese árbol completo y dispone de un lexer
 * PHP real para distinguir markup emitido de comentarios o allowlists.
 *
 * Este test vive en la suite PHPUnit de Business, que el gate SÍ
 * ejecuta y sobre el árbol de trabajo completo (`/workspace` montado):
 *   - tools/akibara-business/qa.sh          → «PHPUnit en Docker...»
 *   - tools/akibara-releases/docker-checkout.sh → «Pruebas PHP de Business...»
 * PHP también resulta la mejor herramienta para la mitad PHP del contrato:
 * `token_get_all()` es el lexer del propio lenguaje, así que distinguir un
 * atributo `class="…"` de una mención en un docblock no depende de heurísticas.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\QuickView;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class QuickViewCardSelectorContractTest extends TestCase {

	/**
	 * Raíz de la card en el loop de WooCommerce.
	 *
	 * quick-view.js sube desde el trigger con `.closest()` hasta este selector;
	 * la variable resultante es la que este test rastrea. Si el markup del loop
	 * dejara de ser `li.product`, el test falla con un mensaje explícito en vez
	 * de pasar en silencio verificando cero selectores.
	 */
	private const CARD_ROOT_SELECTOR = 'li.product';

	/**
	 * Ruta del JS auditado, relativa a wp-content.
	 */
	private const QUICK_VIEW_JS = 'plugins/akibara-business/assets/js/quick-view.js';

	/**
	 * Clases de TERCEROS que este repositorio nunca imprime.
	 *
	 * Excepciones explícitas y justificadas una por una, NO un skip silencioso:
	 * el sheet tiene que poder clonar el precio y el botón de compra que emite
	 * WooCommerce, y exigirle emisión first-party a esas clases sería un falso
	 * positivo garantizado.
	 *
	 * Ojo con el matiz: el theme SÍ estiliza varias de ellas (woocommerce.css
	 * tiene reglas para `.price`, `.button` y `.add_to_cart_button`), así que
	 * hoy pasarían igual por la vía CSS. La allowlist está para que sigan
	 * pasando si el theme deja de estilizarlas, y para dejar por escrito que la
	 * emisión es de Woo. `ajax_add_to_cart` es la que hoy NO tiene ninguna
	 * regla CSS first-party: sin esta lista, el test fallaría por ella.
	 *
	 * Regla de mantenimiento: agregar acá SOLO clases emitidas por un tercero
	 * (Woo core, GeneratePress, un plugin de terceros). Una clase `akb-*` jamás
	 * pertenece a esta lista — si falta, el bug es real.
	 *
	 * @var array<string,string> clase => quién la emite.
	 */
	private const THIRD_PARTY_CLASSES = array(
		// woocommerce/templates/loop/price.php → `<span class="price">`.
		'price'              => 'WooCommerce core: templates/loop/price.php',
		// WC_Product::add_to_cart_html() / templates/loop/add-to-cart.php.
		'add_to_cart_button' => 'WooCommerce core: templates/loop/add-to-cart.php',
		// La agrega Woo al botón del loop cuando el producto soporta AJAX.
		'ajax_add_to_cart'   => 'WooCommerce core: clase AJAX del botón del loop',
		// Clase de botón de Woo/GeneratePress; el theme solo la re-estiliza.
		'button'             => 'WooCommerce core / GeneratePress',
	);

	/**
	 * Raíces first-party escaneadas para construir el índice de emisión.
	 *
	 * Rutas relativas a wp-content. `mu-plugins` entra filtrado en
	 * {@see self::first_party_files()} porque comparte carpeta con mu-plugins
	 * de hosting que no son nuestros.
	 *
	 * @var string[]
	 */
	private const FIRST_PARTY_ROOTS = array(
		'plugins/akibara-business',
		'plugins/akibara-cart-checkout-blocks',
		'plugins/akibara-search',
		'themes/akibara-gp',
	);

	/**
	 * Índice de emisión memorizado: clase => "ruta:línea" de la primera prueba.
	 *
	 * @var array<string,string>|null
	 */
	private static ?array $emission_index = null;

	/**
	 * @testdox Cada clase que quick-view.js busca en la card está emitida por PHP o CSS first-party.
	 */
	public function test_every_class_cloned_from_the_card_is_emitted_by_first_party_code(): void {
		$sites   = self::card_query_sites( self::read_quick_view_js() );
		$index   = self::emission_index();
		$missing = array();

		foreach ( $sites as $site ) {
			foreach ( self::classes_in_selector_list( $site['selector'] ) as $class ) {
				if ( isset( self::THIRD_PARTY_CLASSES[ $class ] ) ) {
					continue;
				}
				if ( isset( $index[ $class ] ) ) {
					continue;
				}
				$missing[ $class ] = sprintf(
					'.%s — %s:%d, %s( "%s" )',
					$class,
					self::QUICK_VIEW_JS,
					$site['line'],
					$site['call'],
					$site['selector']
				);
			}
		}

		self::assertSame(
			array(),
			array_values( $missing ),
			"quick-view.js busca clases que NADIE emite en el proyecto.\n"
			. "El bottom-sheet no fallará: querySelectorAll devuelve vacío y la pieza\n"
			. "simplemente desaparece del sheet (así se perdieron badge, eyebrow, ETA,\n"
			. "disponibilidad y el CTA de agotado en el bug del 2026-07-29).\n\n"
			. "Clases sin emisor:\n  " . implode( "\n  ", $missing ) . "\n\n"
			. "Arréglalo con UNA de estas tres cosas:\n"
			. "  1. Usa el nombre real que imprime el renderer (grep en src/ y en inc/).\n"
			. "  2. Emítela de verdad desde PHP (atributo class=\"…\") o estilízala en CSS.\n"
			. "  3. Si es de un tercero (Woo core, GeneratePress), agrégala a\n"
			. '     THIRD_PARTY_CLASSES con el archivo del tercero que la emite.'
		);
	}

	/**
	 * @testdox La extracción alcanza las consultas reales de la card y deja fuera el DOM del propio sheet.
	 */
	public function test_extraction_reaches_the_real_card_queries(): void {
		$sites = self::card_query_sites( self::read_quick_view_js() );

		$classes = array();
		foreach ( $sites as $site ) {
			$classes = array_merge( $classes, self::classes_in_selector_list( $site['selector'] ) );
		}
		$classes = array_values( array_unique( $classes ) );

		// Un extractor que devuelve vacío haría pasar el test de arriba siempre.
		// Estas aserciones son el seguro contra ese falso verde.
		self::assertGreaterThanOrEqual(
			3,
			count( $sites ),
			'Se esperaban al menos las tres consultas sobre la card: cover, piezas del meta y CTA.'
		);
		foreach ( array( 'akb-badge', 'akb-editorial-eyebrow', 'akb-avail-hint', 'akb-eta-loop', 'akb-notify-link', 'akb-product-cover-wrap' ) as $expected ) {
			self::assertContains(
				$expected,
				$classes,
				sprintf( 'La extracción perdió .%s, que quick-view.js sí clona de la card.', $expected )
			);
		}

		// El DOM del sheet lo crea quick-view.js con innerHTML: no se clona de
		// la card y no debe entrar al contrato.
		foreach ( array( 'akb-qv__meta', 'akb-qv__actions', 'akb-qv-trigger' ) as $own_dom ) {
			self::assertNotContains(
				$own_dom,
				$classes,
				sprintf( '.%s es DOM propio del sheet, no una pieza clonada de la card.', $own_dom )
			);
		}
	}

	/**
	 * @testdox Una clase fantasma reintroducida se reporta por nombre.
	 */
	public function test_a_reintroduced_phantom_class_is_reported_by_name(): void {
		// Reproducción exacta del bug: dos de las cuatro clases inexistentes,
		// una en la lista de piezas y otra en la del CTA.
		$regressed = <<<'JS'
		var card = trigger.closest( 'li.product' );
		var pieces = card.querySelectorAll( '.akb-loop-badge, .price, .akb-eta-pill' );
		var cta = card.querySelector( '.add_to_cart_button, .akb-waitlist-cta' );
		JS;

		$index   = self::emission_index();
		$missing = array();
		foreach ( self::card_query_sites( $regressed ) as $site ) {
			foreach ( self::classes_in_selector_list( $site['selector'] ) as $class ) {
				if ( isset( self::THIRD_PARTY_CLASSES[ $class ] ) || isset( $index[ $class ] ) ) {
					continue;
				}
				$missing[] = $class;
			}
		}

		self::assertSame(
			array( 'akb-loop-badge', 'akb-eta-pill', 'akb-waitlist-cta' ),
			$missing,
			'El contrato tiene que señalar cada clase fantasma por nombre, no solo fallar.'
		);
	}

	/**
	 * @testdox El extractor ignora comentarios y tolera concatenación, saltos de línea y espacios raros.
	 */
	public function test_extractor_ignores_comments_and_tolerates_formatting(): void {
		// Todo lo que un `grep` frágil rompería: la lista partida en varias
		// líneas y concatenada con `+`, espacios dentro de `:not( … )`, un
		// atributo con punto adentro, y clases fantasma nombradas SOLO en
		// comentarios (de línea y de bloque) — igual que hoy en el archivo real.
		$formatted = <<<'JS'
		/*
		 * Antes acá había .akb-eta-pill y .akb-waitlist-cta. No existen.
		 */
		var card = trigger.closest(   'li.product'   ); // ojo: .akb-loop-badge era falsa
		var pieces = card.querySelectorAll(
			'.akb-badge,   .akb-editorial-eyebrow' +
			" , .akb-avail-hint:not( .akb-avail-hint--empty )\n" +
			',.akb-eta-loop'
		);
		var cta = card.querySelector( '.button[data-akb-x=".no-es-clase"], .akb-notify-link' );
		var slot = dialog.querySelector( '.akb-qv__meta' );
		var triggers = ( root || document ).querySelectorAll( '.akb-qv-trigger[hidden]' );
		JS;

		$classes = array();
		foreach ( self::card_query_sites( $formatted ) as $site ) {
			$classes = array_merge( $classes, self::classes_in_selector_list( $site['selector'] ) );
		}

		self::assertSame(
			array(
				'akb-badge',
				'akb-editorial-eyebrow',
				'akb-avail-hint',
				'akb-avail-hint--empty',
				'akb-eta-loop',
				'button',
				'akb-notify-link',
			),
			$classes
		);
	}

	/**
	 * @testdox Una mención en comentario PHP o CSS no cuenta como emisión.
	 */
	public function test_comment_mentions_are_not_evidence(): void {
		$php = <<<'PHP'
		<?php
		/**
		 * Este renderer emite .akb-fantasma-doc y nada más. (Miente: es un docblock.)
		 */
		// También .akb-fantasma-linea.
		# Y .akb-fantasma-hash.
		echo '<span class="akb-real akb-real--%1$s" data-x="1">ok</span>';
		?>
		<div class="akb-real-inline">texto</div>
		<?php
		echo "<b class=\"akb-real-escapada\">literal con comillas escapadas</b>";
		echo "<i class=\"akb-real-interpolada {$modifier}\">con interpolación</i>";
		$selectors[] = '.akb-fantasma-allowlist';
		PHP;

		$css = <<<'CSS'
		/* .akb-fantasma-css solo vive en este comentario. */
		.akb-real-css,
		.woocommerce li.product .akb-real-css--mod:not( .akb-real-css--off ) {
			background: url( "./no-es-clase.png" );
			font-size: 0.78rem;
		}
		CSS;

		$from_php = self::classes_from_php( $php );
		$from_css = self::classes_from_css( $css );

		self::assertSame(
			array( 'akb-real', 'akb-real-inline', 'akb-real-escapada', 'akb-real-interpolada' ),
			array_keys( $from_php ),
			'Solo los atributos class="…" cuentan; los comentarios y los strings de allowlist UCSS no.'
		);
		self::assertSame(
			array( 'akb-real-css', 'woocommerce', 'product', 'akb-real-css--mod', 'akb-real-css--off' ),
			array_keys( $from_css ),
			'Del CSS se leen selectores, no comentarios ni valores de propiedades.'
		);
	}

	/**
	 * @testdox La allowlist de terceros no tiene entradas muertas.
	 */
	public function test_third_party_allowlist_has_no_dead_entries(): void {
		$referenced = array();
		foreach ( self::card_query_sites( self::read_quick_view_js() ) as $site ) {
			$referenced = array_merge( $referenced, self::classes_in_selector_list( $site['selector'] ) );
		}

		$dead = array_values( array_diff( array_keys( self::THIRD_PARTY_CLASSES ), $referenced ) );

		self::assertSame(
			array(),
			$dead,
			"Hay excepciones de terceros que quick-view.js ya no usa: " . implode( ', ', $dead )
			. ".\nBórralas: una allowlist con entradas muertas deja de documentar y empieza a tapar."
		);
	}

	/**
	 * @testdox El índice de emisión cubre las raíces first-party esperadas.
	 */
	public function test_emission_index_is_built_from_the_expected_first_party_roots(): void {
		$index = self::emission_index();

		// Testigos con emisor conocido y distinto, para probar que el índice ve
		// PHP del plugin, PHP del theme y CSS del theme.
		$witnesses = array(
			'akb-avail-hint'         => 'plugins/akibara-business/src/Modules/LoopEnhancer/LoopEnhancer.php',
			'akb-editorial-eyebrow'  => 'themes/akibara-gp/inc/title-parser.php',
			'akb-product-cover-wrap' => 'plugins/akibara-business/src/Modules/Wishlist/Button.php',
		);
		foreach ( $witnesses as $class => $expected_file ) {
			self::assertArrayHasKey(
				$class,
				$index,
				sprintf( 'El índice perdió .%s, que se emite en %s.', $class, $expected_file )
			);
		}

		// Y que NO ve lo que no debe: una clase que solo existe en un test.
		self::assertArrayNotHasKey(
			'akb-eta-pill',
			$index,
			'.akb-eta-pill no existe en el árbol; si aparece, el índice está contando comentarios o tests.'
		);
	}

	// -----------------------------------------------------------------
	// Lectura del JS
	// -----------------------------------------------------------------

	/**
	 * Devuelve el fuente de quick-view.js.
	 */
	private static function read_quick_view_js(): string {
		$path = self::wp_content_dir() . '/' . self::QUICK_VIEW_JS;
		if ( ! is_file( $path ) ) {
			throw new RuntimeException( 'No se encontró quick-view.js en: ' . $path );
		}

		return (string) file_get_contents( $path );
	}

	/**
	 * Raíz de wp-content, deducida de la ubicación de este test.
	 *
	 * tests/Modules/QuickView → tests → akibara-business → plugins → wp-content.
	 * Sirve igual en el árbol de trabajo y en un candidato desempaquetado, que
	 * conserva la misma jerarquía public_html/wp-content/….
	 */
	private static function wp_content_dir(): string {
		return dirname( __DIR__, 5 );
	}

	/**
	 * Consultas que el JS ejecuta SOBRE el elemento de la card.
	 *
	 * @param string $source Fuente JavaScript.
	 * @return array<int,array{selector:string,line:int,call:string}>
	 */
	private static function card_query_sites( string $source ): array {
		$code      = self::blank_js_comments( $source );
		$variables = self::card_variables( $code );
		$sites     = array();

		foreach ( $variables as $variable ) {
			$pattern = '/\b' . preg_quote( $variable, '/' ) . '\s*\.\s*(querySelectorAll|querySelector)\s*\(/';
			if ( ! preg_match_all( $pattern, $code, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches[0] as $index => $match ) {
				$open       = $match[1] + strlen( $match[0] ) - 1;
				$expression = self::balanced_argument( $code, $open );
				$sites[]    = array(
					'selector' => self::static_string_value( $expression, $variable ),
					'line'     => substr_count( substr( $code, 0, $match[1] ), "\n" ) + 1,
					'call'     => $matches[1][ $index ][0],
				);
			}
		}

		if ( array() === $sites ) {
			throw new RuntimeException(
				'No se encontró ninguna consulta sobre la card en quick-view.js. '
				. 'Si el JS cambió de forma, actualiza este contrato: pasar con cero '
				. 'selectores verificados sería peor que fallar.'
			);
		}

		usort( $sites, static fn( array $a, array $b ): int => $a['line'] <=> $b['line'] );

		return $sites;
	}

	/**
	 * Variables que sostienen el elemento de la card.
	 *
	 * Se identifican por el `.closest()` cuyo argumento apunta a la raíz de la
	 * card, así que un rename de la variable no rompe el contrato.
	 *
	 * @param string $code JavaScript sin comentarios.
	 * @return string[]
	 */
	private static function card_variables( string $code ): array {
		$pattern = '/\b(?:var|let|const)\s+([A-Za-z_$][\w$]*)\s*=\s*[^;{}]*?\.\s*closest\s*\(\s*'
			. '([\'"])((?:[^\'"\\\\]|\\\\.)*)\2\s*\)/';

		$variables = array();
		if ( preg_match_all( $pattern, $code, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				if ( str_contains( $match[3], self::CARD_ROOT_SELECTOR ) ) {
					$variables[] = $match[1];
				}
			}
		}

		if ( array() === $variables ) {
			throw new RuntimeException(
				sprintf(
					'No se encontró la variable de la card: ningún `.closest()` apunta a "%s". '
					. 'Si el markup del loop cambió de raíz, actualiza CARD_ROOT_SELECTOR.',
					self::CARD_ROOT_SELECTOR
				)
			);
		}

		return array_values( array_unique( $variables ) );
	}

	/**
	 * Texto del argumento entre paréntesis balanceados, respetando strings.
	 *
	 * @param string $code   Código.
	 * @param int    $open   Offset del `(` de apertura.
	 */
	private static function balanced_argument( string $code, int $open ): string {
		$length = strlen( $code );
		$depth  = 0;
		$quote  = '';

		for ( $i = $open; $i < $length; $i++ ) {
			$char = $code[ $i ];

			if ( '' !== $quote ) {
				if ( '\\' === $char ) {
					$i++;
					continue;
				}
				if ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$quote = $char;
				continue;
			}
			if ( '(' === $char ) {
				$depth++;
				continue;
			}
			if ( ')' === $char ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $code, $open + 1, $i - $open - 1 );
				}
			}
		}

		throw new RuntimeException( 'Paréntesis sin cerrar en una consulta de quick-view.js.' );
	}

	/**
	 * Evalúa una expresión de string estática (literales unidos por `+`).
	 *
	 * Un selector armado en runtime no se puede verificar estáticamente, así que
	 * se falla en voz alta en vez de ignorarlo.
	 *
	 * @param string $expression Expresión JS.
	 * @param string $variable   Variable de la card, solo para el mensaje.
	 */
	private static function static_string_value( string $expression, string $variable ): string {
		$length = strlen( $expression );
		$value  = '';
		$expect = 'string';

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $expression[ $i ];

			if ( ' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char ) {
				continue;
			}

			if ( 'operator' === $expect ) {
				if ( '+' !== $char ) {
					break;
				}
				$expect = 'string';
				continue;
			}

			if ( "'" !== $char && '"' !== $char ) {
				break;
			}

			$quote = $char;
			$i++;
			for ( ; $i < $length; $i++ ) {
				if ( '\\' === $expression[ $i ] && $i + 1 < $length ) {
					$i++;
					$value .= self::unescape_js( $expression[ $i ] );
					continue;
				}
				if ( $expression[ $i ] === $quote ) {
					break;
				}
				$value .= $expression[ $i ];
			}
			$expect = 'operator';
		}

		if ( 'operator' !== $expect || '' === trim( $value ) ) {
			throw new RuntimeException(
				sprintf(
					'La consulta `%s.querySelector…( %s )` no usa un selector literal. '
					. 'Un selector dinámico no se puede verificar: extrae la lista a un '
					. 'literal o excluye el sitio explícitamente en este contrato.',
					$variable,
					trim( preg_replace( '/\s+/', ' ', $expression ) ?? '' )
				)
			);
		}

		return trim( $value );
	}

	/**
	 * Resuelve el escape de un carácter dentro de un literal JS.
	 *
	 * @param string $char Carácter escapado.
	 */
	private static function unescape_js( string $char ): string {
		return match ( $char ) {
			'n'     => "\n",
			't'     => "\t",
			'r'     => "\r",
			default => $char,
		};
	}

	/**
	 * Reemplaza comentarios JS por espacios, conservando offsets y líneas.
	 *
	 * Necesario: el propio quick-view.js documenta en comentarios las cuatro
	 * clases fantasma del bug. Si contaran, este contrato fallaría siempre por
	 * la razón equivocada.
	 *
	 * @param string $source Fuente JavaScript.
	 */
	private static function blank_js_comments( string $source ): string {
		$length = strlen( $source );
		$out    = $source;
		$prev   = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $source[ $i ];

			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$quote = $char;
				for ( $i++; $i < $length; $i++ ) {
					if ( '\\' === $source[ $i ] ) {
						$i++;
						continue;
					}
					if ( $source[ $i ] === $quote ) {
						break;
					}
				}
				$prev = $quote;
				continue;
			}

			if ( '/' === $char && $i + 1 < $length ) {
				$next = $source[ $i + 1 ];

				if ( '/' === $next ) {
					for ( ; $i < $length && "\n" !== $source[ $i ]; $i++ ) {
						$out[ $i ] = ' ';
					}
					$i--;
					continue;
				}

				if ( '*' === $next ) {
					for ( ; $i < $length; $i++ ) {
						if ( "\n" !== $source[ $i ] ) {
							$out[ $i ] = ' ';
						}
						if ( '/' === $source[ $i ] && $i > 0 && '*' === $source[ $i - 1 ] && $i > 1 ) {
							break;
						}
					}
					continue;
				}

				// Literal de expresión regular: se salta para no confundir su
				// contenido con código (hoy no hay ninguno, mañana puede haber).
				if ( ! self::is_division_context( $prev ) ) {
					for ( $i++; $i < $length; $i++ ) {
						if ( '\\' === $source[ $i ] ) {
							$i++;
							continue;
						}
						if ( '/' === $source[ $i ] ) {
							break;
						}
					}
					$prev = '/';
					continue;
				}
			}

			if ( '' !== trim( $char ) ) {
				$prev = $char;
			}
		}

		return $out;
	}

	/**
	 * ¿El `/` que sigue a este carácter es una división y no un regex?
	 *
	 * @param string $prev Último carácter significativo.
	 */
	private static function is_division_context( string $prev ): bool {
		return '' !== $prev && 1 === preg_match( '/[\w$)\]}\'"]/', $prev );
	}

	// -----------------------------------------------------------------
	// Parser de listas de selectores (compartido por JS y CSS)
	// -----------------------------------------------------------------

	/**
	 * Clases de una lista de selectores, en orden de aparición y sin repetir.
	 *
	 * Entra en `:not()`, `:is()` y `:where()` por recorrer los caracteres en
	 * línea, y no confunde el punto de un valor de atributo con una clase.
	 *
	 * @param string $selector_list Lista de selectores CSS.
	 * @return string[]
	 */
	private static function classes_in_selector_list( string $selector_list ): array {
		$length  = strlen( $selector_list );
		$classes = array();

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $selector_list[ $i ];

			if ( "'" === $char || '"' === $char ) {
				$quote = $char;
				for ( $i++; $i < $length; $i++ ) {
					if ( '\\' === $selector_list[ $i ] ) {
						$i++;
						continue;
					}
					if ( $selector_list[ $i ] === $quote ) {
						break;
					}
				}
				continue;
			}

			if ( '.' !== $char ) {
				continue;
			}

			$name = '';
			for ( $j = $i + 1; $j < $length; $j++ ) {
				$candidate = $selector_list[ $j ];
				if ( '\\' === $candidate && $j + 1 < $length ) {
					$name .= $selector_list[ ++$j ];
					continue;
				}
				if ( 1 !== preg_match( '/[\w-]/', $candidate ) ) {
					break;
				}
				$name .= $candidate;
			}

			// Un identificador CSS no empieza con dígito: así `0.78rem` o `.5em`
			// nunca se leen como clase.
			if ( '' !== $name && 1 === preg_match( '/^-?[A-Za-z_]/', $name ) ) {
				$classes[ $name ] = true;
				$i               += strlen( $name );
			}
		}

		return array_keys( $classes );
	}

	// -----------------------------------------------------------------
	// Índice de emisión
	// -----------------------------------------------------------------

	/**
	 * Clases emitidas por código first-party: clase => "ruta:línea".
	 *
	 * @return array<string,string>
	 */
	private static function emission_index(): array {
		if ( null !== self::$emission_index ) {
			return self::$emission_index;
		}

		$root  = self::wp_content_dir();
		$index = array();

		foreach ( self::first_party_files() as $relative ) {
			$contents = (string) file_get_contents( $root . '/' . $relative );
			$found    = str_ends_with( $relative, '.css' )
				? self::classes_from_css( $contents )
				: self::classes_from_php( $contents );

			foreach ( $found as $class => $line ) {
				$index[ $class ] ??= $relative . ':' . $line;
			}
		}

		self::$emission_index = $index;

		return $index;
	}

	/**
	 * Archivos PHP y CSS first-party, relativos a wp-content.
	 *
	 * @return string[]
	 */
	private static function first_party_files(): array {
		$root  = self::wp_content_dir();
		$files = array();

		foreach ( self::FIRST_PARTY_ROOTS as $relative_root ) {
			$absolute = $root . '/' . $relative_root;
			if ( ! is_dir( $absolute ) ) {
				throw new RuntimeException(
					sprintf(
						'Falta la raíz first-party %s. El índice de emisión quedaría '
						. 'incompleto y el contrato fallaría por clases que sí existen.',
						$relative_root
					)
				);
			}
			$files = array_merge( $files, self::scan( $absolute, $root ) );
		}

		// mu-plugins comparte carpeta con mu-plugins de hosting que no son
		// nuestros: solo entran los `akibara-*.php` y los assets propios.
		$mu = $root . '/mu-plugins';
		if ( is_dir( $mu ) ) {
			foreach ( self::scan( $mu, $root ) as $relative ) {
				$name = basename( $relative );
				if ( str_starts_with( $name, 'akibara-' ) || str_contains( $relative, 'mu-plugins/assets/' ) ) {
					$files[] = $relative;
				}
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * Recorre un directorio y devuelve sus PHP/CSS relevantes.
	 *
	 * @param string $directory Directorio absoluto.
	 * @param string $root      Raíz para relativizar.
	 * @return string[]
	 */
	private static function scan( string $directory, string $root ): array {
		$skip_dirs = array( 'vendor', 'node_modules', 'tests', 'build', 'dist', '.git' );
		$files     = array();

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$path = $item->getPathname();

			if ( $item->isDir() ) {
				continue;
			}
			$relative   = ltrim( substr( $path, strlen( $root ) ), '/' );
			$segments   = explode( '/', $relative );
			$in_skipped = false;
			foreach ( $segments as $segment ) {
				if ( in_array( $segment, $skip_dirs, true ) ) {
					$in_skipped = true;
					break;
				}
			}
			if ( $in_skipped ) {
				continue;
			}

			$extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
			if ( ! in_array( $extension, array( 'php', 'css' ), true ) ) {
				continue;
			}
			if ( str_ends_with( $relative, '.min.css' ) ) {
				continue;
			}

			$files[] = $relative;
		}

		return $files;
	}

	/**
	 * Clases impresas por PHP dentro de un atributo `class="…"`.
	 *
	 * Usa `token_get_all()` — el lexer del propio lenguaje — para que un
	 * docblock, un comentario `//`/`#` o un string que solo es un selector no
	 * puedan pasar por emisión.
	 *
	 * @param string $php Fuente PHP.
	 * @return array<string,int> clase => línea.
	 */
	private static function classes_from_php( string $php ): array {
		$classes = array();

		foreach ( token_get_all( $php ) as $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}

			[ $id, $text, $line ] = $token;

			if ( T_CONSTANT_ENCAPSED_STRING === $id ) {
				$text = self::decode_php_literal( $text );
			} elseif ( T_INLINE_HTML !== $id && T_ENCAPSED_AND_WHITESPACE !== $id ) {
				continue;
			}

			foreach ( self::class_attribute_values( $text ) as $attribute ) {
				$attribute_line = $line + substr_count( substr( $text, 0, $attribute['offset'] ), "\n" );
				$candidates     = preg_split( '/\s+/', trim( $attribute['value'] ) ) ?: array();

				// Valor cortado por una interpolación (`class="akb-x {$mod}"` o
				// `class="akb-x <?php echo …`): el último token puede venir
				// truncado a medias, así que se descarta salvo que un espacio
				// pruebe que estaba completo.
				if ( ! $attribute['complete'] && 1 !== preg_match( '/\s$/', $attribute['value'] ) ) {
					array_pop( $candidates );
				}

				foreach ( $candidates as $candidate ) {
					// Tokens con interpolación (`%1$s`, `{$var}`) se descartan:
					// no son un nombre de clase completo.
					if ( 1 === preg_match( '/^-?[A-Za-z_][\w-]*$/', $candidate ) ) {
						$classes[ $candidate ] ??= $attribute_line;
					}
				}
			}
		}

		return $classes;
	}

	/**
	 * Valores de atributos `class` en un fragmento de texto.
	 *
	 * Las comillas pueden venir escapadas (`class=\"…\"`): eso pasa en los
	 * segmentos T_ENCAPSED_AND_WHITESPACE de un string con interpolación, que
	 * el lexer entrega crudos y sin desescapar.
	 *
	 * @param string $text Texto (HTML inline o literal ya decodificado).
	 * @return array<int,array{offset:int,value:string,complete:bool}>
	 */
	private static function class_attribute_values( string $text ): array {
		$values = array();
		$cursor = 0;

		while ( preg_match( '/\bclass\s*=\s*(\\\\?)(["\'])/i', $text, $match, PREG_OFFSET_CAPTURE, $cursor ) ) {
			$closing = $match[1][0] . $match[2][0];
			$start   = $match[2][1] + 1;
			$end     = strpos( $text, $closing, $start );

			if ( false === $end ) {
				$values[] = array(
					'offset'   => $start,
					'value'    => substr( $text, $start ),
					'complete' => false,
				);
				break;
			}

			$values[] = array(
				'offset'   => $start,
				'value'    => substr( $text, $start, $end - $start ),
				'complete' => true,
			);
			$cursor = $end + strlen( $closing );
		}

		return $values;
	}

	/**
	 * Decodifica un literal PHP entrecomillado a su texto.
	 *
	 * @param string $literal Literal con sus comillas.
	 */
	private static function decode_php_literal( string $literal ): string {
		$quote = $literal[0] ?? '';
		if ( "'" !== $quote && '"' !== $quote ) {
			return $literal;
		}

		$inner = substr( $literal, 1, -1 );

		return str_replace( array( '\\' . $quote, '\\\\' ), array( $quote, '\\' ), $inner );
	}

	/**
	 * Clases usadas como selector en CSS.
	 *
	 * Solo se lee el preludio de cada bloque (lo que precede a `{`), así que
	 * comentarios, valores de propiedades y `url( "./x.png" )` quedan fuera.
	 *
	 * @param string $css Fuente CSS.
	 * @return array<string,int> clase => línea.
	 */
	private static function classes_from_css( string $css ): array {
		$length  = strlen( $css );
		$classes = array();
		$buffer  = '';
		$line    = 1;
		$start   = 1;

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $css[ $i ];

			if ( "\n" === $char ) {
				$line++;
			}

			if ( '/' === $char && $i + 1 < $length && '*' === $css[ $i + 1 ] ) {
				for ( $i += 2; $i < $length; $i++ ) {
					if ( "\n" === $css[ $i ] ) {
						$line++;
					}
					if ( '*' === $css[ $i ] && $i + 1 < $length && '/' === $css[ $i + 1 ] ) {
						$i++;
						break;
					}
				}
				continue;
			}

			if ( "'" === $char || '"' === $char ) {
				$buffer .= $char;
				$quote   = $char;
				for ( $i++; $i < $length; $i++ ) {
					if ( "\n" === $css[ $i ] ) {
						$line++;
					}
					$buffer .= $css[ $i ];
					if ( '\\' === $css[ $i ] && $i + 1 < $length ) {
						$buffer .= $css[ ++$i ];
						continue;
					}
					if ( $css[ $i ] === $quote ) {
						break;
					}
				}
				continue;
			}

			if ( '{' === $char ) {
				if ( '' !== trim( $buffer ) && ! str_starts_with( ltrim( $buffer ), '@' ) ) {
					foreach ( self::classes_in_selector_list( $buffer ) as $class ) {
						$classes[ $class ] ??= $start;
					}
				}
				$buffer = '';
				continue;
			}

			if ( '}' === $char || ';' === $char ) {
				$buffer = '';
				continue;
			}

			if ( '' === trim( $buffer ) && '' !== trim( $char ) ) {
				$start = $line;
			}
			$buffer .= $char;
		}

		return $classes;
	}
}
