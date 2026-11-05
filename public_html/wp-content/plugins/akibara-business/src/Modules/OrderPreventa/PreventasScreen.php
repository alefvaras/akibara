<?php
/**
 * PreventasScreen — Centro Operativo de Por Llegar, Recepción y Consolidación.
 *
 * Permite al administrador operar el ciclo completo de tomos en espera:
 *   - Ver la lista de tomos requeridos con unidades exactas (unidades, no solo pedidos).
 *   - Recibir mercadería en bodega y apartarla físicamente en casillero por FIFO
 *     (_akb_item_arrived = 'yes'), sin abrir stock público en la tienda web para
 *     evitar sobreventas.
 *   - Publicar excedentes a stock libre en un solo clic.
 *   - Vista de casilleros por pedido para consolidación de pedidos mixtos.
 *   - Cambiar fechas de lanzamiento editorial y exportar planillas CSV para distribuidoras.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Utilities\StoreTime;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.114.5
 * @since 1.117.0 Evolución a Centro Operativo con recepción FIFO y casilleros de pedido.
 */
class PreventasScreen {

	/** Acción de admin-post que fuerza una revisión. */
	public const ACTION_REVISAR = 'akb_preventa_revisar';

	/** Acción que cambia la fecha de todas las esperas de un tomo. */
	public const ACTION_FECHA = 'akb_preventa_fecha';

	/** Acción que registra la recepción física de un tomo y aparta por FIFO. */
	public const ACTION_RECIBIR = 'akb_preventa_recibir';

	/** Acción que sella los tomos en stock del casillero de un pedido. */
	public const ACTION_APARTAR_PEDIDO = 'akb_preventa_apartar_pedido';

	/** Acción que deshace el sellado físico de una línea de pedido. */
	public const ACTION_DESAPARTAR_ITEM = 'akb_preventa_desapartar_item';

	/** Acción que descarga la planilla CSV para compras a distribuidora. */
	public const ACTION_CSV = 'akb_preventa_csv';

	/** Capacidad exigida: la misma que para ver el panel. */
	private const CAP = 'manage_woocommerce';

	private const PANEL_PAGE = 'akibara-panel';
	private const PANEL_TAB  = 'preventas';

	/** Cuántos tomos se listan por página. */
	private const MAX_TOMOS = 60;

	/** Cuántos casilleros de pedido se listan por página. */
	private const MAX_PEDIDOS = 20;

	/** @var ReadyToShipCron */
	private ReadyToShipCron $cron;

	/**
	 * @param ReadyToShipCron $cron Recalculador de «listo para despachar».
	 */
	public function __construct( ReadyToShipCron $cron ) {
		$this->cron = $cron;
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_REVISAR, array( $this, 'handle_revisar' ) );
		add_action( 'admin_post_' . self::ACTION_FECHA, array( $this, 'handle_fecha' ) );
		add_action( 'admin_post_' . self::ACTION_RECIBIR, array( $this, 'handle_recibir' ) );
		add_action( 'admin_post_' . self::ACTION_APARTAR_PEDIDO, array( $this, 'handle_apartar_pedido' ) );
		add_action( 'admin_post_' . self::ACTION_DESAPARTAR_ITEM, array( $this, 'handle_desapartar_item' ) );
		add_action( 'admin_post_' . self::ACTION_CSV, array( $this, 'handle_csv' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	// ─────────────────────────────────────────────────────────── la pantalla

	/**
	 * Carga y renderiza una plantilla del panel de preventas con ámbito aislado.
	 *
	 * @param string               $template_name Nombre del archivo PHP en templates/admin/preventas/.
	 * @param array<string, mixed> $view_data     Variables pasadas a la plantilla.
	 * @return void
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $view_data se consume dentro del template incluido en el scope local.
	private function render_template( string $template_name, array $view_data = array() ): void {
		$template_path = dirname( __DIR__, 3 ) . '/templates/admin/preventas/' . $template_name;
		if ( is_readable( $template_path ) ) {
			include $template_path;
		}
	}

	/**
	 * Despacha acciones de sub-vista si están presentes en la petición.
	 *
	 * @return bool True si se despachó y renderizó una sub-vista, false para continuar al panel general.
	 */
	private function despachar_accion_subvista(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$accion = isset( $_GET['accion'] ) ? sanitize_key( wp_unslash( (string) $_GET['accion'] ) ) : '';
		if ( '' === $accion ) {
			return false;
		}

		if ( 'fecha' === $accion ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$product = wc_get_product( isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0 );
			if ( self::en_catalogo( $product ) ) {
				$this->render_form_fecha( $product );
				return true;
			}
			echo '<div class="notice notice-error inline"><p>' . esc_html(
				$product instanceof \WC_Product
					? 'Este tomo está en la papelera: restáuralo antes de ponerle fecha, o reembolsa esa línea.'
					: 'Ese tomo ya no existe en el catálogo.'
			) . '</p></div>';
			return false;
		}

		if ( 'recibir' === $accion ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$product = wc_get_product( isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0 );
			if ( self::en_catalogo( $product ) ) {
				$this->render_form_recibir( $product );
				return true;
			}
			echo '<div class="notice notice-error inline"><p>' . esc_html(
				$product instanceof \WC_Product
					? 'Este tomo está en la papelera: restáuralo antes de recibir mercadería, o reembolsa esa línea.'
					: 'Ese tomo ya no existe en el catálogo.'
			) . '</p></div>';
			return false;
		}

		return false;
	}

	/**
	 * Calcula métricas agregadas de tomos en espera para KPIs y encabezado.
	 *
	 * @param array<int, array<string, mixed>> $completa Lista completa de tomos con pedidos.
	 * @return array<string, mixed> Resumen con totales por categoría.
	 */
	private function calcular_metricas_resumen( array $completa ): array {
		$ids_pedidos    = array();
		$fecha_pasada   = 0;
		$total_unidades = 0;
		$por_editorial  = array();
		$agotados_count = 0;

		foreach ( $completa as $t ) {
			$unidades        = (int) ( $t['unidades_pendientes'] ?? count( $t['pedidos'] ) );
			$total_unidades += $unidades;
			foreach ( $t['pedidos'] as $p ) {
				$ids_pedidos[ (int) $p['id'] ] = true;
			}
			if ( ! empty( $t['fecha_pasada'] ) || ! empty( $t['promesa_vencida'] ) ) {
				++$fecha_pasada;
			}
			if ( ! empty( $t['es_agotado'] ) ) {
				++$agotados_count;
			}
			$ed = ! empty( $t['editorial'] ) ? (string) $t['editorial'] : '';
			if ( '' !== $ed ) {
				$por_editorial[ $ed ] = ( $por_editorial[ $ed ] ?? 0 ) + $unidades;
			}
		}

		return array(
			'total_pedidos'  => count( $ids_pedidos ),
			'total_unidades' => $total_unidades,
			'fecha_pasada'   => $fecha_pasada,
			'agotados_count' => $agotados_count,
			'por_editorial'  => $por_editorial,
		);
	}

	/**
	 * Filtra la lista de tomos en memoria según los criterios de búsqueda y filtros.
	 *
	 * @param array<int, array<string, mixed>> $completa      Lista completa de tomos.
	 * @param string                           $search_q      Término de búsqueda.
	 * @param string                           $filtro_sel    Clave de filtro seleccionado.
	 * @param string                           $editorial_sel Clave de editorial seleccionada.
	 * @return array<int, array<string, mixed>> Lista filtrada.
	 */
	private function filtrar_esperas( array $completa, string $search_q, string $filtro_sel, string $editorial_sel ): array {
		if ( '' === $search_q && 'todos' === $filtro_sel && '' === $editorial_sel ) {
			return $completa;
		}

		return array_filter(
			$completa,
			static function ( array $t ) use ( $search_q, $filtro_sel, $editorial_sel ): bool {
				if ( '' !== $editorial_sel && ( $t['editorial'] ?? '' ) !== $editorial_sel ) {
					return false;
				}
				if ( 'agotados' === $filtro_sel && empty( $t['es_agotado'] ) ) {
					return false;
				}
				if ( 'urgentes' === $filtro_sel && empty( $t['urgente'] ) ) {
					return false;
				}
				if ( 'preventa' === $filtro_sel && empty( $t['es_preventa'] ) ) {
					return false;
				}
				if ( 'a_pedido' === $filtro_sel && ( ! empty( $t['es_preventa'] ) || ! empty( $t['es_agotado'] ) ) ) {
					return false;
				}
				if ( '' !== $search_q ) {
					$haystack = mb_strtolower( (string) ( $t['nombre'] ?? '' ) . ' ' . (string) ( $t['sku'] ?? '' ) );
					foreach ( $t['pedidos'] as $p ) {
						$haystack .= ' #' . (int) $p['id'] . ' ' . mb_strtolower( (string) $p['cliente'] );
					}
					if ( ! str_contains( $haystack, mb_strtolower( $search_q ) ) ) {
						return false;
					}
				}
				return true;
			}
		);
	}

	/**
	 * Pinta la sección del panel.
	 *
	 * @return void
	 */
	public function render_admin_section(): void {
		if ( $this->despachar_accion_subvista() ) {
			return;
		}

		$completa = $this->esperas_por_tomo();
		$metricas = $this->calcular_metricas_resumen( $completa );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$vista = isset( $_GET['vista'] ) && 'pedidos' === sanitize_key( wp_unslash( (string) $_GET['vista'] ) ) ? 'pedidos' : 'tomos';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search_q      = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filtro_sel    = isset( $_GET['filtro'] ) ? sanitize_key( wp_unslash( (string) $_GET['filtro'] ) ) : 'todos';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editorial_sel = isset( $_GET['editorial'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['editorial'] ) ) : '';

		$filtrados       = $this->filtrar_esperas( $completa, $search_q, $filtro_sel, $editorial_sel );
		$total_filtrados = count( $filtrados );
		$max_pages       = max( 1, (int) ceil( $total_filtrados / self::MAX_TOMOS ) );
		$tomos           = array_slice( $filtrados, ( $paged - 1 ) * self::MAX_TOMOS, self::MAX_TOMOS );

		$base_url = add_query_arg(
			array_filter(
				array(
					'page'      => self::PANEL_PAGE,
					'tab'       => self::PANEL_TAB,
					'vista'     => 'tomos' !== $vista ? $vista : null,
					'q'         => '' !== $search_q ? $search_q : null,
					'filtro'    => 'todos' !== $filtro_sel ? $filtro_sel : null,
					'editorial' => '' !== $editorial_sel ? $editorial_sel : null,
				)
			),
			admin_url( 'admin.php' )
		);

		$this->render_template(
			'vista-tomos.php',
			array(
				'completa'        => $completa,
				'total_pedidos'   => $metricas['total_pedidos'],
				'total_unidades'  => $metricas['total_unidades'],
				'fecha_pasada'    => $metricas['fecha_pasada'],
				'agotados_count'  => $metricas['agotados_count'],
				'por_editorial'   => $metricas['por_editorial'],
				'vista'           => $vista,
				'paged'           => $paged,
				'search_q'        => $search_q,
				'filtro_sel'      => $filtro_sel,
				'editorial_sel'   => $editorial_sel,
				'tomos'           => $tomos,
				'total_filtrados' => $total_filtrados,
				'max_pages'       => $max_pages,
				'base_url'        => $base_url,
				'max_tomos'       => self::MAX_TOMOS,
			)
		);
	}

	/**
	 * Filtra los pedidos en proceso de consolidación en casillero.
	 *
	 * @param array<int, WC_Order> $todos_pedidos Lista de pedidos activos.
	 * @param string               $search_q      Búsqueda por # de pedido o cliente.
	 * @param string               $filtro_pedido Filtro por estado operativo.
	 * @return array<int, WC_Order> Pedidos filtrados.
	 */
	private function filtrar_pedidos_casillero( array $todos_pedidos, string $search_q, string $filtro_pedido ): array {
		$pedidos_filtrados = array();
		$hoy               = ( new \DateTimeImmutable( 'now', StoreTime::zone() ) )->setTime( 0, 0, 0 )->getTimestamp();

		foreach ( $todos_pedidos as $order ) {
			$oid      = $order->get_id();
			$cliente  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			$desglose = self::desglose_casillero_orden( $order );

			if ( '' !== $search_q ) {
				$q_clean    = ltrim( $search_q, '#' );
				$match_id   = (string) $oid === $q_clean;
				$match_name = false !== mb_stripos( $cliente, $search_q );
				if ( ! $match_id && ! $match_name ) {
					continue;
				}
			}

			if ( 'por_sellar' === $filtro_pedido && ! $desglose['hay_sin_sellar'] ) {
				continue;
			}

			if ( 'con_agotados' === $filtro_pedido ) {
				$tiene_agotado = false;
				foreach ( $desglose['pendientes'] as $p_item ) {
					$p_prod = $p_item->get_product();
					if ( ! $p_prod || StockStatus::OUT_OF_STOCK === Availability::of( $p_prod ) ) {
						$tiene_agotado = true;
						break;
					}
				}
				if ( ! $tiene_agotado ) {
					continue;
				}
			}

			if ( 'vencidos' === $filtro_pedido ) {
				$promesa_ts = SummaryRecalculator::current_max_ts( $order );
				if ( null === $promesa_ts || $promesa_ts >= $hoy ) {
					continue;
				}
			}

			if ( 'listos' === $filtro_pedido && $desglose['faltan_count'] > 0 ) {
				continue;
			}

			$pedidos_filtrados[] = $order;
		}

		return $pedidos_filtrados;
	}

	/**
	 * Vista por Casillero / Pedido: Permite consolidar pedidos mixtos.
	 *
	 * @return void
	 */
	private function render_vista_pedidos(): void {
		$todos_pedidos = $this->pedidos_activos_con_espera();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search_q      = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filtro_pedido = isset( $_GET['filtro_pedido'] ) ? sanitize_key( wp_unslash( (string) $_GET['filtro_pedido'] ) ) : 'todos';

		$pedidos_filtrados = $this->filtrar_pedidos_casillero( $todos_pedidos, $search_q, $filtro_pedido );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged         = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$total_pedidos = count( $pedidos_filtrados );
		$max_pages     = max( 1, (int) ceil( $total_pedidos / self::MAX_PEDIDOS ) );
		$pedidos       = array_slice( $pedidos_filtrados, ( $paged - 1 ) * self::MAX_PEDIDOS, self::MAX_PEDIDOS );

		$base_url = add_query_arg(
			array_filter(
				array(
					'page'          => self::PANEL_PAGE,
					'tab'           => self::PANEL_TAB,
					'vista'         => 'pedidos',
					'q'             => '' !== $search_q ? $search_q : null,
					'filtro_pedido' => 'todos' !== $filtro_pedido ? $filtro_pedido : null,
				)
			),
			admin_url( 'admin.php' )
		);

		$this->render_template(
			'vista-pedidos.php',
			array(
				'search_q'          => $search_q,
				'filtro_pedido'     => $filtro_pedido,
				'pedidos_filtrados' => $pedidos_filtrados,
				'total_pedidos'     => $total_pedidos,
				'pedidos'           => $pedidos,
				'paged'             => $paged,
				'max_pages'         => $max_pages,
				'base_url'          => $base_url,
				'panel_page'        => self::PANEL_PAGE,
				'panel_tab'         => self::PANEL_TAB,
			)
		);
	}

	/**
	 * Tarjetas de resumen de KPIs operativas.
	 *
	 * @param int                $total_tomos    Total de tomos requeridos.
	 * @param int                $total_unidades Total de unidades físicas a recibir.
	 * @param int                $total_pedidos  Total de pedidos únicos en espera.
	 * @param array<string, int> $por_editorial  Unidades acumuladas por editorial.
	 * @param int                $agotados_count Tomos marcados como agotados en tienda.
	 * @param int                $fecha_pasada   Tomos con fecha de salida o promesa vencida.
	 * @return void
	 */
	private function render_kpis_resumen(
		int $total_tomos,
		int $total_unidades,
		int $total_pedidos,
		array $por_editorial,
		int $agotados_count,
		int $fecha_pasada
	): void {
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:12px 0 16px;">';

		// Tarjeta 1: Total requerido
		echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:12px 16px;box-shadow:0 1px 1px rgba(0,0,0,.04);">';
		echo '<div style="font-size:11px;font-weight:600;text-transform:uppercase;color:#646970;margin-bottom:4px;">📚 Total Requerido</div>';
		printf(
			'<div style="font-size:18px;font-weight:600;color:#1d2327;">%d <span style="font-size:13px;font-weight:normal;color:#646970;">%s</span></div>',
			(int) $total_tomos,
			1 === $total_tomos ? 'tomo distinto' : 'tomos distintos'
		);
		printf(
			'<div style="font-size:13px;color:#2271b1;margin-top:2px;"><strong>%d unidades físicas</strong> a recibir</div>',
			(int) $total_unidades
		);
		echo '</div>';

		// Tarjeta 2: Casilleros de clientes
		echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:12px 16px;box-shadow:0 1px 1px rgba(0,0,0,.04);">';
		echo '<div style="font-size:11px;font-weight:600;text-transform:uppercase;color:#646970;margin-bottom:4px;">🗂️ Casilleros de Clientes</div>';
		printf(
			'<div style="font-size:18px;font-weight:600;color:#1d2327;">%d <span style="font-size:13px;font-weight:normal;color:#646970;">%s</span></div>',
			(int) $total_pedidos,
			1 === $total_pedidos ? 'pedido esperando' : 'pedidos esperando'
		);
		printf(
			'<div style="margin-top:4px;"><a href="%s" style="font-size:12px;text-decoration:none;font-weight:600;">Ver consolidación »</a></div>',
			esc_url( self::url_vista( 'pedidos' ) )
		);
		echo '</div>';

		// Tarjeta 3: Por Editorial (clic para filtrar)
		echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:12px 16px;box-shadow:0 1px 1px rgba(0,0,0,.04);">';
		echo '<div style="font-size:11px;font-weight:600;text-transform:uppercase;color:#646970;margin-bottom:6px;">🏢 Por Editorial (compras)</div>';
		if ( empty( $por_editorial ) ) {
			echo '<span style="font-size:12px;color:#666;">Sin editorial asignada</span>';
		} else {
			echo '<div style="display:flex;flex-wrap:wrap;gap:4px;max-height:64px;overflow-y:auto;">';
			foreach ( $por_editorial as $ed => $uds ) {
				$url_ed = add_query_arg( 'editorial', rawurlencode( (string) $ed ), self::url_vista( 'tomos' ) );
				printf(
					'<a href="%s" style="font-size:11px;background:#f0f0f1;padding:2px 8px;border-radius:12px;text-decoration:none;color:#1d2327;border:1px solid #dcdcde;">%s <strong>(%d)</strong></a>',
					esc_url( $url_ed ),
					esc_html( (string) $ed ),
					(int) $uds
				);
			}
			echo '</div>';
		}
		echo '</div>';

		// Tarjeta 4: Alertas operativas
		echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:12px 16px;box-shadow:0 1px 1px rgba(0,0,0,.04);">';
		echo '<div style="font-size:11px;font-weight:600;text-transform:uppercase;color:#646970;margin-bottom:6px;">🚨 Alertas Operativas</div>';
		echo '<div style="display:flex;flex-direction:column;gap:4px;font-size:12px;">';
		$url_agotados = add_query_arg( 'filtro', 'agotados', self::url_vista( 'tomos' ) );
		printf(
			'<a href="%s" style="color:%s;text-decoration:none;font-weight:%s;">⚠️ %d agotados en tienda</a>',
			esc_url( $url_agotados ),
			$agotados_count > 0 ? '#b32d2e' : '#646970',
			$agotados_count > 0 ? '600' : 'normal',
			(int) $agotados_count
		);
		$url_urgentes = add_query_arg( 'filtro', 'urgentes', self::url_vista( 'tomos' ) );
		printf(
			'<a href="%s" style="color:%s;text-decoration:none;font-weight:%s;">⏳ %d vencidos / urgentes</a>',
			esc_url( $url_urgentes ),
			$fecha_pasada > 0 ? '#b32d2e' : '#646970',
			$fecha_pasada > 0 ? '600' : 'normal',
			(int) $fecha_pasada
		);
		echo '</div>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Barra de herramientas: Búsqueda, filtros por estado / editorial y exportación CSV.
	 *
	 * @param string             $search_q      Término de búsqueda actual.
	 * @param string             $filtro_sel    Filtro de estado seleccionado.
	 * @param string             $editorial_sel Editorial seleccionada.
	 * @param array<string, int> $por_editorial Editoriales disponibles con conteo de unidades.
	 * @return void
	 */
	private function render_barra_filtros_tomos(
		string $search_q,
		string $filtro_sel,
		string $editorial_sel,
		array $por_editorial
	): void {
		echo '<div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;background:#fff;border:1px solid #ccd0d4;padding:10px 14px;margin-bottom:14px;border-radius:3px;">';
		printf( '<form method="get" action="%s" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">', esc_url( admin_url( 'admin.php' ) ) );
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::PANEL_PAGE ) );
		printf( '<input type="hidden" name="tab" value="%s">', esc_attr( self::PANEL_TAB ) );
		printf( '<input type="hidden" name="vista" value="tomos">' );

		printf( '<input type="search" name="q" value="%s" placeholder="Buscar tomo, SKU, #pedido..." style="width:200px;">', esc_attr( $search_q ) );

		echo '<select name="filtro">';
		printf( '<option value="todos"%s>Todos los estados</option>', 'todos' === $filtro_sel ? ' selected' : '' );
		printf( '<option value="a_pedido"%s>Solo A pedido</option>', 'a_pedido' === $filtro_sel ? ' selected' : '' );
		printf( '<option value="preventa"%s>Solo Preventas</option>', 'preventa' === $filtro_sel ? ' selected' : '' );
		printf( '<option value="agotados"%s>Agotados en tienda</option>', 'agotados' === $filtro_sel ? ' selected' : '' );
		printf( '<option value="urgentes"%s>Urgentes / Vencidos</option>', 'urgentes' === $filtro_sel ? ' selected' : '' );
		echo '</select>';

		if ( ! empty( $por_editorial ) ) {
			echo '<select name="editorial">';
			echo '<option value="">Todas las editoriales</option>';
			foreach ( $por_editorial as $ed => $cant ) {
				printf(
					'<option value="%s"%s>%s (%d uds)</option>',
					esc_attr( $ed ),
					$editorial_sel === $ed ? ' selected' : '',
					esc_html( $ed ),
					(int) $cant
				);
			}
			echo '</select>';
		}

		echo '<button type="submit" class="button">Filtrar</button>';
		if ( '' !== $search_q || 'todos' !== $filtro_sel || '' !== $editorial_sel ) {
			printf( '<a class="button" href="%s">Limpiar</a>', esc_url( self::url_vista( 'tomos' ) ) );
		}
		echo '</form>';

		printf(
			'<a class="button" href="%s" style="margin-top:4px;">📥 Exportar CSV para Proveedor</a>',
			esc_url( self::url_csv() )
		);
		echo '</div>';
	}

	/**
	 * Controles de paginación estándar de WordPress.
	 *
	 * @param int    $total_items Total de elementos filtrados.
	 * @param int    $paged       Página actual.
	 * @param int    $max_pages   Número máximo de páginas.
	 * @param string $base_url    URL base con parámetros preservados.
	 * @param string $label_item  Etiqueta plural/singular para el total.
	 * @return void
	 */
	private function render_paginacion(
		int $total_items,
		int $paged,
		int $max_pages,
		string $base_url,
		string $label_item
	): void {
		if ( $max_pages <= 1 ) {
			return;
		}

		echo '<div class="tablenav" style="margin-top:12px;"><div class="tablenav-pages">';
		printf( '<span class="displaying-num">%d %s</span> ', (int) $total_items, esc_html( $label_item ) );
		if ( $paged > 1 ) {
			printf( '<a class="prev-page button" href="%s">« Anterior</a> ', esc_url( add_query_arg( 'paged', $paged - 1, $base_url ) ) );
		}
		printf( '<span class="paging-input">Página %d de %d</span> ', (int) $paged, (int) $max_pages );
		if ( $paged < $max_pages ) {
			printf( '<a class="next-page button" href="%s">Siguiente »</a>', esc_url( add_query_arg( 'paged', $paged + 1, $base_url ) ) );
		}
		echo '</div></div>';
	}

	/**
	 * Renderiza la tarjeta de consolidación de casillero para un pedido individual.
	 *
	 * @param WC_Order $order Pedido.
	 * @param int      $ahora Timestamp actual.
	 * @return void
	 */
	private function render_tarjeta_casillero( WC_Order $order, int $ahora ): void {
		$oid      = $order->get_id();
		$creado   = $order->get_date_created();
		$dias     = null !== $creado ? (int) floor( ( $ahora - $creado->getTimestamp() ) / DAY_IN_SECONDS ) : 0;
		$cliente  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$url      = $order->get_edit_order_url();
		$wa_url   = self::url_whatsapp_pedido( $order );

		$desglose                = self::desglose_casillero_orden( $order );
		$listos                  = $desglose['listos'];
		$pendientes              = $desglose['pendientes'];
		$listos_count            = $desglose['listos_count'];
		$total_count             = $desglose['total_count'];
		$faltan_count            = $desglose['faltan_count'];
		$hay_en_stock_sin_sellar = $desglose['hay_sin_sellar'];

		$promesa     = SummaryRecalculator::current_max_ts( $order );
		$promesa_txt = null !== $promesa ? wp_date( 'j M Y', $promesa ) : 'Sin fecha fija';

		$this->render_template(
			'tarjeta-casillero.php',
			array(
				'order'                   => $order,
				'oid'                     => $oid,
				'creado'                  => $creado,
				'dias'                    => $dias,
				'cliente'                 => $cliente,
				'url'                     => $url,
				'wa_url'                  => $wa_url,
				'listos'                  => $listos,
				'pendientes'              => $pendientes,
				'listos_count'            => $listos_count,
				'total_count'             => $total_count,
				'faltan_count'            => $faltan_count,
				'hay_en_stock_sin_sellar' => $hay_en_stock_sin_sellar,
				'promesa_txt'             => $promesa_txt,
			)
		);
	}

	/**
	 * Una fila: un tomo y todos los pedidos que lo esperan.
	 *
	 * @param array<string, mixed> $t Datos del tomo.
	 * @return void
	 */
	private function render_fila( array $t ): void {
		$editar = get_edit_post_link( (int) $t['product_id'] );

		echo '<tr>';

		echo '<td data-label="Tomo">';
		echo $editar
			? '<a href="' . esc_url( $editar ) . '"><strong>' . esc_html( $t['nombre'] ) . '</strong></a>'
			: '<strong>' . esc_html( $t['nombre'] ) . '</strong>';

		$meta_badges = array();
		if ( ! empty( $t['editorial'] ) ) {
			$meta_badges[] = esc_html( $t['editorial'] );
		}
		if ( ! empty( $t['pais'] ) ) {
			$meta_badges[] = esc_html( $t['pais'] );
		}
		if ( ! empty( $t['sku'] ) ) {
			$meta_badges[] = 'SKU: ' . esc_html( $t['sku'] );
		}
		if ( ! empty( $meta_badges ) ) {
			echo '<br><span style="color:#666;font-size:11px;">' . implode( ' · ', $meta_badges ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</td>';

		printf(
			'<td data-label="Stock"><span style="color:%s;font-weight:600;">%s</span></td>',
			esc_attr( $t['stock_color'] ),
			esc_html( $t['stock_label'] )
		);

		printf(
			'<td data-label="Plazo / Lanzamiento"><span style="color:%s;">%s</span></td>',
			esc_attr( $t['urgente'] ? '#b32d2e' : '#666' ),
			esc_html( $t['fecha_label'] )
		);

		$unidades = (int) ( $t['unidades_pendientes'] ?? count( $t['pedidos'] ) );
		echo '<td data-label="Pedidos">';
		printf( '<strong>%d</strong>', count( $t['pedidos'] ) );
		if ( $unidades > 0 ) {
			printf( '<br><span style="color:#666;font-size:11px;">(%d %s)</span>', (int) $unidades, 1 === $unidades ? 'ud' : 'uds' );
		}
		echo '</td>';

		echo '<td data-label="Quién espera">';
		$enlaces = array();
		foreach ( array_slice( $t['pedidos'], 0, 6 ) as $p ) {
			$progreso = '';
			if ( ! empty( $p['totales'] ) && $p['totales'] > 1 ) {
				$progreso = sprintf( ' [%d/%d]', (int) $p['listos'], (int) $p['totales'] );
			}
			$enlaces[] = sprintf(
				'<a href="%s" title="%s">#%d</a><span style="color:#666;"> · %dd%s</span>',
				esc_url( (string) $p['url'] ),
				esc_attr( (string) $p['cliente'] ),
				(int) $p['id'],
				(int) $p['dias'],
				esc_html( $progreso )
			);
		}
		echo wp_kses_post( implode( ' &nbsp; ', $enlaces ) );
		if ( count( $t['pedidos'] ) > 6 ) {
			printf( ' <span style="color:#666;">y %d más</span>', count( $t['pedidos'] ) - 6 );
		}
		echo '</td>';

		echo '<td data-label="Acción">';
		if ( ! empty( $t['existe'] ) ) {
			printf(
				'<a class="button button-small button-primary" href="%s" style="margin-right:4px;">📦 Recibir</a>',
				esc_url( self::url_recibir( (int) $t['product_id'] ) )
			);
			printf(
				'<a class="button button-small" href="%s">Cambiar fecha</a>',
				esc_url( self::url_fecha( (int) $t['product_id'] ) )
			);
		} else {
			echo '<span style="color:#b32d2e;">Este tomo ya no está en el catálogo (borrado o en la papelera): restáuralo o reembolsa esa línea.</span>';
		}
		echo '</td>';

		echo '</tr>';
	}

	/**
	 * Los botones que no dependen de un tomo concreto.
	 *
	 * @return void
	 */
	private function render_acciones_globales(): void {
		$this->render_cumplimiento();

		echo '<hr style="margin:24px 0 16px;">';
		echo '<h3 style="margin:0 0 6px;">Revisar ahora</h3>';
		echo '<p style="color:#666;margin:0 0 10px;">Cuando repones un tomo desde WooCommerce (ficha, edición en lote o importación), el aviso sale solo en unos minutos. Además hay un repaso de respaldo una vez al día. Pulsa esto si cargaste el stock por otra vía o no quieres esperar al repaso.</p>';
		printf(
			'<p><a class="button" href="%s">Revisar los pedidos que esperan</a></p>',
			esc_url( self::url_revisar() )
		);
	}

	/**
	 * ¿Se están cumpliendo los plazos prometidos?
	 *
	 * @return void
	 */
	private function render_cumplimiento(): void {
		$r = null;
		if ( function_exists( 'get_transient' ) ) {
			$cached = get_transient( 'akb_preventa_cumplimiento_v1' );
			if ( is_array( $cached ) ) {
				$r = $cached;
			}
		}

		if ( null === $r ) {
			$audit = new PromiseAudit();
			$ahora = time();
			$vered = array();

			OrderQueryPaginator::process_all(
				array(
					'limit'      => 100,
					'type'       => 'shop_order',
					'status'     => 'any',
					'meta_key'   => MetaKeys::ORDER_HAS_PREVENTA,
					'meta_value' => 'yes',
					'orderby'    => 'date',
					'order'      => 'ASC',
				),
				static function ( WC_Order $order ) use ( $audit, $ahora, &$vered ): void {
					if ( ! PromiseWindow::auditable( $order ) ) {
						return;
					}
					$promesa = PromiseWindow::vigente( $order );
					if ( null === $promesa['pais'] ) {
						return;
					}
					$creado = $order->get_date_created();
					if ( null === $creado ) {
						return;
					}

					$despachable = (int) $order->get_meta( MetaKeys::ORDER_SHIPPABLE_AT );
					$del_cron    = (int) $order->get_meta( MetaKeys::ORDER_READY_DATE );
					$listo       = $despachable > 0 ? $despachable : $del_cron;

					$vered[] = $audit->veredicto(
						$creado->getTimestamp(),
						$promesa['min'],
						$promesa['max'],
						$listo > 0 ? $listo : null,
						$ahora
					);
				}
			);

			if ( array() === $vered ) {
				return;
			}

			$r = $audit->resumen( $vered );
			if ( function_exists( 'set_transient' ) ) {
				set_transient( 'akb_preventa_cumplimiento_v1', $r, 300 );
			}
		}

		echo '<hr style="margin:24px 0 16px;">';
		echo '<h3 style="margin:0 0 6px;">¿Estás cumpliendo los plazos?</h3>';

		$cerrados = (int) ( $r['cerrados'] ?? 0 );
		$dentro   = (int) ( $r['dentro'] ?? 0 ) + (int) ( $r['antes'] ?? 0 );
		$tarde    = (int) ( $r['tarde'] ?? 0 );
		$vencidos = (int) ( $r['vencidos'] ?? 0 );

		if ( $cerrados > 0 ) {
			printf(
				'<p>De los <strong>%d</strong> pedidos con espera que ya se cerraron, <strong style="color:#006505;">%d llegaron en plazo</strong> y <strong style="color:%s;">%d se pasaron</strong>.</p>',
				(int) $cerrados,
				(int) $dentro,
				esc_attr( $tarde > 0 ? '#b32d2e' : '#666' ),
				(int) $tarde
			);
		} else {
			echo '<p>Todavía no hay pedidos con espera cerrados con los que medir el cumplimiento.</p>';
		}

		if ( $vencidos > 0 ) {
			printf(
				'<p style="color:#b32d2e;"><strong>%d %s</strong> con la fecha prometida al cliente ya pasada y todavía sin cerrar.</p>',
				(int) $vencidos,
				esc_html( 1 === $vencidos ? 'pedido abierto' : 'pedidos abiertos' )
			);
		}

		echo '<p style="color:#666;font-size:12px;">El detalle pedido por pedido, con la fecha prometida y la real de cada uno, está en el informe completo: <code>wp akibara order-preventa-cumplimiento</code>. Las cifras de aquí y las de ahí salen de la misma regla.</p>';
	}

	// ────────────────────────────────────────────────────────────── los datos

	/**
	 * Las esperas vivas, agrupadas por tomo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function esperas_por_tomo(): array {
		$tomos = array();
		$ahora = time();

		OrderQueryPaginator::process_all(
			array(
				'limit'      => 200,
				'status'     => FulfillmentCapabilities::active_order_statuses(),
				'meta_key'   => MetaKeys::ORDER_HAS_PREVENTA,
				'meta_value' => 'yes',
				'return'     => 'objects',
				'orderby'    => 'date',
				'order'      => 'ASC',
			),
			function ( WC_Order $order ) use ( &$tomos, $ahora ): void {
				$this->acumular_esperas( $order, $tomos, $ahora );
			}
		);

		$tz  = StoreTime::zone();
		$hoy = ( new \DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0, 0 )->getTimestamp();

		// Refinar fecha_label con la promesa más próxima para productos sin fecha futura y ordenar FIFO por tomo
		foreach ( $tomos as &$t ) {
			if ( ! empty( $t['pedidos'] ) && count( $t['pedidos'] ) > 1 ) {
				usort(
					$t['pedidos'],
					static function ( array $pa, array $pb ): int {
						if ( (int) $pa['dias'] !== (int) $pb['dias'] ) {
							return (int) $pb['dias'] <=> (int) $pa['dias'];
						}
						return (int) $pa['id'] <=> (int) $pb['id'];
					}
				);
			}
			if ( ! empty( $t['existe'] ) && null === ( $t['ts_lanzamiento'] ?? null ) && isset( $t['min_promesa_max'] ) ) {
				$promesa_ts = (int) $t['min_promesa_max'];
				$vencida    = $promesa_ts < $hoy;
				$texto      = 'Promesa cliente: ~' . wp_date( 'j M Y', $promesa_ts, $tz );
				if ( $vencida ) {
					$texto               .= ' · vencida';
					$t['urgente']         = true;
					$t['promesa_vencida'] = true;
				}
				if ( ! empty( $t['es_agotado'] ) ) {
					$t['fecha_label'] = 'Agotado · ' . $texto;
				} else {
					$t['fecha_label'] = $texto;
				}
			}
		}
		unset( $t );

		// Ordenar: primero lo más urgente, y a igualdad, más unidades/pedidos
		uasort(
			$tomos,
			static function ( array $a, array $b ): int {
				if ( $a['urgente'] !== $b['urgente'] ) {
					return $a['urgente'] ? -1 : 1;
				}
				$u_a = (int) ( $a['unidades_pendientes'] ?? count( $a['pedidos'] ) );
				$u_b = (int) ( $b['unidades_pendientes'] ?? count( $b['pedidos'] ) );
				if ( $u_a !== $u_b ) {
					return $u_b <=> $u_a;
				}
				return count( $b['pedidos'] ) <=> count( $a['pedidos'] );
			}
		);

		return array_values( $tomos );
	}

	/**
	 * Suma a `$tomos` las líneas de un pedido que siguen sin llegar.
	 *
	 * @param WC_Order                         $order Pedido.
	 * @param array<int, array<string, mixed>> $tomos Acumulador por producto.
	 * @param int                              $ahora Reloj.
	 * @return void
	 */
	private function acumular_esperas( WC_Order $order, array &$tomos, int $ahora ): void {
		$desglose = null;
		$dias     = null;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			if ( ! PreventaItems::is_pending_item( $order, $item ) ) {
				continue;
			}

			$pid = PreventaItems::product_id_of( $item );
			if ( $pid <= 0 ) {
				continue;
			}

			$qty = PreventaItems::pending_qty( $order, $item );
			if ( $qty <= 0 ) {
				continue;
			}

			if ( ! isset( $tomos[ $pid ] ) ) {
				$tomos[ $pid ] = array(
					'product_id'          => $pid,
					'nombre'              => (string) $item->get_name(),
					'unidades_pendientes' => 0,
					'pedidos'             => array(),
				) + $this->estado_del_tomo( $pid );
			}

			$tomos[ $pid ]['unidades_pendientes'] += $qty;

			$promesa     = PreventaItems::promised_window( $item );
			$promesa_max = $promesa['max'];
			if ( null === $promesa_max ) {
				$promesa_max = SummaryRecalculator::current_max_ts( $order );
			}
			if ( null !== $promesa_max ) {
				if ( ! isset( $tomos[ $pid ]['min_promesa_max'] ) || $promesa_max < $tomos[ $pid ]['min_promesa_max'] ) {
					$tomos[ $pid ]['min_promesa_max'] = $promesa_max;
				}
			}

			if ( null === $dias ) {
				$created = $order->get_date_created();
				$dias    = null !== $created ? (int) floor( ( $ahora - $created->getTimestamp() ) / DAY_IN_SECONDS ) : 0;
			}

			if ( null === $desglose ) {
				$desglose = self::desglose_casillero_orden( $order );
			}

			$tomos[ $pid ]['pedidos'][] = array(
				'id'          => $order->get_id(),
				'url'         => (string) $order->get_edit_order_url(),
				'cliente'     => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'dias'        => $dias,
				'qty'         => $qty,
				'promesa_max' => $promesa['max'],
				'listos'      => $desglose['listos_count'],
				'totales'     => $desglose['total_count'],
			);
		}
	}

	/**
	 * Stock y fecha de un tomo, en lenguaje de tienda.
	 *
	 * @param int $product_id Producto.
	 * @return array<string, mixed>
	 */
	private function estado_del_tomo( int $product_id ): array {
		$product = wc_get_product( $product_id );

		if ( $product instanceof \WC_Product && ! self::en_catalogo( $product ) ) {
			return array(
				'existe'              => false,
				'sku'                 => '',
				'editorial'           => '',
				'pais'                => '',
				'stock_label'         => 'En la papelera',
				'stock_color'         => '#b32d2e',
				'fecha_label'         => '—',
				'fecha_pasada'        => false,
				'ts_lanzamiento'      => null,
				'es_agotado'          => false,
				'urgente'             => true,
			);
		}

		if ( ! $product ) {
			return array(
				'existe'              => false,
				'sku'                 => '',
				'editorial'           => '',
				'pais'                => '',
				'stock_label'         => 'Eliminado',
				'stock_color'         => '#b32d2e',
				'fecha_label'         => '—',
				'fecha_pasada'        => false,
				'ts_lanzamiento'      => null,
				'es_agotado'          => false,
				'urgente'             => true,
			);
		}

		$estado  = Availability::of( $product );
		$release = (string) get_post_meta( $product_id, Availability::META_RELEASE_DATE, true );

		$tz  = StoreTime::zone();
		$ts  = Availability::release_timestamp( $release, $tz );
		$hoy = ( new \DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0, 0 )->getTimestamp();

		$pasada = null !== $ts && $ts < $hoy;

		$editorial = '';
		$pais      = '';
		if ( function_exists( 'wp_get_post_terms' ) && $product_id > 0 ) {
			$brands = wp_get_post_terms( $product_id, 'product_brand', array( 'fields' => 'names' ) );
			if ( is_array( $brands ) && ! empty( $brands ) && ( ! function_exists( 'is_wp_error' ) || ! is_wp_error( $brands ) ) ) {
				$editorial = (string) reset( $brands );
			}
			$countries = wp_get_post_terms( $product_id, 'pa_pais', array( 'fields' => 'names' ) );
			if ( is_array( $countries ) && ! empty( $countries ) && ( ! function_exists( 'is_wp_error' ) || ! is_wp_error( $countries ) ) ) {
				$pais = (string) reset( $countries );
			}
		}

		$sku = '';
		if ( method_exists( $product, 'get_sku' ) ) {
			$sku = (string) $product->get_sku();
		}

		$etiquetas = array(
			Availability::DISPONIBLE => array( 'Ya llegó', '#006505' ),
			Availability::PREVENTA   => array( Availability::label_for_state( Availability::PREVENTA ), '#996800' ),
			Availability::A_PEDIDO   => array( Availability::label_for_state( Availability::A_PEDIDO ), '#996800' ),
			Availability::AGOTADO    => array( 'Agotado en tienda', '#b32d2e' ),
		);
		list( $label, $color ) = $etiquetas[ $estado ] ?? array( $estado, '#666' );

		if ( null !== $ts ) {
			$fecha = 'Lanzamiento: ' . wp_date( 'j M Y', $ts, $tz );
			if ( $pasada ) {
				$fecha .= ' · pasó';
			}
		} elseif ( Availability::PREVENTA === $estado ) {
			$fecha = 'Preventa: fecha editorial por confirmar';
		} elseif ( Availability::AGOTADO === $estado ) {
			$fecha = 'Sin stock en distribuidora · Reponer o reembolsar';
		} else {
			$fecha = 'Reposición a proveedor (en tránsito)';
		}

		return array(
			'existe'              => true,
			'sku'                 => $sku,
			'editorial'           => $editorial,
			'pais'                => $pais,
			'stock_label'         => $label,
			'stock_color'         => $color,
			'fecha_label'         => $fecha,
			'fecha_pasada'        => $pasada,
			'ts_lanzamiento'      => $ts,
			'es_agotado'          => Availability::AGOTADO === $estado,
			'es_preventa'         => Availability::PREVENTA === $estado || null !== $ts,
			'urgente'             => $pasada || Availability::AGOTADO === $estado,
		);
	}

	/**
	 * Devuelve todos los pedidos activos que esperan un producto específico,
	 * ordenados por antigüedad (FIFO: el más antiguo primero).
	 *
	 * @param int $product_id ID del producto.
	 * @return array<int, WC_Order>
	 */
	public function pedidos_esperando_tomo( int $product_id ): array {
		$pedidos = array();
		OrderQueryPaginator::process_all(
			array(
				'limit'      => 200,
				'status'     => FulfillmentCapabilities::active_order_statuses(),
				'meta_key'   => MetaKeys::ORDER_HAS_PREVENTA,
				'meta_value' => 'yes',
				'return'     => 'objects',
				'orderby'    => 'date',
				'order'      => 'ASC',
			),
			static function ( WC_Order $order ) use ( &$pedidos, $product_id ): void {
				foreach ( $order->get_items() as $item ) {
					if ( ! $item instanceof WC_Order_Item_Product ) {
						continue;
					}
					$pid = PreventaItems::product_id_of( $item );
					if ( $pid === $product_id && PreventaItems::is_pending_item( $order, $item ) ) {
						$pedidos[ $order->get_id() ] = $order;
						break;
					}
				}
			}
		);

		$lista = array_values( $pedidos );
		usort( $lista, array( self::class, 'comparar_fifo_pedidos' ) );
		return $lista;
	}

	/**
	 * Devuelve todos los pedidos activos con líneas en espera,
	 * ordenados por fecha ascendente (FIFO).
	 *
	 * @return array<int, WC_Order>
	 */
	public function pedidos_activos_con_espera(): array {
		$pedidos = array();
		OrderQueryPaginator::process_all(
			array(
				'limit'      => 200,
				'status'     => FulfillmentCapabilities::active_order_statuses(),
				'meta_key'   => MetaKeys::ORDER_HAS_PREVENTA,
				'meta_value' => 'yes',
				'return'     => 'objects',
				'orderby'    => 'date',
				'order'      => 'ASC',
			),
			static function ( WC_Order $order ) use ( &$pedidos ): void {
				if ( array() !== PreventaItems::pending_items( $order ) ) {
					$pedidos[ $order->get_id() ] = $order;
				}
			}
		);

		$lista = array_values( $pedidos );
		usort( $lista, array( self::class, 'comparar_fifo_pedidos' ) );
		return $lista;
	}

	/**
	 * Ordena pedidos por antigüedad real (FIFO: más antiguo primero), inmune al
	 * recorrido inverso de páginas de OrderQueryPaginator.
	 *
	 * @param WC_Order $a Pedido A.
	 * @param WC_Order $b Pedido B.
	 * @return int
	 */
	private static function comparar_fifo_pedidos( WC_Order $a, WC_Order $b ): int {
		$da = $a->get_date_created();
		$db = $b->get_date_created();
		$ta = null !== $da ? $da->getTimestamp() : 0;
		$tb = null !== $db ? $db->getTimestamp() : 0;
		if ( $ta !== $tb ) {
			return $ta <=> $tb;
		}
		return $a->get_id() <=> $b->get_id();
	}

	// ───────────────────────────────────────────────────────────── las acciones

	/**
	 * Fuerza una pasada del recalculador.
	 *
	 * @return void
	 */
	public function handle_revisar(): void {
		$this->guard( self::ACTION_REVISAR );

		$this->cron->run();

		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( 'akb_preventa_cumplimiento_v1' );
		}

		$this->redirect( 'revisado' );
	}

	/**
	 * Handler de admin-post: Registra la recepción física de mercadería y aparta
	 * unidades en los casilleros de pedidos por FIFO. Si hay excedente y el
	 * dueño eligió publicarlo, pasa el excedente libre a stock en la tienda web.
	 *
	 * @return void
	 */
	public function handle_recibir(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$product_id = isset( $_REQUEST['product_id'] ) ? absint( $_REQUEST['product_id'] ) : 0;

		$es_post = isset( $_SERVER['REQUEST_METHOD'] )
			&& 'POST' === strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) );
		if ( ! $es_post ) {
			wp_safe_redirect( self::url_recibir( $product_id ) );
			exit;
		}

		$this->guard( self::ACTION_RECIBIR, $product_id );

		$product = wc_get_product( $product_id );
		if ( ! self::en_catalogo( $product ) ) {
			$this->redirect( $product instanceof \WC_Product ? 'papelera' : 'error' );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$unidades_recibidas = isset( $_POST['unidades_recibidas'] ) ? absint( $_POST['unidades_recibidas'] ) : 0;
		if ( $unidades_recibidas <= 0 ) {
			wp_safe_redirect( add_query_arg( array( 'akb_preventa' => 'recibir_invalido', 'accion' => 'recibir', 'product_id' => $product_id ), self::url_panel() ) );
			exit;
		}

		// PA-04: Si el formulario explícito de pedidos fue enviado pero no se seleccionó ninguno, detener con aviso.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order_selection_submitted = ! empty( $_POST['akb_order_selection_submitted'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$order_ids = isset( $_POST['order_ids'] ) && is_array( $_POST['order_ids'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			? array_map( 'absint', wp_unslash( $_POST['order_ids'] ) )
			: null;

		if ( $order_selection_submitted && ( null === $order_ids || empty( $order_ids ) ) ) {
			wp_safe_redirect( add_query_arg( array( 'akb_preventa' => 'seleccion_vacia', 'accion' => 'recibir', 'product_id' => $product_id ), self::url_panel() ) );
			exit;
		}

		// PA-02: Idempotencia durable ante recargas de página o envíos repetidos (Replay Guard).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw_reception_id = isset( $_POST['reception_id'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['reception_id'] ) ) ) : '';
		if ( '' !== $raw_reception_id ) {
			$reception_id = $raw_reception_id;
		} elseif ( isset( $_POST['_wpnonce'] ) && '' !== (string) $_POST['_wpnonce'] ) {
			$reception_id = 'nonce_' . sanitize_key( (string) $_POST['_wpnonce'] );
		} else {
			$reception_id = 'post_' . md5( (string) $product_id . ':' . (string) $unidades_recibidas . ':' . serialize( $order_ids ) );
		}

		$existing_reception = (string) $product->get_meta( '_akb_reception_' . $reception_id );
		if ( '' !== $existing_reception ) {
			$prev      = json_decode( $existing_reception, true );
			$apartadas = is_array( $prev ) ? (int) ( $prev['apartadas'] ?? 0 ) : 0;
			$listos    = is_array( $prev ) ? (int) ( $prev['listos'] ?? 0 ) : 0;
			$excedente = is_array( $prev ) ? (int) ( $prev['excedente'] ?? 0 ) : 0;

			wp_safe_redirect(
				add_query_arg(
					array(
						'akb_preventa' => 'recibido',
						'apartadas'    => $apartadas,
						'listos'       => $listos,
						'excedente'    => $excedente,
						'product_id'   => $product_id,
						'replay'       => '1',
					),
					self::url_panel()
				)
			);
			exit;
		}

		$candidatos = $this->pedidos_esperando_tomo( $product_id );

		$restantes       = $unidades_recibidas;
		$apartadas_total = 0;
		$pedidos_listos  = 0;

		foreach ( $candidatos as $order ) {
			if ( $restantes <= 0 ) {
				break;
			}
			if ( null !== $order_ids && ! in_array( $order->get_id(), $order_ids, true ) ) {
				continue;
			}

			$asignadas_en_orden = 0;
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}
				$pid = PreventaItems::product_id_of( $item );
				if ( $pid !== $product_id ) {
					continue;
				}
				if ( $restantes <= 0 ) {
					break;
				}

				$pendiente_linea = PreventaItems::pending_qty( $order, $item );
				if ( $pendiente_linea > 0 ) {
					$to_seal = min( $restantes, $pendiente_linea );
					$sealed  = PreventaItems::seal_item_arrived( $order, $item, $to_seal, time() );
					if ( $sealed > 0 ) {
						$restantes           -= $sealed;
						$asignadas_en_orden += $sealed;
						$apartadas_total     += $sealed;
					}
				}
			}

			if ( $asignadas_en_orden > 0 ) {
				$order->add_order_note(
					sprintf(
						'📦 Recepción en bodega: %s (×%d) apartado físicamente en casillero para este pedido.',
						$product->get_name(),
						$asignadas_en_orden
					)
				);
				$this->cron->process_order( $order );
				$order->save();

				if ( 'yes' === (string) $order->get_meta( MetaKeys::ORDER_READY_TO_SHIP ) ) {
					++$pedidos_listos;
				}
			}
		}

		$excedente_publicado = 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$publicar            = isset( $_POST['publicar_excedente'] ) && 'yes' === sanitize_key( wp_unslash( (string) $_POST['publicar_excedente'] ) );
		if ( $restantes > 0 && $publicar ) {
			$excedente_publicado = $restantes;

			if ( method_exists( $product, 'set_manage_stock' ) ) {
				$product->set_manage_stock( true );
				$actual = (int) $product->get_stock_quantity();
				$product->set_stock_quantity( max( 0, $actual ) + $excedente_publicado );
				$product->set_backorders( 'no' );
				$product->set_stock_status( StockStatus::IN_STOCK );
			} else {
				$product->update_meta_data( '_manage_stock', 'yes' );
				$actual = (int) $product->get_meta( '_stock' );
				$product->update_meta_data( '_stock', max( 0, $actual ) + $excedente_publicado );
				$product->update_meta_data( '_backorders', 'no' );
				$product->update_meta_data( '_stock_status', StockStatus::IN_STOCK );
			}

			$reg_price = method_exists( $product, 'get_regular_price' )
				? (float) $product->get_regular_price()
				: (float) get_post_meta( $product_id, '_regular_price', true );

			if ( $reg_price > 0 ) {
				if ( method_exists( $product, 'set_sale_price' ) ) {
					$product->set_sale_price( '' );
					$product->set_price( $reg_price );
				} else {
					$product->delete_meta_data( '_sale_price' );
					$product->update_meta_data( '_price', $reg_price );
				}
			}

			$product->save();
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			do_action( 'litespeed_purge_post', $product_id );
		}

		// PA-02: Registrar la operación para idempotencia futura.
		$current_user_id = 0;
		if ( function_exists( 'get_current_user_id' ) ) {
			try {
				$current_user_id = (int) get_current_user_id();
			} catch ( \Throwable $e ) {
				$current_user_id = 0;
			}
		}

		$reception_meta = wp_json_encode(
			array(
				'reception_id' => $reception_id,
				'timestamp'    => time(),
				'user_id'      => $current_user_id,
				'received'     => $unidades_recibidas,
				'apartadas'    => $apartadas_total,
				'listos'       => $pedidos_listos,
				'excedente'    => $excedente_publicado,
			)
		);
		$product->update_meta_data( '_akb_reception_' . $reception_id, $reception_meta );
		$product->save();

		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( 'akb_preventa_cumplimiento_v1' );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'akb_preventa' => 'recibido',
					'apartadas'    => $apartadas_total,
					'listos'       => $pedidos_listos,
					'excedente'    => $excedente_publicado,
				),
				self::url_panel()
			)
		);
		exit;
	}

	/**
	 * Sella los tomos disponibles de un pedido que están en stock pero no sellados.
	 *
	 * Sella tanto los tomos comprados en stock como las preventas/a pedido que ya
	 * llegaron o están en stock en catálogo.
	 *
	 * @return void
	 */
	public function handle_apartar_pedido(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = isset( $_REQUEST['order_id'] ) ? absint( $_REQUEST['order_id'] ) : 0;
		$this->guard( self::ACTION_APARTAR_PEDIDO, $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			$this->redirect( 'error' );
			return;
		}

		$sellados = 0;
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			if ( PreventaItems::is_item_sealed( $order, $item ) ) {
				continue;
			}

			$qty_neta = PreventaItems::outstanding_qty( $order, $item );
			if ( $qty_neta <= 0 ) {
				continue;
			}

			$debe_sellar = false;
			if ( ! PreventaItems::is_outstanding_item( $order, $item ) ) {
				// Comprado en stock: siempre disponible en bodega
				$debe_sellar = true;
			} elseif ( PreventaItems::has_arrived( $order, $item ) ) {
				// Preventa / a pedido que ya tiene cantidad asignada
				$debe_sellar = true;
			} else {
				$product = $item->get_product();
				if ( $product instanceof \WC_Product && 'trash' !== (string) $product->get_status() && StockStatus::is_instock( $product ) ) {
					// Preventa / a pedido cuyo producto está hoy en stock en bodega (disponible para apartar)
					$debe_sellar = true;
				}
			}

			if ( $debe_sellar ) {
				$already  = max( 0, (int) $item->get_meta( MetaKeys::ITEM_ARRIVED_QTY ) );
				$a_sellar = max( 0, $qty_neta - $already );
				if ( $a_sellar > 0 ) {
					PreventaItems::seal_item_arrived( $order, $item, $a_sellar, time() );
					$sellados += $a_sellar;
				}
			}
		}

		if ( $sellados > 0 ) {
			$order->add_order_note( sprintf( '🔒 Apartado en casillero: %d tomo(s) en stock fueron apartados y sellados físicamente para esta orden.', $sellados ) );
			$this->cron->process_order( $order );
			$order->save();
			if ( function_exists( 'delete_transient' ) ) {
				delete_transient( 'akb_preventa_cumplimiento_v1' );
			}
		}

		wp_safe_redirect( add_query_arg( array( 'akb_preventa' => 'apartado', 'vista' => 'pedidos' ), self::url_panel() ) );
		exit;
	}

	/**
	 * Handler de admin-post: Deshace el sellado/apartado físico de una línea en casillero.
	 *
	 * @return void
	 */
	public function handle_desapartar_item(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = isset( $_REQUEST['order_id'] ) ? absint( $_REQUEST['order_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$item_id  = isset( $_REQUEST['item_id'] ) ? absint( $_REQUEST['item_id'] ) : 0;
		$this->guard( self::ACTION_DESAPARTAR_ITEM, $order_id . '-' . $item_id );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			$this->redirect( 'error' );
			return;
		}

		$item = $order->get_item( $item_id );
		if ( ! $item instanceof WC_Order_Item_Product ) {
			$this->redirect( 'error' );
			return;
		}

		$item->delete_meta_data( MetaKeys::ITEM_ARRIVED );
		$item->delete_meta_data( MetaKeys::ITEM_ARRIVED_AT );
		$item->delete_meta_data( MetaKeys::ITEM_ARRIVED_QTY );
		if ( method_exists( $item, 'save' ) ) {
			$item->save();
		}

		// Si el pedido estaba marcado como ready_to_ship, recalcular
		$order->delete_meta_data( MetaKeys::ORDER_READY_TO_SHIP );
		$order->delete_meta_data( MetaKeys::ORDER_READY_DATE );
		$order->add_order_note( sprintf( '↩️ Deshecho apartado en casillero para el tomo "%s".', $item->get_name() ) );
		$this->cron->process_order( $order );
		$order->save();

		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( 'akb_preventa_cumplimiento_v1' );
		}

		wp_safe_redirect( add_query_arg( array( 'akb_preventa' => 'desapartado', 'vista' => 'pedidos' ), self::url_panel() ) );
		exit;
	}

	/**
	 * Descarga la planilla CSV para compras a proveedor.
	 *
	 * @return void
	 */
	public function handle_csv(): void {
		$this->guard( self::ACTION_CSV );

		$completa = $this->esperas_por_tomo();

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/csv; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="akibara-preventas-' . gmdate( 'Y-m-d' ) . '.csv"' );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );
		}

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			$this->terminate();
			return;
		}

		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		fputcsv( $output, array( 'SKU / ISBN', 'Tomo', 'Editorial', 'Pais', 'Estado Tienda', 'Unidades Requeridas', 'Pedidos Esperando', 'Plazo o Lanzamiento' ) );

		foreach ( $completa as $t ) {
			fputcsv(
				$output,
				array(
					$t['sku'] ?? '',
					$t['nombre'] ?? '',
					$t['editorial'] ?? '',
					$t['pais'] ?? '',
					$t['stock_label'] ?? '',
					(int) ( $t['unidades_pendientes'] ?? count( $t['pedidos'] ) ),
					count( $t['pedidos'] ),
					$t['fecha_label'] ?? '',
				)
			);
		}

		fclose( $output );
		$this->terminate();
	}

	/**
	 * Termina el script tras una descarga o salida directa.
	 *
	 * @return void
	 */
	protected function terminate(): void {
		exit;
	}

	/**
	 * Cambia la fecha de SALIDA de la editorial de un tomo.
	 *
	 * @return void
	 */
	public function handle_fecha(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$product_id = isset( $_REQUEST['product_id'] ) ? absint( $_REQUEST['product_id'] ) : 0;

		$es_post = isset( $_SERVER['REQUEST_METHOD'] )
			&& 'POST' === strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) );
		if ( ! $es_post ) {
			wp_safe_redirect( self::url_fecha( $product_id ) );
			exit;
		}

		$this->guard( self::ACTION_FECHA, $product_id );

		$product = wc_get_product( $product_id );
		if ( ! self::en_catalogo( $product ) ) {
			$this->redirect( $product instanceof \WC_Product ? 'papelera' : 'error' );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$nueva = isset( $_POST['fecha'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['fecha'] ) ) : '';
		if ( '' !== $nueva && ! Availability::is_valid_release_date( $nueva ) ) {
			$this->redirect( 'fecha_invalida' );
			return;
		}
		if ( '' !== $nueva && $nueva < StoreTime::today_ymd() ) {
			$this->redirect( 'fecha_pasada' );
			return;
		}

		$anterior = (string) get_post_meta( $product_id, Availability::META_RELEASE_DATE, true );

		if ( $anterior === $nueva ) {
			$this->redirect( 'fecha' );
			return;
		}

		if ( '' === $nueva ) {
			$product->delete_meta_data( Availability::META_RELEASE_DATE );
			$product->update_meta_data( Availability::META_TYPE, Availability::TYPE_TBA );
		} else {
			$product->update_meta_data( Availability::META_RELEASE_DATE, $nueva );
			$product->update_meta_data( Availability::META_TYPE, Availability::TYPE_RELEASE_DATE );
		}
		$product->save();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'litespeed_purge_post', $product_id );
		do_action( 'akb_eta_release_date_changed', $product_id, $anterior, $nueva );

		$this->redirect( '' === $nueva ? 'fecha_retirada' : 'fecha' );
	}

	/**
	 * Sub-vista: Formulario de recepción física de mercadería.
	 *
	 * @param \WC_Product $product Tomo que llegó.
	 * @return void
	 */
	private function render_form_recibir( \WC_Product $product ): void {
		$pid        = $product->get_id();
		$volver     = self::url_panel();
		$candidatos = $this->pedidos_esperando_tomo( $pid );

		$total_unidades = 0;
		$filas_pedidos  = array();
		$ahora          = time();

		foreach ( $candidatos as $order ) {
			$unidades_orden = 0;
			foreach ( $order->get_items() as $item ) {
				if ( $item instanceof WC_Order_Item_Product && PreventaItems::product_id_of( $item ) === $pid ) {
					$unidades_orden += PreventaItems::pending_qty( $order, $item );
				}
			}
			if ( $unidades_orden > 0 ) {
				$total_unidades += $unidades_orden;
				$creado          = $order->get_date_created();
				$dias            = null !== $creado ? (int) floor( ( $ahora - $creado->getTimestamp() ) / DAY_IN_SECONDS ) : 0;
				$desglose        = self::desglose_casillero_orden( $order );
				$filas_pedidos[] = array(
					'id'       => $order->get_id(),
					'url'      => $order->get_edit_order_url(),
					'cliente'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
					'dias'     => $dias,
					'unidades' => $unidades_orden,
					'totales'  => $desglose['total_count'],
					'listos'   => $desglose['listos_count'],
					'order'    => $order,
				);
			}
		}

		$this->render_template(
			'form-recibir.php',
			array(
				'product'        => $product,
				'pid'            => $pid,
				'volver'         => $volver,
				'filas_pedidos'  => $filas_pedidos,
				'total_unidades' => $total_unidades,
				'action_recibir' => self::ACTION_RECIBIR,
			)
		);
	}

	/**
	 * El formulario de cambio de fecha.
	 *
	 * @param \WC_Product $product Producto.
	 * @return void
	 */
	private function render_form_fecha( \WC_Product $product ): void {
		$pid        = $product->get_id();
		$actual     = (string) get_post_meta( $pid, Availability::META_RELEASE_DATE, true );
		$candidatos = $this->pedidos_esperando_tomo( $pid );
		$cuantos    = count( $candidatos );
		$volver     = self::url_panel();

		$es_primera             = '' === $actual;
		$ventana                = Settings::get_int( Settings::OPT_DATE_CHANGE_NOTIFY_WINDOW );
		$ya_salieron            = 0;
		$capados                = 0;
		$con_preventa_sin_fecha = 0;
		$con_preventa_con_fecha = 0;
		$ahora                  = time();

		foreach ( $candidatos as $o ) {
			if ( ! $o->has_status( FulfillmentCapabilities::waiting_order_statuses( $o ) ) ) {
				++$ya_salieron;
			}
			if ( $es_primera ) {
				if ( ReminderEmail::within_notify_window(
					$o,
					array( MetaKeys::ORDER_DATE_CHANGE_LAST_NOTIFIED_AT, MetaKeys::ORDER_DATE_ANNOUNCED_LAST_NOTIFIED_AT ),
					$ahora
				) ) {
					++$capados;
				}

				if ( PreventaItems::has_real_preventa( $o ) ) {
					if ( $this->linea_comprada_sin_fecha( $o, $pid ) ) {
						++$con_preventa_sin_fecha;
					} else {
						++$con_preventa_con_fecha;
					}
				}
			}
		}

		$nota_salieron = $ya_salieron > 0
			? sprintf( ' <strong>A %d de los %d no le llega ningún correo</strong>: ya salió hacia el transportista y desde ahí no se le avisan cambios de fecha.', (int) $ya_salieron, (int) $cuantos )
			: '';

		$asuntos = array();
		if ( $es_primera && $cuantos > 0 ) {
			$resto = $cuantos - $con_preventa_sin_fecha - $con_preventa_con_fecha;
			if ( $con_preventa_sin_fecha > 0 ) {
				$asuntos[] = sprintf( 'a %d con asunto «%s»', $con_preventa_sin_fecha, ReminderEmail::subject_for_screen( ReminderEmail::SUBJECT_DATE_ANNOUNCED_PREVENTA ) );
			}
			if ( $con_preventa_con_fecha > 0 ) {
				$asuntos[] = sprintf( 'a %d con asunto «%s» (compraron con fecha: para ellos es un cambio)', $con_preventa_con_fecha, ReminderEmail::subject_for_screen( ReminderEmail::SUBJECT_DATE_CHANGED_PREVENTA ) );
			}
			if ( $resto > 0 ) {
				$asuntos[] = sprintf( 'a %d con asunto «%s» (para ese cliente es un cambio de fecha)', $resto, ReminderEmail::subject_for_screen( ReminderEmail::SUBJECT_DATE_ANNOUNCED_PEDIDO ) );
			}
		}

		$this->render_template(
			'form-fecha.php',
			array(
				'product'       => $product,
				'pid'           => $pid,
				'actual'        => $actual,
				'cuantos'       => $cuantos,
				'volver'        => $volver,
				'es_primera'    => $es_primera,
				'ventana'       => $ventana,
				'capados'       => $capados,
				'nota_salieron' => $nota_salieron,
				'asuntos'       => $asuntos,
				'action_fecha'  => self::ACTION_FECHA,
			)
		);
	}

	/**
	 * ¿La línea de ese tomo en el pedido se compró sin fecha (tba)?
	 */
	private function linea_comprada_sin_fecha( WC_Order $order, int $product_id ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && PreventaItems::product_id_of( $item ) === $product_id ) {
				return 'yes' === (string) $item->get_meta( MetaKeys::ITEM_IS_TBA_AT_PURCHASE );
			}
		}
		return false;
	}

	// ──────────────────────────────────────────────────────────── plomería

	/**
	 * @param string          $action  Acción para el nonce.
	 * @param int|string|null $context ID del producto, pedido o línea si la acción va atada a uno.
	 * @return void
	 */
	private function guard( string $action, $context = 0 ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 'Sin permiso', array( 'response' => 403 ) );
		}
		$suffix       = ( null !== $context && '' !== (string) $context && 0 !== $context && '0' !== (string) $context ) ? '-' . $context : '';
		$nonce_action = $action . $suffix;
		check_admin_referer( $nonce_action );
	}

	/**
	 * @param string $resultado Qué pasó.
	 * @return void
	 */
	private function redirect( string $resultado ): void {
		wp_safe_redirect( add_query_arg( 'akb_preventa', $resultado, self::url_panel() ) );
		exit;
	}

	/**
	 * ¿El tomo sigue a la venta? Borrado o en la papelera = no.
	 *
	 * @since 1.114.16
	 *
	 * @param mixed $product Lo que devolvió `wc_get_product()`.
	 * @return bool
	 */
	private static function en_catalogo( $product ): bool {
		return $product instanceof \WC_Product && 'trash' !== (string) $product->get_status();
	}

	/**
	 * @return string
	 */
	private static function url_panel(): string {
		return add_query_arg(
			array(
				'page' => self::PANEL_PAGE,
				'tab'  => self::PANEL_TAB,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * @return string
	 */
	public static function url_revisar(): string {
		return wp_nonce_url(
			add_query_arg( 'action', self::ACTION_REVISAR, admin_url( 'admin-post.php' ) ),
			self::ACTION_REVISAR
		);
	}

	/**
	 * @param int $product_id Producto.
	 * @return string
	 */
	public static function url_fecha( int $product_id ): string {
		return add_query_arg(
			array(
				'accion'     => 'fecha',
				'product_id' => $product_id,
			),
			self::url_panel()
		);
	}

	/**
	 * @param int $product_id Producto.
	 * @return string
	 */
	public static function url_recibir( int $product_id ): string {
		return add_query_arg(
			array(
				'accion'     => 'recibir',
				'product_id' => $product_id,
			),
			self::url_panel()
		);
	}

	/**
	 * @param string $vista Vista 'tomos'|'pedidos'.
	 * @return string
	 */
	public static function url_vista( string $vista ): string {
		return add_query_arg(
			array(
				'vista' => $vista,
			),
			self::url_panel()
		);
	}

	/**
	 * @param int $order_id Pedido.
	 * @return string
	 */
	public static function url_apartar_pedido( int $order_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::ACTION_APARTAR_PEDIDO,
					'order_id' => $order_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION_APARTAR_PEDIDO . '-' . $order_id
		);
	}

	/**
	 * @return string
	 */
	public static function url_csv(): string {
		return wp_nonce_url(
			add_query_arg( 'action', self::ACTION_CSV, admin_url( 'admin-post.php' ) ),
			self::ACTION_CSV
		);
	}

	/**
	 * Desglosa los tomos de un pedido entre listos en bodega y pendientes.
	 * Incluye tanto tomos comprados en stock como preventas/a pedido que ya llegaron.
	 *
	 * @param WC_Order $order Pedido WooCommerce.
	 * @return array{
	 *     total_count: int,
	 *     listos_count: int,
	 *     faltan_count: int,
	 *     listos: array<int, WC_Order_Item_Product>,
	 *     pendientes: array<int, WC_Order_Item_Product>,
	 *     hay_sin_sellar: bool
	 * }
	 */
	public static function desglose_casillero_orden( WC_Order $order ): array {
		$listos         = array();
		$pendientes     = array();
		$hay_sin_sellar = false;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$qty_neta = PreventaItems::outstanding_qty( $order, $item );
			if ( $qty_neta <= 0 ) {
				continue;
			}

			// Caso 1: Se compró en stock (disponible de inmediato en bodega)
			if ( ! PreventaItems::is_outstanding_item( $order, $item ) ) {
				$listos[] = $item;
				if ( ! PreventaItems::is_item_sealed( $order, $item ) ) {
					$hay_sin_sellar = true;
				}
				continue;
			}

			// Caso 2: Es preventa o a pedido
			$tomo_en_stock = false;
			$product       = $item->get_product();
			if ( $product instanceof \WC_Product && 'trash' !== (string) $product->get_status() && StockStatus::is_instock( $product ) ) {
				$tomo_en_stock = true;
			}

			if ( PreventaItems::has_arrived( $order, $item ) || $tomo_en_stock ) {
				$listos[] = $item;
				if ( ! PreventaItems::is_item_sealed( $order, $item ) ) {
					$hay_sin_sellar = true;
				}
			} else {
				$pendientes[] = $item;
			}
		}

		$total_count  = count( $listos ) + count( $pendientes );
		$listos_count = count( $listos );
		$faltan_count = count( $pendientes );

		return array(
			'total_count'    => $total_count,
			'listos_count'   => $listos_count,
			'faltan_count'   => $faltan_count,
			'listos'         => $listos,
			'pendientes'     => $pendientes,
			'hay_sin_sellar' => $hay_sin_sellar,
		);
	}

	/**
	 * Normaliza un número telefónico al formato internacional de Chile (+56 9...).
	 *
	 * @param string $phone Teléfono crudo.
	 * @return string Número normalizado solo con dígitos, ej: 56912345678, o vacío si inválido.
	 */
	public static function normalizar_telefono_cl( string $phone ): string {
		$digits = (string) preg_replace( '/\D+/', '', $phone );
		if ( '' === $digits ) {
			return '';
		}
		if ( 11 === strlen( $digits ) && str_starts_with( $digits, '56' ) ) {
			return $digits;
		}
		if ( 9 === strlen( $digits ) && str_starts_with( $digits, '9' ) ) {
			return '56' . $digits;
		}
		if ( 8 === strlen( $digits ) ) {
			return '569' . $digits;
		}
		if ( str_starts_with( $digits, '569' ) ) {
			return $digits;
		}
		if ( strlen( $digits ) >= 8 ) {
			return str_starts_with( $digits, '56' ) ? $digits : '56' . $digits;
		}
		return '';
	}

	/**
	 * Construye la URL de WhatsApp para contactar al cliente de un pedido.
	 *
	 * @param WC_Order $order       Pedido.
	 * @param string   $custom_text Mensaje personalizado opcional.
	 * @return string URL de api.whatsapp.com o vacía si no hay teléfono válido.
	 */
	public static function url_whatsapp_pedido( WC_Order $order, string $custom_text = '' ): string {
		$raw_phone = $order->get_billing_phone();
		$phone     = self::normalizar_telefono_cl( (string) $raw_phone );
		if ( '' === $phone ) {
			return '';
		}

		$first_name = trim( (string) $order->get_billing_first_name() );
		if ( '' === $custom_text ) {
			$msg = sprintf(
				'¡Hola %s! Te escribimos de Akibara sobre tu pedido #%d: ',
				$first_name,
				$order->get_id()
			);
		} else {
			$msg = $custom_text;
		}

		return 'https://api.whatsapp.com/send?phone=' . rawurlencode( $phone ) . '&text=' . rawurlencode( $msg );
	}

	/**
	 * URL protegida por nonce para deshacer el apartado físico de un item.
	 *
	 * @param int $order_id ID del pedido.
	 * @param int $item_id  ID de la línea.
	 * @return string
	 */
	public static function url_desapartar_item( int $order_id, int $item_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::ACTION_DESAPARTAR_ITEM,
					'order_id' => $order_id,
					'item_id'  => $item_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION_DESAPARTAR_ITEM . '-' . $order_id . '-' . $item_id
		);
	}

	/**
	 * El resultado de la última acción, de vuelta en la pantalla.
	 *
	 * @return void
	 */
	public function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['akb_preventa'] ) || ! current_user_can( self::CAP ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$estado = sanitize_key( wp_unslash( (string) $_GET['akb_preventa'] ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$apartadas = isset( $_GET['apartadas'] ) ? absint( $_GET['apartadas'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$listos    = isset( $_GET['listos'] ) ? absint( $_GET['listos'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$excedente = isset( $_GET['excedente'] ) ? absint( $_GET['excedente'] ) : 0;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$replay = ! empty( $_GET['replay'] );
		$msg_recibido = $replay
			? 'Esta recepción ya había sido procesada anteriormente (operación registrada). No se duplicaron asignaciones.'
			: sprintf(
				'Recepción completada: se apartaron %d unidad(es) en casillero (%d pedido(s) quedaron listos para despacho)%s.',
				$apartadas,
				$listos,
				$excedente > 0 ? sprintf( ' y %d unidad(es) libre(s) pasaron a stock en la tienda', $excedente ) : ''
			);

		$mensajes = array(
			'revisado'         => array( 'success', 'Revisión hecha. Los pedidos cuyos tomos ya llegaron quedaron marcados como listos, y sus avisos salen en los próximos minutos.' ),
			'fecha'            => array( 'success', 'Fecha guardada. La estimación de los pedidos se recalcula y, si tocaba avisar, el correo sale en los próximos minutos (queda como nota en cada pedido). La ficha pública puede tardar unos minutos en reflejarlo.' ),
			'recibido'         => array( 'success', $msg_recibido ),
			'apartado'         => array( 'success', 'Tomos en stock apartados y sellados físicamente en el casillero de este pedido.' ),
			'desapartado'      => array( 'success', 'Apartado en casillero deshecho correctamente. La línea vuelve a estado pendiente.' ),
			'recibir_invalido' => array( 'error', 'La cantidad de unidades recibidas debe ser al menos 1.' ),
			'seleccion_vacia'  => array( 'error', 'No seleccionaste ningún pedido para asignar mercadería. Selecciona al menos un pedido de la lista.' ),
			'error'            => array( 'error', 'No se pudo completar la acción: revisa que el tomo siga existiendo.' ),
			'papelera'         => array( 'error', 'Este tomo está en la papelera: restáuralo antes de realizar esta acción, o reembolsa esa línea.' ),
			'fecha_retirada'   => array( 'success', 'Fecha retirada. El tomo queda como preventa sin fecha confirmada y la estimación de los pedidos se recalcula. No sale ningún correo: el cambio queda anotado en cada pedido; si quieres avisar al cliente, escríbele tú.' ),
			'fecha_pasada'     => array( 'error', 'La fecha no se guardó porque ya pasó. Una fecha pasada no anuncia nada ni manda correo: si la editorial ya publicó el tomo, déjalo «a pedido» sin fecha desde su ficha.' ),
			'fecha_invalida'   => array(
				'error',
				sprintf(
					'La fecha no se guardó: tiene que ser un día real y como mucho %d años en el futuro. El error típico es un año con un dígito de más (2206 en vez de 2026).',
					Availability::MAX_RELEASE_DATE_YEARS
				),
			),
		);

		if ( ! isset( $mensajes[ $estado ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $mensajes[ $estado ][0] ),
			esc_html( $mensajes[ $estado ][1] )
		);
	}
}
