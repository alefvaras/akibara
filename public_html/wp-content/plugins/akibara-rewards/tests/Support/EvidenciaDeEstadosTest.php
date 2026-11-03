<?php
/**
 * El texto bajo los campos «estado del pedido» del panel: la promesa contra el hecho.
 *
 * R7-invitacion-nunca-sale (2026-09-19): el disparador de fábrica de la
 * invitación a reseñar era «Completado» y ningún pedido real llega a él (la
 * tienda termina en «Listo para enviar»): cero invitaciones en toda la historia
 * y el panel no lo decía.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Support;

use Akibara\Rewards\Support\SettingsPage;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

final class EvidenciaDeEstadosTest extends RewardsTestCase {

	private const ETIQUETAS = array(
		'processing'        => 'Procesando',
		'shipping-progress' => 'Listo para enviar',
		'completed'         => 'Completado',
	);

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'number_format_i18n' )->alias( static fn ( $n ) => (string) $n );
	}

	/** @testdox Current states cannot prove that an invitation never fired. */
	public function test_current_states_do_not_claim_to_prove_transition_history(): void {
		$counts = array(
			'processing'        => 14,
			'shipping-progress' => 63,
			'completed'         => 0,
		);

		$texto = SettingsPage::status_evidence_text( $counts, 'completed', self::ETIQUETAS );

		$this->assertStringContainsString( 'Procesando 14 · Listo para enviar 63 · Completado 0', $texto );
		$this->assertStringContainsString( 'hoy hay 0 pedidos en «Completado» y 63 en «Listo para enviar»', $texto );
		$this->assertStringContainsString( 'no indican por qué estados pasó cada pedido', $texto );
		$this->assertStringNotContainsString( 'nunca', $texto );
	}

	/**
	 * @testdox A less common later status suggests reviewing the workflow, not changing the business rule.
	 */
	public function test_a_minority_status_after_the_terminal_one_is_flagged_with_the_numbers(): void {
		$counts = array(
			'processing'        => 12,
			'shipping-progress' => 49,
			'completed'         => 12,
		);

		$texto = SettingsPage::status_evidence_text( $counts, 'completed', self::ETIQUETAS );

		$this->assertStringContainsString( 'hoy hay 12 pedidos en «Completado» y 49 en «Listo para enviar»', $texto );
		$this->assertStringContainsString( 'estado elegido para las invitaciones coincida con tu flujo', $texto );
		$this->assertStringNotContainsString( 'no se dispara nunca', $texto );
	}

	/** @testdox Points and referral fields describe their own workflow without mentioning invitations. */
	public function test_context_matches_points_and_referral_fields(): void {
		$counts = array( 'shipping-progress' => 49, 'completed' => 12 );
		foreach ( array( 'akb_rewards_award_status' => 'la acreditación de puntos', 'akb_rewards_referral_status' => 'los premios de referidos' ) as $option => $context ) {
			$text = SettingsPage::status_evidence_text( $counts, 'completed', self::ETIQUETAS, $option );
			$this->assertStringContainsString( $context, $text );
			$this->assertStringNotContainsString( 'invitaciones', $text );
		}
	}

	public function test_a_status_that_orders_pass_through_is_not_flagged(): void {
		$counts = array(
			'processing'        => 0,
			'shipping-progress' => 63,
			'completed'         => 0,
		);

		$texto = SettingsPage::status_evidence_text( $counts, 'processing', self::ETIQUETAS );

		$this->assertStringContainsString( 'Procesando 0', $texto );
		$this->assertStringNotContainsString( 'Ojo', $texto, '«Procesando» va ANTES del estado terminal: todos pasan por él aunque hoy no queden ahí.' );
	}

	public function test_the_terminal_status_itself_and_an_empty_store_are_quiet(): void {
		$counts = array(
			'processing'        => 2,
			'shipping-progress' => 63,
			'completed'         => 0,
		);

		$this->assertStringNotContainsString( 'Ojo', SettingsPage::status_evidence_text( $counts, 'shipping-progress', self::ETIQUETAS ) );
		$this->assertSame( '', SettingsPage::status_evidence_text( array(), 'completed', self::ETIQUETAS ) );
		$this->assertSame( '', SettingsPage::status_evidence_text( array( 'completed' => 0 ), 'completed', self::ETIQUETAS ) );
	}
}
