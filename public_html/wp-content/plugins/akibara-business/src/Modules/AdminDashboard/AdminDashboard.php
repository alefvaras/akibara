<?php
/**
 * AdminDashboard — Módulo que renderiza la pantalla central del panel.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\AdminDashboard;

use Akibara\Business\Modules\TaxonomyDiscount\Settings as TaxonomyDiscountSettings;
use Akibara\Business\Modules\Forms\ContactoLog;
use Akibara\Business\Modules\Forms\Forms;
use Akibara\Business\Modules\OrderPreventa\RemindersCron;
use Akibara\Business\Modules\OrderPreventa\Settings as OrderPreventaSettings;
use Akibara\Business\Modules\SiteSpeed\EdgePurge;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Modules\UpsellsAutofill\UpsellsAutofill;
use Akibara\Business\Utilities\QueueHealth;
use Akibara\Business\Plugin;
use Akibara\Business\Utilities\OwnCrons;

defined( 'ABSPATH' ) || exit;

final class AdminDashboard {

	/**
	 * Margen antes de considerar atrasada una tarea programada.
	 *
	 * Todos los eventos vigilados son diarios o semanales y WP-Cron depende de
	 * que alguien visite el sitio, así que un retraso corto es normal y avisar
	 * por él enseñaría a ignorar la tarjeta. Seis horas es lo bastante holgado
	 * para una noche tranquila y lo bastante estrecho para que un cron caído se
	 * note el mismo día.
	 */
	private const CRON_GRACE_SECONDS = 6 * HOUR_IN_SECONDS;

	/**
	 * Huecos del buscador que se piden por render: el máximo entre lo que
	 * consume el ranking (10) y lo que pinta el radar (8), para resolverlos con
	 * una sola llamada a la función puente.
	 */
	private const GAPS_MAX = 10;

	/** @var array<int, array<string, mixed>>|null Memo por petición de los huecos. */
	private ?array $gaps_memo = null;

	/**
	 * Etiquetas admitidas en el formulario de descarte de una alerta.
	 *
	 * `wp_kses_post()` —el filtro del resto del cuerpo de la alerta— borra
	 * `<form>`, `<input>` y `<button>` sin avisar. Esta lista es mínima a
	 * propósito: solo lo que el botón necesita para existir.
	 *
	 * @var array<string, array<string, bool>>
	 */
	private const DISMISS_FORM_TAGS = array(
		'form'   => array(
			'method' => true,
			'action' => true,
			'class'  => true,
		),
		'input'  => array(
			'type'  => true,
			'name'  => true,
			'value' => true,
			'id'    => true,
		),
		'button' => array(
			'type'  => true,
			'class' => true,
		),
	);

	public function register(): void {
		// Render del tab de AdminPanel + descarga CSV del pedido sugerido.
		add_action( 'admin_post_akb_demand_csv', array( $this, 'download_demand_csv' ) );
	}

	/**
	 * Calcula salud con options y agenda, sin consultas pesadas. Las tareas
	 * vigiladas se obtienen de watched_crons().
	 *
	 * @return array<int, array{level: 'error'|'warning', html: string, form?: string}>
	 */
	private function health_alerts(): array {
		$alerts = array();

		foreach ( self::watched_crons() as $hook => $label ) {
			$next = wp_next_scheduled( $hook );

			if ( false === $next ) {
				$alerts[] = array(
					'level' => 'warning',
					'html'  => sprintf(
						'⏰ <strong>Cron desagendado</strong>: %s (<code>%s</code>). Se re-agenda solo al recargar el sitio; si persiste, avisar a soporte.',
						esc_html( $label ),
						esc_html( $hook )
					),
				);
				continue;
			}

			// La existencia del evento no demuestra ejecución: cada módulo puede
			// reagendarlo en init. Comprueba también el retraso respecto a su fecha.
			$overdue = time() - (int) $next;
			if ( $overdue <= self::CRON_GRACE_SECONDS ) {
				continue;
			}

			$alerts[] = array(
				'level' => 'warning',
				'html'  => sprintf(
					'⏰ <strong>Tarea atrasada</strong>: %s (<code>%s</code>) debía correr hace %s y sigue pendiente. Suele significar que las tareas programadas del sitio no se están ejecutando.',
					esc_html( $label ),
					esc_html( $hook ),
					esc_html( human_time_diff( (int) $next, time() ) )
				),
			);
		}

		$alerts = array_merge( $alerts, $this->undelivered_contact_alerts() );

		// Vigila Action Scheduler con el mismo margen de retraso que WP-Cron.
		$alerts = array_merge( $alerts, ( new QueueHealth() )->alerts( self::CRON_GRACE_SECONDS ) );

		// Expone en el panel los fallos sostenidos de purga de precio y stock en Cloudflare.
		$edge_purge_alert = EdgePurge::health_alert();
		if ( null !== $edge_purge_alert ) {
			$alerts[] = $edge_purge_alert;
		}

		return $alerts;
	}

	/**
	 * Mapa hook → nombre de todas las recurrentes propias, compartido con la
	 * desactivación y la desinstalación. Las tareas de un módulo apagado no se
	 * esperan activas; el módulo dueño determina el estado de su interruptor.
	 *
	 * @return array<string, string>
	 */
	public static function watched_crons(): array {
		$crons = OwnCrons::RECURRING;

		if ( ! OrderPreventaSettings::get_bool( OrderPreventaSettings::OPT_T7_ENABLED ) ) {
			unset( $crons[ RemindersCron::HOOK ] );
		}
		if ( ! UpsellsAutofill::is_enabled() ) {
			unset( $crons[ UpsellsAutofill::CRON_HOOK ] );
		}

		return $crons;
	}

	/**
	 * Expone íntegros los mensajes conservados cuyo envío por correo falló,
	 * para que el dueño pueda responderlos desde el panel.
	 *
	 * @return array<int, array{level: 'error'|'warning', html: string, form: string}>
	 */
	private function undelivered_contact_alerts(): array {
		$pending = ContactoLog::undelivered();
		if ( array() === $pending ) {
			return array();
		}

		$total   = count( $pending );
		$shown   = array_slice( $pending, 0, 5 );
		$items   = '';
		foreach ( $shown as $entry ) {
			$when = isset( $entry['time'] ) ? wp_date( 'j M H:i', (int) $entry['time'] ) : 'sin fecha';
			$items .= sprintf(
				'<li><strong>%s</strong> &lt;<a href="mailto:%s">%s</a>&gt; · %s<br><em>%s</em></li>',
				esc_html( (string) ( $entry['nombre'] ?? '' ) ),
				esc_attr( (string) ( $entry['email'] ?? '' ) ),
				esc_html( (string) ( $entry['email'] ?? '' ) ),
				esc_html( (string) $when ),
				esc_html( wp_trim_words( (string) ( $entry['mensaje'] ?? '' ), 40, '…' ) )
			);
		}

		$more = $total > count( $shown )
			? sprintf( '<p>Y %d más.</p>', $total - count( $shown ) )
			: '';

		return array(
			array(
				'level' => 'error',
				'html'  => sprintf(
					'✉️ <strong>%d mensaje%s de contacto sin enviar por correo</strong>. El sitio los recibió pero no pudo notificarlos; están completos aquí abajo.<ul class="akb-dashboard-contact">%s</ul>%s',
					$total,
					1 === $total ? '' : 's',
					$items,
					$more
				),
				// Va aparte del `html` porque ese pasa por `wp_kses_post()`, que
				// borra `<form>`, `<input>` y `<button>`: metido ahí, el botón
				// simplemente no se habría publicado.
				'form'  => sprintf(
					'<form method="post" action="%s" class="akb-dashboard-dismiss"><input type="hidden" name="action" value="%s">%s<button type="submit" class="button button-small">Ya los respondí, ocultar</button></form>',
					esc_url( admin_url( 'admin-post.php' ) ),
					esc_attr( Forms::DISMISS_ACTION ),
					wp_nonce_field( Forms::DISMISS_ACTION, '_wpnonce', true, false )
				),
			),
		);
	}

	/**
	 * Puntaje del ranking «Lo más solicitado» — función PURA (testeable sin WP).
	 *
	 * La demanda no vale lo mismo según cuánto costó expresarla: un correo
	 * esperando el aviso de reposición (×3) pesa más que una búsqueda que
	 * terminó en nada (×2), y ésta más que un favorito o una venta pasada (×1).
	 * Los pesos son una convención EXPLICABLE, no ciencia: el orden resultante
	 * se defiende en una frase delante del dueño, que es el requisito.
	 *
	 * @param array<int, int>                                        $bis   product_id => personas esperando aviso.
	 * @param array<int, int>                                        $wish  product_id => favoritos (solo productos agotados).
	 * @param array<int, int>                                        $sales product_id => unidades vendidas 90 d (solo agotados hoy).
	 * @param array<int, array{query: string, s90: int, s30: int}>   $gaps       Búsquedas sin resultado (huecos reales del buscador).
	 * @param array<int, int>                                        $cont       product_id => coleccionistas en racha (siguiente tomo).
	 * @param array<int, array<string, mixed>>                       $serie_gaps Siguiente tomo SIN ficha => coleccionistas que lo esperan.
	 * @return array<int, array<string, mixed>> Filas ordenadas por puntaje, máx. 10.
	 */
	public static function score_demand( array $bis, array $wish, array $sales, array $gaps, array $cont = array(), array $serie_gaps = array(), array $requests = array() ): array {
		$rows = array();

		$ids = array_unique( array_merge( array_keys( $bis ), array_keys( $wish ), array_keys( $sales ), array_keys( $cont ) ) );
		foreach ( $ids as $id ) {
			$esperando = (int) ( $bis[ $id ] ?? 0 );
			$favoritos = (int) ( $wish[ $id ] ?? 0 );
			$ventas    = (int) ( $sales[ $id ] ?? 0 );
			$racha     = (int) ( $cont[ $id ] ?? 0 );
			$rows[]    = array(
				'type'      => 'product',
				'id'        => (int) $id,
				'esperando' => $esperando,
				'favoritos' => $favoritos,
				'ventas'    => $ventas,
				'racha'     => $racha,
				'score'     => 3 * $esperando + 2 * $racha + $favoritos + $ventas,
			);
		}

		foreach ( $serie_gaps as $hueco ) {
			$clientes = (int) ( $hueco['clientes'] ?? 0 );
			$rows[]   = array(
				'type'         => 'serie_gap',
				'serie'        => (string) ( $hueco['serie'] ?? '' ),
				'serie_nombre' => (string) ( $hueco['serie_nombre'] ?? ( $hueco['serie'] ?? '' ) ),
				'vol'          => (int) ( $hueco['vol'] ?? 0 ),
				'clientes'     => $clientes,
				'score'        => 2 * $clientes,
			);
		}

		$seen_requests = array();
		foreach ( $gaps as $gap ) {
			$q           = (string) ( $gap['query'] ?? '' );
			$s90         = (int) ( $gap['s90'] ?? 0 );
			$s30         = (int) ( $gap['s30'] ?? 0 );
			$req         = $requests[ $q ] ?? null;
			$solicitudes = 0;
			$title       = $q;
			$editorial   = '';
			if ( null !== $req && is_array( $req ) ) {
				$seen_requests[ $q ] = true;
				$solicitudes         = (int) ( $req['count'] ?? 0 );
				$title               = (string) ( $req['title'] ?? $q );
				$editorial           = (string) ( $req['editorial'] ?? '' );
			}

			$rows[] = array(
				'type'        => 'gap',
				'query'       => $title,
				'query_raw'   => $q,
				'editorial'   => $editorial,
				'solicitudes' => $solicitudes,
				's90'         => $s90,
				's30'         => $s30,
				'score'       => 3 * $solicitudes + 2 * $s90,
			);
		}

		foreach ( $requests as $q => $req ) {
			if ( isset( $seen_requests[ $q ] ) || ! is_array( $req ) ) {
				continue;
			}
			$solicitudes = (int) ( $req['count'] ?? 0 );
			$title       = (string) ( $req['title'] ?? $q );
			$editorial   = (string) ( $req['editorial'] ?? '' );

			$rows[] = array(
				'type'        => 'gap',
				'query'       => $title,
				'query_raw'   => (string) $q,
				'editorial'   => $editorial,
				'solicitudes' => $solicitudes,
				's90'         => 0,
				's30'         => 0,
				'score'       => 3 * $solicitudes,
			);
		}

		usort( $rows, static fn( array $a, array $b ): int => $b['score'] <=> $a['score'] );

		return array_slice(
			array_values( array_filter( $rows, static fn( array $row ): bool => $row['score'] > 0 ) ),
			0,
			10
		);
	}

	/**
	 * Continuidad de colecciones — señal PURA (testeable sin WP).
	 *
	 * La señal única de Akibara: quien compró hasta el tomo N de una serie es
	 * un comprador casi seguro del N+1. Racha REAL = tener el último y el
	 * penúltimo tomo (max y max−1) contando VOLS DISTINTOS — ni recompras, ni
	 * reimpresiones, ni tomos salteados cuentan. Se mira SOLO el siguiente
	 * tomo (max+1): es donde la compra está esperando.
	 *
	 * @param array<int, array<int, int>>           $compras   customer_id => lista de product_ids comprados.
	 * @param array<int, array{serie: string, vol: int}> $serie_vol product_id => serie y nº de tomo.
	 * @param array<string, array<int, int>>        $catalogo  serie => (vol => product_id existente).
	 * @param array<int, string>                    $stock     product_id => stock_status.
	 * @return array{productos: array<int, int>, huecos: array<int, array{serie: string, vol: int, clientes: int}>}
	 *         `productos`: siguiente-tomo EXISTENTE no comprable de inmediato (agotado o reservable) => nº de coleccionistas.
	 *         `huecos`: siguiente-tomo SIN ficha => coleccionistas que lo esperan.
	 */
	public static function continuity_from_maps( array $compras, array $serie_vol, array $catalogo, array $stock ): array {
		$productos = array();
		$huecos    = array();

		foreach ( $compras as $pids ) {
			// VOLS DISTINTOS por serie, no filas de compra: una recompra del
			// mismo tomo o una reimpresión (dos productos, mismo vol) NO son
			// avance de colección. La revisión adversarial encontró los tres
			// falsos positivos con datos reales antes del primer deploy.
			$vols_por_serie = array();
			foreach ( $pids as $pid ) {
				$info = $serie_vol[ $pid ] ?? null;
				if ( null === $info || $info['vol'] < 1 ) {
					continue;
				}
				$vols_por_serie[ $info['serie'] ][ (int) $info['vol'] ] = true;
			}

			foreach ( $vols_por_serie as $serie => $vols ) {
				$max_vol = max( array_keys( $vols ) );
				// Racha REAL: viene comprando seguido hacia el frente — exige el
				// último y el penúltimo tomo (max y max−1). Con «tomos 1 y 7
				// sueltos» nadie está esperando el 8.
				if ( count( $vols ) < 2 || ! isset( $vols[ $max_vol - 1 ] ) ) {
					continue;
				}
				$siguiente = $max_vol + 1;
				$pid       = $catalogo[ $serie ][ $siguiente ] ?? 0;
				if ( $pid > 0 ) {
					if ( StockStatus::IN_STOCK !== (string) ( $stock[ $pid ] ?? '' ) ) {
						$productos[ $pid ] = ( $productos[ $pid ] ?? 0 ) + 1;
					}
					continue;
				}
				$key            = $serie . '#' . $siguiente;
				$huecos[ $key ] = array(
					'serie'    => $serie,
					'vol'      => $siguiente,
					'clientes' => ( $huecos[ $key ]['clientes'] ?? 0 ) + 1,
				);
			}
		}

		return array(
			'productos' => $productos,
			'huecos'    => array_values( $huecos ),
		);
	}

	/**
	 * Lee de la base los cuatro mapas de continuity_from_maps (cache 6 h).
	 *
	 * @return array{productos: array<int, int>, huecos: array<int, array{serie: string, vol: int, clientes: int}>}
	 */
	private function continuity_signal(): array {
		$cached = get_transient( 'akb_demand_continuity_v1' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		// DISTINCT (una recompra no es avance) y ventana de 24 meses: acota la
		// memoria a escala y saca de la señal al coleccionista que abandonó.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT o.customer_id AS cid, opl.product_id AS pid
				FROM {$wpdb->prefix}wc_order_product_lookup opl
				INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = opl.order_id
					AND o.status IN ('wc-completed','wc-processing','wc-shipping-progress')
				WHERE o.customer_id > 0 AND o.date_created_gmt >= %s",
				gmdate( 'Y-m-d H:i:s', time() - 24 * MONTH_IN_SECONDS )
			)
		);
		$compras = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$compras[ (int) $row->cid ][] = (int) $row->pid;
		}

		// ORDER BY p.ID: con reimpresiones (misma serie+vol en dos productos) el
		// ganador de $catalogo debe ser DETERMINISTA entre rebuilds del transient;
		// y se prefiere la edición EN STOCK — señalar «reponer» la edición vieja
		// teniendo la nueva comprable sería una compra fantasma.
		$rows = $wpdb->get_results(
			"SELECT p.ID AS pid, p.post_title AS titulo, t.slug AS serie, t.name AS serie_nombre, l.stock_status AS stock
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'pa_serie'
			INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			INNER JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = p.ID
			WHERE p.post_type = 'product' AND p.post_status = 'publish'
			ORDER BY p.ID"
		);
		$serie_vol = array();
		$catalogo  = array();
		$stock     = array();
		$nombres   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$vol = \Akibara\Business\Services\VolumeTitle::extract_number( (string) $row->titulo );
			if ( null === $vol || $vol < 1 ) {
				continue;
			}
			$pid                          = (int) $row->pid;
			$serie                        = (string) $row->serie;
			$serie_vol[ $pid ]            = array( 'serie' => $serie, 'vol' => (int) $vol );
			$stock[ $pid ]                = (string) $row->stock;
			$nombres[ $serie ]            = (string) $row->serie_nombre;
			$actual                       = $catalogo[ $serie ][ (int) $vol ] ?? 0;
			if ( 0 === $actual || ( StockStatus::IN_STOCK !== (string) ( $stock[ $actual ] ?? '' ) && StockStatus::IN_STOCK === (string) $row->stock ) ) {
				$catalogo[ $serie ][ (int) $vol ] = $pid;
			}
		}

		$out = self::continuity_from_maps( $compras, $serie_vol, $catalogo, $stock );
		// Nombre legible de la serie para display/prefill (el slug es clave
		// interna; «one-piece 08» como título de ficha rompería la convención
		// de títulos del pipeline de portadas).
		foreach ( $out['huecos'] as $i => $hueco ) {
			$out['huecos'][ $i ]['serie_nombre'] = $nombres[ $hueco['serie'] ] ?? $hueco['serie'];
		}
		set_transient( 'akb_demand_continuity_v1', $out, 6 * HOUR_IN_SECONDS );

		return $out;
	}

	/**
	 * Filas del ranking, con las cuatro señales leídas de la base (cache 1 h).
	 *
	 * Todo SOLO LECTURA sobre columnas indexadas; los huecos del buscador
	 * llegan por la función puente `akibara_search_demand_gaps()` (mismo
	 * contrato que las funciones puente del theme: sin buscador, sin huecos).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	/**
	 * Huecos del buscador, resueltos UNA vez por render.
	 *
	 * La función puente se llamaba dos veces por pantalla: una dentro de
	 * `demand_ranking()` —que sí cachea— y otra al pintar el radar, esta última
	 * SIEMPRE contra la base, transient o no. Se pide una sola vez el máximo que
	 * necesita cualquiera de las dos superficies y cada una recorta lo suyo.
	 *
	 * @param int $limit Cuántos huecos quiere el llamador.
	 * @return array<int, array<string, mixed>>
	 */
	private function demand_gaps( int $limit ): array {
		if ( null === $this->gaps_memo ) {
			$gaps = function_exists( 'akibara_search_demand_gaps' )
				? akibara_search_demand_gaps( 90, self::GAPS_MAX )
				: array();

			$this->gaps_memo = is_array( $gaps ) ? array_values( $gaps ) : array();
		}

		return array_slice( $this->gaps_memo, 0, max( 0, $limit ) );
	}

	/**
	 * URL de «Descartar del radar», pedida al plugin que es dueño de la acción.
	 *
	 * `akibara-search` expone `gap_action_url()` justo para esto. Devuelve ''
	 * cuando el buscador no está: el Centro de Mando degrada mudo, sin enlace,
	 * en vez de ofrecer un botón que no puede funcionar.
	 *
	 * @param string $query Búsqueda normalizada.
	 */
	private static function gap_dismiss_url( string $query ): string {
		$seccion = '\\Akibara\\Search\\SearchAdminSection';
		if ( ! class_exists( $seccion ) || ! method_exists( $seccion, 'gap_action_url' ) ) {
			return '';
		}

		return (string) $seccion::gap_action_url( $seccion::ACTION_GAP_DISMISS, $query );
	}

	/**
	 * Ceba posts y términos de las filas de producto antes de recorrerlas.
	 *
	 * El ranking pinta hasta 15 filas y cada una pedía su título con un SELECT y
	 * su editorial con otro: 30 consultas por render del Centro de Mando, todas
	 * evitables con dos.
	 *
	 * @param array<int, array<string, mixed>> $filas Filas del ranking.
	 */
	private function prime_demand_rows( array $filas ): void {
		if ( ! function_exists( '_prime_post_caches' ) ) {
			return;
		}

		$ids = array();
		foreach ( $filas as $fila ) {
			$id = (int) ( $fila['id'] ?? 0 );
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		if ( array() === $ids ) {
			return;
		}

		// Términos SÍ (la editorial de cada fila), meta no: el ranking no la usa.
		_prime_post_caches( array_values( $ids ), true, false );
	}

	private function demand_ranking(): array {
		$cached = get_transient( 'akb_demand_ranking_v1' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$to_map = static function ( $results ): array {
			$map = array();
			foreach ( is_array( $results ) ? $results : array() as $row ) {
				$map[ (int) $row->pid ] = (int) $row->c;
			}
			return $map;
		};

		$bis = $to_map(
			$wpdb->get_results(
				"SELECT s.product_id AS pid, COUNT(*) AS c
				FROM {$wpdb->prefix}akb_bis_subscribers s
				INNER JOIN {$wpdb->posts} p ON p.ID = s.product_id AND p.post_status = 'publish'
				WHERE s.status = 'subscribed'
				GROUP BY s.product_id ORDER BY c DESC LIMIT 15"
			)
		);

		// Favoritos SOLO de agotados: un favorito comprable no es demanda insatisfecha.
		// El estado entra por `prepare()` desde StockStatus, no escrito en el SQL.
		$wish = $to_map(
			$wpdb->get_results(
				$wpdb->prepare(
					"SELECT w.product_id AS pid, COUNT(*) AS c
					FROM {$wpdb->prefix}akb_wishlist w
					INNER JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = w.product_id AND l.stock_status = %s
					INNER JOIN {$wpdb->posts} p ON p.ID = w.product_id AND p.post_status = 'publish'
					GROUP BY w.product_id ORDER BY c DESC LIMIT 15",
					StockStatus::OUT_OF_STOCK
				)
			)
		);

		// Vendía y se agotó: unidades de 90 días de productos HOY sin stock.
		$since = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
		$sales = $to_map(
			$wpdb->get_results(
				$wpdb->prepare(
					"SELECT opl.product_id AS pid, SUM(opl.product_qty) AS c
					FROM {$wpdb->prefix}wc_order_product_lookup opl
					INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = opl.order_id
						AND o.status IN ('wc-completed','wc-processing','wc-shipping-progress')
					INNER JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = opl.product_id AND l.stock_status = %s
					INNER JOIN {$wpdb->posts} p ON p.ID = opl.product_id AND p.post_status = 'publish'
					WHERE o.date_created_gmt >= %s
					GROUP BY opl.product_id ORDER BY c DESC LIMIT 15",
					StockStatus::OUT_OF_STOCK,
					$since
				)
			)
		);

		$gaps = $this->demand_gaps( self::GAPS_MAX );

		$cont = $this->continuity_signal();

		$reqs = function_exists( 'akibara_search_title_requests' )
			? akibara_search_title_requests( 90 )
			: array();

		$rows = self::score_demand(
			$bis,
			$wish,
			$sales,
			is_array( $gaps ) ? $gaps : array(),
			$cont['productos'] ?? array(),
			$cont['huecos'] ?? array(),
			is_array( $reqs ) ? $reqs : array()
		);

		set_transient( 'akb_demand_ranking_v1', $rows, HOUR_IN_SECONDS );

		return $rows;
	}

	/**
	 * Etiqueta de urgencia por tramos de puntaje. HTML ya escapado.
	 *
	 * Texto + color (nunca color solo): score >= 8 URGENTE, 4-7 Esta semana,
	 * 1-3 Observar. Los cortes son convención simple; lo importante es que el
	 * dueño lea la prioridad sin interpretar números.
	 *
	 * @param int $score Puntaje de la fila.
	 */
	private function demand_urgency_html( int $score ): string {
		if ( $score >= 8 ) {
			return '<span style="display:inline-block;padding:1px 8px;border-radius:10px;background:#fcf0f1;color:#b32d2e;font-weight:600;font-size:11px">URGENTE</span>';
		}
		if ( $score >= 4 ) {
			return '<span style="display:inline-block;padding:1px 8px;border-radius:10px;background:#fcf9e8;color:#996800;font-weight:600;font-size:11px">Esta semana</span>';
		}
		return '<span style="display:inline-block;padding:1px 8px;border-radius:10px;background:#f0f0f1;color:#646970;font-weight:600;font-size:11px">Observar</span>';
	}

	/**
	 * Señales de una fila del ranking, en palabras. HTML ya escapado.
	 *
	 * @param array<string, mixed> $row Fila de score_demand().
	 */
	private function demand_signals_html( array $row ): string {
		$parts = array();
		if ( 'gap' === $row['type'] ) {
			if ( (int) ( $row['solicitudes'] ?? 0 ) > 0 ) {
				$parts[] = sprintf(
					( 1 === (int) $row['solicitudes'] ? '<strong>%d esperando encargo</strong>' : '<strong>%d esperando encargo</strong>' ),
					(int) $row['solicitudes']
				);
			}
			if ( (int) ( $row['s90'] ?? 0 ) > 0 ) {
				$parts[] = sprintf( ( 1 === (int) $row['s90'] ? '%d búsqueda sin resultado en 90 d' : '%d búsquedas sin resultado en 90 d' ), (int) $row['s90'] );
				if ( (int) $row['s30'] > 0 ) {
					$parts[] = sprintf( '%d este mes', (int) $row['s30'] );
				}
			}
		} elseif ( 'serie_gap' === $row['type'] ) {
			$parts[] = sprintf( ( 1 === (int) $row['clientes'] ? '<strong>%d coleccionista va en este tomo</strong> y no tiene ficha' : '<strong>%d coleccionistas van en este tomo</strong> y no tiene ficha' ), (int) $row['clientes'] );
		} else {
			if ( (int) $row['esperando'] > 0 ) {
				$parts[] = sprintf( '<strong>%d esperando el aviso</strong>', (int) $row['esperando'] );
			}
			if ( (int) ( $row['racha'] ?? 0 ) > 0 ) {
				$parts[] = sprintf( ( 1 === (int) $row['racha'] ? '<strong>%d coleccionista va en este tomo</strong>' : '<strong>%d coleccionistas van en este tomo</strong>' ), (int) $row['racha'] );
			}
			if ( (int) $row['ventas'] > 0 ) {
				$parts[] = sprintf( ( 1 === (int) $row['ventas'] ? '%d vendido en 90 d' : '%d vendidos en 90 d' ), (int) $row['ventas'] );
			}
			if ( (int) $row['favoritos'] > 0 ) {
				$parts[] = sprintf( '%d en favoritos', (int) $row['favoritos'] );
			}
		}

		return implode( ' · ', array_map( 'wp_kses_post', $parts ) );
	}

	/**
	 * Líneas del pedido sugerido, agrupadas por editorial.
	 *
	 * Cantidad sugerida = señales de intención directa (esperando + racha + solicitudes de encargo),
	 * mínimo 1: es un punto de partida editable, no un forecast.
	 *
	 * @return array<string, array<int, array{titulo: string, cantidad: int, nota: string}>>
	 */
	public function purchase_order_lines(): array {
		$grupos = array();
		$filas  = $this->demand_ranking();
		$this->prime_demand_rows( $filas );

		foreach ( $filas as $fila ) {
			if ( 'gap' === $fila['type'] ) {
				$editorial = ( '' !== (string) ( $fila['editorial'] ?? '' ) )
					? (string) $fila['editorial']
					: 'Por definir (crear ficha)';
				$cantidad  = max( 1, (int) ( $fila['solicitudes'] ?? 0 ) + (int) round( ( (int) ( $fila['s90'] ?? 0 ) ) / 2 ) );
				$notas     = array();
				if ( (int) ( $fila['solicitudes'] ?? 0 ) > 0 ) {
					$notas[] = sprintf( '%d esperando encargo', (int) $fila['solicitudes'] );
				}
				if ( (int) ( $fila['s90'] ?? 0 ) > 0 ) {
					$notas[] = sprintf( '%d búsquedas 90 d', (int) $fila['s90'] );
				}
				$grupos[ $editorial ][] = array(
					'titulo'   => (string) $fila['query'],
					'cantidad' => $cantidad,
					'nota'     => implode( ' · ', $notas ),
				);
				continue;
			}
			if ( 'serie_gap' === $fila['type'] ) {
				$grupos['Por definir (crear ficha)'][] = array(
					'titulo'   => sprintf( '%s — tomo %d', (string) ( $fila['serie_nombre'] ?? $fila['serie'] ), (int) $fila['vol'] ),
					'cantidad' => max( 1, (int) $fila['clientes'] ),
					'nota'     => sprintf( ( 1 === (int) $fila['clientes'] ? '%d coleccionista en racha' : '%d coleccionistas en racha' ), (int) $fila['clientes'] ),
				);
				continue;
			}

			$id     = (int) $fila['id'];
			$brands = get_the_terms( $id, 'product_brand' );
			$brand  = ( is_array( $brands ) && isset( $brands[0]->name ) ) ? (string) $brands[0]->name : 'Sin editorial';
			$notas  = array();
			if ( (int) $fila['esperando'] > 0 ) {
				$notas[] = sprintf( '%d esperando', (int) $fila['esperando'] );
			}
			if ( (int) ( $fila['racha'] ?? 0 ) > 0 ) {
				$notas[] = sprintf( '%d en racha', (int) $fila['racha'] );
			}
			if ( (int) $fila['ventas'] > 0 ) {
				$notas[] = sprintf( '%d vendidos 90 d', (int) $fila['ventas'] );
			}
			$titulo = (string) get_the_title( $id );
			if ( '' === $titulo ) {
				continue; // Producto eliminado tras el cacheo: no va a un pedido.
			}
			$grupos[ $brand ][] = array(
				'titulo'   => $titulo,
				'cantidad' => max( 1, (int) $fila['esperando'] + (int) ( $fila['racha'] ?? 0 ) ),
				'nota'     => implode( ' · ', $notas ),
			);
		}

		ksort( $grupos );

		return $grupos;
	}

	/**
	 * Descarga CSV del pedido sugerido (admin-post, con nonce y capability).
	 *
	 * @return void
	 */
	public function download_demand_csv(): void {
		if ( ! current_user_can( 'manage_woocommerce' )
			|| ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_key( (string) wp_unslash( $_GET['_wpnonce'] ) ), 'akb_demand_csv' ) ) {
			wp_die( 'Sin permiso.' );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=pedido-sugerido-akibara-' . gmdate( 'Ymd' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		// BOM UTF-8: sin él, Excel en Windows muestra las tildes rotas.
		fwrite( $out, "\xEF\xBB\xBF" );
		// Defensa contra inyección de fórmulas en Excel: un valor que empiece en
		// =+-@ o tab se prefija con apóstrofo (los datos vienen de títulos y
		// nombres de admin, pero el blindaje cuesta tres líneas).
		$celda = static fn( $v ): string => preg_match( '/^[=+\-@\t]/', (string) $v ) ? "'" . (string) $v : (string) $v;
		fputcsv( $out, array( 'Editorial', 'Título', 'Cantidad sugerida', 'Señales' ) );
		foreach ( $this->purchase_order_lines() as $editorial => $lineas ) {
			foreach ( $lineas as $linea ) {
				fputcsv( $out, array( $celda( $editorial ), $celda( $linea['titulo'] ), $linea['cantidad'], $celda( $linea['nota'] ) ) );
			}
		}
		fclose( $out );
		exit;
	}

	/**
	 * Mapa CENTRAL de los interruptores de módulo de business (paso 7 del
	 * blueprint de paneles, versión útil): una sola fuente para pintar
	 * estados. Los módulos conservan su gate propio en runtime — este mapa
	 * es de LECTURA para el panel, no un nivel de indirección nuevo.
	 *
	 * @return array<string, array{label:string,option:string,default:string,tab:string}>
	 */
	public static function module_toggles(): array {
		return array(
			'announcement'      => array( 'label' => 'Barra de anuncios', 'option' => 'akibara_announcement_enabled', 'default' => 'yes', 'tab' => 'announcement' ),
			'fsp'               => array( 'label' => 'Envío gratis', 'option' => 'akibara_fsp_enabled', 'default' => 'yes', 'tab' => 'free_shipping_progress' ),
			'native_cart'       => array( 'label' => 'Carrito', 'option' => 'akibara_native_cart_enabled', 'default' => 'yes', 'tab' => 'native_cart' ),
			// El estado de cada interruptor se consulta al módulo que lo gobierna.
			'taxonomy_discount' => array( 'label' => 'Descuentos', 'option' => TaxonomyDiscountSettings::OPTION_ENABLED, 'default' => TaxonomyDiscountSettings::DEFAULT_ENABLED, 'tab' => 'taxonomy_discount' ),
			'address'           => array( 'label' => 'Autocompletar dirección', 'option' => 'akibara_address_autocomplete_enabled', 'default' => 'no', 'tab' => 'address' ),
		);
	}

	/**
	 * Tarjeta de estado de los 4 plugins first-party (paso 6 del blueprint):
	 * representativa, barata (cálculo en el render, cero framework) y cada
	 * cifra es un ENLACE a donde se arregla. Cada bloque degrada a «no
	 * disponible» por su cuenta — la tarjeta jamás rompe la página.
	 *
	 * @return void
	 */
	private function render_first_party_status(): void {
		echo '<ul class="akb-dashboard-plugins" style="margin:0;line-height:1.9">';

		// business: sus módulos con interruptor.
		$apagados = array();
		foreach ( self::module_toggles() as $mod ) {
			if ( 'yes' !== (string) get_option( $mod['option'], $mod['default'] ) ) {
				$apagados[] = sprintf(
					'<a href="%s">%s</a>',
					esc_url( admin_url( 'admin.php?page=akibara-panel&tab=' . $mod['tab'] ) ),
					esc_html( $mod['label'] )
				);
			}
		}
		printf(
			'<li>🧩 <strong>Business</strong> %s · %s</li>',
			esc_html( defined( 'AKIBARA_BUSINESS_VERSION' ) ? AKIBARA_BUSINESS_VERSION : '' ),
			array() === $apagados
				? 'módulos: todos encendidos'
				: wp_kses_post( 'apagados: ' . implode( ', ', $apagados ) )
		);

		// search: índice y superficies.
		global $wpdb;
		$tabla_docs = $wpdb->prefix . 'akb_search_docs';
		$docs       = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tabla_docs ) ) === $tabla_docs
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tabla_docs}" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabla propia verificada.
			: -1;
		printf(
			'<li>🔎 <strong>Buscador</strong> · %s · <a href="%s">panel →</a></li>',
			$docs >= 0 ? esc_html( number_format_i18n( $docs ) ) . ' productos indexados' : 'índice no disponible',
			esc_url( admin_url( 'admin.php?page=akibara-search' ) )
		);

		// rewards: saldo vivo del programa (SUM del ledger = la verdad).
		if ( class_exists( '\\Akibara\\Rewards\\Plugin' ) ) {
			$tabla_ledger = $wpdb->prefix . 'akb_rewards_ledger';
			$saldo        = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tabla_ledger ) ) === $tabla_ledger
				? (int) $wpdb->get_var( "SELECT COALESCE(SUM(delta),0) FROM {$tabla_ledger}" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabla propia verificada.
				: -1;
			printf(
				'<li>⭐ <strong>Puntos y Reseñas</strong> · %s · <a href="%s">configurar →</a></li>',
				$saldo >= 0 ? esc_html( number_format_i18n( $saldo ) ) . ' puntos vivos en circulación' : 'ledger no disponible',
				esc_url( admin_url( 'admin.php?page=akb-rewards-settings' ) )
			);
		} else {
			echo '<li>⭐ <strong>Puntos y Reseñas</strong> · plugin inactivo</li>';
		}

		// cart-checkout-blocks: contrato estable de observación (blueprint:
		// leer la option cruda a propósito; el plugin no tiene ajustes).
		// La option es un registro (array) cuando hubo observación; el render
		// no asume su forma (el Array-to-string lo cazó el propio gate QA).
		$mp     = get_option( 'akb_blocks_mp_dequeue_observed', '' );
		$estado = 'operando (sin observaciones)';
		if ( is_array( $mp ) && array() !== $mp ) {
			$estado = sprintf( 'observación registrada (%d dato%s)', count( $mp ), 1 === count( $mp ) ? '' : 's' );
		} elseif ( is_string( $mp ) && '' !== $mp ) {
			$estado = 'observación: ' . $mp;
		}
		printf(
			'<li>🧾 <strong>Cart/Checkout Blocks</strong> · %s</li>',
			esc_html( $estado )
		);

		echo '</ul>';
	}

	/**
	 * Radar de búsquedas sin resultados del Centro de Mando. Ofrece la misma
	 * señal operativa del panel del buscador desde la cabina diaria del dueño.
	 */
	public function render_demand_gaps_radar(): void {
		// La función puente ya descarta los typos que el corrector rescata y las
		// búsquedas que el dueño descartó del radar.
		$akb_huecos = $this->demand_gaps( 8 );
		if ( empty( $akb_huecos ) ) {
			return;
		}
		?>
				<details style="margin-top:10px">
					<summary style="cursor:pointer;font-weight:600">🔎 Buscados y no encontrados (90 días)</summary>
					<div class="akb-demand-scroll">
					<table class="widefat striped" style="margin-top:8px">
						<thead><tr><th scope="col">Título buscado</th><th scope="col">90 días</th><th scope="col">Este mes</th><th scope="col"><span class="screen-reader-text">Acciones</span></th></tr></thead>
						<tbody>
						<?php foreach ( $akb_huecos as $akb_hueco ) : ?>
							<tr>
								<td><strong><?php echo esc_html( (string) $akb_hueco['query'] ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( (int) $akb_hueco['s90'] ) ); ?></td>
								<td><?php echo (int) $akb_hueco['s30'] > 0 ? esc_html( number_format_i18n( (int) $akb_hueco['s30'] ) ) : '<span style="color:#646970">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ramas ya escapadas. ?></td>
								<td><a class="button button-small button-primary" href="<?php echo esc_url( add_query_arg( array( 'post_type' => 'product', 'post_title' => rawurlencode( (string) $akb_hueco['query'] ) ), admin_url( 'post-new.php' ) ) ); ?>">Crear ficha<span class="screen-reader-text"> de «<?php echo esc_html( (string) $akb_hueco['query'] ); ?>»</span></a>
									<?php
									// La acción y su nonce los define el buscador, que vive en OTRO
									// riel: reproducirlos a mano acá (dos literales en la misma
									// línea) significaba que renombrarlos allá dejaba este enlace
									// muerto sin que nada fallara. Se consume su puente, y si el
									// plugin no está, el enlace simplemente no se pinta.
									$akb_descartar = self::gap_dismiss_url( (string) $akb_hueco['query'] );
									?>
									<?php if ( '' !== $akb_descartar ) : ?>
									<a style="color:#b32d2e;margin-left:6px" href="<?php echo esc_url( $akb_descartar ); ?>">Descartar<span class="screen-reader-text"> «<?php echo esc_html( (string) $akb_hueco['query'] ); ?>» del radar</span></a>
									<?php endif; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
					<p class="description" style="margin-bottom:0">«Este mes» en guion = demanda antigua, prioridad baja. «Descartar» oculta esa búsqueda del radar (restaurable en Buscador → Búsquedas; el log se poda solo a 180 días). <a href="<?php echo esc_url( admin_url( 'admin.php?page=akibara-search&sub=busquedas' ) ); ?>">Ver el log completo en Buscador → Búsquedas →</a></p>
				</details>
		<?php
	}

	public function render_admin_section(): void {
		$upsells_module = Plugin::instance()->module( 'upsells_autofill' );
		$upsells_stats  = $upsells_module ? $upsells_module->get_coverage_stats() : array();
		$pct            = (float) ( $upsells_stats['pct'] ?? 0 );
		$alerts         = $this->health_alerts();

		// Las alertas de la era FacetWP (watchdogs en 1.61.0, ShopLoopMonitor
		// en 1.62.0) murieron con el cutover — la salud del índice del buscador
		// propio vive en su propio panel (Buscador → Índice, HealthReport).
		?>
		<div class="akb-dashboard">
			<h2>Centro de Mando Akibara</h2>
			<p class="akb-dashboard-intro">Resumen global del ecosistema y alertas del sistema.</p>

			<div class="akb-dashboard-grid">

				<div class="akb-dashboard-card">
					<h3>Plugins Akibara</h3>
					<?php $this->render_first_party_status(); ?>
				</div>


				<!-- Alertas del Sistema -->
				<div class="akb-dashboard-card<?php echo empty( $alerts ) ? '' : ' has-alerts'; ?>">
					<h3>Alertas del Sistema</h3>
					<?php if ( empty( $alerts ) ) : ?>
						<p class="status-ok">✅ Todos los sistemas operando normalmente.</p>
					<?php else : ?>
						<?php foreach ( $alerts as $alert ) : ?>
							<div class="alert-item <?php echo esc_attr( $alert['level'] ); ?>">
									<?php echo wp_kses_post( $alert['html'] ); ?>
									<?php
									if ( isset( $alert['form'] ) ) {
										echo wp_kses( (string) $alert['form'], self::DISMISS_FORM_TAGS );
									}
									?>
								</div>
						<?php endforeach; ?>
					<?php endif; ?>
					<p class="akb-dashboard-health-links"><a href="<?php echo esc_url( admin_url( 'admin.php?page=akibara-search' ) ); ?>">Salud del Buscador →</a></p>
				</div>

				<!-- Lo más solicitado: el ranking que decide compras y fichas.
				     Ancho COMPLETO: es la única tarjeta con tabla de 5 columnas
				     (min-content ~471px) y la única accionable del panel
				     (auditoría UX 2026-08-31). -->
				<div class="akb-dashboard-card akb-dashboard-card--wide">
					<h3>Lo más solicitado</h3>
					<?php
				$demanda   = $this->demand_ranking();
				$max_score = empty( $demanda ) ? 1 : max( 1, (int) $demanda[0]['score'] );
				$this->prime_demand_rows( $demanda );
				?>
					<?php if ( empty( $demanda ) ) : ?>
						<p class="status-ok">Sin demanda insatisfecha registrada — catálogo al día.</p>
					<?php else : ?>
						<div class="akb-demand-scroll">
						<table class="widefat striped">
							<thead><tr><th scope="col" style="width:2em">#</th><th scope="col">Qué</th><th scope="col">Urgencia</th><th scope="col">Señales</th><th scope="col">Acción</th></tr></thead>
							<tbody>
							<?php $akb_pos = 0; ?>
							<?php foreach ( $demanda as $fila ) : ?>
								<?php $akb_pos++; ?>
								<tr>
									<td style="font-size:13px;font-weight:700;color:#646970">#<?php echo (int) $akb_pos; ?></td>
									<?php if ( 'serie_gap' === $fila['type'] ) : ?>
										<td><strong><?php echo esc_html( sprintf( '%s — tomo %d', (string) ( $fila['serie_nombre'] ?? $fila['serie'] ), (int) $fila['vol'] ) ); ?></strong>
											<span aria-hidden="true" style="display:block;height:6px;border-radius:3px;margin-top:4px;background:#7c3aed;width:<?php echo esc_attr( (string) max( 6, (int) round( 100 * (int) $fila['score'] / $max_score ) ) ); ?>%"></span></td>
										<td><?php echo $this->demand_urgency_html( (int) $fila['score'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper ya escapa. ?></td>
										<td><?php echo $this->demand_signals_html( $fila ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper ya escapa. ?></td>
										<td><a class="button button-small button-primary" href="<?php echo esc_url( add_query_arg( array( 'post_type' => 'product', 'post_title' => rawurlencode( sprintf( '%s %02d', (string) ( $fila['serie_nombre'] ?? $fila['serie'] ), (int) $fila['vol'] ) ) ), admin_url( 'post-new.php' ) ) ); ?>">Crear ficha<span class="screen-reader-text"> de «<?php echo esc_html( sprintf( '%s %02d', (string) ( $fila['serie_nombre'] ?? $fila['serie'] ), (int) $fila['vol'] ) ); ?>»</span></a></td>
									<?php elseif ( 'gap' === $fila['type'] ) : ?>
										<td><strong><?php echo esc_html( (string) $fila['query'] ); ?></strong>
											<span aria-hidden="true" style="display:block;height:6px;border-radius:3px;margin-top:4px;background:#7c3aed;width:<?php echo esc_attr( (string) max( 6, (int) round( 100 * (int) $fila['score'] / $max_score ) ) ); ?>%"></span></td>
										<td><?php echo $this->demand_urgency_html( (int) $fila['score'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper ya escapa. ?></td>
										<td><?php echo $this->demand_signals_html( $fila ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper ya escapa. ?></td>
										<td><a class="button button-small button-primary" href="<?php echo esc_url( add_query_arg( array( 'post_type' => 'product', 'post_title' => rawurlencode( (string) $fila['query'] ) ), admin_url( 'post-new.php' ) ) ); ?>">Crear ficha<span class="screen-reader-text"> de «<?php echo esc_html( (string) $fila['query'] ); ?>»</span></a></td>
									<?php else : ?>
										<td><strong><?php echo esc_html( get_the_title( (int) $fila['id'] ) ?: '(producto eliminado)' ); ?></strong>
											<span aria-hidden="true" style="display:block;height:6px;border-radius:3px;margin-top:4px;background:#2271b1;width:<?php echo esc_attr( (string) max( 6, (int) round( 100 * (int) $fila['score'] / $max_score ) ) ); ?>%"></span></td>
										<td><?php echo $this->demand_urgency_html( (int) $fila['score'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper ya escapa. ?></td>
										<td><?php echo $this->demand_signals_html( $fila ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper ya escapa. ?></td>
										<td><a class="button button-small button-primary" href="<?php echo esc_url( (string) get_edit_post_link( (int) $fila['id'] ) ); ?>">Reponer<span class="screen-reader-text"> «<?php echo esc_html( get_the_title( (int) $fila['id'] ) ?: '(producto eliminado)' ); ?>»</span></a></td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						</div>
						<div style="margin-top:12px;padding:10px 14px;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:4px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
							<span style="font-size:13px;color:#5b21b6"><strong>📬 Solicitudes de Encargo:</strong> Revisa el listado de clientes que pidieron tomos por el buscador, con su WhatsApp y correo listos para contactar.</span>
							<a class="button button-secondary" style="color:#6d28d9;border-color:#7c3aed" href="<?php echo esc_url( admin_url( 'admin.php?page=akibara-search&sub=encargos' ) ); ?>">Gestionar Encargos →</a>
						</div>
						<details style="margin-top:10px">
							<summary style="cursor:pointer;font-weight:600">📋 Pedido sugerido por editorial</summary>
							<pre style="background:#f6f7f7;border:1px solid #c3c4c7;border-radius:4px;padding:10px;overflow:auto;font-size:12px"><?php
							foreach ( $this->purchase_order_lines() as $akb_editorial => $akb_lineas ) {
								echo esc_html( $akb_editorial ) . "\n";
								foreach ( $akb_lineas as $akb_linea ) {
									echo esc_html( sprintf( '  %d × %s  (%s)', $akb_linea['cantidad'], $akb_linea['titulo'], $akb_linea['nota'] ) ) . "\n";
								}
							}
							?></pre>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=akb_demand_csv' ), 'akb_demand_csv' ) ); ?>">Descargar CSV</a>
						</details>
						<p class="description">La barra es la urgencia: azul = reponer un producto tuyo, morado = crear la ficha de un título que te buscan. Cada persona esperando el aviso vale ×3, cada coleccionista en racha y cada búsqueda sin resultado ×2, favoritos y ventas ×1. El ranking se actualiza cada hora (la señal de colecciones, cada 6).</p>
					<?php endif; ?>
					<?php $this->render_demand_gaps_radar(); ?>
				</div>

				<!-- Cobertura Upsells -->
				<div class="akb-dashboard-card">
					<h3>Autofill de Upsells</h3>
					<div class="upsell-progress">
						<span class="pct"><?php echo esc_html( number_format( $pct, 2 ) ); ?>%</span>
						<span class="lbl">Cobertura del catálogo manga</span>
					</div>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=akibara-panel&tab=upsells_autofill' ) ); ?>" class="button">Ver detalles</a></p>
				</div>

				<!-- Enlaces Rápidos: directorio de TODAS las superficies de
				     configuración, incluidas las que viven fuera de este panel
				     (la config está repartida en 4 lugares y el dueño no tiene
				     cómo saberlo — auditoría 2026-07-28). -->
				<div class="akb-dashboard-card">
					<h3>Accesos Rápidos</h3>
					<ul class="quick-links">
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=akibara-panel&tab=home_hero' ) ); ?>">🖼️ Configurar Slider de Inicio</a></li>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=akibara-panel&tab=' . OrderPreventaSettings::PANEL_TAB_ID ) ); ?>">📦 Pedidos y preventa</a></li>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=akibara-panel&tab=taxonomy_discount' ) ); ?>">🏷️ Descuentos Masivos</a></li>
					</ul>
					<h4 class="quick-links-subtitle">Fuera de este panel</h4>
					<ul class="quick-links">
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=akibara-search' ) ); ?>">🔍 Buscador (índice, facetas, sinónimos)</a></li>
						<li><a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=pa_pais&post_type=product' ) ); ?>">🌎 Días de tránsito por país (Atributos → País)</a></li>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=email' ) ); ?>">✉️ Textos de emails (WooCommerce → Emails)</a></li>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ); ?>">🚚 Envíos y Blue Express (WooCommerce → Envío)</a></li>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ); ?>">💳 Medios de pago (MercadoPago, Flow)</a></li>
					</ul>
				</div>

			</div>
		</div>

		<style>
			.akb-dashboard { margin-top: 20px; max-width: none; }
			.akb-dashboard h2 { font-size: 20px; margin-bottom: 5px; }
			.akb-dashboard-intro { color: #50575e; margin-bottom: 24px; }
			/* 2 columnas anchas en vez de 3 angostas, y align-items:start para
			   que la tarjeta alta no estire a las vecinas (auditoría UX
			   2026-08-31: min FIJO en minmax desactiva el auto-minimum del
			   grid item — la tabla del radar salía 185px fuera de su card). */
			.akb-dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); align-items: start; gap: 20px; }
			.akb-dashboard-card--wide { grid-column: 1 / -1; }
			/* Tabla ancha: scrollea DENTRO de su tarjeta, jamás empuja el
			   layout. position:relative NO es decorativo: sin él los
			   screen-reader-text absolutos resuelven contra un ancestro de
			   más arriba y empujan el scrollWidth del documento (la trampa
			   documentada en .akb-facets-scroll). */
			.akb-demand-scroll { position: relative; overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 0 -20px; padding: 0 20px; }
			.akb-demand-scroll > table { min-width: 520px; }
			.akb-dashboard-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px;  min-width: 0; }
			.akb-dashboard-card.has-alerts { border-color: #d63638; box-shadow: 0 0 0 1px #d63638; }
			.akb-dashboard-card h3 { margin: 0 0 15px; font-size: 15px; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px; }
			.status-ok { color: #007017; font-weight: 500; }
			.alert-item { padding: 10px; margin-bottom: 10px; border-left: 4px solid; background: #f6f7f7; font-size: 13px; line-height: 1.5; }
			.alert-item.error { border-color: #d63638; }
			.alert-item.warning { border-color: #f0b849; }
			.alert-item a { color: #d63638; font-weight: bold; text-decoration: none; }
			.akb-dashboard-contact { margin: 10px 0 0; padding-left: 18px; }
			.akb-dashboard-contact li { margin-bottom: 10px; }
			.akb-dashboard-contact em { color: #50575e; font-style: normal; }
			.akb-dashboard-dismiss { margin-top: 10px; }
			.alert-item a:hover { text-decoration: underline; }
			.upsell-progress { display: flex; flex-direction: column; margin-bottom: 15px; }
			.upsell-progress .pct { font-size: 22px; font-weight: bold; color: #D90010; line-height: 1; }
			.upsell-progress .lbl { font-size: 12px; color: #50575e; text-transform: uppercase; margin-top: 4px; }
			.akb-dashboard-health-links { margin: 12px 0 0; }
			.akb-dashboard-health-links a { text-decoration: none; font-weight: 500; }
			.quick-links-subtitle { margin: 16px 0 8px; font-size: 12px; color: #50575e; text-transform: uppercase; letter-spacing: 0.03em; }
			.quick-links { margin: 0; padding: 0; list-style: none; }
			.quick-links li { margin-bottom: 10px; }
			.quick-links a { text-decoration: none; color: #2271b1; font-weight: 500; display: block; padding: 6px 10px; border-radius: 4px; background: #f6f7f7; }
			.quick-links a:hover { background: #f0f0f1; color: #135e96; }
		</style>
		<?php
	}
}
