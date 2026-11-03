<?php
/**
 * Tests for canonical PUDO session storage.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Integrations\Bluex\Pudo;

require_once __DIR__ . '/PudoTestDoubles.php';

use Akibara\Business\Integrations\Bluex\Pudo\PudoSelectionStore;
use PHPUnit\Framework\TestCase;

final class PudoSelectionStoreTest extends TestCase {

	/**
	 * The System Under Test.
	 *
	 * @var PudoSelectionStore
	 */
	private $sut;

	protected function setUp(): void {
		parent::setUp();

		$this->sut = new PudoSelectionStore( 900 );
	}

	/**
	 * @testdox Should synchronize only canonical provider data to BlueX session keys.
	 */
	public function test_stores_canonical_selection_and_vendor_compatibility_keys(): void {
		$session     = new PudoSessionStub();
		$fingerprint = str_repeat( 'a', 64 );

		$stored = $this->sut->prepare( $this->selection(), $fingerprint, 1000 );
		$this->sut->hydrate( $stored, $session );

		self::assertSame( 'AGENCY-123', $session->get( 'bluex_agency_id' ) );
		// Blue Express 3.3 no da por elegido el punto sin esta marca igual al id.
		self::assertSame( 'AGENCY-123', $session->get( 'bluex_agency_validated_id' ) );
		self::assertFalse( $session->get( 'bluex_pudo_selected_quote_valid' ), 'La valida el cálculo de envío de Blue, no la selección.' );
		self::assertNull( $session->get( 'bluex_agency_city' ), 'Sin ciudad de agencia Blue cotiza el mismo destino que nuestra cotización.' );
		self::assertNull( $session->get( 'bluex_agency_state' ) );
		self::assertSame( 'Punto Blue Centro', $session->get( 'bluex_agency_name' ) );
		self::assertSame( 'Av. Siempre Viva 123, Santiago', $session->get( 'bluex_agency_address' ) );
		self::assertTrue( $session->get( 'bluex_pudo_selected' ) );
		self::assertSame( 1000, $stored['selected_at'] );
		self::assertSame( 1900, $stored['expires_at'] );
		self::assertSame( $stored, $this->sut->validate( $stored, $fingerprint, 1500 ) );
	}

	/**
	 * @testdox Should invalidate selection after its TTL and clear BlueX compatibility state.
	 */
	public function test_ttl_expiry_clears_all_session_state(): void {
		$session     = new PudoSessionStub();
		$fingerprint = str_repeat( 'b', 64 );
		$stored = $this->sut->prepare( $this->selection(), $fingerprint, 1000 );
		$this->sut->hydrate( $stored, $session );

		$result = $this->sut->validate( $stored, $fingerprint, 1900 );
		if ( null === $result ) {
			$this->sut->clear( $session );
		}

		self::assertNull( $result );
		self::assertNull( $session->get( 'akb_pudo_selection' ) );
		self::assertNull( $session->get( 'bluex_agency_id' ) );
		self::assertNull( $session->get( 'bluex_agency_validated_id' ) );
		self::assertNull( $session->get( 'bluex_agency_name' ) );
		self::assertNull( $session->get( 'bluex_agency_address' ) );
		self::assertNull( $session->get( 'bluex_pudo_selected' ) );
		self::assertNull( $session->get( 'bluex_pudo_selected_quote_valid' ) );
	}

	/**
	 * @testdox Should clear the BlueX keys its own selection path leaves behind.
	 *
	 * Blue's validator also writes the agency city and state. A leftover pair from
	 * that path would make Blue quote another destination than ours.
	 */
	public function test_clear_removes_vendor_only_session_keys(): void {
		$session = new PudoSessionStub();
		foreach ( array( 'bluex_agency_id', 'bluex_agency_validated_id', 'bluex_agency_city', 'bluex_agency_state', 'bluex_pudo_selected_quote_valid' ) as $key ) {
			$session->set( $key, 'dejado-por-blue' );
		}

		$this->sut->clear( $session );

		foreach ( array( 'bluex_agency_id', 'bluex_agency_validated_id', 'bluex_agency_city', 'bluex_agency_state', 'bluex_pudo_selected_quote_valid' ) as $key ) {
			self::assertNull( $session->get( $key ), $key );
		}
	}

	/**
	 * @testdox Should keep the quote validity BlueX set while the agency does not change.
	 *
	 * The projection runs on every shipping recalculation. Writing false each time
	 * erased the true BlueX sets after quoting the selected agency.
	 */
	public function test_reprojection_keeps_bluex_quote_validity_for_the_same_agency(): void {
		$session = new PudoSessionStub();
		$stored  = $this->sut->prepare( $this->selection(), str_repeat( 'a', 64 ), 1000 );

		$this->sut->hydrate( $stored, $session );
		self::assertFalse( $session->get( 'bluex_pudo_selected_quote_valid' ), 'Una agencia nueva arranca sin cotización válida.' );

		$session->set( 'bluex_pudo_selected_quote_valid', true ); // Lo que hace el cálculo de envío de Blue.
		$this->sut->hydrate( $stored, $session );
		self::assertTrue( $session->get( 'bluex_pudo_selected_quote_valid' ), 'Reproyectar la misma agencia no borra la validez.' );

		$otra = $this->selection();
		$otra['agency_id'] = 'AGENCY-456';
		$this->sut->hydrate( $this->sut->prepare( $otra, str_repeat( 'a', 64 ), 1100 ), $session );
		self::assertFalse( $session->get( 'bluex_pudo_selected_quote_valid' ), 'Cambiar de agencia vuelve a exigir cotización.' );
	}

	/**
	 * @testdox Should demand a fresh BlueX quote when the same agency is chosen again after clearing.
	 *
	 * Limpiar borra `bluex_agency_validated_id`; al volver a elegir la MISMA agencia la
	 * validez vieja no puede sobrevivir: la tarifa se recalculó sin punto.
	 */
	public function test_clear_then_same_agency_needs_a_new_quote(): void {
		$session = new PudoSessionStub();
		$stored  = $this->sut->prepare( $this->selection(), str_repeat( 'a', 64 ), 1000 );
		$this->sut->hydrate( $stored, $session );
		$session->set( 'bluex_pudo_selected_quote_valid', true );

		$this->sut->clear( $session );
		$this->sut->hydrate( $this->sut->prepare( $this->selection(), str_repeat( 'a', 64 ), 1200 ), $session );

		self::assertFalse( $session->get( 'bluex_pudo_selected_quote_valid' ) );
		self::assertSame( 'AGENCY-123', $session->get( 'bluex_agency_validated_id' ) );
	}

	/**
	 * @testdox Should drop agency geography left in session by the BlueX classic path.
	 */
	public function test_hydrate_drops_leftover_agency_geography(): void {
		$session = new PudoSessionStub();
		$session->set( 'bluex_agency_city', 'PUERTO MONTT' );
		$session->set( 'bluex_agency_state', 'CL-10' );

		$this->sut->hydrate( $this->sut->prepare( $this->selection(), str_repeat( 'a', 64 ), 1000 ), $session );

		self::assertNull( $session->get( 'bluex_agency_city' ) );
		self::assertNull( $session->get( 'bluex_agency_state' ) );
	}

	/**
	 * @testdox Should satisfy the exact selected-agency check of BlueX 3.3.
	 *
	 * Mirrors `WC_BlueX_Pudo::get_selected_agency()`: a selection only counts when
	 * the validated id is present and equal to the agency id.
	 */
	public function test_hydrated_session_passes_bluex_selected_agency_check(): void {
		$session = new PudoSessionStub();
		$stored  = $this->sut->prepare( $this->selection(), str_repeat( 'f', 64 ), 1000 );
		$this->sut->hydrate( $stored, $session );

		$agency_id    = (string) $session->get( 'bluex_agency_id', '' );
		$validated_id = (string) $session->get( 'bluex_agency_validated_id', '' );
		self::assertNotSame( '', $agency_id );
		self::assertNotSame( '', $validated_id );
		self::assertTrue( hash_equals( $validated_id, $agency_id ) );

		// Contrato del tercero, si su código está al lado (espejo): la clave sigue siendo ésa.
		$ruta = dirname( __DIR__, 5 ) . '/bluex-for-woocommerce/includes/shipping/class-wc-bluex-pudo.php';
		if ( is_readable( $ruta ) ) {
			self::assertStringContainsString( "WC()->session->get('bluex_agency_validated_id', '')", (string) file_get_contents( $ruta ), 'Blue sigue exigiendo la misma clave de sesión.' );
		}
	}

	/**
	 * @testdox Should invalidate selection when the cart fingerprint changes.
	 */
	public function test_cart_mutation_invalidates_selection(): void {
		$session = new PudoSessionStub();
		$stored = $this->sut->prepare( $this->selection(), str_repeat( 'c', 64 ), 1000 );
		$this->sut->hydrate( $stored, $session );

		$result = $this->sut->validate( $stored, str_repeat( 'd', 64 ), 1100 );
		if ( null === $result ) {
			$this->sut->clear( $session );
		}

		self::assertNull( $result );
		self::assertNull( $session->get( 'bluex_agency_id' ) );
	}

	/**
	 * @testdox Should reject malformed session values instead of returning them.
	 */
	public function test_rejects_malformed_session_state(): void {
		$session = new PudoSessionStub();
		$malformed = array(
			'schema_version'   => 1,
			'agency_id'        => '<script>',
			'cart_fingerprint' => str_repeat( 'e', 64 ),
			'selected_at'      => 1000,
			'expires_at'       => 1900,
		);
		$session->set( 'akb_pudo_selection', $malformed );

		$result = $this->sut->validate( $malformed, str_repeat( 'e', 64 ), 1100 );
		if ( null === $result ) {
			$this->sut->clear( $session );
		}

		self::assertNull( $result );
		self::assertNull( $session->get( 'akb_pudo_selection' ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function selection(): array {
		return array(
			'agency_id'        => 'AGENCY-123',
			'agency_name'      => 'Punto Blue Centro',
			'agency_address'   => 'Av. Siempre Viva 123, Santiago',
			'region_code'      => 'CL-RM',
			'district_code'    => 'SCL',
			'city'             => 'Santiago',
			'shipping_address' => array(
				'address_1' => 'Av. Siempre Viva 123',
				'city'      => 'Santiago',
				'state'     => 'CL-RM',
				'country'   => 'CL',
			),
			'quote'            => array(
				'total'       => 3990.0,
				'is_free'     => false,
				'promise_day' => 2,
			),
		);
	}
}
