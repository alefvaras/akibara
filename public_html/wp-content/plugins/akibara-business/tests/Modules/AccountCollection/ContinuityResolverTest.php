<?php
/**
 * Resolución pura de continuidad para Mi colección.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AccountCollection;

use Akibara\Business\Modules\AccountCollection\ContinuityResolver;
use PHPUnit\Framework\TestCase;

final class ContinuityResolverTest extends TestCase {

	/**
	 * The System Under Test.
	 *
	 * @var ContinuityResolver
	 */
	private ContinuityResolver $sut;

	protected function setUp(): void {
		parent::setUp();
		$this->sut = new ContinuityResolver();
	}

	/**
	 * @testdox Should select the earliest missing catalogue volume as a gap.
	 */
	public function test_selects_earliest_gap_before_continuation(): void {
		$result = $this->sut->resolve(
			array( $this->product( 101, 1 ), $this->product( 103, 3 ) ),
			array( $this->product( 201, 1 ), $this->product( 202, 2 ), $this->product( 203, 3 ), $this->product( 204, 4 ) ),
			array( 'is_complete' => false )
		);

		$this->assertSame( 'gap', $result['kind'], 'A missing volume below the highest owned volume should take priority' );
		$this->assertSame( 2, $result['volume_number'], 'The earliest catalogue gap should be selected' );
		$this->assertSame( 202, $result['product']['id'], 'The gap product should be actionable' );
	}

	/**
	 * @testdox Should select the first catalogue volume after the highest owned volume.
	 */
	public function test_selects_next_volume_when_there_are_no_gaps(): void {
		$result = $this->sut->resolve(
			array( $this->product( 101, 1 ), $this->product( 102, 2 ) ),
			array( $this->product( 201, 1 ), $this->product( 202, 2 ), $this->product( 204, 4 ), $this->product( 203, 3 ) ),
			array( 'is_complete' => false )
		);

		$this->assertSame( 'next', $result['kind'], 'A contiguous collection should continue after the highest owned volume' );
		$this->assertSame( 3, $result['volume_number'], 'Natural volume order should not depend on catalogue input order' );
	}

	/**
	 * @testdox Should not skip a next volume that has no current catalogue product.
	 */
	public function test_does_not_skip_absent_next_volume(): void {
		$result = $this->sut->resolve(
			array(
				$this->product( 101, 1 ),
				$this->product( 102, 2 ),
				$this->product( 103, 3 ),
				$this->product( 104, 4 ),
			),
			array(
				$this->product( 201, 1 ),
				$this->product( 202, 2 ),
				$this->product( 203, 3 ),
				$this->product( 204, 4 ),
				$this->product( 206, 6 ),
			),
			array( 'is_complete' => false, 'state' => 'en_publicacion', 'total' => 13 )
		);

		$this->assertSame( 'next', $result['kind'] );
		$this->assertSame( 5, $result['volume_number'], 'Volume 6 must never be recommended before the absent volume 5' );
		$this->assertSame( 'unknown', $result['availability'] );
		$this->assertNull( $result['product'], 'An absent product must not fabricate a destination' );
		$this->assertSame( array(), $result['options'] );
		$this->assertSame( 0, $result['options_count'] );
	}

	/**
	 * @testdox Should detect an owned-sequence gap even when that product is absent from the catalogue.
	 */
	public function test_detects_gap_absent_from_catalogue(): void {
		$result = $this->sut->resolve(
			array( $this->product( 101, 1 ), $this->product( 103, 3 ) ),
			array( $this->product( 201, 1 ), $this->product( 203, 3 ), $this->product( 204, 4 ) ),
			array( 'is_complete' => false, 'state' => 'en_publicacion', 'total' => 4 )
		);

		$this->assertSame( 'gap', $result['kind'] );
		$this->assertSame( 2, $result['volume_number'] );
		$this->assertNull( $result['product'] );
		$this->assertSame( 'unknown', $result['availability'] );
	}

	/**
	 * @testdox Should continue after the owned volume instead of assuming the reader starts at one.
	 *
	 * Este test afirmaba lo contrario hasta 1.108.13: con un solo tomo comprado
	 * (el 4) esperaba `gap` en el tomo 1. Se cambió con datos de producción
	 * (2026-08-23, 31 colecciones reales): 13 clientes tienen UN solo tomo, 10
	 * una racha contigua que no empieza en 1 y 3 un hueco interno empezando
	 * arriba — 26 de 31 recibían «No vemos el tomo 1 entre tus compras» y un CTA
	 * al tomo 1, incluido alguien con Hunter X Hunter 31 a 38. Lo que el módulo
	 * NO puede saber es si esos tomos previos se compraron en otra tienda; lo que
	 * sí sabe es por dónde va el cliente. La preocupación original del caso —no
	 * recomendar un salto al 70 porque el catálogo es disperso— se mantiene: el
	 * candidato sigue siendo contiguo a lo que el cliente tiene.
	 */
	public function test_single_owned_volume_continues_instead_of_restarting(): void {
		$result = $this->sut->resolve(
			array( $this->product( 104, 4 ) ),
			array(
				$this->product( 203, 3 ),
				$this->product( 204, 4 ),
				$this->product( 270, 70 ),
				$this->product( 271, 71 ),
				$this->product( 272, 72 ),
			),
			array( 'is_complete' => false, 'state' => 'completa', 'total' => 72 )
		);

		$this->assertSame( 'next', $result['kind'] );
		$this->assertSame( 5, $result['volume_number'], 'No jump to the sparse high numbers of the catalogue' );
	}

	/**
	 * @testdox Should not ask for volume one when the reader owns a contiguous run higher up.
	 *
	 * Caso real de producción: Kagurabachi 3,4,5,6,7.
	 */
	public function test_contiguous_run_starting_high_recommends_the_next_volume(): void {
		$result = $this->sut->resolve(
			array(
				$this->product( 103, 3 ),
				$this->product( 104, 4 ),
				$this->product( 105, 5 ),
				$this->product( 106, 6 ),
				$this->product( 107, 7 ),
			),
			array( $this->product( 208, 8 ) ),
			array( 'is_complete' => false, 'state' => 'en_publicacion', 'total' => 12 )
		);

		$this->assertSame( 'next', $result['kind'], 'Quien va por el tomo 7 no necesita que le ofrezcan el 1' );
		$this->assertSame( 8, $result['volume_number'] );
	}

	/**
	 * @testdox Should surface an internal gap even when the run starts above volume one.
	 *
	 * Caso real de producción: Los diarios de la boticaria 2 y 6 — el hueco útil
	 * es el 3, no el 1.
	 */
	public function test_internal_gap_above_volume_one_is_still_a_gap(): void {
		$result = $this->sut->resolve(
			array( $this->product( 102, 2 ), $this->product( 106, 6 ) ),
			array( $this->product( 203, 3 ) ),
			array( 'is_complete' => false, 'state' => 'en_publicacion', 'total' => 12 )
		);

		$this->assertSame( 'gap', $result['kind'] );
		$this->assertSame( 3, $result['volume_number'], 'El hueco es el primero que falta DENTRO de su racha' );
	}

	/**
	 * @testdox Should keep recommending volume one to a reader who really starts there.
	 */
	public function test_collection_starting_at_one_is_unchanged(): void {
		$result = $this->sut->resolve(
			array( $this->product( 101, 1 ), $this->product( 103, 3 ) ),
			array( $this->product( 202, 2 ) ),
			array( 'is_complete' => false, 'state' => 'en_publicacion', 'total' => 12 )
		);

		$this->assertSame( 'gap', $result['kind'] );
		$this->assertSame( 2, $result['volume_number'] );
	}

	/**
	 * @testdox Should start at volume one when the current catalogue begins later.
	 */
	public function test_starts_at_one_when_catalogue_begins_later(): void {
		$result = $this->sut->resolve(
			array(),
			array( $this->product( 203, 3 ), $this->product( 204, 4 ) ),
			array( 'is_complete' => false, 'state' => 'en_publicacion', 'total' => 4 )
		);

		$this->assertSame( 'start', $result['kind'] );
		$this->assertSame( 1, $result['volume_number'] );
		$this->assertNull( $result['product'] );
		$this->assertSame( 'unknown', $result['availability'] );
	}

	/**
	 * @testdox Should fail closed when observed numbering exceeds the declared edition total.
	 */
	public function test_inconsistent_edition_total_fails_closed(): void {
		$result = $this->sut->resolve(
			array( $this->product( 101, 1 ) ),
			array( $this->product( 201, 1 ), $this->product( 206, 6 ) ),
			array( 'is_complete' => false, 'state' => 'completa', 'total' => 5 )
		);

		$this->assertTerminalResult( 'unknown', $result );
	}

	/**
	 * @testdox Should not promise an absent future volume from a cancelled edition total.
	 */
	public function test_cancelled_edition_total_does_not_fabricate_continuation(): void {
		$products = array( $this->product( 101, 1 ), $this->product( 102, 2 ), $this->product( 103, 3 ) );

		$result = $this->sut->resolve(
			$products,
			$products,
			array( 'is_complete' => false, 'state' => 'cancelada', 'total' => 10 )
		);

		$this->assertTerminalResult( 'up_to_date', $result );
	}

	/**
	 * @testdox Should start with the earliest catalogue volume when no numbered volume is owned.
	 */
	public function test_selects_start_for_collection_without_numbered_ownership(): void {
		$result = $this->sut->resolve(
			array( $this->product( 900, null ) ),
			array( $this->product( 203, 3 ), $this->product( 201, 1 ) ),
			array( 'is_complete' => false )
		);

		$this->assertSame( 'start', $result['kind'], 'A non-linear purchase should not block a linear starting action' );
		$this->assertSame( 1, $result['volume_number'], 'The earliest numbered catalogue product should start the collection' );
	}

	/**
	 * @testdox Should treat a reprint or alternate cover as the same owned volume.
	 */
	public function test_treats_different_product_ids_as_the_same_logical_slot(): void {
		$result = $this->sut->resolve(
			array( $this->product( 101, 1 ), $this->product( 102, 2 ) ),
			array( $this->product( 201, 1 ), $this->product( 202, 2 ), $this->product( 203, 3 ) ),
			array( 'is_complete' => false )
		);

		$this->assertSame( 'next', $result['kind'], 'A replacement product ID for an owned volume must not become a false gap' );
		$this->assertSame( 3, $result['volume_number'], 'Continuity should advance by logical volume, not product ID' );
	}

	/**
	 * @testdox Should group duplicate-volume catalogue options and choose a deterministic primary.
	 */
	public function test_groups_options_and_prefers_purchasable_instock_then_id(): void {
		$out_of_stock = $this->product( 201, 1, 'outofstock', false );
		$higher_id = $this->product( 203, 1 );
		$lower_id = $this->product( 202, 1 );

		$result = $this->sut->resolve(
			array(),
			array( $out_of_stock, $higher_id, $lower_id ),
			array( 'is_complete' => false )
		);

		$this->assertSame( 3, $result['options_count'], 'Every distinct product option for the slot should be exposed' );
		$this->assertSame( array( 202, 203, 201 ), array_column( $result['options'], 'id' ), 'Options should be sorted by availability and then ID' );
		$this->assertSame( 202, $result['product']['id'], 'The first deterministic option should be the primary product' );
		$this->assertSame( 'available', $result['availability'], 'An in-stock purchasable primary should be available' );
	}

	/**
	 * @testdox Should prefer purchasable in-stock options over purchasable preorders.
	 */
	public function test_prefers_available_option_over_preorder_for_same_volume(): void {
		$result = $this->sut->resolve(
			array(),
			array(
				$this->product( 201, 1, 'onbackorder', true, 'Preventa' ),
				$this->product( 202, 1, 'instock', true ),
			),
			array( 'is_complete' => false )
		);

		$this->assertSame( 202, $result['product']['id'], 'Immediate availability should win over preorder' );
		$this->assertSame( 'available', $result['availability'], 'The selected in-stock option should be available' );
	}

	/**
	 * @testdox Should expose a purchasable backorder as a preorder.
	 */
	public function test_resolves_preorder_availability(): void {
		$result = $this->sut->resolve(
			array(),
			array( $this->product( 201, 1, 'onbackorder', true, 'Llega pronto' ) ),
			array( 'is_complete' => false )
		);

		$this->assertSame( 'preorder', $result['availability'], 'A purchasable preorder must not be presented as immediately available' );
	}

	/**
	 * @testdox Should use an explicit preorder label even when stock is reported as in stock.
	 */
	public function test_explicit_preorder_label_takes_precedence_over_stock_status(): void {
		$result = $this->sut->resolve(
			array(),
			array( $this->product( 201, 1, 'instock', true, 'Preventa' ) ),
			array( 'is_complete' => false )
		);

		$this->assertSame( 'preorder', $result['availability'], 'Explicit preorder semantics should prevent an immediate-availability claim' );
	}

	/**
	 * @testdox Should expose an out-of-stock candidate without making it purchasable.
	 */
	public function test_resolves_out_of_stock_availability(): void {
		$product = $this->product( 201, 1, 'outofstock', false );
		$product['waitlist_url'] = 'https://akibara.cl/producto/201/#akb-bis-form';
		$result = $this->sut->resolve(
			array(),
			array( $product ),
			array( 'is_complete' => false )
		);

		$this->assertSame( 'out_of_stock', $result['availability'], 'An out-of-stock candidate should remain a continuity slot without a purchase claim' );
		$this->assertSame( $product['waitlist_url'], $result['product']['waitlist_url'], 'The semantic action must preserve the canonical waitlist destination' );
	}

	/**
	 * @testdox Should return complete when every numbered catalogue slot is owned and the edition ended.
	 */
	public function test_returns_complete_for_finished_owned_edition(): void {
		$products = array( $this->product( 101, 1 ), $this->product( 102, 2 ) );

		$result = $this->sut->resolve( $products, $products, array( 'is_complete' => true ) );

		$this->assertTerminalResult( 'complete', $result );
	}

	/**
	 * @testdox Should return up to date when every numbered catalogue slot is owned and the edition continues.
	 */
	public function test_returns_up_to_date_for_ongoing_owned_edition(): void {
		$products = array( $this->product( 101, 1 ), $this->product( 102, 2 ) );

		$result = $this->sut->resolve( $products, $products, array( 'is_complete' => false ) );

		$this->assertTerminalResult( 'up_to_date', $result );
	}

	/**
	 * @testdox Should return up to date when the edition completion metadata is unknown.
	 */
	public function test_returns_up_to_date_when_completion_metadata_is_null(): void {
		$products = array( $this->product( 101, 1 ), $this->product( 102, 2 ) );

		$result = $this->sut->resolve( $products, $products, array( 'is_complete' => null ) );

		$this->assertTerminalResult( 'up_to_date', $result );
	}

	/**
	 * @testdox Should return unknown when an unfinished edition has no numbered catalogue slots.
	 */
	public function test_returns_unknown_without_numbered_catalogue(): void {
		$result = $this->sut->resolve(
			array( $this->product( 101, 1 ) ),
			array( $this->product( 900, null ) ),
			array( 'is_complete' => false )
		);

		$this->assertTerminalResult( 'unknown', $result );
	}

	/**
	 * @testdox Should return complete for a finished edition without numbered catalogue slots.
	 */
	public function test_returns_complete_without_numbered_catalogue_when_edition_ended(): void {
		$result = $this->sut->resolve(
			array(),
			array( $this->product( 900, null ) ),
			array( 'is_complete' => true )
		);

		$this->assertTerminalResult( 'complete', $result );
	}

	/**
	 * @testdox Should fail closed for malformed linear products and edition metadata.
	 */
	public function test_malformed_inputs_fail_closed(): void {
		$conflicting_product = $this->product( 1, 2 );
		$cases = array(
			'non-array product'     => array( array( 'invalid' ), array(), array( 'is_complete' => false ) ),
			'string volume number' => array( array( array( 'id' => 1, 'volume_number' => '1' ) ), array(), array( 'is_complete' => false ) ),
			'invalid product ID'    => array( array(), array( array( 'id' => 0, 'volume_number' => 1 ) ), array( 'is_complete' => false ) ),
			'conflicting product ID' => array( array(), array( $this->product( 1, 1 ), $conflicting_product ), array( 'is_complete' => false ) ),
			'invalid completeness'  => array( array(), array(), array( 'is_complete' => 1 ) ),
			'invalid edition total' => array( array(), array(), array( 'is_complete' => false, 'total' => '4' ) ),
			'invalid edition state' => array( array(), array(), array( 'is_complete' => false, 'state' => array() ) ),
		);

		foreach ( $cases as $label => $case ) {
			$result = $this->sut->resolve( $case[0], $case[1], $case[2] );

			$this->assertTerminalResult( 'unknown', $result, $label );
		}
	}

	/**
	 * @testdox Should ignore non-positive or absent volume numbers.
	 */
	public function test_ignores_products_without_positive_volume_number(): void {
		$without_volume = $this->product( 900, null );
		$zero_volume = $this->product( 901, 0 );
		unset( $without_volume['volume_number'] );

		$result = $this->sut->resolve(
			array( $without_volume, $zero_volume ),
			array( $without_volume, $zero_volume, $this->product( 201, 1 ) ),
			array( 'is_complete' => false )
		);

		$this->assertSame( 'start', $result['kind'], 'Non-linear products should not corrupt or occupy a linear slot' );
		$this->assertSame( 1, $result['volume_number'], 'The valid positive volume should remain actionable' );
	}

	/**
	 * @testdox Should keep availability unknown when optional commerce fields are absent.
	 */
	public function test_missing_optional_commerce_fields_cannot_claim_availability(): void {
		$result = $this->sut->resolve(
			array(),
			array( array( 'id' => 201, 'volume_number' => 1 ) ),
			array( 'is_complete' => false )
		);

		$this->assertSame( 'start', $result['kind'], 'A structurally valid logical slot should remain identifiable' );
		$this->assertSame( 'unknown', $result['availability'], 'Missing stock and purchasability must fail closed' );
		$this->assertFalse( $result['product']['is_purchasable'], 'Missing purchasability should normalize to false' );
	}

	/**
	 * Creates a product DTO.
	 *
	 * @return array{id:int,title:string,url:string,volume_number:int|null,stock_status:string,is_purchasable:bool,preorder_label:string}
	 */
	private function product(
		int $id,
		?int $volume_number,
		string $stock_status = 'instock',
		bool $is_purchasable = true,
		string $preorder_label = ''
	): array {
		return array(
			'id'               => $id,
			'title'            => 'Tomo ' . ( $volume_number ?? 'especial' ),
			'url'              => 'https://akibara.cl/producto/' . $id . '/',
			'volume_number'    => $volume_number,
			'stock_status'     => $stock_status,
			'is_purchasable'   => $is_purchasable,
			'preorder_label'   => $preorder_label,
		);
	}

	/**
	 * @testdox El atajo del número de tomo responde lo mismo que la resolución completa.
	 *
	 * `CollectionResolver` necesita el número ANTES de hidratar stock y ETA, y
	 * pedía un `resolve()` entero para leerle un campo: dos normalizaciones del
	 * catálogo por serie de la colección. El atajo sólo vale si nunca discrepa,
	 * y sólo puede no discrepar si comparten implementación.
	 *
	 * @dataProvider continuity_scenarios
	 *
	 * @param list<array<string,mixed>> $owned
	 * @param list<array<string,mixed>> $catalog
	 * @param array<string,mixed>       $edition
	 */
	public function test_the_volume_shortcut_agrees_with_the_full_resolution( array $owned, array $catalog, array $edition ): void {
		$completo = $this->sut->resolve( $owned, $catalog, $edition );
		$atajo    = $this->sut->candidate_volume_number( $owned, $catalog, $edition );

		$this->assertSame( $completo['volume_number'], $atajo );
	}

	/**
	 * @return array<string, array{0:list<array<string,mixed>>, 1:list<array<string,mixed>>, 2:array<string,mixed>}>
	 */
	public function continuity_scenarios(): array {
		return array(
			'hueco'                 => array(
				array( $this->product( 101, 1 ), $this->product( 103, 3 ) ),
				array( $this->product( 201, 1 ), $this->product( 202, 2 ), $this->product( 203, 3 ) ),
				array( 'is_complete' => false ),
			),
			'siguiente'             => array(
				array( $this->product( 101, 1 ), $this->product( 102, 2 ) ),
				array( $this->product( 201, 1 ), $this->product( 202, 2 ), $this->product( 203, 3 ) ),
				array( 'is_complete' => false ),
			),
			'inicio'                => array(
				array(),
				array( $this->product( 201, 1 ), $this->product( 202, 2 ) ),
				array( 'is_complete' => false ),
			),
			'sin catálogo numerado' => array(
				array( $this->product( 101, 1 ) ),
				array( $this->product( 201, null ) ),
				array( 'is_complete' => false ),
			),
			'edición completa'      => array(
				array( $this->product( 101, 1 ), $this->product( 102, 2 ) ),
				array( $this->product( 201, 1 ), $this->product( 202, 2 ) ),
				array( 'is_complete' => true, 'total' => 2, 'state' => 'complete' ),
			),
			'total inconsistente'   => array(
				array( $this->product( 101, 9 ) ),
				array( $this->product( 201, 9 ) ),
				array( 'is_complete' => false, 'total' => 2, 'state' => 'ongoing' ),
			),
			'entrada mal formada'   => array(
				array( array( 'id' => 'no-es-int', 'volume_number' => 1 ) ),
				array( $this->product( 201, 1 ) ),
				array( 'is_complete' => false ),
			),
		);
	}

	/**
	 * Asserts the stable shape of a result without an actionable product.
	 *
	 * @param string              $kind    Expected terminal kind.
	 * @param array<string,mixed> $result  Resolver result.
	 * @param string              $message Optional assertion context.
	 */
	private function assertTerminalResult( string $kind, array $result, string $message = '' ): void {
		$this->assertSame( $kind, $result['kind'], $message );
		$this->assertSame( 'unknown', $result['availability'], $message );
		$this->assertNull( $result['volume_number'], $message );
		$this->assertNull( $result['product'], $message );
		$this->assertSame( array(), $result['options'], $message );
		$this->assertSame( 0, $result['options_count'], $message );
	}
}
