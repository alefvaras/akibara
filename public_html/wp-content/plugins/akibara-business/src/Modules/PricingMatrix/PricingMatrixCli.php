<?php
/**
 * Comandos WP-CLI para Akibara Pricing Matrix (`wp akb-pricing`).
 *
 * Subcomandos soportados:
 *   - wp akb-pricing self-test
 *   - wp akb-pricing scales
 *   - wp akb-pricing rules <export|import> [--file=<path>]
 *   - wp akb-pricing audit-series
 *   - wp akb-pricing level-serie
 *
 * @package Akibara\Business\Modules\PricingMatrix
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\PricingMatrix;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 * @psalm-suppress InvalidArrayOffset
 * @psalm-suppress InvalidGlobal
 */
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
		\WP_CLI::add_command( 'akb-pricing rules', array( $this, 'cmd_rules' ) );
		\WP_CLI::add_command( 'akb-pricing audit-series', array( $this, 'cmd_audit_series' ) );
		\WP_CLI::add_command( 'akb-pricing level-serie', array( $this, 'cmd_level_serie' ) );
	}

	/**
	 * Ejecuta la suite de pruebas automatizadas (`wp akb-pricing self-test`).
	 *
	 * @return void
	 */
	public function cmd_self_test(): void {
		\WP_CLI::log( '=== AKIBARA PRICING MATRIX — SELF-TEST SUITE ===' );
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

		if ( ! empty( $report['error'] ) ) {
			\WP_CLI::error( $report['error'] ); // @phpstan-ignore offsetAccess.notFound
			return;
		}

		if ( empty( $report['ok'] ) ) {
			\WP_CLI::error(
				sprintf(
					'Suite en FAIL: %d de %d tests aprobados. Hay regresiones o presupuestos excedidos.',
					$report['passed_count'],
					$report['total_count']
				)
			);
			return;
		}

		\WP_CLI::success(
			sprintf(
				'%d/%d tests en PASS. Integridad matemática, blindaje de órdenes, buscador y rendimiento certificados.',
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
				'Agotados'      => $r['agotados'],
				'NuevoRegular'  => $r['nuevo_precio_regular'],
			);
		}

		\WP_CLI\Utils\format_items(
			'table',
			$table,
			array( 'Editorial', 'Pais', 'Regular', 'Tomos', 'InStock', 'Agotados', 'NuevoRegular' )
		);
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
				'Dominante'       => '$' . number_format( (int) $r['dominant_scale'], 0, ',', '.' ),
				'Promedio'        => '$' . number_format( (int) ( $r['average_price'] ?? $r['dominant_scale'] ), 0, ',', '.' ),
				'TotalTomos'      => $r['total_tomos'],
			);
		}

		\WP_CLI\Utils\format_items(
			'table',
			$table,
			array( 'SerieID', 'Serie', 'Editorial', 'Escalas', 'Dominante', 'Promedio', 'TotalTomos' )
		);
	}

	/**
	 * Nivela el precio regular de los tomos estándar de una serie respetando el blindaje de órdenes.
	 *
	 * @param array<int, string>    $args       Argumentos posicionales.
	 * @param array<string, string> $assoc_args Flags (`--serie-id=<id>`, `--target-regular=<precio>`, `--brand-id=<id>`, `--dry-run`).
	 * @return void
	 */
	public function cmd_level_serie( array $args = array(), array $assoc_args = array() ): void {
		unset( $args );
		$serie_id       = isset( $assoc_args['serie-id'] ) ? (int) $assoc_args['serie-id'] : 0;
		$target_regular = isset( $assoc_args['target-regular'] ) ? (int) $assoc_args['target-regular'] : 0;
		$brand_id       = isset( $assoc_args['brand-id'] ) ? (int) $assoc_args['brand-id'] : 0;
		$dry_run        = isset( $assoc_args['dry-run'] );
		$protect_orders = true; // Blindaje obligatorio sin bypass

		if ( $serie_id <= 0 || $target_regular <= 0 ) {
			\WP_CLI::error( 'Uso: wp akb-pricing level-serie --serie-id=<id> --target-regular=<precio> [--brand-id=<id>] [--dry-run]' );
			return;
		}

		if ( $brand_id <= 0 ) {
			global $wpdb;
			$brand_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT tt_b.term_id
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->term_relationships} tr_s ON p.ID = tr_s.object_id
					INNER JOIN {$wpdb->term_taxonomy} tt_s ON tr_s.term_taxonomy_id = tt_s.term_taxonomy_id AND tt_s.taxonomy = 'pa_serie' AND tt_s.term_id = %d
					INNER JOIN {$wpdb->term_relationships} tr_b ON p.ID = tr_b.object_id
					INNER JOIN {$wpdb->term_taxonomy} tt_b ON tr_b.term_taxonomy_id = tt_b.term_taxonomy_id AND tt_b.taxonomy = 'product_brand'
					LIMIT 1",
					$serie_id
				)
			);
		}

		$summary = PricingSynchronizer::level_series_scale( $serie_id, $brand_id, $target_regular, $protect_orders, $dry_run );

		if ( ! $summary['ok'] ) {
			\WP_CLI::error( $summary['message'] ?? 'Falló la nivelación de serie.' );
			return;
		}

		\WP_CLI::log(
			sprintf(
				'Serie #%d nivelada a $%s (%s): %d tomos actualizados',
				$serie_id,
				number_format( $target_regular, 0, ',', '.' ),
				$dry_run ? 'DRY-RUN' : 'APLICADO',
				$summary['updated_count']
			)
		);

		\WP_CLI::success( $dry_run ? 'Simulación completada.' : 'Nivelación de serie completada.' );
	}
}
