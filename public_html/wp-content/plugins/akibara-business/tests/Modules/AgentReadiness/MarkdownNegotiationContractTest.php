<?php
/**
 * Contratos de la negociación de contenido (release 1.108.33).
 *
 * @package Akibara\Business\Tests
 */

declare(strict_types=1);

namespace Akibara\Business\Tests\Modules\AgentReadiness;

use Akibara\Business\Modules\AgentReadiness\MarkdownNegotiation;
use Akibara\Business\Services\SeriesHubUrl;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class MarkdownNegotiationContractTest extends TestCase {

	/** @var array<string,mixed> */
	private array $server_original = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->server_original = $_SERVER;
		// La URL del directorio de series se memoriza por proceso: sin esto, lo
		// que resolvió una prueba se le cuela a la siguiente.
		SeriesHubUrl::reset();
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_original;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox El módulo está instanciado Y registrado en Plugin.php.
	 */
	public function test_esta_registrado(): void {
		$plugin = file_get_contents( dirname( __DIR__, 3 ) . '/src/Plugin.php' );
		$this->assertIsString( $plugin );

		$this->assertMatchesRegularExpression(
			"/\\\$this->modules\\['markdown_negotiation'\\] = new MarkdownNegotiation\\(\\)/",
			$plugin,
			'El módulo debe instanciarse en Plugin.php.'
		);
		$this->assertMatchesRegularExpression(
			"/\\\$this->modules\\['markdown_negotiation'\\]->register\\(\\)/",
			$plugin,
			'Instanciar sin register() deja el módulo como código muerto: ya pasó con CrossOriginScripts.'
		);
	}

	/**
	 * @testdox HTML va PRIMERO en los tipos ofrecidos.
	 *
	 * Este orden es lo único que impide servirle markdown a todos los
	 * navegadores: Chrome manda `*​/*;q=0.8`, que acepta markdown, y el empate
	 * lo rompe la preferencia del servidor. Invertir el array rompe el sitio
	 * para humanos sin romper ninguna otra prueba.
	 */
	public function test_html_es_la_preferencia_del_servidor(): void {
		$reflexion = new ReflectionClass( MarkdownNegotiation::class );
		$ofrecidos = $reflexion->getConstant( 'OFRECIDOS' );

		$this->assertSame(
			array( 'text/html', 'text/markdown' ),
			$ofrecidos,
			'HTML primero, siempre.'
		);
	}

	/**
	 * @testdox Los alias en inglés apuntan a páginas que existen.
	 *
	 * Un alias a una URL muerta es peor que no tenerlo: convierte un 404 honesto
	 * en un 301 hacia otro 404. Las rutas destino se comprobaron una a una
	 * contra producción el 2026-08-28.
	 */
	public function test_los_alias_apuntan_a_rutas_canonicas_conocidas(): void {
		$reflexion = new ReflectionClass( MarkdownNegotiation::class );
		$alias     = $reflexion->getConstant( 'ALIAS' );

		$this->assertIsArray( $alias );
		$this->assertNotEmpty( $alias );

		$paginas_reales = array(
			'/nosotros/',
			'/politica-de-privacidad/',
			'/contacto/',
			'/envios/',
			'/devoluciones/',
			'/terminos-y-condiciones/',
		);

		foreach ( $alias as $desde => $hacia ) {
			$this->assertStringStartsNotWith( '/', $desde, "El alias «{$desde}» se compara sin barras." );
			$this->assertContains(
				$hacia,
				$paginas_reales,
				"El alias «{$desde}» apunta a «{$hacia}», que no está en la lista de páginas verificadas."
			);
		}
	}

	/**
	 * @testdox El alias sólo actúa sobre un 404.
	 *
	 * Sin el guard, la regla se aplicaría también a una futura página real
	 * llamada /about/ y la haría irreachable con un 301 hacia otra.
	 */
	public function test_el_alias_solo_actua_sobre_404(): void {
		$_SERVER['REQUEST_URI'] = '/about/';
		$saltos                 = array();
		$this->stub_wordpress();
		Functions\when( 'is_404' )->justReturn( true );
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $destino, int $estado ) use ( &$saltos ): void {
				$saltos[] = array( $destino, $estado );
				throw new RedirigidoException();
			}
		);

		$sut = new MarkdownNegotiation();

		try {
			$sut->redirigir_alias();
			$this->fail( 'La ruta alias sobre un 404 tiene que redirigir.' );
		} catch ( RedirigidoException $esperado ) {
			$this->assertSame( array( array( 'https://akibara.cl/nosotros/', 301 ) ), $saltos );
		}

		// La MISMA ruta, cuando WordPress SÍ resolvió contenido, no se toca:
		// una futura página real /about/ quedaría inalcanzable.
		Functions\when( 'is_404' )->justReturn( false );
		$sut->redirigir_alias();

		$this->assertCount( 1, $saltos, 'Sólo el 404 dispara el alias.' );
	}

	/**
	 * @testdox El 404 en markdown entrega puntos de entrada rastreables.
	 */
	public function test_el_404_en_markdown_entrega_puntos_de_entrada(): void {
		$this->stub_wordpress();
		Functions\when( 'is_product' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( true );

		$cuerpo = ( new MarkdownNegotiation() )->markdown();

		// Un 404 que sólo dice «no existe» deja al agente adivinando.
		$this->assertStringContainsString( 'https://akibara.cl/series/', $cuerpo );
		$this->assertStringContainsString( 'https://akibara.cl/tienda/', $cuerpo );
		$this->assertStringContainsString( 'llms.txt', $cuerpo );
	}

	/**
	 * El directorio de series es una página que el dueño puede mover, y el 404
	 * llevaba `https://akibara.cl/series/` escrito a mano (2026-09-19, cierre de
	 * R2-8951cc1e: el primer barrido buscó `home_url('/series/')` y esta cadena
	 * no lo es).
	 *
	 * @testdox Si el dueño mueve el directorio de series, el 404 en markdown lo sigue.
	 */
	public function test_el_404_sigue_al_directorio_de_series_si_el_dueno_lo_mueve(): void {
		$this->stub_wordpress();
		Functions\when( 'is_product' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( true );
		Functions\when( 'get_posts' )->justReturn( array( 77 ) );
		Functions\when( 'get_post_field' )->justReturn( 'Intro [akibara_series_directory] fin' );
		Functions\when( 'has_shortcode' )->alias(
			static fn( string $contenido, string $tag ): bool => false !== strpos( $contenido, '[' . $tag )
		);
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.cl/todas-las-series/' );

		$cuerpo = ( new MarkdownNegotiation() )->markdown();

		$this->assertStringContainsString( 'https://akibara.cl/todas-las-series/', $cuerpo );
		$this->assertStringNotContainsString( 'https://akibara.cl/series/', $cuerpo );
	}

	/**
	 * @testdox La variante markdown NUNCA convierte un 404 en 200.
	 *
	 * La primera versión fijaba `status_header( 200 )` a secas y /no-existe/
	 * devolvía 200 con cuerpo markdown. Es el antipatrón exacto que la auditoría
	 * de agentes marca como fallo: un agente concluye que todas las rutas
	 * existen.
	 */
	public function test_el_estado_se_hereda_de_la_consulta(): void {
		$this->stub_wordpress();

		Functions\when( 'is_404' )->justReturn( true );
		$this->assertSame( 404, ( new MarkdownNegotiation() )->estado_de_la_variante() );

		Functions\when( 'is_404' )->justReturn( false );
		$this->assertSame( 200, ( new MarkdownNegotiation() )->estado_de_la_variante() );
	}

	public function test_declara_los_dos_tipos_del_contrato(): void {
		$this->assertSame( 'text/markdown', MarkdownNegotiation::TIPO_MARKDOWN );
		$this->assertSame( 'text/html', MarkdownNegotiation::TIPO_HTML );
	}

	/**
	 * @testdox El Vary se AÑADE a lo que ya haya, no lo pisa.
	 */
	public function test_el_vary_no_pisa_accept_encoding(): void {
		// Si alguien simplifica esto a `header('Vary: Accept')` se pierde
		// Accept-Encoding y se rompe la compresión en los CDN intermedios.
		$this->assertSame(
			'Accept-Encoding, Accept',
			MarkdownNegotiation::componer_vary( array( 'Content-Type: text/html', 'Vary: Accept-Encoding' ) )
		);

		// Varias cabeceras Vary, y un duplicado con otra caja: un solo listado,
		// con la primera caja vista.
		$this->assertSame(
			'Accept-Encoding, Cookie, Accept',
			MarkdownNegotiation::componer_vary(
				array( 'Vary: Accept-Encoding, Cookie', 'vary: accept-encoding' )
			)
		);

		// Un `Accept` que ya venía de arriba no se emite dos veces.
		$this->assertSame(
			'Accept',
			MarkdownNegotiation::componer_vary( array( 'Vary: Accept' ) )
		);

		// Sin Vary previo, el contrato sigue exigiendo el suyo.
		$this->assertSame( 'Accept', MarkdownNegotiation::componer_vary( array() ) );
	}

	/**
	 * @testdox La rama HTML también se negocia y por tanto emite Vary.
	 *
	 * Es el punto del contrato que más se olvida, porque la página «se ve bien»
	 * igual: el daño lo hace el CDN al cachear una variante y servir la otra.
	 * Lo que se observa aquí es la DECISIÓN —`negociar()` termina en `header()`
	 * y `exit`, que PHPUnit no puede interceptar—: mientras la petición sea
	 * negociable, la rama HTML es una respuesta negociada como cualquier otra.
	 */
	public function test_la_rama_html_tambien_emite_vary(): void {
		$this->stub_wordpress();
		Functions\when( 'get_option' )->justReturn( 'yes' );
		$_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

		$this->assertSame(
			MarkdownNegotiation::TIPO_HTML,
			( new MarkdownNegotiation() )->representacion_negociada(),
			'Chrome recibe HTML, y esa respuesta sigue siendo una variante negociada.'
		);

		$_SERVER['HTTP_ACCEPT'] = 'text/markdown';
		$this->assertSame(
			MarkdownNegotiation::TIPO_MARKDOWN,
			( new MarkdownNegotiation() )->representacion_negociada()
		);

		$_SERVER['HTTP_ACCEPT'] = 'image/png';
		$this->assertSame(
			MarkdownNegotiation::SIN_REPRESENTACION,
			( new MarkdownNegotiation() )->representacion_negociada(),
			'Un cliente que no acepta nada producible recibe 406, no HTML por defecto.'
		);
	}

	/**
	 * El interruptor apagado tiene que dejar la petición ENTERA sin negociar:
	 * ni markdown, ni 406, ni `Vary`.
	 *
	 * @testdox Con el interruptor apagado no se negocia nada.
	 */
	public function test_el_interruptor_apagado_no_negocia(): void {
		$this->stub_wordpress();
		Functions\when( 'get_option' )->justReturn( 'no' );
		$_SERVER['HTTP_ACCEPT'] = 'text/markdown';

		$this->assertNull( ( new MarkdownNegotiation() )->representacion_negociada() );
	}

	/**
	 * Un cliente autenticado ve barra de admin y precios por rol: su salida no
	 * es la pública, así que no se negocia.
	 *
	 * @testdox Un usuario autenticado nunca recibe la variante markdown.
	 */
	public function test_un_usuario_autenticado_no_negocia(): void {
		$this->stub_wordpress();
		Functions\when( 'get_option' )->justReturn( 'yes' );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$_SERVER['HTTP_ACCEPT'] = 'text/markdown';

		$this->assertNull( ( new MarkdownNegotiation() )->representacion_negociada() );
	}

	/**
	 * Un producto/entrada/página protegido con contraseña nunca se negocia: si
	 * se negociara, markdown_producto()/markdown_entrada() servirían el precio
	 * y el contenido íntegro a cualquiera que pidiera `Accept: text/markdown`,
	 * sin conocer la clave — la misma fuga que post_password_required() evita
	 * en las plantillas HTML.
	 *
	 * @testdox Un producto/entrada protegido con contraseña nunca recibe la variante markdown.
	 */
	public function test_contenido_protegido_con_contrasena_no_negocia(): void {
		$this->stub_wordpress();
		Functions\when( 'get_option' )->justReturn( 'yes' );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'post_password_required' )->justReturn( true );
		$_SERVER['HTTP_ACCEPT'] = 'text/markdown';

		$this->assertNull(
			( new MarkdownNegotiation() )->representacion_negociada(),
			'Con post_password_required() en true la petición cae al HTML nativo (formulario de contraseña), no a markdown.'
		);
	}

	/**
	 * Contraparte de la prueba anterior: la MISMA consulta singular, sin
	 * contraseña, sigue negociando con normalidad. Sin este caso, poner
	 * `is_singular()` a secas en el guard (sin el `&&`) pasaría la prueba de
	 * arriba pero rompería markdown en cualquier ficha o página pública.
	 *
	 * @testdox Una entrada singular SIN contraseña sigue negociando markdown con normalidad.
	 */
	public function test_contenido_singular_sin_contrasena_si_negocia(): void {
		$this->stub_wordpress();
		Functions\when( 'get_option' )->justReturn( 'yes' );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'post_password_required' )->justReturn( false );
		$_SERVER['HTTP_ACCEPT'] = 'text/markdown';

		$this->assertSame(
			MarkdownNegotiation::TIPO_MARKDOWN,
			( new MarkdownNegotiation() )->representacion_negociada()
		);
	}

	/**
	 * Deja el entorno de una petición pública de front, negociable por defecto.
	 */
	private function stub_wordpress(): void {
		$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ) => parse_url( $url, $component )
		);
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '/' ): string => 'https://akibara.cl' . $path
		);
		Functions\when( 'get_option' )->justReturn( 'yes' );
		// Ninguna página contiene el shortcode del directorio: `SeriesHubUrl`
		// cae al slug de fábrica. La prueba que lo mueve redefine este doble.
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_robots' )->justReturn( false );
		Functions\when( 'is_trackback' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'post_password_required' )->justReturn( false );
	}

	/**
	 * @testdox Los pares clave/valor y las tablas (tarjetas de edición) salen legibles en el markdown para agentes.
	 */
	public function test_dl_and_tables_become_readable_markdown(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $t ): string => strip_tags( $t ) );
		$m = new \ReflectionMethod( MarkdownNegotiation::class, 'html_a_markdown' );
		$m->setAccessible( true );
		$html = '<dl><div><dt>Idioma</dt><dd>Español rioplatense</dd></div></dl><table><thead><tr><th>Criterio</th><th>Panini Argentina</th></tr></thead><tbody><tr><th>Idioma</th><td>Rioplatense</td></tr></tbody></table>';

		$md = (string) $m->invoke( new MarkdownNegotiation(), $html );

		self::assertStringContainsString( '- **Idioma**: Español rioplatense', $md );
		self::assertStringContainsString( '| Criterio | Panini Argentina |', $md );
		self::assertStringContainsString( '| Idioma | Rioplatense |', $md );
	}

	/**
	 * @testdox Una portada enlazada sale como enlace con el nombre de la serie, no como URL suelta.
	 */
	public function test_linked_cover_keeps_the_series_name(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $t ): string => strip_tags( $t ) );
		$m = new \ReflectionMethod( MarkdownNegotiation::class, 'html_a_markdown' );
		$m->setAccessible( true );

		$md = (string) $m->invoke( new MarkdownNegotiation(), '<a href="https://akibara.test/serie/fire-force/"><img src="https://akibara.test/p/1.jpg" alt="Fire Force" width="160"></a>' );

		self::assertStringContainsString( '[Fire Force](https://akibara.test/serie/fire-force/)', $md );
		self::assertStringNotContainsString( '1.jpg', $md );
	}
}

/** Marca el `wp_safe_redirect` que precede al `exit` de `redirigir_alias()`. */
final class RedirigidoException extends \RuntimeException {}
