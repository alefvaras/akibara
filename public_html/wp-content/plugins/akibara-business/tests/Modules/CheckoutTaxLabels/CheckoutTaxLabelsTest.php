<?php
/**
 * Tests for canonical checkout tax labels.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\CheckoutTaxLabels;

use Akibara\Business\Modules\CheckoutTaxLabels\CheckoutTaxLabels;
use PHPUnit\Framework\TestCase;

final class CheckoutTaxLabelsTest extends TestCase {

	/**
	 * @testdox Should use IVA as the canonical generic tax label.
	 */
	public function test_uses_iva_for_the_generic_tax_label(): void {
		$sut = new CheckoutTaxLabels();

		self::assertSame( 'IVA', $sut->handle_tax_or_vat( 'Impuesto' ) );
	}

	/**
	 * @testdox Should relabel cart and order tax rows without changing their amounts.
	 */
	public function test_relabels_tax_rows_without_changing_amounts(): void {
		$sut       = new CheckoutTaxLabels();
		$cart_tax  = (object) array(
			'label'             => 'CL-IVA-19',
			'formatted_amount'  => '$1.900',
		);
		$order_tax = (object) array(
			'label'  => 'Tax',
			'amount' => 1900.0,
		);

		$cart_result  = $sut->handle_cart_tax_totals( array( 'vat' => $cart_tax ) );
		$order_result = $sut->handle_order_tax_totals( array( 'vat' => $order_tax ) );

		self::assertSame( 'IVA', $cart_result['vat']->label );
		self::assertSame( '$1.900', $cart_result['vat']->formatted_amount );
		self::assertSame( 'IVA', $order_result['vat']->label );
		self::assertSame( 1900.0, $order_result['vat']->amount );
	}
}
