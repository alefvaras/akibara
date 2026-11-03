<?php
/**
 * Contrato ejecutable del orden de la rejilla en las landings de serie.
 *
 * La suite Node de al lado fija el TEXTO de `inc/series-order.php`; acá se
 * ejecutan los callbacks, que es donde una regresión llega a producción con
 * todo lo demás en verde. Dos ramas importan de verdad:
 *
 *   1. Que el nuevo defecto (`title`) NO se escape de `pa_serie`. Si se
 *      escapara, `/tienda/` perdería el interleaver de round-robin y la
 *      portada del catálogo pasaría a ser un muro de una sola serie.
 *   2. Que "Novedades primero" (`menu_order`) siga en el selector. WooCommerce
 *      la BORRA por diseño cuando el defecto no es `menu_order`
 *      (wc-template-functions.php:1726), así que sin la reposición el arreglo
 *      del orden costaría perder la única salida al orden anterior en las 106
 *      landings.
 *
 * @package akibara-gp\Tests
 */

declare( strict_types = 1 );

namespace Akibara\GP\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class SeriesOrderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// El módulo registra sus filtros al cargarse; Brain Monkey intercepta
		// add_filter, así que el require es seguro e idempotente.
		require_once dirname( __DIR__ ) . '/inc/series-order.php';
	}

	protected function tearDown(): void {
		unset( $_GET['_buscar'], $_GET['orderby'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Simula `?_buscar=` en la landing.
	 *
	 * @param string|null $value Texto buscado, o null para una landing sin búsqueda.
	 */
	private function searching( ?string $value ): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $t ): string => trim( strip_tags( (string) $t ) ) );
		unset( $_GET['_buscar'] );
		if ( null !== $value ) {
			$_GET['_buscar'] = $value;
		}
	}

	/**
	 * Revisión B1-fix (lote de integración): con búsqueda la rejilla sale por
	 * relevancia; con el defecto en `title` el selector marcaba «Tomo 1 → N» y
	 * WooCommerce borraba `menu_order`, que este módulo reponía como «Novedades
	 * primero». Medido en /serie/one-piece/?_buscar=…&orderby=menu_order.
	 *
	 * @testdox Con búsqueda en la serie, el defecto sigue siendo la relevancia y su opción conserva el nombre del plugin.
	 */
	public function test_search_on_a_series_keeps_relevance(): void {
		$this->on_series( true );
		$this->searching( 'one piece 10' );

		self::assertSame( 'menu_order', akibara_gp_series_default_orderby( 'menu_order' ) );

		$options = akibara_gp_series_orderby_options( array( 'menu_order' => 'Relevancia' ) + $this->plugin_options() );
		self::assertSame( 'Relevancia', $options['menu_order'], '«Novedades primero» anunciaba un orden que no era el de la lista.' );
		self::assertSame( 'Número de tomo', $options['title'], 'El orden por tomo sigue nombrando su resultado.' );

		$this->searching( '   ' );
		self::assertSame( 'title', akibara_gp_series_default_orderby( 'menu_order' ), 'Una búsqueda en blanco no es una búsqueda.' );
		$this->searching( null );
		self::assertSame( 'Novedades primero', akibara_gp_series_orderby_options( $this->plugin_options() )['menu_order'] );
	}

	/**
	 * Fuerza la superficie: landing de serie o cualquier otra cosa.
	 *
	 * @param bool $is_series Si la ruta actual debe considerarse `pa_serie`.
	 * @return void
	 */
	private function on_series( bool $is_series ): void {
		Functions\when( 'is_tax' )->justReturn( $is_series );
	}

	/**
	 * Simula el `?orderby` de la petición.
	 *
	 * @param string|null $value Valor pedido, o null para una URL sin parámetro.
	 * @return void
	 */
	private function requested_orderby( ?string $value ): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static fn( $key ): string => strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) )
		);

		unset( $_GET['orderby'] );
		if ( null !== $value ) {
			$_GET['orderby'] = $value;
		}
	}

	/**
	 * Selector tal como lo entrega akibara-business en prioridad 10.
	 *
	 * @return array<string, string>
	 */
	private function plugin_options(): array {
		return array(
			'menu_order' => 'Destacados',
			'date'       => 'Más recientes',
			'popularity' => 'Más vendidos',
			'price'      => 'Menor precio',
			'price-desc' => 'Mayor precio',
			'title'      => 'Título: A-Z',
		);
	}

	/**
	 * @testdox En una landing de serie el orden por defecto pasa a ser el número de tomo.
	 */
	public function test_series_archive_defaults_to_title(): void {
		$this->on_series( true );

		self::assertSame( 'title', akibara_gp_series_default_orderby( 'menu_order' ) );
	}

	/**
	 * @testdox Fuera de pa_serie el defecto se devuelve intacto: /tienda/ conserva su interleaver.
	 */
	public function test_other_archives_keep_menu_order(): void {
		$this->on_series( false );

		self::assertSame(
			'menu_order',
			akibara_gp_series_default_orderby( 'menu_order' ),
			'Cambiar el defecto global convertiría la página 1 de /tienda/ en un muro de una sola serie.'
		);
		// Y si el sitio tuviera otro defecto configurado, tampoco se pisa.
		self::assertSame( 'popularity', akibara_gp_series_default_orderby( 'popularity' ) );
	}

	/**
	 * @testdox En serie se renombran solo las dos salidas que el lector necesita entender.
	 */
	public function test_series_labels_name_the_result(): void {
		$this->on_series( true );

		$options = akibara_gp_series_orderby_options( $this->plugin_options() );

		self::assertSame( 'Número de tomo', $options['title'] );
		self::assertSame( 'Novedades primero', $options['menu_order'] );
		// El resto del selector es el mismo de siempre.
		self::assertSame( 'Menor precio', $options['price'] );
		self::assertSame(
			array_keys( $this->plugin_options() ),
			array_keys( $options ),
			'Renombrar no debe reordenar el selector.'
		);
	}

	/**
	 * @testdox Fuera de pa_serie el selector conserva los textos globales del plugin.
	 */
	public function test_labels_untouched_outside_series(): void {
		$this->on_series( false );

		self::assertSame(
			$this->plugin_options(),
			akibara_gp_series_orderby_options( $this->plugin_options() )
		);
	}

	/**
	 * @testdox Si el selector llegara sin `title`, la landing lo ofrece igual.
	 *
	 * Es su propio orden por defecto: un selector que no contiene el valor por
	 * defecto hace que WooCommerce marque otra opción cualquiera
	 * (wc-template-functions.php:1735) y el control mentiría sobre lo que se ve.
	 */
	public function test_title_is_offered_even_if_missing(): void {
		$this->on_series( true );

		$options = akibara_gp_series_orderby_options( array( 'menu_order' => 'Destacados' ) );

		self::assertArrayHasKey( 'title', $options );
		self::assertSame( 'Número de tomo', $options['title'] );
	}

	/**
	 * @testdox La plantilla repone "Novedades primero" después del unset de WooCommerce.
	 */
	public function test_menu_order_is_restored_after_woocommerce_drops_it(): void {
		$this->on_series( true );

		// Lo que la plantilla recibe realmente cuando el defecto es `title`.
		$after_unset = $this->plugin_options();
		unset( $after_unset['menu_order'] );

		$restored = akibara_gp_series_orderby_restore_menu_order( $after_unset );

		self::assertSame( 'Novedades primero', $restored['menu_order'] );
		self::assertSame(
			'menu_order',
			array_key_first( $restored ),
			'Vuelve a su hueco original: el selector no debe reordenarse por cambiar el defecto.'
		);
		self::assertCount( count( $this->plugin_options() ), $restored );
	}

	/**
	 * @testdox La reposición es idempotente: si la opción sigue ahí, no se duplica.
	 */
	public function test_restore_is_a_noop_when_menu_order_survives(): void {
		$this->on_series( true );

		self::assertSame(
			$this->plugin_options(),
			akibara_gp_series_orderby_restore_menu_order( $this->plugin_options() ),
			'Un WooCommerce futuro que deje de borrar la opción debe volver esto un no-op, no un duplicado.'
		);
	}

	/**
	 * @testdox Fuera de pa_serie la plantilla no inventa una opción que WooCommerce quitó a propósito.
	 */
	public function test_restore_does_not_leak_to_other_archives(): void {
		$this->on_series( false );

		$without = $this->plugin_options();
		unset( $without['menu_order'] );

		self::assertSame( $without, akibara_gp_series_orderby_restore_menu_order( $without ) );
	}

	/**
	 * @testdox Elegir "Novedades primero" queda marcado, en vez de saltar a "Más recientes".
	 *
	 * Sin esto, WooCommerce descartaba el `?orderby=menu_order` que el propio
	 * usuario pidió (la opción ya no estaba en la lista al llegar a :1735) y
	 * marcaba la primera que quedara, mientras la rejilla SÍ salía ordenada por
	 * `menu_order`. El selector anunciaba un orden distinto del visible.
	 */
	public function test_user_choice_survives_the_woocommerce_fallback(): void {
		$this->on_series( true );
		$this->requested_orderby( 'menu_order' );

		self::assertSame(
			'menu_order',
			akibara_gp_series_orderby_selected( 'date', $this->plugin_options() )
		);
	}

	/**
	 * @testdox Sin `?orderby` manda el defecto que calculó WooCommerce (`title`).
	 */
	public function test_default_selection_is_left_alone(): void {
		$this->on_series( true );
		$this->requested_orderby( null );

		self::assertSame(
			'title',
			akibara_gp_series_orderby_selected( 'title', $this->plugin_options() )
		);
	}

	/**
	 * @testdox Un `?orderby` que no está en el selector no se honra: cae al fallback de WooCommerce.
	 */
	public function test_unknown_orderby_falls_back(): void {
		$this->on_series( true );
		$this->requested_orderby( 'rating' );

		self::assertSame(
			'date',
			akibara_gp_series_orderby_selected( 'date', $this->plugin_options() ),
			'Sólo se re-marcan claves que existan en el selector repuesto.'
		);
	}

	/**
	 * @testdox Fuera de pa_serie la opción marcada se devuelve intacta.
	 */
	public function test_selection_untouched_outside_series(): void {
		$this->on_series( false );
		$this->requested_orderby( 'menu_order' );

		self::assertSame(
			'date',
			akibara_gp_series_orderby_selected( 'date', $this->plugin_options() )
		);
	}
}
