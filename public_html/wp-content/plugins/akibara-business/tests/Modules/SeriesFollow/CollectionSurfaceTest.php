<?php
/**
 * El interruptor pintado en /mi-cuenta/coleccion/.
 *
 * Seguir una serie es un hecho DERIVADO de haberla comprado: lo que se almacena
 * es la NEGACIÓN. Por eso el estado por defecto de una tarjeta es «siguiendo», y
 * lo que hay que probar es que la baja se refleja.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFollow;

use Akibara\Business\Modules\AccountCollection\Renderer;
use Akibara\Business\Modules\SeriesFollow\FollowRegistry;
use Akibara\Business\Services\SeriesHubUrl;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) . '/' );

final class CollectionSurfaceTest extends TestCase {

	private const CLIENTE = 91;
	private const TERM_ID = 42;

	/** @var array<int, int> Series dadas de baja por el cliente. */
	private array $bajas = array();

	private string $baja_global = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->bajas       = array();
		$this->baja_global = '';

		$escape = static fn ( $value ): string => htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		Functions\when( 'esc_html' )->alias( $escape );
		Functions\when( 'esc_attr' )->alias( $escape );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ): string => 'https://akibara.test' . $path );
		// SeriesHubUrl (R2-8951cc1e): sin páginas que la contengan, cae al
		// fallback `/series/` — igual que el literal que reemplazó.
		Functions\when( 'get_posts' )->justReturn( array() );
		SeriesHubUrl::reset();
		Functions\when( 'wc_get_endpoint_url' )->alias(
			static fn ( string $slug, string $value = '', string $permalink = '' ): string =>
				( '' !== $permalink ? rtrim( $permalink, '/' ) . '/' : 'https://akibara.test/mi-cuenta/' ) . $slug . '/'
		);
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.test/mi-cuenta/' );
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( '' );
		Functions\when( 'update_meta_cache' )->justReturn( array() );
		Functions\when( 'number_format_i18n' )->alias( static fn ( $number ): string => number_format( (float) $number, 0, ',', '.' ) );
		// El interruptor global lleva un nonce atado al id del cliente.
		Functions\when( 'wp_create_nonce' )->alias( static fn ( string $action ): string => 'nonce-' . $action );
		Functions\when( 'get_user_meta' )->alias(
			function ( int $user_id, string $key ) {
				unset( $user_id );
				return FollowRegistry::META_OPTOUT === $key ? $this->bajas : $this->baja_global;
			}
		);
		Functions\when( 'wc_get_logger' )->justReturn(
			new class() {
				/** @param array<string, mixed> $context Contexto del log. */
				public function warning( string $message, array $context = array() ): void {
					unset( $message, $context );
				}
			}
		);
	}

	protected function tearDown(): void {
		SeriesHubUrl::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param int $term_id Serie de la tarjeta (0 = sin término).
	 * @return array<string, mixed>
	 */
	private function serie( int $term_id = self::TERM_ID ): array {
		$dto = array(
			'name'                 => 'One Piece',
			'url'                  => 'https://akibara.test/serie/one-piece/',
			'owned_products'       => array(),
			'owned_count'          => 3,
			'owned_volume_count'   => 3,
			'catalog_count'        => 10,
			'edition'              => array(
				'state'          => 'ongoing',
				'total'          => 10,
				'progress_owned' => 3,
				'is_complete'    => false,
			),
			'continuity'           => array( 'kind' => 'next' ),
		);

		if ( $term_id > 0 ) {
			$dto['term_id'] = $term_id;
		}

		return $dto;
	}

	private function html( int $user_id = self::CLIENTE, int $term_id = self::TERM_ID ): string {
		$serie = $this->serie( $term_id );

		return ( new Renderer(
			static fn ( int $id ): array => array(
				'status' => 'ok',
				'series' => array( $serie ),
			)
		) )->render( $user_id );
	}

	/** @testdox Una serie comprada y sin baja se pinta como «siguiendo». */
	public function test_una_serie_sin_baja_se_pinta_como_siguiendo(): void {
		$html = $this->html();

		self::assertStringContainsString( 'data-akb-series-follow', $html );
		self::assertStringContainsString( 'aria-pressed="true"', $html );
		self::assertStringContainsString( 'Avisos activados', $html );
		self::assertStringContainsString( 'data-term-id="42"', $html );
	}

	/** @testdox Una serie dada de baja se pinta apagada. */
	public function test_una_serie_dada_de_baja_se_pinta_apagada(): void {
		$this->bajas = array( self::TERM_ID );

		$html = $this->html();

		self::assertStringContainsString( 'aria-pressed="false"', $html );
		self::assertStringContainsString( 'Avisarme del siguiente tomo', $html );
	}

	/**
	 * CAMBIO DE CONTRATO DELIBERADO (2026-09-11). Hasta hoy la baja global se
	 * reflejaba pintando cada botón por serie en `aria-pressed="false"`. Eso
	 * tenía una trampa: `FollowRegistry::opt_in()` de UNA serie levanta la
	 * baja global, así que N botones «Avisarme del siguiente tomo» eran N
	 * formas de reencender TODOS los avisos desde una tarjeta. Ahora, con el
	 * global apagado, la tarjeta no pinta botón y dice por qué; el único
	 * camino de vuelta es el interruptor global de arriba.
	 *
	 * @testdox La baja global quita el botón de todas las series y lo explica en cada tarjeta.
	 */
	public function test_la_baja_global_bloquea_todas_las_series(): void {
		$this->baja_global = '1';

		$html = $this->html();

		self::assertStringNotContainsString( 'data-akb-series-follow', $html );
		self::assertStringNotContainsString( 'aria-pressed=', $html );
		self::assertStringContainsString( 'akb-series-follow__locked', $html );
		self::assertStringContainsString( 'Avisos apagados para toda tu colección.', $html );
	}

	/**
	 * @param string $status Estado que devuelve el resolver.
	 * @param int    $user_id Cliente.
	 */
	private function html_de_estado( string $status, int $user_id = self::CLIENTE ): string {
		return ( new Renderer(
			static fn ( int $id ): array => array(
				'status' => $status,
				'series' => array(),
			)
		) )->render( $user_id );
	}

	/** @testdox Con los avisos encendidos, la sección global ofrece apagarlos. */
	public function test_con_el_global_encendido_ofrece_apagar(): void {
		$html = $this->html();

		self::assertStringContainsString( 'Avisos de mi colección', $html );
		self::assertStringContainsString( 'Activados. Cuando compras un tomo, te avisamos por correo al salir el siguiente de esa serie. Como máximo un correo por semana, y en cada aviso puedes apagar una serie o todas.', $html );
		self::assertStringContainsString( 'Apagar los avisos', $html );
		self::assertMatchesRegularExpression( '/<input[^>]*name="akb_sf_global"[^>]*value="off"/', $html );
		self::assertStringNotContainsString( 'Encender los avisos', $html );
	}

	/** @testdox Con los avisos apagados, la sección global ofrece encenderlos. */
	public function test_con_el_global_apagado_ofrece_encender(): void {
		$this->baja_global = '1';

		$html = $this->html();

		self::assertStringContainsString( 'Apagados. No te avisaremos de ningún tomo nuevo. Tus compras y tu colección siguen igual.', $html );
		self::assertStringContainsString( 'Encender los avisos', $html );
		self::assertMatchesRegularExpression( '/<input[^>]*name="akb_sf_global"[^>]*value="on"/', $html );
		self::assertStringNotContainsString( 'Apagar los avisos', $html );
	}

	/**
	 * Sin JavaScript: un formulario POST con nonce contra la propia Colección.
	 *
	 * @testdox El interruptor global es un formulario POST con nonce del cliente.
	 */
	public function test_el_interruptor_global_es_un_form_post_con_nonce(): void {
		$html = $this->html();

		self::assertMatchesRegularExpression( '/<form method="post" action="https:\/\/akibara\.test\/mi-cuenta\/coleccion\/"/', $html );
		self::assertMatchesRegularExpression( '/<input type="hidden" name="_akb_sf_nonce" value="nonce-akb_sf_global_91"/', $html );
		self::assertMatchesRegularExpression( '/<button type="submit"[^>]*>Apagar los avisos<\/button>/', $html );
	}

	/** @testdox La nota bajo el interruptor dice lo mismo que la política y el checkout. */
	public function test_la_nota_del_interruptor_lleva_el_texto_exacto(): void {
		self::assertStringContainsString(
			'Estos avisos no son publicidad: nacen de lo que ya compraste. Las ofertas y novedades llegan solo si marcaste esa casilla al comprar, y se cortan con el enlace de baja de cada correo.',
			$this->html()
		);
	}

	/**
	 * El consentimiento no depende de tener tomos: quien se dio de baja desde
	 * un correo tiene que poder reencender aunque su colección esté vacía.
	 *
	 * @testdox Con la colección vacía el interruptor global SÍ se pinta.
	 */
	public function test_con_coleccion_vacia_se_pinta_el_interruptor_global(): void {
		$html = $this->html_de_estado( 'empty' );

		self::assertStringContainsString( 'Avisos de mi colección', $html );
		self::assertStringContainsString( 'Apagar los avisos', $html );
	}

	/**
	 * CAMBIO DE CONTRATO DELIBERADO (2026-09-11). Hasta hoy en «no disponible»
	 * NO se pintaba el interruptor. Pero 'unavailable' es un fallo de la vista
	 * de compras (cualquier Throwable del resolver), no del consentimiento, y
	 * mientras dure el cliente no tenía ninguna superficie para apagar o
	 * encender los avisos: la página «Listo» de la baja por correo le mandaba
	 * justo aquí, a una pantalla que sólo decía «No pudimos cargar tu
	 * colección». El interruptor no depende de que la colección cargue.
	 *
	 * @testdox En «no disponible» el interruptor global SÍ se pinta, junto al aviso de error.
	 */
	public function test_en_no_disponible_si_se_pinta_el_interruptor_global(): void {
		$html = $this->html_de_estado( 'unavailable' );

		self::assertStringContainsString( 'Avisos de mi colección', $html );
		self::assertStringContainsString( 'name="akb_sf_global"', $html );
		self::assertStringContainsString( 'No pudimos cargar tu colección ahora', $html );
	}

	/**
	 * «Te avisamos … al salir el siguiente de esa serie» no vale para las
	 * series apagadas una a una desde el correo: el global encendido no las
	 * reactiva. El copy del dueño va intacto y le sigue la salvedad.
	 *
	 * @testdox Con el global encendido y una serie apagada una a una, la sección lo dice; sin bajas por serie, no.
	 */
	public function test_con_una_serie_apagada_una_a_una_la_seccion_lo_dice(): void {
		self::assertStringNotContainsString( 'que apagaste una a una', $this->html() );

		$this->bajas = array( self::TERM_ID );
		$html        = $this->html();

		self::assertStringContainsString( 'Salvo una serie que apagaste una a una: la reactivas desde su tarjeta.', $html );
		self::assertStringContainsString( 'Activados. Cuando compras un tomo', $html, 'El copy del dueño no cambia.' );
	}

	/** @testdox [NEG] Con el global apagado no hay salvedad por serie: ya está todo apagado. */
	public function test_con_el_global_apagado_no_hay_salvedad_por_serie(): void {
		$this->bajas       = array( self::TERM_ID );
		$this->baja_global = '1';

		self::assertStringNotContainsString( 'que apagaste una a una', $this->html() );
	}

	/** @testdox [NEG] Sin sesión no se pinta el interruptor global. */
	public function test_sin_sesion_no_se_pinta_el_interruptor_global(): void {
		self::assertStringNotContainsString( 'Avisos de mi colección', $this->html( 0 ) );
		self::assertStringNotContainsString( 'Avisos de mi colección', $this->html_de_estado( 'empty', 0 ) );
	}

	/** @testdox [NEG] Una tarjeta sin término no pinta interruptor. */
	public function test_una_tarjeta_sin_termino_no_pinta_interruptor(): void {
		self::assertStringNotContainsString( 'data-akb-series-follow', $this->html( self::CLIENTE, 0 ) );
	}

	/** @testdox [NEG] Sin sesión no se pinta interruptor. */
	public function test_sin_sesion_no_se_pinta_interruptor(): void {
		self::assertStringNotContainsString( 'data-akb-series-follow', $this->html( 0 ) );
	}

	/**
	 * El mensaje de estado tiene que existir en el DOM ANTES de que llegue el
	 * texto, o el lector de pantalla pierde el anuncio.
	 *
	 * @testdox El interruptor lleva su región aria-live desde el render inicial.
	 */
	public function test_el_interruptor_lleva_su_region_aria_live(): void {
		$html = $this->html();

		self::assertStringContainsString( 'akb-series-follow__msg', $html );
		self::assertStringContainsString( 'aria-live="polite"', $html );
	}

	/** @testdox El interruptor es un button, no un enlace: muta estado. */
	public function test_el_interruptor_es_un_boton(): void {
		self::assertMatchesRegularExpression(
			'/<button[^>]*type="button"[^>]*data-akb-series-follow/s',
			$this->html()
		);
	}
}
