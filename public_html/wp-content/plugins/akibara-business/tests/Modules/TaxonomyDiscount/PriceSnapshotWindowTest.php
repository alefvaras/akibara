<?php
/**
 * Ronda 7.2 — la foto no se cree a sí misma.
 *
 * Cuatro huecos que la reauditoría encontró en el contrato adoptado:
 *
 *   1. `window_active` y `boundary_at` se aceptaban casi tal cual. Son
 *      exactamente los dos campos que deciden si HAY descuento y hasta cuándo:
 *      creerle al emisor sobre ellos es firmar en blanco. Ahora se recomputan
 *      desde las options decodificadas + la zona + el reloj de adopción, y se
 *      exige igualdad LITERAL con lo declarado.
 *   2. `adopt()` seguido de `invalidate_derived_memos()` dejaba
 *      `snapshot_is_fresh() === true` y el primer `__invoke()` volvía a ligar la
 *      configuración vieja: la invalidación no se veía porque en `PENDING` no
 *      había nada ligado que comprobar. Ahora la adopción captura la generación
 *      de la autoridad y la exige en los dos estados.
 *   3. `UTC` —lo que WordPress guarda cuando el sitio elige UTC sin ciudad— se
 *      rechazaba por no llevar barra.
	 *   4. Sin `timezone_string`, `gmt_offset` presente con raw vacío y las tres
	 *      escrituras del cero negativo entraban al dominio.
 *
 * Y el addendum sticky: un getter que lanza `PriceAuthorityUnavailable` no
 * puede confundirse con un fallo ya procesado por `fail()`.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\TaxonomyDiscount\AdoptedSnapshot;
use Akibara\Business\Modules\TaxonomyDiscount\DiscountResolver;
use Akibara\Business\Modules\TaxonomyDiscount\PriceAuthorityUnavailable;
use Akibara\Business\Modules\TaxonomyDiscount\SearchPriceProvider;
use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use Akibara\Business\Tests\Support\AuthorityWpdb;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Product;

require_once dirname( __DIR__, 2 ) . '/Support/AuthorityWpdb.php';

final class PriceSnapshotWindowTest extends TestCase {

	private const PRODUCT = 4242;

	private const TERM = 501;

	/** 2026-07-25 13:20 en America/Santiago. */
	private const NOW = 1785000000;

	/** `2026-08-01 00:00` en Santiago: futuro respecto de NOW. */
	private const FUTURE_FROM = 1785556800;

	/** `2026-08-01 00:00 +1 día`: el fin de una ventana que cierra el 01-08. */
	private const FUTURE_TO_END = 1785643200;

	/** `2026-07-25 00:00 +1 día` en Santiago: el cierre de una ventana que acaba hoy. */
	private const TODAY_END_SCL = 1785038400;

	/** El mismo cierre para una foto cuya zona es UTC. */
	private const TODAY_END_UTC = 1785024000;

	private AuthorityWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb                    = new AuthorityWpdb();
		$this->wpdb->relationships     = array( self::PRODUCT => array( array( self::TERM, 'product_brand' ) ) );
		$GLOBALS['wpdb']               = $this->wpdb;
		$GLOBALS['akb_test_price_dec'] = 0;

		// Cada archivo declara SUS dependencias de WordPress: heredarlas del
		// stub que dejó otro test es exactamente lo que hizo verde en suite y
		// rojo en aislado a la ronda 7.1.
		Functions\when( 'wc_get_price_decimals' )->justReturn( 0 );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'clean_object_term_cache' )->justReturn( null );
		Functions\when( 'update_object_term_cache' )->justReturn( null );
		Functions\when( 'update_meta_cache' )->justReturn( array() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['akb_test_price_dec'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// P0 — la ventana se recomputa, no se cree.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Con from futuro, declarar la ventana activa se rechaza.
	 */
	public function test_future_from_cannot_be_active(): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt(
			$this->windowed( '', '2026-08-01', array( 'window_active' => true, 'boundary_at' => self::FUTURE_FROM ) )
		);
	}

	/**
	 * `window_active` fuera del dominio booleano ni siquiera llega a
	 * compararse: `null` no es "todavía no se sabe", es una foto rota.
	 *
	 * @testdox Con from futuro, un window_active null se rechaza.
	 */
	public function test_future_from_with_null_active_is_rejected(): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt(
			$this->windowed( '', '2026-08-01', array( 'window_active' => null, 'boundary_at' => self::FUTURE_FROM ) )
		);
	}

	/**
	 * @testdox Con to ya vencido, declarar la ventana activa se rechaza.
	 */
	public function test_past_to_cannot_be_active(): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt(
			$this->windowed( '2026-06-01', '2026-07-01', array( 'window_active' => true, 'boundary_at' => null ) )
		);
	}

	/**
	 * Dentro de la ventana el cierre SÍ es un límite futuro: declarar `null`
	 * sería prometer que la foto no caduca.
	 *
	 * @testdox Dentro de la ventana, un boundary null se rechaza.
	 */
	public function test_inside_window_requires_the_closing_boundary(): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt(
			$this->windowed( '2026-07-01', '2026-08-01', array( 'window_active' => true, 'boundary_at' => null ) )
		);
	}

	/**
	 * @testdox Dentro de la ventana, un boundary futuro pero equivocado se rechaza.
	 */
	public function test_inside_window_rejects_a_wrong_boundary(): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt(
			$this->windowed(
				'2026-07-01',
				'2026-08-01',
				array( 'window_active' => true, 'boundary_at' => self::FUTURE_TO_END - 1 )
			)
		);
	}

	/**
	 * @testdox Antes de from, el boundary tiene que ser exactamente from 00:00.
	 */
	public function test_before_from_requires_the_opening_boundary(): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt(
			$this->windowed(
				'2026-08-01',
				'2026-08-20',
				array( 'window_active' => false, 'boundary_at' => 1787284800 ) // `2026-08-21 00:00`: el cierre, no la apertura.
			)
		);
	}

	/**
	 * @testdox Dentro de la ventana: activa y con el cierre exacto (to 00:00 + 1 día).
	 */
	public function test_inside_window_exact_values_are_accepted(): void {
		$token = $this->adopt(
			$this->windowed( '2026-07-01', '2026-08-01', array( 'window_active' => true, 'boundary_at' => self::FUTURE_TO_END ) )
		);

		self::assertNotSame( '', $token );
	}

	/**
	 * @testdox Antes de from: inactiva y con la apertura exacta (from 00:00).
	 */
	public function test_before_from_exact_values_are_accepted(): void {
		$token = $this->adopt(
			$this->windowed( '2026-08-01', '2026-08-20', array( 'window_active' => false, 'boundary_at' => self::FUTURE_FROM ) )
		);

		self::assertNotSame( '', $token );
	}

	/**
	 * Ninguno de los dos candidatos es futuro, así que no hay nada que esperar:
	 * la foto ya nació fuera de vigencia y lo dice.
	 *
	 * @testdox Con la ventana cerrada en el pasado: inactiva y sin boundary.
	 */
	public function test_closed_window_has_no_boundary(): void {
		$token = $this->adopt(
			$this->windowed( '2026-06-01', '2026-07-01', array( 'window_active' => false, 'boundary_at' => null ) )
		);

		self::assertNotSame( '', $token );
	}

	/**
	 * El primer día de vigencia cuenta como dentro: Search compara `today` con
	 * `from`/`to` como cadenas `Y-m-d`, no con el instante.
	 *
	 * @testdox El día exacto de from ya está dentro de la ventana.
	 */
	public function test_from_today_is_already_inside(): void {
		$token = $this->adopt(
			$this->windowed( '2026-07-25', '', array( 'window_active' => true, 'boundary_at' => null ) )
		);

		self::assertNotSame( '', $token );
	}

	/**
	 * @testdox El día exacto de to sigue estando dentro, y el cierre es el día siguiente.
	 */
	public function test_to_today_is_still_inside(): void {
		$token = $this->adopt(
			$this->windowed( '', '2026-07-25', array( 'window_active' => true, 'boundary_at' => self::TODAY_END_SCL ) )
		);

		self::assertNotSame( '', $token );
	}

	/**
	 * Search decide primero si el módulo está encendido y sólo entonces mira el
	 * calendario: en `window_snapshot()` los dos candidatos se agregan bajo
	 * `'yes' === $enabled`. Con el módulo apagado no hay nada que esperar, así
	 * que `boundary_at` es `null` aunque el calendario tenga fechas futuras.
	 *
	 * La ronda 7.2 selló lo contrario —el boundary derivado seguía siendo
	 * exigible con el módulo apagado— y eso divergía del productor [ronda 7.3].
	 *
	 * @dataProvider disabled_windows
	 * @testdox Con el módulo apagado no hay boundary, aunque el calendario siga vivo
	 *
	 * @param string $from Fecha de inicio.
	 * @param string $to   Fecha de cierre.
	 */
	public function test_disabled_module_has_no_boundary( string $from, string $to ): void {
		$token = $this->adopt(
			$this->windowed(
				$from,
				$to,
				array( 'window_active' => false, 'boundary_at' => null ),
				array( 'akibara_taxonomy_discount_enabled' => array( 'present' => true, 'raw' => 'no' ) )
			)
		);

		self::assertNotSame( '', $token );
	}

	/**
	 * @dataProvider disabled_boundaries
	 * @testdox Con el módulo apagado, declarar cualquier boundary se rechaza
	 *
	 * @param string $from     Fecha de inicio.
	 * @param string $to       Fecha de cierre.
	 * @param int    $boundary Límite declarado.
	 */
	public function test_disabled_module_cannot_declare_any_boundary( string $from, string $to, int $boundary ): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt(
			$this->windowed(
				$from,
				$to,
				array( 'window_active' => false, 'boundary_at' => $boundary ),
				array( 'akibara_taxonomy_discount_enabled' => array( 'present' => true, 'raw' => 'no' ) )
			)
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function disabled_windows(): array {
		return array(
			'apertura futura'      => array( '2026-08-01', '2026-08-20' ),
			'sólo apertura futura' => array( '2026-08-01', '' ),
			'sólo cierre futuro'   => array( '', '2026-08-01' ),
			'dentro del rango'     => array( '2026-07-01', '2026-08-01' ),
			'sin fechas'           => array( '', '' ),
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: int}>
	 */
	public function disabled_boundaries(): array {
		return array(
			// El instante que la 7.2 daba por bueno: la apertura derivada.
			'apertura derivada' => array( '2026-08-01', '2026-08-20', self::FUTURE_FROM ),
			// El cierre derivado, para la ventana que ya está en curso.
			'cierre derivado'   => array( '2026-07-01', '2026-08-01', self::FUTURE_TO_END ),
			// Y cualquier otro entero futuro.
			'entero arbitrario' => array( '2026-08-01', '2026-08-20', self::NOW + 60 ),
		);
	}

	/**
	 * El interruptor manda sobre el calendario en las dos direcciones: apagado
	 * con fechas vivas no puede declararse activo.
	 *
	 * @testdox Con el módulo apagado, la ventana tampoco puede declararse activa.
	 */
	public function test_disabled_module_cannot_be_active(): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt(
			$this->windowed(
				'2026-07-01',
				'2026-08-01',
				array( 'window_active' => true, 'boundary_at' => null ),
				array( 'akibara_taxonomy_discount_enabled' => array( 'present' => true, 'raw' => 'no' ) )
			)
		);
	}

	/**
	 * @testdox La ventana se evalúa en la zona de la foto, no en la del servidor.
	 */
	public function test_window_is_evaluated_in_the_snapshot_zone(): void {
		// A las 17:20 UTC del 25-07 en Santiago son las 13:20 del 25-07: el mismo
		// día. Pero el cierre de `to = 2026-07-25` cae en instantes distintos.
		$utc = $this->snapshot(
			array(
				'timezone'      => 'UTC',
				'window_active' => true,
				'boundary_at'   => self::TODAY_END_UTC, // 2026-07-26 00:00 UTC.
			),
			array(
				'timezone_string'              => array( 'present' => true, 'raw' => 'UTC' ),
				'akibara_taxonomy_discount_to' => array( 'present' => true, 'raw' => '2026-07-25' ),
			)
		);

		self::assertNotSame( '', $this->adopt( $utc ) );

		// El mismo boundary, declarado por una foto de Santiago, es incoherente.
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt(
			$this->windowed( '', '2026-07-25', array( 'window_active' => true, 'boundary_at' => self::TODAY_END_UTC ) )
		);
	}

	// -----------------------------------------------------------------------
	// P1 — la invalidación se ve también en PENDING.
	// -----------------------------------------------------------------------

	/**
	 * El agujero: `adopt()` deja `PENDING`, donde no hay nada ligado; sin la
	 * generación capturada, `snapshot_is_fresh()` no tenía forma de notar que
	 * la autoridad se invalidó y el primer `__invoke()` ligaba tan tranquilo la
	 * configuración vieja.
	 *
	 * @testdox Invalidar en PENDING deja la foto no fresca y el primer invoke falla sticky.
	 */
	public function test_invalidation_while_pending_is_visible(): void {
		$resolver = new DiscountResolver( new Settings() );
		$provider = $this->provider( $resolver );
		$token    = $provider->adopt_search_snapshot( $this->snapshot() );

		self::assertSame( SearchPriceProvider::STATE_PENDING, $provider->state() );
		self::assertTrue( $provider->snapshot_is_fresh( $token ) );

		$resolver->invalidate_derived_memos();
		$queries_before = count( $this->wpdb->queries );

		self::assertFalse( $provider->snapshot_is_fresh( $token ), 'La foto no puede seguir fresca tras invalidar.' );

		$failed = false;
		try {
			( $provider )( 10000.0, $this->product() );
		} catch ( PriceAuthorityUnavailable $e ) {
			$failed = true;
			unset( $e );
		}

		self::assertTrue( $failed, 'El primer invoke tras invalidar tiene que lanzar.' );
		self::assertSame( SearchPriceProvider::STATE_FAILED, $provider->state() );
		self::assertFalse( $provider->snapshot_is_fresh( $token ) );
		self::assertCount( $queries_before, $this->wpdb->queries, 'No puede haber releído la base.' );

		// Sticky: el segundo intento tampoco revive ni consulta.
		$this->expectException( PriceAuthorityUnavailable::class );
		( $provider )( 10000.0, $this->product() );
	}

	/**
	 * @testdox Adoptar no activa el modo estricto: eso ocurre en el primer invoke.
	 */
	public function test_adoption_does_not_arm_strict_mode(): void {
		$settings = new Settings();
		$resolver = new DiscountResolver( $settings );
		$provider = $this->provider( $resolver );

		$provider->adopt_search_snapshot( $this->snapshot() );

		self::assertSame( SearchPriceProvider::STATE_PENDING, $provider->state() );
		self::assertCount( 0, $this->wpdb->queries, 'Adoptar no consulta.' );
	}

	/**
	 * @testdox Invalidar en ACTIVE deja la foto no fresca y el siguiente invoke falla.
	 */
	public function test_invalidation_while_active_is_visible(): void {
		$resolver = new DiscountResolver( new Settings() );
		$provider = $this->provider( $resolver );
		$token    = $provider->adopt_search_snapshot( $this->snapshot() );

		self::assertSame( 8500.0, ( $provider )( 10000.0, $this->product() ) );
		self::assertSame( SearchPriceProvider::STATE_ACTIVE, $provider->state() );
		self::assertTrue( $provider->snapshot_is_fresh( $token ) );

		$resolver->invalidate_derived_memos();

		self::assertFalse( $provider->snapshot_is_fresh( $token ) );
		$this->expectException( PriceAuthorityUnavailable::class );
		( $provider )( 10000.0, $this->product() );
	}

	/**
	 * @testdox Readoptar la misma foto sobre una autoridad ya invalidada falla.
	 */
	public function test_readoption_after_invalidation_fails(): void {
		$resolver = new DiscountResolver( new Settings() );
		$provider = $this->provider( $resolver );
		$snapshot = $this->snapshot();

		$provider->adopt_search_snapshot( $snapshot );
		$resolver->invalidate_derived_memos();

		$this->expectException( PriceAuthorityUnavailable::class );
		$provider->adopt_search_snapshot( $snapshot );
	}

	// -----------------------------------------------------------------------
	// Addendum sticky — un getter que lanza PriceAuthorityUnavailable.
	// -----------------------------------------------------------------------

	/**
	 * `catch ( PriceAuthorityUnavailable ) { throw $e; }` daba por hecho que esa
	 * clase sólo podía venir de `fail()`. Un getter de WooCommerce, o un filtro
	 * de terceros, puede lanzar exactamente esa excepción: el proveedor se
	 * quedaba en `ACTIVE`, `snapshot_is_fresh()` seguía en `true` y el siguiente
	 * producto reintentaba como si nada [addendum ronda 7.2].
	 *
	 * @testdox Un getter que lanza PriceAuthorityUnavailable deja FAILED, no ACTIVE.
	 */
	public function test_foreign_authority_exception_is_not_mistaken_for_our_own(): void {
		$provider = $this->provider();
		$token    = $provider->adopt_search_snapshot( $this->snapshot() );

		$product                    = new ForeignFailureProduct( 'instock' );
		$product->akb_id            = self::PRODUCT;
		$product->akb_regular_price = '10000';

		try {
			( $provider )( 10000.0, $product );
			self::fail( 'Debió lanzar.' );
		} catch ( PriceAuthorityUnavailable $e ) {
			self::assertNotNull( $e->getPrevious(), 'La causa original tiene que preservarse.' );
			self::assertSame( 'precio no disponible desde un getter ajeno', $e->getPrevious()->getMessage() );
		}

		self::assertSame( SearchPriceProvider::STATE_FAILED, $provider->state() );
		self::assertFalse( $provider->snapshot_is_fresh( $token ) );

		$queries_before = count( $this->wpdb->queries );

		$retried = false;
		try {
			( $provider )( 10000.0, $this->product() );
		} catch ( PriceAuthorityUnavailable $e ) {
			$retried = true;
			unset( $e );
		}

		self::assertTrue( $retried, 'La segunda invocación tampoco puede devolver precio.' );
		self::assertCount( $queries_before, $this->wpdb->queries, 'El reintento no puede consultar.' );
	}

	// -----------------------------------------------------------------------
	// P1 / P2 — dominios que Search integrará idénticos.
	// -----------------------------------------------------------------------

	/**
	 * @testdox UTC es un identificador de zona válido, sin barra.
	 */
	public function test_utc_is_a_valid_identifier(): void {
		$token = $this->adopt(
			$this->snapshot(
				array( 'timezone' => 'UTC' ),
				array( 'timezone_string' => array( 'present' => true, 'raw' => 'UTC' ) )
			)
		);

		self::assertNotSame( '', $token );
	}

	/**
	 * WordPress mantiene una fila gmt_offset vacía cuando la zona nombrada es la
	 * autoridad, y las opciones históricas vacías conservan los defaults que ya
	 * aplica Settings/AuthoritativeReader.
	 *
	 * @testdox La foto acepta defaults vacíos reales y omite gmt_offset bajo una zona nombrada
	 */
	public function test_real_empty_defaults_with_named_timezone_are_accepted(): void {
		$token = $this->adopt(
			$this->snapshot(
				array(
					'window_active' => false,
					'boundary_at'   => null,
				),
				array(
					'akibara_taxonomy_discount_enabled'     => array( 'present' => true, 'raw' => '' ),
					'akibara_taxonomy_discount_apply_to'    => array( 'present' => true, 'raw' => '' ),
					'akibara_taxonomy_discount_sale_policy' => array( 'present' => true, 'raw' => '' ),
					'timezone_string'                       => array( 'present' => true, 'raw' => 'America/Santiago' ),
					'gmt_offset'                            => array( 'present' => true, 'raw' => '' ),
				)
			)
		);

		self::assertNotSame( '', $token );
	}

	/**
	 * @dataProvider irrelevant_named_timezone_offsets
	 * @testdox Una zona nombrada válida conserva pero no interpreta gmt_offset
	 */
	public function test_named_timezone_ignores_irrelevant_scalar_gmt_offset( string $raw ): void {
		$token = $this->adopt(
			$this->snapshot(
				array(),
				array(
					'timezone_string' => array( 'present' => true, 'raw' => 'America/Santiago' ),
					'gmt_offset'      => array( 'present' => true, 'raw' => $raw ),
				)
			)
		);

		self::assertNotSame( '', $token );
	}

	/** @return array<string, array<int, string>> */
	public function irrelevant_named_timezone_offsets(): array {
		return array(
			'vacío de Core'    => array( '' ),
			'palabra ignorada' => array( 'cinco' ),
			'fuera de rango'   => array( '15' ),
			'cero negativo'    => array( '-0' ),
		);
	}

	/**
	 * `effective_timezone()` de Search toma el literal de `timezone_string` y lo
	 * pasa tal cual a `new DateTimeZone()`; si PHP lo acepta, ESE literal es la
	 * firma publicada. No hay exigencia de barra ni tope de ±14:00: eso sólo
	 * aplica a las firmas DERIVADAS de `gmt_offset`.
	 *
	 * Business rechazaba `GMT`, `CET`, `EST`, `Factory`, `+14:45` y `+15:00`,
	 * que Search publica sin problema: una foto que el productor emite y el
	 * consumidor rechaza no es una foto compartida [ronda 7.3].
	 *
	 * @dataProvider literal_timezones
	 * @testdox Un timezone_string que PHP acepta se conserva literal
	 *
	 * @param string $literal Identificador declarado.
	 */
	public function test_literal_timezone_strings_are_accepted( string $literal ): void {
		$token = $this->adopt(
			$this->snapshot(
				array( 'timezone' => $literal ),
				array( 'timezone_string' => array( 'present' => true, 'raw' => $literal ) )
			)
		);

		self::assertNotSame( '', $token, $literal . ' debería ser una firma válida.' );
	}

	/**
	 * Todos verificados contra `new DateTimeZone()` en el mismo PHP que corre
	 * Search.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function literal_timezones(): array {
		return array(
			'GMT'              => array( 'GMT' ),
			'CET'              => array( 'CET' ),
			'EST'              => array( 'EST' ),
			'Factory'          => array( 'Factory' ),
			'Z'                => array( 'Z' ),
			'EST5EDT'          => array( 'EST5EDT' ),
			'W-SU'             => array( 'W-SU' ),
			'offset ancho'     => array( '+14:45' ),
			'offset excedido'  => array( '+15:00' ),
			'cero negativo'    => array( '-00:00' ),
			'IANA con barra'   => array( 'America/Santiago' ),
			'UTC'              => array( 'UTC' ),
		);
	}

	/**
	 * @dataProvider impossible_timezones
	 * @testdox Un timezone_string que PHP no reconoce se rechaza
	 *
	 * @param string $literal Identificador declarado.
	 */
	public function test_impossible_timezone_strings_are_rejected( string $literal ): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt(
			$this->snapshot(
				array( 'timezone' => $literal ),
				array( 'timezone_string' => array( 'present' => true, 'raw' => $literal ) )
			)
		);
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function impossible_timezones(): array {
		return array(
			'minutos imposibles' => array( '+99:99' ),
			'sin signo'          => array( '05:30' ),
			'inventada'          => array( 'Marte/Olympus' ),
			'con NUL'            => array( "America/Santiago\0" ),
		);
	}

	/**
	 * La permisividad es del literal, no de la derivación: sin
	 * `timezone_string`, la firma tiene que ser exactamente el offset canónico
	 * que sale de `gmt_offset`.
	 *
	 * @dataProvider underived_signatures
	 * @testdox Sin timezone_string, una firma que no se deriva de gmt_offset se rechaza
	 *
	 * @param string $signature Firma declarada.
	 */
	public function test_derived_signatures_stay_strict( string $signature ): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt(
			$this->snapshot(
				array( 'timezone' => $signature ),
				array(
					'timezone_string' => array( 'present' => false, 'raw' => '' ),
					'gmt_offset'      => array( 'present' => true, 'raw' => '0' ),
				)
			)
		);
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function underived_signatures(): array {
		return array(
			'offset excedido'  => array( '+15:00' ),
			'minutos no cuarto'=> array( '+14:45' ),
			'cero negativo'    => array( '-00:00' ),
			'identificador'    => array( 'CET' ),
			'zona con barra'   => array( 'America/Santiago' ),
		);
	}

	/**
	 * @testdox La firma literal debe ser el MISMO literal, no una zona equivalente.
	 */
	public function test_literal_signature_must_match_the_option_exactly(): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt(
			$this->snapshot(
				array( 'timezone' => 'GMT' ), // equivalente en offset…
				array( 'timezone_string' => array( 'present' => true, 'raw' => 'UTC' ) ) // …pero no es el literal.
			)
		);
	}

	/**
	 * @testdox La ventana se recomputa en la zona del literal declarado.
	 */
	public function test_window_uses_the_literal_zone(): void {
		// CET es UTC+1: a las 17:20 UTC del 25-07 allí son las 18:20 del mismo
		// día, y el cierre de `to = 2026-07-25` cae en 2026-07-26 00:00 CET.
		$token = $this->adopt(
			$this->snapshot(
				array(
					'timezone'      => 'CET',
					'window_active' => true,
					'boundary_at'   => 1785020400,
				),
				array(
					'timezone_string'              => array( 'present' => true, 'raw' => 'CET' ),
					'akibara_taxonomy_discount_to' => array( 'present' => true, 'raw' => '2026-07-25' ),
				)
			)
		);

		self::assertNotSame( '', $token );
	}

	/**
	 * @dataProvider rejected_offsets
	 * @testdox Un gmt_offset fuera de la forma canónica se rechaza
	 *
	 * @param string $raw     Valor crudo.
	 * @param bool   $present Si la option está presente.
	 */
	public function test_gmt_offset_domain_is_sealed( string $raw, bool $present = true ): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt(
			$this->snapshot(
				array( 'timezone' => '+00:00' ),
				array(
					'timezone_string' => array( 'present' => false, 'raw' => '' ),
					'gmt_offset'      => array( 'present' => $present, 'raw' => $raw ),
				)
			)
		);
	}

	/**
	 * @return array<string, array{0: string, 1?: bool}>
	 */
	public function rejected_offsets(): array {
		return array(
			'presente y vacío'  => array( '' ),
			'cero negativo'     => array( '-0' ),
			'cero negativo .0'  => array( '-0.0' ),
			'cero negativo 00'  => array( '-00' ),
			'cero con decimal'  => array( '0.0' ),
			'cero con signo'    => array( '+0' ),
			'cero con relleno'  => array( '00' ),
			'entero con cero'   => array( '02' ),
			'fracción no cuarto'=> array( '5.4' ),
			'fuera de rango'    => array( '15' ),
		);
	}

	/**
	 * @testdox La única escritura aceptada del offset cero es 0.
	 */
	public function test_zero_offset_has_a_single_canonical_form(): void {
		$token = $this->adopt(
			$this->snapshot(
				array( 'timezone' => '+00:00' ),
				array(
					'timezone_string' => array( 'present' => false, 'raw' => '' ),
					'gmt_offset'      => array( 'present' => true, 'raw' => '0' ),
				)
			)
		);

		self::assertNotSame( '', $token );
	}

	/**
	 * @testdox Un gmt_offset ausente equivale a +00:00.
	 */
	public function test_absent_offset_means_utc(): void {
		$token = $this->adopt(
			$this->snapshot(
				array( 'timezone' => '+00:00' ),
				array(
					'timezone_string' => array( 'present' => false, 'raw' => '' ),
					'gmt_offset'      => array( 'present' => false, 'raw' => '' ),
				)
			)
		);

		self::assertNotSame( '', $token );
	}

	/**
	 * @dataProvider rejected_decimals
	 * @testdox Un woocommerce_price_num_decimals fuera del dominio canónico se rechaza
	 *
	 * @param string $raw Valor crudo.
	 */
	public function test_decimals_domain_is_sealed( string $raw ): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt(
			$this->snapshot(
				array(),
				array( 'woocommerce_price_num_decimals' => array( 'present' => true, 'raw' => $raw ) )
			)
		);
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function rejected_decimals(): array {
		return array(
			'con relleno'   => array( '02' ),
			'con signo'     => array( '+2' ),
			'negativo'      => array( '-1' ),
			'fraccionario'  => array( '2.0' ),
			'fuera de rango'=> array( '9' ),
			'vacío'         => array( '' ),
		);
	}

	// -----------------------------------------------------------------------
	// Utilidades.
	// -----------------------------------------------------------------------

	/**
	 * Foto con ventana explícita.
	 *
	 * @param string               $from             Fecha de inicio, o ''.
	 * @param string               $to               Fecha de cierre, o ''.
	 * @param array<string, mixed> $overrides        Cambios al top-level.
	 * @param array<string, mixed> $option_overrides Cambios extra a options.
	 * @return array<string, mixed>
	 */
	private function windowed( string $from, string $to, array $overrides, array $option_overrides = array() ): array {
		$options = array(
			'akibara_taxonomy_discount_from' => array( 'present' => '' !== $from, 'raw' => $from ),
			'akibara_taxonomy_discount_to'   => array( 'present' => '' !== $to, 'raw' => $to ),
		);

		return $this->snapshot( $overrides, array_merge( $options, $option_overrides ) );
	}

	/**
	 * @param array<string, mixed> $overrides        Cambios al top-level.
	 * @param array<string, mixed> $option_overrides Cambios a options.
	 * @return array<string, mixed>
	 */
	private function snapshot( array $overrides = array(), array $option_overrides = array() ): array {
		$options = array(
			// akb_ ordena ANTES de akibara_ en el ksort del contrato (10 options
			// desde el 2026-09-01: el tope configurable entró a la foto).
			'akb_discount_max_pct'                         => array( 'present' => false, 'raw' => '' ),
			'akibara_business_search_feed_contract_version' => array( 'present' => true, 'raw' => '2' ),
			'akibara_taxonomy_discount_apply_to'           => array( 'present' => true, 'raw' => 'all' ),
			'akibara_taxonomy_discount_enabled'            => array( 'present' => true, 'raw' => 'yes' ),
			'akibara_taxonomy_discount_from'               => array( 'present' => false, 'raw' => '' ),
			'akibara_taxonomy_discount_sale_policy'        => array( 'present' => true, 'raw' => 'greatest_wins' ),
			'akibara_taxonomy_discount_to'                 => array( 'present' => false, 'raw' => '' ),
			'gmt_offset'                                   => array( 'present' => false, 'raw' => '' ),
			'timezone_string'                              => array( 'present' => true, 'raw' => 'America/Santiago' ),
			'woocommerce_price_num_decimals'               => array( 'present' => true, 'raw' => '0' ),
		);

		foreach ( $option_overrides as $name => $row ) {
			$options[ $name ] = $row;
		}

		$snapshot = array(
			'contract'           => AdoptedSnapshot::CONTRACT,
			'source_fingerprint' => str_repeat( 'a', 64 ),
			'options'            => $options,
			'timezone'           => 'America/Santiago',
			'window_active'      => true,
			'boundary_at'        => null,
			'max_pct'            => 15,
			'decimals'           => 0,
			'taxonomies'         => array( 'pa_editorial', 'product_brand' ),
			'term_meta'          => array( array( 'meta_id' => 11, 'term_id' => self::TERM, 'raw' => '15' ) ),
		);

		foreach ( $overrides as $key => $value ) {
			$snapshot[ $key ] = $value;
		}

		return $snapshot;
	}

	private function product(): WC_Product {
		$product                    = new WC_Product( 'instock' );
		$product->akb_id            = self::PRODUCT;
		$product->akb_regular_price = '10000';

		return $product;
	}

	private function provider( ?DiscountResolver $resolver = null ): SearchPriceProvider {
		return new SearchPriceProvider(
			$resolver ?? new DiscountResolver( new Settings() ),
			static fn(): int => self::NOW
		);
	}

	/**
	 * @param array<string, mixed> $snapshot Foto.
	 * @return string
	 */
	private function adopt( array $snapshot ): string {
		return ( $this->provider() )->adopt_search_snapshot( $snapshot );
	}
}

/**
 * Producto cuyo getter lanza la MISMA clase que usa el proveedor para señalar
 * sus propios fallos.
 */
final class ForeignFailureProduct extends WC_Product {

	public function get_regular_price( string $context = 'view' ): string {
		unset( $context );

		throw new PriceAuthorityUnavailable( 'precio no disponible desde un getter ajeno' );
	}
}
