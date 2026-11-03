<?php
/**
 * Promesas de preventa en la ficha (release 1.108.28).
 *
 * Este bloque existe porque Akibara ya cumplía cuatro cosas que no decía en
 * ninguna parte. Su único valor es que TODAS sean ciertas: una promesa
 * aspiracional aquí es peor que no tener el bloque, porque el cliente paga por
 * adelantado apoyándose en ella.
 *
 * Lo que se fija:
 *
 *   1) La rama SIN fecha (TBA) no promete el recordatorio de una semana antes.
 *      Sin fecha no hay T-7 que contar. Es la promesa más fácil de colar por
 *      copiar la otra rama.
 *   2) El bloque se registra en prioridad 31, ESTRICTAMENTE después del botón
 *      de compra (`woocommerce_template_single_add_to_cart`, prioridad 30). En
 *      25 —donde vive el banner de fecha— empujaba el botón fuera de pantalla
 *      en móvil.
 *   3) Ninguna promesa habla de despacho parcial ni de cancelación. Ninguna de
 *      las dos está implementada; si algún día lo están, se añaden aquí a
 *      propósito y esta prueba se actualiza a propósito.
 *
 * @package Akibara\Business\Tests
 */

declare(strict_types=1);

namespace Akibara\Business\Tests\Modules\EstimatedDelivery;

use Akibara\Business\Modules\EstimatedDelivery\BannerRenderer;
use Akibara\Business\Modules\OrderPreventa\Settings;
use PHPUnit\Framework\TestCase;

final class PromesasPreventaTest extends TestCase {

	public function test_la_rama_sin_fecha_no_promete_el_recordatorio_de_una_semana(): void {
		$tba = BannerRenderer::promesas_para( true );

		$this->assertNotEmpty( $tba, 'La rama TBA se quedó sin promesas.' );
		foreach ( $tba as $promesa ) {
			$this->assertStringNotContainsStringIgnoringCase(
				'una semana antes',
				$promesa,
				'Sin fecha no hay T-7 que contar: prometerlo es una promesa que el sistema no puede cumplir.'
			);
		}
	}

	public function test_la_rama_con_fecha_si_promete_el_recordatorio(): void {
		$con_fecha = implode( ' ', BannerRenderer::promesas_para( false ) );

		$this->assertStringContainsString( 'una semana antes', $con_fecha );
		$this->assertStringContainsString( 'lo despachamos de inmediato', $con_fecha );
	}

	/**
	 * Hallazgo S-7022f6e1: apagar «Activar el aviso previo» desprogramaba el
	 * cron, pero la ficha seguía prometiendo avisar una semana antes.
	 */
	public function test_con_el_aviso_previo_apagado_no_se_promete(): void {
		$con_fecha = implode( ' ', BannerRenderer::promesas_para( false, false ) );

		$this->assertStringNotContainsStringIgnoringCase( 'una semana antes', $con_fecha );
		$this->assertStringContainsString( 'cuando llegue a bodega', $con_fecha, 'El aviso de llegada no depende de ese interruptor.' );
	}

	public function test_la_ficha_lee_el_interruptor_del_aviso_previo(): void {
		$codigo = '';
		foreach ( token_get_all( (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/EstimatedDelivery/BannerRenderer.php' ) ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$codigo .= is_array( $token ) ? $token[1] : $token;
		}

		$this->assertMatchesRegularExpression( '/promesas_para\(\s*\$eta->is_tba\(\),\s*Settings::get_bool\(\s*Settings::OPT_T7_ENABLED\s*\)\s*\)/', $codigo );
	}

	public function test_ninguna_rama_promete_algo_que_no_esta_implementado(): void {
		$todas = array_merge(
			BannerRenderer::promesas_para( true ),
			BannerRenderer::promesas_para( false )
		);

		// El despacho parcial se evaluó y NO se implementó: cuesta ~$4.270 por
		// envío extra y cubre un caso que ocurrió 1 vez en 100 pedidos.
		foreach ( array( 'parcial', 'por separado' ) as $prohibida ) {
			foreach ( $todas as $promesa ) {
				$this->assertStringNotContainsStringIgnoringCase(
					$prohibida,
					$promesa,
					"La promesa «{$promesa}» habla de algo que no está implementado."
				);
			}
		}
	}

	public function test_nunca_se_promete_cancelacion_libre(): void {
		// REGLA DE NEGOCIO, no limitación técnica. Cuando entra una preventa
		// Akibara la encarga a España o Argentina y paga por adelantado: ese
		// dinero está gastado antes de que el tomo llegue. Prometer «cancela
		// cuando quieras» convierte el cobro anticipado en un depósito a la
		// vista y deja a Akibara pagando un stock que nadie pidió.
		//
		// Esta prueba existió al revés durante unas horas el 2026-08-28 y el
		// dueño lo corrigió. No volver a darle la vuelta sin preguntárselo.
		$todas = array_merge(
			BannerRenderer::promesas_para( true ),
			BannerRenderer::promesas_para( false )
		);

		foreach ( $todas as $promesa ) {
			foreach ( array( 'cuando quieras', 'no lo despachemos', 'antes del despacho' ) as $libre ) {
				$this->assertStringNotContainsStringIgnoringCase(
					$libre,
					$promesa,
					"La promesa «{$promesa}» ofrece cancelación libre: el dinero ya está gastado en el proveedor."
				);
			}
		}
	}

	public function test_la_garantia_de_atraso_va_donde_hay_fecha(): void {
		// Los 15 días cuelgan de la fecha estimada. Sin fecha no hay reloj que
		// echar a andar, así que la rama TBA promete la cláusula de agotado.
		$con_fecha = implode( ' ', BannerRenderer::promesas_para( false ) );
		$sin_fecha = implode( ' ', BannerRenderer::promesas_para( true ) );

		$this->assertStringContainsString( '15 días', $con_fecha );
		$this->assertStringNotContainsString( '15 días', $sin_fecha );
		$this->assertStringContainsString( 'agotó', $sin_fecha );
	}

	/**
	 * R3-cbc69f7a (2026-09-19): el «15 días» no puede ser un literal suelto —
	 * tiene que ser el MISMO número que usa `ReadyToShipCron::maybe_mark_refund_eligible()`
	 * para dejar la señal cuando el pedido cruza ese plazo. Esta prueba compara
	 * contra la constante, no contra el literal: si alguien cambia
	 * `Settings::REFUND_AFTER_DAYS`, esta prueba sigue en verde SÓLO si la
	 * ficha cambió con ella.
	 */
	public function test_la_garantia_de_atraso_cita_la_misma_constante_que_el_cron(): void {
		$con_fecha = implode( ' ', BannerRenderer::promesas_para( false ) );

		$this->assertStringContainsString(
			Settings::REFUND_AFTER_DAYS . ' días desde esa fecha, cancelas y te devolvemos el 100%',
			$con_fecha
		);
	}

	public function test_el_bloque_va_despues_del_boton_de_compra(): void {
		$fuente = file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/EstimatedDelivery/BannerRenderer.php'
		);
		$this->assertIsString( $fuente );

		$this->assertMatchesRegularExpression(
			"/'woocommerce_single_product_summary',\s*array\(\s*\\\$this,\s*'render_promesas'\s*\),\s*31\s*\)/",
			$fuente,
			'El bloque debe colgar de la prioridad 31: el botón de compra va en 30.'
		);
	}
}
