<?php
/**
 * Contrato CRUZADO de la zona horaria en la foto de precios.
 *
 * El validador de `AdoptedSnapshot` está espejado línea a línea con el de
 * akibara-search, en OTRO riel de despliegue, y las dos copias sólo coinciden
 * mientras alguien se acuerde de tocar las dos. La auditoría 2026-08-31 avisó
 * de una divergencia en `timezone_string`: Search lo valida con «≤100 bytes y
 * sin NUL» y Business exige además que PHP sepa construir la zona.
 *
 * Medido contra el código de los dos lados, la divergencia NO produce el fallo
 * que se temía. El gate de publicación de Search no es
 * `valid_source_option_value()`: es `effective_timezone()`, que pasa el literal
 * a `new DateTimeZone()` dentro de un try y devuelve null si lanza; y
 * `source_snapshot_from_options()` exige `is_array( $timezone )` para emitir la
 * foto. O sea que una zona que PHP no sabe interpretar hace que Search NO
 * publique, no que publique algo que Business rechace. Los dos lados aceptan
 * exactamente lo mismo por caminos distintos.
 *
 * Esta prueba fija ese predicado del lado de Business —lo único que se puede
 * fijar desde este riel— para que la próxima edición de cualquiera de las dos
 * copias tenga que pasar por acá. Los casos son los que la auditoría nombró:
 * las zonas cortas sin barra que la ronda 7.3 ya había desbloqueado, y los
 * offsets extremos que PHP sí acepta.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\TaxonomyDiscount\AdoptedSnapshot;
use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class PriceSnapshotTimezoneCrossRailTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * La foto se adopta exactamente cuando PHP sabe construir la zona.
	 *
	 * @dataProvider zonas
	 */
	public function test_la_zona_se_acepta_si_y_solo_si_php_la_sabe_construir( string $timezone, bool $esperado ): void {
		$why      = '';
		$adoptada = AdoptedSnapshot::from_search( $this->snapshot( $timezone ), $why );

		self::assertSame(
			$esperado,
			null !== $adoptada,
			sprintf( 'Zona «%s»: %s (motivo: %s)', $timezone, $esperado ? 'debía adoptarse' : 'debía rechazarse', $why )
		);

		// El predicado de Business tiene que ser el MISMO que el gate de Search
		// (`new DateTimeZone` dentro de un try), no uno más estricto ni uno más
		// laxo: una foto que un lado publica y el otro rechaza deja al proveedor
		// de precio sin autoridad en silencio.
		self::assertSame( $esperado, $this->search_publicaria( $timezone ) );
	}

	/**
	 * @return array<string, array{0:string, 1:bool}>
	 */
	public static function zonas(): array {
		return array(
			'IANA de la tienda'   => array( 'America/Santiago', true ),
			'sin barra: GMT'      => array( 'GMT', true ),
			'sin barra: CET'      => array( 'CET', true ),
			'sin barra: UTC'      => array( 'UTC', true ),
			'offset extremo'      => array( '+14:45', true ),
			'zona inventada'      => array( 'Mars/Olympus', false ),
			'texto no canónico'   => array( 'UTC5.5', false ),
			'con NUL'             => array( "America/Santiago\0", false ),
			'más de 100 bytes'    => array( str_repeat( 'A', 101 ), false ),
			'vacía'               => array( '', false ),
		);
	}

	/**
	 * Réplica exacta del gate de publicación de Search (`effective_timezone`)
	 * para el caso en que `timezone_string` viene con valor: construir la zona
	 * dentro de un try, y exigir que el literal no sea vacío.
	 */
	private function search_publicaria( string $timezone ): bool {
		if ( '' === $timezone || strlen( $timezone ) > 100 || false !== strpos( $timezone, "\0" ) ) {
			return false;
		}
		try {
			new \DateTimeZone( $timezone );
			return true;
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * Foto mínima válida con la zona bajo prueba, coherente en las dos claves
	 * que la declaran (`options['timezone_string']` y `timezone`).
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot( string $timezone ): array {
		return array(
			'contract'           => AdoptedSnapshot::CONTRACT,
			'source_fingerprint' => str_repeat( 'a', 64 ),
			'options'            => array(
				'akb_discount_max_pct'                         => array( 'present' => false, 'raw' => '' ),
				'akibara_business_search_feed_contract_version' => array( 'present' => true, 'raw' => '2' ),
				'akibara_taxonomy_discount_apply_to'           => array( 'present' => true, 'raw' => Settings::APPLY_ALL ),
				'akibara_taxonomy_discount_enabled'            => array( 'present' => true, 'raw' => 'yes' ),
				'akibara_taxonomy_discount_from'               => array( 'present' => false, 'raw' => '' ),
				'akibara_taxonomy_discount_sale_policy'        => array( 'present' => true, 'raw' => Settings::POLICY_GREATEST_WINS ),
				'akibara_taxonomy_discount_to'                 => array( 'present' => false, 'raw' => '' ),
				'gmt_offset'                                   => array( 'present' => false, 'raw' => '' ),
				'timezone_string'                              => array( 'present' => true, 'raw' => $timezone ),
				'woocommerce_price_num_decimals'               => array( 'present' => true, 'raw' => '0' ),
			),
			'timezone'           => $timezone,
			'window_active'      => true,
			'boundary_at'        => null,
			'max_pct'            => 15,
			'decimals'           => 0,
			'taxonomies'         => array( 'pa_editorial', 'product_brand' ),
			'term_meta'          => array(
				array( 'meta_id' => 11, 'term_id' => 501, 'raw' => '15' ),
			),
		);
	}
}
