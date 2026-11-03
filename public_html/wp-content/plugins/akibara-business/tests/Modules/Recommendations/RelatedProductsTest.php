<?php
/**
 * «Productos relacionados» de la ficha (UC-6): el adaptador que arma el pool,
 * resuelve los tomos de entrada en lote y respeta el modo del panel.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Recommendations;

use Akibara\Business\Modules\Recommendations\RelatedProducts;
use Akibara\Business\Modules\Recommendations\Settings;
use Akibara\Business\Tests\Support\CatalogoDeRecomendaciones;
use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class RelatedProductsTest extends TestCase {

	private const JJK  = 1;
	private const DDD  = 2;
	private const BC   = 3;
	private const KING = 4;

	/** Otra edición de la misma obra que JJK (SeriesEditions). */
	private const JJK_COLECCIONISTA = 5;

	private const FICHA = 125;

	private CatalogoDeRecomendaciones $catalogo;

	private string $modo = Settings::RELATED_OTRA_SERIE;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->catalogo = new CatalogoDeRecomendaciones();
		$this->catalogo->agregar( self::FICHA, 'Jujutsu Kaisen 25', self::JJK );
		$this->catalogo->agregar( 126, 'Jujutsu Kaisen 26', self::JJK, false );
		$this->catalogo->agregar( 201, 'Dan Da Dan 01', self::DDD );
		$this->catalogo->agregar( 205, 'Dan Da Dan 05', self::DDD );
		$this->catalogo->agregar( 301, 'Black Clover 01', self::BC, false );
		$this->catalogo->agregar( 302, 'Black Clover 02', self::BC );
		$this->catalogo->agregar( 303, 'Black Clover 03', self::BC );
		$this->catalogo->agregar( 401, 'Kingdom 01', self::KING );
		$this->catalogo->agregar( 431, 'Kingdom 31', self::KING );
		$this->catalogo->agregar( 900, 'Artbook sin serie', null, false );
		$this->catalogo->instalar();

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default = false ) => Settings::OPTION_RELATED === $name ? $this->modo : $default
		);
	}

	protected function tearDown(): void {
		$this->catalogo->desinstalar();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Una serie por hueco y cada una desde su tomo de entrada; la serie de la ficha y los excluidos no ocupan huecos.
	 */
	public function test_una_serie_por_hueco_desde_su_entrada(): void {
		$pool = array( 205, 303, 302, 126, 431, 900 );

		$ids = ( new RelatedProducts() )->choose( $pool, self::FICHA, array( 'limit' => 3, 'excluded_ids' => array( 0, self::FICHA ) ) );

		$primeros = array_slice( $ids, 0, 3 );
		sort( $primeros );
		self::assertSame( array( 201, 302, 401 ), $primeros, 'Dan Da Dan 01 y Kingdom 01 abren su serie; Black Clover entra con el 02 porque el 01 está agotado.' );
		self::assertSame( 302, $ids[2], 'La serie que no abre va detrás de las que sí.' );
		self::assertNotContains( self::FICHA, $ids );
		self::assertSame( 1, $this->catalogo->consultas_de_tomos, 'Una sola consulta para los tomos de todas las series.' );
	}

	/**
	 * @testdox «Empezar otra serie» no ofrece otra edición de la misma obra: va detrás de todas las series que abren (B2-2-R2).
	 */
	public function test_otra_edicion_de_la_misma_obra_no_cuenta_como_otra_serie(): void {
		Functions\when( 'absint' )->alias( static fn( $v ): int => abs( (int) $v ) );
		$this->catalogo->agregar( 501, 'Jujutsu Kaisen Edición Coleccionista 01', self::JJK_COLECCIONISTA );
		$this->catalogo->ediciones[ self::JJK ] = array( self::JJK_COLECCIONISTA );

		$ids = ( new RelatedProducts() )->choose( array( 501, 205, 303, 431 ), self::FICHA, array( 'limit' => 3, 'excluded_ids' => array( 0, self::FICHA ) ) );

		$primeros = array_slice( $ids, 0, 3 );
		sort( $primeros );
		self::assertSame( array( 201, 302, 401 ), $primeros, 'Los tres huecos son series de verdad distintas.' );
		self::assertNotContains( 501, array_slice( $ids, 0, 3 ), 'La otra edición de JJK no ocupa un hueco de «otra serie».' );
		self::assertContains( 501, $ids, 'Sigue en la lista, en el grupo de la misma serie: rellena si faltaran series.' );
		self::assertSame( 1, $this->catalogo->consultas_de_ediciones, 'Una consulta para pasar las ediciones a term_taxonomy_id.' );
	}

	/**
	 * @testdox Sin ediciones relacionadas no se consulta nada más.
	 */
	public function test_sin_ediciones_relacionadas_no_consulta(): void {
		( new RelatedProducts() )->choose( array( 205, 303, 431 ), self::FICHA, array( 'limit' => 3 ) );

		self::assertSame( 0, $this->catalogo->consultas_de_ediciones );
	}

	/**
	 * @testdox El lote de los primeros tomos se prima UNA vez, no producto a producto.
	 */
	public function test_prima_en_lotes(): void {
		( new RelatedProducts() )->choose( array( 205, 303, 431 ), self::FICHA, array( 'limit' => 4 ) );

		self::assertCount( 2, $this->catalogo->primados, 'Un lote para el pool y otro para las estanterías.' );
	}

	/**
	 * @testdox Modo «como antes»: sólo reordena el pool con los comprables delante, sin consultar series.
	 */
	public function test_modo_comprables(): void {
		$this->modo = Settings::RELATED_COMPRABLES;

		$ids = ( new RelatedProducts() )->choose( array( 301, 126, 205 ), self::FICHA, array( 'limit' => 4 ) );

		self::assertSame( 205, $ids[0] );
		self::assertEqualsCanonicalizing( array( 301, 126 ), array_slice( $ids, 1 ) );
		self::assertSame( 0, $this->catalogo->consultas_de_tomos );
	}

	/**
	 * QA-RC-3 (QA integral 2026-09-24): barajar TOMOS premiaba a las series
	 * largas —One Piece, 110 tomos— y su tomo 1 recibía relacionados desde 417
	 * de 1.626 fichas. El pool se agrupa por serie y el orden fijo es el de la
	 * serie: cada una tiene la misma probabilidad.
	 *
	 * @testdox El pool tiene un orden FIJO por ficha, antes del LIMIT: un producto por serie, ordenado por MD5 de la serie con la ficha, no RAND().
	 */
	public function test_pool_con_orden_fijo_por_ficha_antes_del_limit(): void {
		$query = array(
			'fields' => 'SELECT DISTINCT ID FROM wp_posts p',
			'join'   => ' INNER JOIN ( SELECT object_id FROM wp_term_relationships ) AS include_join ON include_join.object_id = p.ID',
			'where'  => 'WHERE 1=1',
			'limits' => 'LIMIT 24',
		);
		Functions\when( 'absint' )->alias( static fn( $v ): int => abs( (int) $v ) );
		$series = array( 11, 12, 13 );
		Functions\when( 'get_terms' )->alias(
			static function ( array $args ) use ( &$series ) {
				return 'pa_serie' === $args['taxonomy'] && 'tt_ids' === $args['fields'] && false === $args['hide_empty'] ? $series : array( 999 );
			}
		);

		$sql = implode( ' ', ( new RelatedProducts() )->stable_pool( $query, self::FICHA ) );
		self::assertMatchesRegularExpression( '/^\s*SELECT MIN\( p\.ID \) AS ID, COALESCE\( akb_serie\.term_taxonomy_id, -p\.ID \) AS akb_grupo FROM wp_posts p /', $sql, 'La primera columna sigue siendo el id: WooCommerce lee la consulta con get_col().' );
		self::assertStringContainsString( 'AS include_join ON include_join.object_id = p.ID LEFT JOIN wp_term_relationships akb_serie ON akb_serie.object_id = p.ID AND akb_serie.term_taxonomy_id IN ( 11, 12, 13 )', $sql, 'Los JOIN de WooCommerce se conservan; la serie se suma detrás, sin unir term_taxonomy por fila.' );
		self::assertMatchesRegularExpression( "/WHERE 1=1\\s+GROUP BY akb_grupo\\s+ORDER BY MD5\\( CONCAT\\( akb_grupo, ':', 125 \\) \\), ID\\s+LIMIT 24/", $sql );
		self::assertStringNotContainsString( 'RAND()', $sql, 'Sin RAND(): el pool no rota cada vez que caduca el transient.' );

		// Otro filtro ya cambió el SELECT: no se sabe qué columnas pide, así que
		// no se agrupa; el orden fijo por tomo de antes.
		$ajena           = $query;
		$ajena['fields'] = 'SELECT DISTINCT ID, post_title FROM wp_posts p';
		$sql             = implode( ' ', ( new RelatedProducts() )->stable_pool( $ajena, self::FICHA ) );
		self::assertStringNotContainsString( 'GROUP BY', $sql );
		self::assertMatchesRegularExpression( "/WHERE 1=1\\s+ORDER BY MD5\\( CONCAT\\( p\\.ID, ':', 125 \\) \\), p\\.ID\\s+LIMIT 24/", $sql );

		// Sin series (o sin la taxonomía), tampoco: un IN () vacío es un error de SQL.
		$series = array();
		$sql    = implode( ' ', ( new RelatedProducts() )->stable_pool( $query, self::FICHA ) );
		self::assertStringNotContainsString( 'IN (  )', $sql );
		self::assertStringNotContainsString( 'GROUP BY', $sql );
		self::assertStringContainsString( "ORDER BY MD5( CONCAT( p.ID, ':', 125 ) ), p.ID", $sql );

		$this->modo = Settings::RELATED_COMPRABLES;
		self::assertSame( $query, ( new RelatedProducts() )->stable_pool( $query, self::FICHA ), 'El modo anterior deja la consulta de WooCommerce como era.' );
	}

	/**
	 * @testdox El filtro del pool se registra con dos argumentos: WordPress le pasa el id de la ficha y dos fichas no comparten pool (RN1-D2).
	 */
	public function test_el_filtro_del_pool_recibe_el_id_de_la_ficha(): void {
		Functions\when( 'absint' )->alias( static fn( $v ): int => abs( (int) $v ) );
		Functions\when( 'get_terms' )->justReturn( array( 11, 12 ) );
		$enganches = array();
		Filters\expectAdded( 'woocommerce_product_related_posts_query' )->once()->whenHappen(
			static function ( $callback, $priority, $accepted_args ) use ( &$enganches ): void {
				$enganches[] = array( $callback, $priority, $accepted_args );
			}
		);

		( new RelatedProducts() )->register();

		self::assertCount( 1, $enganches );
		list( $callback, , $accepted_args ) = $enganches[0];
		self::assertGreaterThanOrEqual( 2, $accepted_args, 'Con uno solo, el id de la ficha llega como 0 y toda la categoría comparte el mismo pool.' );

		// Como lo llama WordPress: `WP_Hook::apply_filters()` corta los argumentos
		// a `accepted_args`, y WooCommerce pasa la consulta, el id y sus args
		// (`WC_Product_Data_Store_CPT::get_related_products()`).
		$query   = array(
			'fields' => 'SELECT DISTINCT ID FROM wp_posts p',
			'join'   => '',
			'where'  => 'WHERE 1=1',
			'limits' => 'LIMIT 24',
		);
		$pool_de = static fn( int $ficha ): string => implode(
			' ',
			call_user_func_array( $callback, array_slice( array( $query, $ficha, array( 'limit' => 24 ) ), 0, $accepted_args ) )
		);
		self::assertStringContainsString( "MD5( CONCAT( akb_grupo, ':', 125 ) )", $pool_de( self::FICHA ) );
		self::assertNotSame( $pool_de( self::FICHA ), $pool_de( 126 ), 'Dos fichas de la misma categoría ordenan su pool con su propio id.' );

		// Y el orden elegido llega a la plantilla sin barajar.
		self::assertSame( 20, Filters\has( 'woocommerce_output_related_products_args', array( RelatedProducts::class, 'keep_display_order' ) ) );
		self::assertSame( 20, Filters\has( 'woocommerce_related_products', RelatedProducts::class . '->choose()' ) );
	}

	/**
	 * @testdox La misma ficha elige siempre lo mismo, llegue el pool en el orden que llegue; otra ficha, otro orden.
	 */
	public function test_la_eleccion_es_la_misma_en_cada_render(): void {
		$pool   = array( 205, 303, 302, 126, 431, 900, 401, 201 );
		$args   = array( 'limit' => 3, 'excluded_ids' => array( 0, self::FICHA ) );
		$sut    = new RelatedProducts();
		$vistas = array();
		for ( $i = 0; $i < 12; $i++ ) {
			shuffle( $pool );
			$vistas[] = implode( ',', $sut->choose( $pool, self::FICHA, $args ) );
		}

		self::assertCount( 1, array_unique( $vistas ), 'Doce renders con el pool en otro orden: una sola elección.' );

		// El orden con que se miran las series es el del hash de ESTA ficha, por
		// SERIE (el producto sin serie, por su -ID), como el GROUP BY en MySQL.
		$orden = new \ReflectionMethod( RelatedProducts::class, 'stable_order' );
		$orden->setAccessible( true );
		$serie    = array( 205 => self::DDD, 201 => self::DDD, 303 => self::BC, 302 => self::BC, 126 => self::JJK, 431 => self::KING, 401 => self::KING, 900 => -900 );
		$esperado = $pool;
		usort( $esperado, static fn( int $a, int $b ): int => strcmp( md5( $serie[ $a ] . ':' . self::FICHA ), md5( $serie[ $b ] . ':' . self::FICHA ) ) ?: $a <=> $b );
		self::assertSame( $esperado, $orden->invoke( null, $pool, self::FICHA ), 'El mismo orden que MD5( CONCAT( akb_grupo, ":", ficha ) ) en MySQL.' );
		self::assertNotSame( $orden->invoke( null, $pool, self::FICHA ), $orden->invoke( null, $pool, 126 ), 'Otra ficha reparte en otro orden.' );
	}

	/**
	 * @testdox Los tomos de una serie quedan juntos en el orden: la serie larga no ocupa más lugares que una corta.
	 */
	public function test_el_orden_es_por_serie_y_no_por_tomo(): void {
		$orden = new \ReflectionMethod( RelatedProducts::class, 'stable_order' );
		$orden->setAccessible( true );

		foreach ( array( self::FICHA, 126, 201, 777 ) as $ficha ) {
			$series = array_map(
				static fn( int $id ): int => array( 205 => self::DDD, 201 => self::DDD, 303 => self::BC, 302 => self::BC, 301 => self::BC, 431 => self::KING, 401 => self::KING, 900 => -900 )[ $id ],
				$orden->invoke( null, array( 205, 303, 302, 301, 431, 900, 401, 201 ), $ficha )
			);
			$bloques = array();
			foreach ( $series as $i => $s ) {
				if ( 0 === $i || $series[ $i - 1 ] !== $s ) {
					$bloques[] = $s;
				}
			}
			self::assertSame( $bloques, array_values( array_unique( $bloques ) ), 'Ficha ' . $ficha . ': cada serie aparece en un solo tramo.' );
		}
	}

	/**
	 * @testdox WooCommerce pinta los relacionados en el orden elegido, sin barajar; el modo anterior, como siempre.
	 */
	public function test_la_plantilla_no_baraja_las_tarjetas(): void {
		$gp = array( 'posts_per_page' => 4, 'columns' => 4, 'orderby' => 'rand', 'order' => 'desc' );

		self::assertSame(
			array( 'posts_per_page' => 4, 'columns' => 4, 'orderby' => 'none', 'order' => 'asc' ),
			RelatedProducts::keep_display_order( $gp ),
			'Conserva cuántos y columnas; no baraja ni invierte.'
		);

		$this->modo = Settings::RELATED_COMPRABLES;
		self::assertSame( $gp, RelatedProducts::keep_display_order( $gp ) );
		self::assertSame( 'x', RelatedProducts::keep_display_order( 'x' ) );
	}

	/**
	 * @testdox Lo que no es una lista se devuelve tal cual.
	 */
	public function test_entrada_rara(): void {
		self::assertSame( 'x', ( new RelatedProducts() )->choose( 'x', self::FICHA ) );
		self::assertSame( array(), ( new RelatedProducts() )->choose( array(), self::FICHA ) );
	}
}
