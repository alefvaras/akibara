<?php
/**
 * Comandos WP-CLI para Akibara Pricing & Backorder Matrix (`wp akb-pricing`).
 *
 * Subcomandos soportados:
 *   - wp akb-pricing self-test
 *   - wp akb-pricing scales
 *   - wp akb-pricing sync-backorders [--dry-run] [--unprotect-orders] [--brand-id=<id>]
 *   - wp akb-pricing rules <export|import> [--file=<path>]
 *   - wp akb-pricing audit-orders
 *   - wp akb-pricing audit-series
 *
 * @package Akibara\Business\Modules\PricingMatrix
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\PricingMatrix;

defined( 'ABSPATH' ) || exit;

final class PricingMatrixCli {

	/**
	 * Registra los comandos WP-CLI bajo el namespace `akb-pricing`.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'akb-pricing self-test', array( $this, 'cmd_self_test' ) );
		\WP_CLI::add_command( 'akb-pricing scales', array( $this, 'cmd_scales' ) );
		\WP_CLI::add_command( 'akb-pricing sync-backorders', array( $this, 'cmd_sync_backorders' ) );
		\WP_CLI::add_command( 'akb-pricing rules', array( $this, 'cmd_rules' ) );
		\WP_CLI::add_command( 'akb-pricing audit-orders', array( $this, 'cmd_audit_orders' ) );
		\WP_CLI::add_command( 'akb-pricing audit-series', array( $this, 'cmd_audit_series' ) );
	}

	/**
	 * Ejecuta la suite de 6 pruebas automatizadas (`wp akb-pricing self-test`).
	 *
	 * @return void
	 */
	public function cmd_self_test(): void {
		\WP_CLI::log( '=== AKIBARA PRICING & BACKORDER MATRIX — SELF-TEST SUITE ===' );
		$report = PricingSelfTest::run_all();

		foreach ( $report['tests'] as $test ) {
			$badge = $test['passed'] ? 'PASS' : 'FAIL';
			\WP_CLI::log(
				sprintf(
					'[%s] %s — %s (%.1f ms): %s',
					$badge,
					$test['id'],
					$test['name'],
					$test['duration_ms'],
					$test['details']
				)
			);
		}

		if ( ! $report['ok'] ) {
			\WP_CLI::error(
				sprintf(
					'Self-test falló: %d/%d tests en PASS.',
					$report['passed_count'],
					$report['total_count']
				)
			);
			return;
		}

		\WP_CLI::success(
			sprintf(
				'6/6 tests en PASS (%d/%d). Integridad matemática, blindaje de órdenes, buscador y rendimiento certificados.',
				$report['passed_count'],
				$report['total_count']
			)
		);
	}

	/**
	 * Muestra la tabla del Auto-Escáner de Escalas por Editorial (`wp akb-pricing scales`).
	 *
	 * @return void
	 */
	public function cmd_scales(): void {
		$rows = ScaleScanner::scan_editorial_scales();
		if ( empty( $rows ) ) {
			\WP_CLI::warning( 'No se encontraron escalas de precio en el catálogo.' );
			return;
		}

		$table = array();
		foreach ( $rows as $r ) {
			$table[] = array(
				'Editorial'     => $r['editorial'],
				'Pais'          => $r['pais_codigo'],
				'Regular'       => $r['escala_precio_regular'],
				'Tomos'         => $r['total_tomos'],
				'InStock'       => $r['en_stock'],
				'APedido'       => $r['a_pedido'],
				'Agotados'      => $r['agotados'],
				'Protegidos'    => $r['comprometidos_count'],
				'NuevoRegular'  => $r['nuevo_precio_regular'],
				'NuevoAPedido'  => $r['nuevo_precio_a_pedido'],
				'Modo'          => $r['rounding_mode'],
			);
		}

		\WP_CLI\Utils\format_items(
			'table',
			$table,
			array( 'Editorial', 'Pais', 'Regular', 'Tomos', 'InStock', 'APedido', 'Agotados', 'Protegidos', 'NuevoRegular', 'NuevoAPedido', 'Modo' )
		);
	}

	/**
	 * Sincroniza el descuento redondeado A Pedido en todos los productos `onbackorder`.
	 *
	 * @param array<int, string>    $args       Argumentos posicionales.
	 * @param array<string, string> $assoc_args Flags (`--dry-run`, `--unprotect-orders`, `--brand-id`).
	 * @return void
	 */
	public function cmd_sync_backorders( array $args = array(), array $assoc_args = array() ): void {
		unset( $args );
		$dry_run        = isset( $assoc_args['dry-run'] );
		$protect_orders = ! isset( $assoc_args['unprotect-orders'] );
		$brand_id       = isset( $assoc_args['brand-id'] ) ? (int) $assoc_args['brand-id'] : null;

		$summary = PricingSynchronizer::sync_all_backorders( $protect_orders, $dry_run, $brand_id );

		\WP_CLI::log(
			sprintf(
				'Escaneados: %d | Actualizados: %d | Sin cambio: %d | Protegidos omitidos: %d (%s)',
				$summary['total_scanned'],
				$summary['updated_count'],
				$summary['unchanged_count'],
				$summary['skipped_locked_count'],
				$dry_run ? 'DRY-RUN' : 'APLICADO'
			)
		);

		\WP_CLI::success( $dry_run ? 'Simulación completada.' : 'Sincronización de precios A Pedido completada.' );
	}

	/**
	 * Exporta o importa las reglas JSON de la matriz (`wp akb-pricing rules <export|import>`).
	 *
	 * @param array<int, string>    $args       Posicionales (`export` o `import`).
	 * @param array<string, string> $assoc_args Flags (`--file=<path>`).
	 * @return void
	 */
	public function cmd_rules( array $args = array(), array $assoc_args = array() ): void {
		$sub  = isset( $args[0] ) ? strtolower( trim( (string) $args[0] ) ) : 'export';
		$file = isset( $assoc_args['file'] ) ? trim( (string) $assoc_args['file'] ) : '';

		if ( 'export' === $sub ) {
			$json = PricingRulesRepository::export_json();
			if ( '' !== $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- operación explícita de CLI local.
				if ( false === file_put_contents( $file, $json . "\n" ) ) {
					\WP_CLI::error( sprintf( 'No se pudo escribir el archivo %s', $file ) );
					return;
				}
				\WP_CLI::success( sprintf( 'Reglas exportadas a %s', $file ) );
				return;
			}
			\WP_CLI::line( $json );
			return;
		}

		if ( 'import' === $sub ) {
			if ( '' !== $file ) {
				if ( ! is_readable( $file ) ) {
					\WP_CLI::error( sprintf( 'El archivo %s no existe o no es legible.', $file ) );
					return;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lectura explícita de archivo CLI.
				$json = (string) file_get_contents( $file );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lectura desde STDIN en CLI.
				$json = (string) file_get_contents( 'php://stdin' );
			}

			$res = PricingRulesRepository::import_json( $json );
			if ( ! $res['ok'] ) {
				\WP_CLI::error( $res['message'] );
				return;
			}
			\WP_CLI::success( $res['message'] );
			return;
		}

		\WP_CLI::error( 'Uso: wp akb-pricing rules <export|import> [--file=<path>]' );
	}

	/**
	 * Muestra la auditoría de productos comprometidos en órdenes activas (Consulta 1.3).
	 *
	 * @return void
	 */
	public function cmd_audit_orders(): void {
		$rows = OrderLockGuard::get_locked_orders_audit();
		if ( empty( $rows ) ) {
			\WP_CLI::log( 'No hay productos comprometidos en órdenes abiertas actualmente.' );
			return;
		}

		\WP_CLI\Utils\format_items(
			'table',
			$rows,
			array( 'order_id', 'order_status', 'product_id', 'order_item_name', 'qty_comprada', 'precio_unitario_pagado_iva', 'era_preventa_al_comprar', 'estado_actual_catalogo', 'stock_actual_catalogo' )
		);
	}

	/**
	 * Muestra las series con discrepancias de escalas dentro de la misma editorial.
	 *
	 * @return void
	 */
	public function cmd_audit_series(): void {
		$rows = ScaleScanner::scan_series_discrepancies();
		if ( empty( $rows ) ) {
			\WP_CLI::success( '0 discrepancias de escala por serie detectadas.' );
			return;
		}

		$table = array();
		foreach ( $rows as $r ) {
			$scales_str = array();
			foreach ( $r['scales'] as $price => $cnt ) {
				$scales_str[] = sprintf( '$%s (%d)', number_format( (int) $price, 0, ',', '.' ), $cnt );
			}
			$table[] = array(
				'SerieID'         => $r['serie_id'],
				'Serie'           => $r['serie_name'],
				'Editorial'       => $r['editorial'],
				'Escalas'         => implode( ' / ', $scales_str ),
				'EscalaDominante' => $r['dominant_scale'],
				'TotalTomos'      => $r['total_tomos'],
				'Protegidos'      => $r['locked_count'],
			);
		}

		\WP_CLI\Utils\format_items(
			'table',
			$table,
			array( 'SerieID', 'Serie', 'Editorial', 'Escalas', 'EscalaDominante', 'TotalTomos', 'Protegidos' )
		);
	}
}
