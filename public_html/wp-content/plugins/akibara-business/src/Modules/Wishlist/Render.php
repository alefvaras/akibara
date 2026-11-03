<?php
/**
 * Render — HTML del endpoint /mi-cuenta/favoritos/.
 *
 * Dos estados:
 *   1. Empty: ícono corazón + título + subtítulo + CTA tienda. Compact (panel
 *      narrow de mi-cuenta).
 *   2. Items: grid de productos con `wc_get_template_part('content', 'product')`
 *      para heredar el styling brand del child theme. Cada card tiene un botón
 *      "Quitar" inline con AJAX que reusa el endpoint toggle.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Wishlist;

defined( 'ABSPATH' ) || exit;

final class Render {

	/**
	 * Cota dura de cards por render. Cada card cuesta un
	 * `wc_get_template_part('content','product')` (instancia el producto, corre
	 * los hooks del loop, toca la meta cache), y ni el alta ni el storage tienen
	 * tope: sin esto una wishlist grande arma la página entera de una pasada.
	 * No es paginación — es defensa de cota; el lead sigue mostrando el total real.
	 */
	private const MAX_ITEMS_RENDERED = 100;

	public function account_endpoint_html(): string {
		// El estado vacío puede no imprimir ningún corazón (sin cross-sell) y
		// aun así necesita la hoja: `.akb-wl-empty__icon` vive en wishlist.css.
		Wishlist::ensure_assets();

		$total = Storage::count();
		if ( 0 === $total ) {
			return $this->empty_state_html();
		}

		$current_page = max( 1, isset( $_GET['wl_page'] ) ? absint( $_GET['wl_page'] ) : 1 );
		$total_pages  = max( 1, (int) ceil( $total / self::MAX_ITEMS_RENDERED ) );
		if ( $current_page > $total_pages ) {
			$current_page = $total_pages;
		}

		$offset = ( $current_page - 1 ) * self::MAX_ITEMS_RENDERED;
		$ids    = Storage::product_ids( null, self::MAX_ITEMS_RENDERED, $offset );
		if ( empty( $ids ) ) {
			return $this->empty_state_html();
		}

		return $this->items_grid_html( $ids, $total, $current_page, $total_pages );
	}

	private function empty_state_html(): string {
		$shop_url = method_exists( '\\Akibara\\Business\\Services\\NavigationLinks', 'shop_url' )
			? \Akibara\Business\Services\NavigationLinks::shop_url()
			: home_url( '/tienda/' );

		ob_start();
		?>
		<section class="akb-empty akb-wl-empty" aria-labelledby="akb-wl-empty-title">
			<div class="akb-empty__hero">
				<svg class="akb-wl-empty__icon" width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false">
					<path stroke-linecap="round" stroke-linejoin="round" d="m12 21-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.18L12 21z"/>
				</svg>
				<h2 id="akb-wl-empty-title" class="akb-empty__title">Aún no guardas favoritos</h2>
				<p class="akb-empty__subtitle">Toca el corazón en cualquier producto para guardarlo acá y volver cuando quieras comprarlo.</p>
				<div class="akb-empty__cta-row">
					<a class="akb-empty__btn akb-empty__btn--primary" href="<?php echo esc_url( $shop_url ); ?>">Explorar catálogo</a>
				</div>
			</div>
			<?php
			/*
			 * Cross-sell «Más vendidos»: 4 productos para enseñar la mecánica del
			 * corazón. Lo pinta el template-part del theme, que es el dueño del
			 * bloque (IDs cacheados, query, grilla y clases). Acá vivía la CUARTA
			 * copia a mano del mismo marcado —justo el que el theme extrajo para
			 * matar la triplicación— y ya había drifteado: a la sección le faltaba
			 * la clase `woocommerce` que sí ponen las otras tres.
			 *
			 * Mismo guard que ContactoForm: con un theme sin la parte, el estado
			 * vacío se queda sin cross-sell en vez de romperse.
			 */
			if ( '' !== locate_template( 'template-parts/empty/cross-sell.php' ) ) {
				get_template_part(
					'template-parts/empty/cross-sell',
					null,
					array(
						'heading_id'   => 'akb-wl-empty-popular-title',
						'heading_tag'  => 'h2',
						'heading_text' => 'Más vendidos',
					)
				);
			}
			?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private function items_grid_html( array $product_ids, ?int $total = null, int $current_page = 1, int $total_pages = 1 ): string {
		$args = array(
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'post__in'            => $product_ids,
			'orderby'             => 'post__in',
			'posts_per_page'      => self::MAX_ITEMS_RENDERED,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'tax_query'           => array(
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => array( 'exclude-from-catalog' ),
					'operator' => 'NOT IN',
				),
			),
		);
		$query = new \WP_Query( $args );

		// Conteo desde el Storage si no se pasó: la lista viene recortada
		// a MAX_ITEMS_RENDERED y contarla mentiría sobre cuántos guardó el usuario.
		if ( null === $total ) {
			$total = Storage::count();
		}
		// MAX_ITEMS_RENDERED acota IDs antes de consultar. Contar esos IDs aparte
		// de las tarjetas pintadas distingue la cota de defensa de las exclusiones
		// por productos ocultos o despublicados, sin afirmar una indisponibilidad falsa.

		$pintados = (int) $query->post_count;
		$aviso    = self::aviso_grilla( $total, count( $product_ids ), $pintados );

		ob_start();
		?>
		<div class="woocommerce akb-wl-grid-wrap">
			<p class="akb-wl-grid__lead">
				<?php
				echo esc_html(
					1 === $total
						? '1 producto guardado'
						: sprintf( '%d productos guardados', $total )
				);
				?>
				<?php if ( '' !== $aviso ) : ?>
					<span class="akb-wl-grid__nota"><?php echo esc_html( $aviso ); ?></span>
				<?php endif; ?>
			</p>

			<nav class="akb-wl-filters" aria-label="Filtrar favoritos">
				<button type="button" class="akb-wl-filter-tab is-active" data-wl-filter="all">Todos (<?php echo esc_html( (string) $total ); ?>)</button>
				<button type="button" class="akb-wl-filter-tab" data-wl-filter="instock">🟢 En Bodega · Entrega Inmediata</button>
				<button type="button" class="akb-wl-filter-tab" data-wl-filter="other">📦 Preventas y Agotados</button>
			</nav>

			<div class="akb-wl-empty-filter" style="display:none;padding:32px 16px;text-align:center;background:var(--aki-surface-2,#1a1a1a);border:1px solid var(--aki-border,#2a2a2e);margin:16px 0 24px">
				<p style="margin:0 0 12px;font-size:1rem;color:var(--aki-gray-300,#b0b0b0)">No tienes favoritos en esta categoría.</p>
				<button type="button" class="akb-wl-filter-tab akb-wl-btn-reset" data-wl-filter="all" style="background:var(--aki-buy-bg,#CC0010);color:#fff;border:none;padding:8px 18px;font-weight:700;cursor:pointer">Ver todos los favoritos</button>
			</div>

			<?php if ( $query->have_posts() ) : ?>
				<ul class="products columns-3 akb-wl-grid">
					<?php
					/**
					 * `content-product.php` (WC core) YA abre su propio `<li class="product...">`,
					 * por eso NO envolvemos en `<li>` propio acá (eso causaba doble `<li>` y
					 * cards "fantasma" vacíos en el grid).
					 *
					 * El botón corazón inyectado por Button.php en
					 * `woocommerce_after_shop_loop_item_title` actúa como toggle: en este
					 * contexto un click lo quita de favoritos. No agregamos un botón
					 * "Quitar" extra para evitar duplicación UX.
					 */
					while ( $query->have_posts() ) :
						$query->the_post();
						wc_get_template_part( 'content', 'product' );
					endwhile;
					wp_reset_postdata();
					?>
				</ul>

				<?php if ( $total_pages > 1 ) : ?>
					<nav class="woocommerce-pagination akb-wl-pagination" aria-label="Paginación de favoritos">
						<ul class="page-numbers">
							<?php if ( $current_page > 1 ) : ?>
								<li><a class="prev page-numbers" href="<?php echo esc_url( add_query_arg( 'wl_page', $current_page - 1 ) ); ?>">← Anterior</a></li>
							<?php endif; ?>
							<li><span class="page-numbers current"><?php echo esc_html( sprintf( 'Página %d de %d', $current_page, $total_pages ) ); ?></span></li>
							<?php if ( $current_page < $total_pages ) : ?>
								<li><a class="next page-numbers" href="<?php echo esc_url( add_query_arg( 'wl_page', $current_page + 1 ) ); ?>">Siguiente →</a></li>
							<?php endif; ?>
						</ul>
					</nav>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Arma el aviso de la grilla sin fundir las dos causas de que falten
	 * tarjetas. Puro y determinista para poder
	 * probarlo por comportamiento, sin WP_Query ni plantillas.
	 *
	 * @param int $total     Total guardado, de Storage::count().
	 * @param int $devueltos Cuántos IDs llegaron a consultarse (recortados
	 *                       por MAX_ITEMS_RENDERED antes de armar el WP_Query;
	 *                       de estos NO se sabe si están disponibles).
	 * @param int $pintados  Cuántos sobrevivieron a la consulta (post_count).
	 */
	public static function aviso_grilla( int $total, int $devueltos, int $pintados ): string {
		$recortados = max( 0, $total - $devueltos );
		$ocultos    = max( 0, $devueltos - $pintados );

		if ( 0 === $pintados && 0 === $recortados ) {
			return 'Ninguno está disponible ahora mismo: puede que estén agotados o retirados del catálogo.';
		}

		$partes = array();
		if ( 0 === $pintados && $recortados > 0 ) {
			// Ni siquiera lo mostrado se salvó, y encima hay más sin consultar:
			// no se puede decir "ninguno" del total, porque de los recortados
			// no se sabe nada.
			$partes[] = sprintf(
				'Ninguno de los %1$d que consultamos está disponible ahora mismo, y tienes %2$d guardados más que no llegamos a consultar.',
				$devueltos,
				$recortados
			);

			return implode( ' ', $partes );
		}

		if ( $ocultos > 0 ) {
			$partes[] = 1 === $ocultos
				? '1 de los que guardaste no está disponible ahora mismo.'
				: sprintf( '%d de los que guardaste no están disponibles ahora mismo.', $ocultos );
		}
		if ( $recortados > 0 ) {
			// Estos SÍ están disponibles hasta donde se sabe: la única razón de
			// que no salgan es la cota de defensa, nunca se consultaron.
			$partes[] = sprintf( 'Mostramos los %d más recientes.', $pintados );
		}

		return implode( ' ', $partes );
	}
}
