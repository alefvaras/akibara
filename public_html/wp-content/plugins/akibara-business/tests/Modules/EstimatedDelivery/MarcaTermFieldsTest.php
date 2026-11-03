<?php
/**
 * Contrato de la pantalla de política de preventa de una editorial: guardado y
 * columna del listado.
 *
 * Hereda las dos lecciones que ya cobraron en la pantalla gemela de país
 * ({@see PaisTermFieldsSaveTest}):
 *
 * 1. `edited_product_brand` lo dispara CUALQUIER `wp_update_term()`, no sólo la
 *    pantalla del término. Un update programático llega sin los campos, y leerlo
 *    como «el dueño lo dejó en heredado» borraría la política de la editorial.
 * 2. Rechazar en silencio es un defecto: WordPress imprime «Elemento
 *    actualizado» igual, así que sin aviso el dueño se queda creyendo que su
 *    corte quedó activo.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\EstimatedDelivery;

use Akibara\Business\Modules\EstimatedDelivery\MarcaTermFields;
use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @since 1.108.22
 */
final class MarcaTermFieldsTest extends TestCase {

	private const TERM_ID = 99;
	private const USER_ID = 7;

	/** @var array<string, mixed> */
	private array $original_post = array();

	/** @var array<string, mixed> */
	private array $meta = array();

	/** @var list<string> */
	private array $borradas = array();

	/** @var array<string, mixed> */
	private array $transients = array();

	private MarcaTermFields $sut;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->original_post = $_POST;
		$_POST               = array();
		$this->meta          = array();
		$this->borradas      = array();
		$this->transients    = array();
		$this->sut           = new MarcaTermFields();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'get_current_screen' )->alias(
			fn() => (object) array( 'taxonomy' => 'product_brand', 'id' => 'edit-product_brand' )
		);
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( self::USER_ID );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $value ): string => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?? '' )
		);
		Functions\when( 'update_term_meta' )->alias(
			function ( int $term_id, string $key, $value ): bool {
				$this->meta[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_term_meta' )->alias(
			function ( int $term_id, string $key ): bool {
				$this->borradas[] = $key;
				unset( $this->meta[ $key ] );
				return true;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ): bool {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			fn( string $key ) => $this->transients[ $key ] ?? false
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ): bool {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$_POST = $this->original_post;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Un update programático del término no toca la política guardada.
	 */
	public function test_a_programmatic_term_update_leaves_the_policy_alone(): void {
		$this->sut->save_term_fields( self::TERM_ID );

		self::assertSame( array(), $this->borradas, 'Sin los campos en el POST no se puede borrar nada.' );
		self::assertSame( array(), $this->meta );
		self::assertSame( array(), $this->transients );
	}

	/**
	 * @testdox Sin permiso de gestionar categorías no se escribe nada.
	 */
	public function test_without_the_capability_nothing_is_written(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$_POST['akb_pedido_modo'] = PedidoPolicy::MODO_PAUSADA;

		$this->sut->save_term_fields( self::TERM_ID );

		self::assertSame( array(), $this->meta );
		self::assertSame( array(), $this->borradas );
	}

	/**
	 * @testdox Una tanda completa se persiste entera.
	 */
	public function test_a_complete_batch_is_persisted(): void {
		$_POST['akb_pedido_modo']   = PedidoPolicy::MODO_TANDA;
		$_POST['akb_tanda_dia']       = '5';
		$_POST['akb_tanda_min_dias']  = '25';
		$_POST['akb_tanda_max_dias']  = '35';

		$this->sut->save_term_fields( self::TERM_ID );

		self::assertSame(
			array(
				PedidoPolicy::META_DIA        => 5,
				PedidoPolicy::META_MIN        => 25,
				PedidoPolicy::META_MAX        => 35,
				PedidoPolicy::META_FRECUENCIA => PedidoPolicy::FRECUENCIA_MENSUAL,
				PedidoPolicy::META_MODO       => PedidoPolicy::MODO_TANDA,
			),
			$this->meta
		);
		self::assertSame( array(), $this->transients, 'Un guardado válido no deja avisos.' );
	}

	/**
	 * @return array<string, array{0: array<string,string>}>
	 */
	public function tandas_invalidas(): array {
		return array(
			'día 0'            => array( array( 'dia' => '0', 'min' => '25', 'max' => '35' ) ),
			'día 31'           => array( array( 'dia' => '31', 'min' => '25', 'max' => '35' ) ),
			'sin máximo'       => array( array( 'dia' => '5', 'min' => '25', 'max' => '0' ) ),
			'mínimo mayor'     => array( array( 'dia' => '5', 'min' => '40', 'max' => '35' ) ),
			'máximo absurdo'   => array( array( 'dia' => '5', 'min' => '25', 'max' => '400' ) ),
		);
	}

	/**
	 * @testdox Una tanda inválida se rechaza ENTERA y con aviso, nunca a medias.
	 *
	 * Guardar el modo sin el día dejaría al calculador cayendo al plazo del país
	 * mientras el panel muestra «por tanda»: la peor combinación posible, porque
	 * es indistinguible de que funcione.
	 *
	 * @dataProvider tandas_invalidas
	 *
	 * @param array<string,string> $campos
	 */
	public function test_an_invalid_batch_is_rejected_whole_and_says_why( array $campos ): void {
		$_POST['akb_pedido_modo']  = PedidoPolicy::MODO_TANDA;
		$_POST['akb_tanda_dia']      = $campos['dia'];
		$_POST['akb_tanda_min_dias'] = $campos['min'];
		$_POST['akb_tanda_max_dias'] = $campos['max'];

		$this->sut->save_term_fields( self::TERM_ID );

		self::assertSame( array(), $this->meta, 'No puede quedar media configuración escrita.' );
		self::assertNotSame( array(), $this->transients, 'El rechazo tiene que avisar: WordPress ya dijo «Elemento actualizado».' );

		ob_start();
		$this->sut->render_rejection_notice();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'notice-error', $html );
		self::assertStringContainsString( 'No se guardó el pedido por tanda', $html );
		self::assertSame( array(), $this->transients, 'El aviso se consume una sola vez.' );
	}

	/**
	 * Hallazgo S-0ad29321: el mismo bloque «Pedidos desde…» vive en la pantalla
	 * de editoriales Y en la de países (`PaisTermFields` delega en este
	 * validador), pero el aviso sólo se pintaba en la de editoriales. El dueño
	 * editaba «España», dejaba la tanda a medias, WordPress decía «Elemento
	 * actualizado» y el rechazo era mudo.
	 *
	 * @testdox El rechazo también se ve en la pantalla de países.
	 */
	public function test_the_rejection_notice_also_shows_on_the_country_screen(): void {
		Functions\when( 'get_current_screen' )->alias(
			static fn() => (object) array( 'taxonomy' => 'pa_pais', 'id' => 'edit-pa_pais' )
		);

		$_POST['akb_pedido_modo']    = PedidoPolicy::MODO_TANDA;
		$_POST['akb_tanda_dia']      = '45';
		$_POST['akb_tanda_min_dias'] = '3';
		$_POST['akb_tanda_max_dias'] = '9';

		$this->sut->save_term_fields( self::TERM_ID );

		ob_start();
		$this->sut->render_rejection_notice();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'notice-error', $html );
		self::assertStringContainsString( 'No se guardó el pedido por tanda', $html );
	}

	/** @testdox En una pantalla ajena el aviso no se pinta ni se consume. */
	public function test_a_foreign_screen_keeps_the_notice_for_later(): void {
		Functions\when( 'get_current_screen' )->alias(
			static fn() => (object) array( 'taxonomy' => 'product_cat', 'id' => 'edit-product_cat' )
		);

		$_POST['akb_pedido_modo']    = PedidoPolicy::MODO_TANDA;
		$_POST['akb_tanda_dia']      = '45';
		$_POST['akb_tanda_min_dias'] = '3';
		$_POST['akb_tanda_max_dias'] = '9';

		$this->sut->save_term_fields( self::TERM_ID );

		ob_start();
		$this->sut->render_rejection_notice();

		self::assertSame( '', (string) ob_get_clean() );
		self::assertNotSame( array(), $this->transients, 'El aviso espera a la pantalla que lo entiende.' );
	}

	/**
	 * @testdox Volver a «heredado» limpia toda la configuración de tanda.
	 *
	 * Si sólo se borrara el modo, el día y los plazos quedarían de fantasma y
	 * reaparecerían al reactivar la tanda con valores que nadie recuerda.
	 */
	public function test_going_back_to_inherited_clears_the_whole_config(): void {
		$_POST['akb_pedido_modo'] = PedidoPolicy::MODO_HEREDADO;

		$this->sut->save_term_fields( self::TERM_ID );

		self::assertEqualsCanonicalizing(
			array( PedidoPolicy::META_MODO, PedidoPolicy::META_DIA, PedidoPolicy::META_MIN, PedidoPolicy::META_MAX, PedidoPolicy::META_FRECUENCIA, PedidoPolicy::META_MESES ),
			$this->borradas
		);
		self::assertSame( array(), $this->meta );
	}

	/**
	 * @testdox Un modo inventado se trata como heredado, no se guarda tal cual.
	 */
	public function test_an_unknown_mode_is_treated_as_inherited(): void {
		$_POST['akb_pedido_modo'] = 'loquesea';

		$this->sut->save_term_fields( self::TERM_ID );

		self::assertSame( array(), $this->meta );
		self::assertContains( PedidoPolicy::META_MODO, $this->borradas );
	}

	/**
	 * @testdox El aviso no se asoma a otras pantallas del admin.
	 *
	 * Un mensaje sobre tandas encima de Pedidos no se entiende y parece un fallo
	 * del sitio; además el transient sobrevive al redirect, así que sin este corte
	 * saldría en la primera pantalla que el dueño abriera, fuera cual fuera.
	 */
	public function test_the_notice_stays_on_the_publishers_screen(): void {
		$this->transients[ 'akb_pedido_aviso_' . self::USER_ID ] = 'algo que decir';
		Functions\when( 'get_current_screen' )->alias(
			fn() => (object) array( 'taxonomy' => 'product_cat', 'id' => 'edit-product_cat' )
		);

		ob_start();
		$this->sut->render_rejection_notice();

		self::assertSame( '', (string) ob_get_clean() );
		self::assertArrayHasKey( 'akb_pedido_aviso_' . self::USER_ID, $this->transients, 'Y tampoco puede consumirlo: el dueño todavía no lo vio.' );
	}

	/**
	 * @testdox La columna del listado resume la política sin entrar a editar.
	 *
	 * Con ocho editoriales, revisar una por una es justo lo que hace invisible el
	 * error que importa: una que quedó en tanda sin querer.
	 */
	public function test_the_list_column_summarises_the_policy(): void {
		$this->meta = array(
			PedidoPolicy::META_MODO => PedidoPolicy::MODO_TANDA,
			PedidoPolicy::META_DIA  => 5,
			PedidoPolicy::META_MIN  => 25,
			PedidoPolicy::META_MAX  => 35,
		);
		Functions\when( 'get_term_meta' )->alias(
			fn( int $term_id, string $key, bool $single = true ) => $this->meta[ $key ] ?? ''
		);

		self::assertStringContainsString( '5', (string) $this->sut->render_policy_column( '', 'akb_pedido', self::TERM_ID ) );
		self::assertStringContainsString( '25-35', (string) $this->sut->render_policy_column( '', 'akb_pedido', self::TERM_ID ) );

		$this->meta = array();
		self::assertStringContainsString( 'usa el plazo del país', (string) $this->sut->render_policy_column( '', 'akb_pedido', self::TERM_ID ) );
	}

	/**
	 * @testdox La columna no toca lo que pintan las demás columnas.
	 *
	 * `manage_{taxonomy}_custom_column` es un FILTRO compartido: devolver algo
	 * propio cuando la columna es de otro borra su contenido.
	 */
	public function test_the_column_filter_leaves_other_columns_alone(): void {
		Functions\when( 'get_term_meta' )->justReturn( '' );

		self::assertSame( 'lo de otro', $this->sut->render_policy_column( 'lo de otro', 'name', self::TERM_ID ) );
		self::assertSame( 'lo de otro', $this->sut->render_policy_column( 'lo de otro', 'posts', self::TERM_ID ) );

		$columnas = $this->sut->add_policy_column( array( 'name' => 'Nombre', 'posts' => 'Cuenta' ) );
		self::assertSame( array( 'name', 'posts', 'akb_pedido' ), array_keys( $columnas ) );
	}

	/**
	 * @testdox Pausada y continuo se guardan sin exigir día ni plazos.
	 */
	public function test_paused_and_continuous_need_no_batch_fields(): void {
		foreach ( array( PedidoPolicy::MODO_PAUSADA, PedidoPolicy::MODO_CONTINUO ) as $modo ) {
			$this->meta                 = array();
			$_POST['akb_pedido_modo'] = $modo;

			$this->sut->save_term_fields( self::TERM_ID );

			self::assertSame( array( PedidoPolicy::META_MODO => $modo ), $this->meta );
		}
	}
}
