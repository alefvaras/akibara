<?php
/**
 * Mi colección — vista privada, server-rendered y sin JavaScript.
 *
 * @var array<string,mixed> $data Datos normalizados por Renderer.
 *
 * @package Akibara\Business
 */

defined( 'ABSPATH' ) || exit;

$akibara_plural = static function ( int $count, string $singular, string $plural_form ): string {
	return 1 === $count ? $singular : $plural_form;
};
?>

<div class="akb-account-collection" data-status="<?php echo esc_attr( (string) $data['status'] ); ?>">
	<?php if ( ! in_array( $data['status'], array( 'unavailable', 'empty' ), true ) && ! $data['only_unclassified'] ) : ?>
		<section class="akb-account-collection__intro" aria-labelledby="akb-collection-summary-title">
			<h2 id="akb-collection-summary-title" class="screen-reader-text">Resumen de tu colección</h2>
			<p class="akb-account-collection__lead">Tus tomos comprados en Akibara, ordenados por edición, con el siguiente paso que podemos identificar de forma segura.</p>
			<?php if ( '' !== (string) $data['delay_notice'] ) : ?>
				<p class="akb-account-collection__delay-note"><?php echo esc_html( (string) $data['delay_notice'] ); ?></p>
			<?php endif; ?>

			<dl class="akb-account-collection__summary" aria-label="Resumen de tu colección">
				<div>
					<dt><?php echo esc_html( $akibara_plural( (int) $data['series_count'], 'Edición', 'Ediciones' ) ); ?></dt>
					<dd><?php echo esc_html( (string) $data['series_count'] ); ?></dd>
				</div>
				<div>
					<dt><?php echo esc_html( (string) $data['owned_label'] ); ?></dt>
					<dd>
						<?php if ( is_int( $data['owned_total'] ) ) : ?>
							<?php echo esc_html( (string) $data['owned_total'] ); ?>
						<?php else : ?>
							<span class="akb-account-collection__summary-text">Sin cifra segura</span>
						<?php endif; ?>
					</dd>
				</div>
				<div>
					<dt>Ediciones completas</dt>
					<dd><?php echo esc_html( (string) $data['complete_total'] ); ?></dd>
				</div>
			</dl>

			<details class="akb-account-collection__help">
				<summary>Cómo funciona este asistente</summary>
				<p>Cada tomo identificado cuenta una vez, aunque lo hayas comprado más de una vez. No contamos pedidos cancelados o reembolsados. Sólo usamos compras asociadas de forma verificada a tu cuenta y no suponemos que no tengas tomos comprados en otra tienda.</p>
			</details>

			<?php if ( ! empty( $data['rewards_banner'] ) ) : ?>
				<aside class="akb-account-collection__rewards-banner" aria-label="Puntos Akibara">
					<span class="akb-account-collection__rewards-icon" aria-hidden="true">📸</span>
					<div class="akb-account-collection__rewards-text">
						<strong><?php echo esc_html( sprintf( '¿Ya leíste tus tomos? Solicita %s con tu reseña con foto', (string) $data['rewards_points'] ) ); ?></strong>
						<p>La invitación por correo permite reseñar y solicitar puntos con foto obligatoria; puedes recuperar su enlace vigente desde <a href="<?php echo esc_url( $data['orders_url'] ); ?>">Mis pedidos</a> (los tomos marcados manualmente en tu estantería no califican para puntos).</p>
					</div>
				</aside>
			<?php endif; ?>
		</section>

	<?php endif; ?>
	<?php if ( true === $data['follow_all']['available'] ) : ?>
		<?php
		// Interruptor GLOBAL de los avisos de colección. Formulario POST con
		// nonce, sin JavaScript: el servidor es la autoridad y el estado
		// pintado es el real en cada carga. Sigue al resumen y precede las ramas de estado;
		// se muestra en todas —vacía y también «no disponible»—, porque el
		// consentimiento no depende de tener tomos ni de que la vista de
		// compras cargue: quien se dio de baja desde un correo tiene que
		// poder reencender desde aquí, que es adonde apunta la página «Listo».
		// Los literales son del dueño y viven en la plantilla, como los del
		// botón por serie.
		$akibara_follow_all_on     = true === $data['follow_all']['enabled'];
		$akibara_follow_series_off = (int) $data['follow_all']['series_off_count'];
		?>
		<details class="akb-account-collection__follow-all" aria-labelledby="akb-collection-follow-all-title" data-enabled="<?php echo $akibara_follow_all_on ? 'true' : 'false'; ?>">
			<summary id="akb-collection-follow-all-title">Avisos de mi colección <span><?php echo $akibara_follow_all_on ? 'Activados' : 'Apagados'; ?></span></summary>
			<?php if ( $akibara_follow_all_on ) : ?>
				<p class="akb-account-collection__follow-all-state">Activados. Cuando compras un tomo, te avisamos por correo al salir el siguiente de esa serie. Como máximo un correo por semana, y en cada aviso puedes apagar una serie o todas.</p>
				<?php if ( $akibara_follow_series_off > 0 ) : ?>
					<?php
					// Salvedad honesta: «te avisamos … de esa serie» no vale para las
					// series que el cliente apagó una a una desde el correo, y el
					// global encendido no las reactiva. Se dice aquí, no sólo en la
					// tarjeta de abajo.
					?>
					<p class="akb-account-collection__follow-all-partial">Salvo <?php echo esc_html( 1 === $akibara_follow_series_off ? 'una serie' : (string) $akibara_follow_series_off . ' series' ); ?> que apagaste una a una: <?php echo esc_html( 1 === $akibara_follow_series_off ? 'la reactivas' : 'las reactivas' ); ?> desde su tarjeta.</p>
				<?php endif; ?>
			<?php else : ?>
				<p class="akb-account-collection__follow-all-state">Apagados. No te avisaremos de ningún tomo nuevo. Tus compras y tu colección siguen igual.</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( (string) $data['follow_all']['action_url'] ); ?>" class="akb-account-collection__follow-all-form">
				<input type="hidden" name="<?php echo esc_attr( \Akibara\Business\Modules\SeriesFollow\GlobalToggle::NONCE_FIELD ); ?>" value="<?php echo esc_attr( (string) $data['follow_all']['nonce'] ); ?>">
				<input type="hidden" name="<?php echo esc_attr( \Akibara\Business\Modules\SeriesFollow\GlobalToggle::FIELD ); ?>" value="<?php echo $akibara_follow_all_on ? 'off' : 'on'; ?>">
				<button type="submit" class="akb-account-collection__button akb-account-collection__button--follow-all"><?php echo esc_html( $akibara_follow_all_on ? 'Apagar los avisos' : 'Encender los avisos' ); ?></button>
			</form>
			<p class="akb-account-collection__privacy-note">Estos avisos no son publicidad: nacen de lo que ya compraste. Las ofertas y novedades llegan solo si marcaste esa casilla al comprar, y se cortan con el enlace de baja de cada correo.</p>
		</details>
	<?php endif; ?>

	<?php if ( 'unavailable' === $data['status'] ) : ?>
		<section class="akb-account-collection__state" aria-labelledby="akb-collection-unavailable-title" role="alert">
			<div class="akb-account-collection__state-mark" aria-hidden="true"></div>
			<h2 id="akb-collection-unavailable-title">No pudimos cargar tu colección ahora</h2>
			<p>Tus compras siguen seguras. Inténtalo nuevamente o revisa tus pedidos mientras recuperamos esta vista.</p>
			<div class="akb-account-collection__state-actions">
				<a class="akb-account-collection__button" href="<?php echo esc_url( (string) $data['collection_url'] ); ?>">Reintentar</a>
				<a class="akb-account-collection__text-link" href="<?php echo esc_url( (string) $data['orders_url'] ); ?>">Ver mis pedidos</a>
			</div>
		</section>
	<?php elseif ( 'empty' === $data['status'] ) : ?>
		<section class="akb-account-collection__state" aria-labelledby="akb-collection-empty-title">
			<div class="akb-account-collection__state-mark" aria-hidden="true"></div>
			<?php
			// Jerarquía invertida hasta 1.108.14: el título le negaba la compra a
			// quien SÍ había comprado, la salida real iba en letra chica y el
			// botón principal llevaba a «Explorar series». Medido el 2026-08-23:
			// tres clientes con pedidos pagados aterrizaban justo acá, y su
			// historial se recupera en un clic desde Pedidos —confirmando el
			// correo, que es como WooCommerce vincula las compras de invitado—.
			// Ahora el título no afirma que no compraste, la recuperación es el
			// texto principal y el botón lleva a la acción que la resuelve.
			?>
			<h2 id="akb-collection-empty-title">Aún no hay tomos en tu colección</h2>
			<p>¿Compraste antes sin iniciar sesión? Confirma tu correo en <strong>Pedidos</strong> y sumamos esas compras a tu colección.</p>
			<p>Si es tu primera vez, cuando compres con esta cuenta agruparemos aquí tus tomos por edición para ayudarte a continuar cada historia.</p>
			<?php
			// El aviso de demora va en las DOS pantallas donde el cliente cuenta
			// tomos y le falta uno: ésta —donde acaba de comprar y no ve nada— y
			// la de las tarjetas. No va en «no pudimos cargar tu colección»: ahí
			// el problema no es la espera y ofrecerla como explicación sería
			// mandarlo a esperar por un fallo que no se arregla solo.
			if ( '' !== (string) $data['delay_notice'] ) :
				?>
				<p class="akb-account-collection__delay-note"><?php echo esc_html( (string) $data['delay_notice'] ); ?></p>
			<?php endif; ?>
			<p class="akb-account-collection__privacy-note">Confirmar tu correo no te suscribe a marketing.</p>
			<div class="akb-account-collection__state-actions">
				<a class="akb-account-collection__button" href="<?php echo esc_url( (string) $data['orders_url'] ); ?>">Ir a Pedidos</a>
				<a class="akb-account-collection__text-link" href="<?php echo esc_url( (string) $data['series_url'] ); ?>">Explorar series</a>
			</div>
		</section>
	<?php elseif ( true === $data['only_unclassified'] ) : ?>
		<section class="akb-account-collection__state" aria-labelledby="akb-collection-unclassified-title">
			<div class="akb-account-collection__state-mark" aria-hidden="true"></div>
			<h2 id="akb-collection-unclassified-title">Aún no pudimos agrupar tus compras por edición</h2>
			<p>Tienes <?php echo esc_html( (string) $data['unclassified_count'] ); ?> <?php echo esc_html( $akibara_plural( (int) $data['unclassified_count'], 'producto comprado', 'productos comprados' ) ); ?> con esta cuenta que todavía no podemos asociar de forma segura a una sola edición. No <?php echo 1 === (int) $data['unclassified_count'] ? 'lo contamos' : 'los contamos'; ?> como <?php echo 1 === (int) $data['unclassified_count'] ? 'tomo' : 'tomos'; ?> mientras no podamos <?php echo 1 === (int) $data['unclassified_count'] ? 'clasificarlo' : 'clasificarlos'; ?> con seguridad.</p>
			<div class="akb-account-collection__state-actions">
				<a class="akb-account-collection__button" href="<?php echo esc_url( (string) $data['orders_url'] ); ?>">Ver mis pedidos</a>
				<a class="akb-account-collection__text-link" href="<?php echo esc_url( (string) $data['series_url'] ); ?>">Explorar series</a>
			</div>
		</section>
	<?php else : ?>

		<?php if ( (int) $data['unclassified_count'] > 0 ) : ?>
			<aside class="akb-account-collection__notice" aria-label="Compras todavía sin agrupar por edición">
				<p>
					<strong><?php echo esc_html( (string) $data['unclassified_count'] ); ?> <?php echo esc_html( $akibara_plural( (int) $data['unclassified_count'], 'producto comprado todavía no está agrupado', 'productos comprados todavía no están agrupados' ) ); ?> por edición.</strong>
					Puedes <?php echo 1 === (int) $data['unclassified_count'] ? 'revisarlo' : 'revisarlos'; ?> en tus pedidos.
				</p>
				<a href="<?php echo esc_url( (string) $data['orders_url'] ); ?>">Ver mis pedidos</a>
			</aside>
		<?php endif; ?>

		<div class="akb-account-collection__grid">
		<?php foreach ( $data['series'] as $akibara_series_index => $akibara_series ) : ?>
			<?php
			$akibara_edition          = $akibara_series['edition'];
			$akibara_continuity       = $akibara_series['continuity'];
			$akibara_progress_owned   = $akibara_edition['progress_owned'];
			$akibara_edition_total    = (int) $akibara_edition['total'];
			$akibara_has_progress     = null !== $akibara_progress_owned && $akibara_edition_total > 0;
			$akibara_progress_value   = $akibara_has_progress ? min( (int) $akibara_progress_owned, $akibara_edition_total ) : 0;
			$akibara_historical_count = (int) $akibara_series['historical_only_count'];
			?>
			<article
				class="akb-account-collection__card"
				data-continuity-kind="<?php echo esc_attr( (string) $akibara_continuity['kind'] ); ?>"
				aria-labelledby="akb-collection-series-<?php echo esc_attr( (string) ( $akibara_series_index + 1 ) ); ?>"
			>
				<div class="akb-account-collection__cover akb-cover-cage" aria-hidden="true">
					<?php if ( '' !== $akibara_series['cover_url'] ) : ?>
						<img src="<?php echo esc_url( (string) $akibara_series['cover_url'] ); ?>" alt="" width="72" height="108" loading="lazy" decoding="async">
						<?php else : ?>
							<span></span>
						<?php endif; ?>
					</div>

					<div class="akb-account-collection__card-body">
					<h3 id="akb-collection-series-<?php echo esc_attr( (string) ( $akibara_series_index + 1 ) ); ?>">
						<?php if ( '' !== $akibara_series['url'] ) : ?>
							<a href="<?php echo esc_url( (string) $akibara_series['url'] ); ?>"><?php echo esc_html( (string) $akibara_series['name'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( (string) $akibara_series['name'] ); ?>
						<?php endif; ?>
					</h3>
					<p class="akb-account-collection__edition-state">
						<?php echo esc_html( (string) $akibara_edition['state_label'] ); ?>
						<?php if ( true === $akibara_edition['is_complete'] || 'complete' === $akibara_continuity['kind'] ) : ?>
							<span class="akb-account-collection__badge-complete">🏆 Edición Completa</span>
						<?php endif; ?>
					</p>

					<?php if ( $akibara_has_progress ) : ?>
						<?php $akibara_tomo_word = $akibara_plural( $akibara_edition_total, 'tomo', 'tomos' ); ?>
						<div class="akb-account-collection__progress-copy">
							<span>Avance de esta edición</span>
							<strong><?php echo esc_html( (string) $akibara_progress_owned ); ?> de <?php echo esc_html( (string) $akibara_edition_total ); ?> <?php echo esc_html( $akibara_tomo_word ); ?></strong>
						</div>
						<progress
							class="akb-account-collection__progress"
							value="<?php echo esc_attr( (string) $akibara_progress_value ); ?>"
							max="<?php echo esc_attr( (string) $akibara_edition_total ); ?>"
							aria-label="<?php echo esc_attr( 'Avance de la edición ' . (string) $akibara_series['name'] . ': ' . (string) $akibara_progress_owned . ' de ' . (string) $akibara_edition_total . ' ' . $akibara_tomo_word ); ?>"
						><?php echo esc_html( (string) $akibara_progress_owned . ' de ' . (string) $akibara_edition_total ); ?></progress>
					<?php elseif ( $akibara_edition_total > 0 ) : ?>
						<p class="akb-account-collection__total-note">Total de esta edición: <?php echo esc_html( (string) $akibara_edition_total ); ?> <?php echo esc_html( $akibara_plural( $akibara_edition_total, 'tomo', 'tomos' ) ); ?></p>
						<?php else : ?>
							<p class="akb-account-collection__total-note">Total de esta edición por confirmar</p>
						<?php endif; ?>


					<section
						class="akb-account-collection__continuity akb-account-collection__continuity--<?php echo esc_attr( (string) $akibara_continuity['kind'] ); ?>"
						aria-label="Continuidad de <?php echo esc_attr( (string) $akibara_series['name'] ); ?>"
					>
						<p class="akb-account-collection__continuity-copy"><strong><?php echo esc_html( (string) $akibara_continuity['message'] ); ?></strong></p>
						<?php if ( '' !== $akibara_continuity['versions_label'] ) : ?>
							<p class="akb-account-collection__versions"><?php echo esc_html( (string) $akibara_continuity['versions_label'] ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $akibara_continuity['availability_label'] ) : ?>
							<p class="akb-account-collection__availability akb-account-collection__availability--<?php echo esc_attr( (string) $akibara_continuity['availability'] ); ?>">
								<?php echo esc_html( (string) $akibara_continuity['availability_label'] ); ?>
								</p>
							<?php endif; ?>
						</section>
					</div>

					<div class="akb-account-collection__card-footer">
					<?php if ( true === $akibara_series['follow']['locked'] ) : ?>
						<p class="akb-series-follow__locked">Avisos apagados para toda tu colección.</p>
					<?php endif; ?>
					<?php if ( true === $akibara_series['follow']['available'] ) : ?>
						<?php $akibara_following = true === $akibara_series['follow']['following']; ?>
						<button
							type="button"
							class="akb-series-follow__toggle"
							data-akb-series-follow
							data-term-id="<?php echo esc_attr( (string) $akibara_series['follow']['term_id'] ); ?>"
							data-series-name="<?php echo esc_attr( (string) $akibara_series['name'] ); ?>"
							aria-pressed="<?php echo $akibara_following ? 'true' : 'false'; ?>"
						>
							<span class="akb-series-follow__label"><?php echo esc_html( $akibara_following ? 'Avisos activados' : 'Avisarme del siguiente tomo' ); ?></span>
						</button>
						<p class="akb-series-follow__msg" role="status" aria-live="polite"></p>
					<?php endif; ?>

					<?php if ( '' !== $akibara_continuity['cta_url'] && '' !== $akibara_continuity['cta_label'] ) : ?>
						<a
							class="akb-account-collection__button akb-account-collection__button--card"
							href="<?php echo esc_url( (string) $akibara_continuity['cta_url'] ); ?>"
							aria-label="<?php echo esc_attr( (string) $akibara_continuity['cta_aria'] ); ?>"
						>
							<?php echo esc_html( (string) $akibara_continuity['cta_label'] ); ?>
						</a>
					<?php endif; ?>

					<?php if ( in_array( (string) $akibara_continuity['kind'], array( 'gap', 'next' ), true ) && ! empty( $akibara_continuity['product']['id'] ) ) : ?>
						<button
							type="button"
							class="akb-account-collection__mark-owned"
							data-product-id="<?php echo esc_attr( (string) $akibara_continuity['product']['id'] ); ?>"
							data-action="add"
						>
							¿Ya tienes este tomo? Márcalo en tu estantería
						</button>
					<?php endif; ?>

					<?php if ( ! empty( $akibara_series['bundle_suggestion']['available'] ) && ! empty( $akibara_series['bundle_suggestion']['url'] ) ) : ?>
						<a
							class="akb-account-collection__bundle-cta"
							href="<?php echo esc_url( (string) $akibara_series['bundle_suggestion']['url'] ); ?>"
						>
							<?php echo esc_html( (string) ( $akibara_series['bundle_suggestion']['label'] ?? ( '🔥 Completa más rápido: Lleva los siguientes ' . $akibara_series['bundle_suggestion']['count'] . ' tomos en Arma tu Pack con descuento →' ) ) ); ?>
						</a>
					<?php endif; ?>

						<details class="akb-account-collection__owned-details">
							<summary>Ver compras de esta edición<?php if ( ! empty( $akibara_series['manual_owned_product_ids'] ) ) : ?> <span class="akb-account-collection__manual-count-badge">(y <?php echo esc_html( (string) count( $akibara_series['manual_owned_product_ids'] ) ); ?> en estantería)</span><?php endif; ?></summary>
						<?php if ( array() !== $akibara_series['owned_products'] ) : ?>
							<ul>
								<?php
								$akibara_manual_ids = array_flip( $akibara_series['manual_owned_product_ids'] ?? array() );
								foreach ( $akibara_series['owned_products'] as $akibara_product ) :
									$akibara_is_manual = isset( $akibara_manual_ids[ (int) $akibara_product['id'] ] );
								?>
									<li class="<?php echo $akibara_is_manual ? 'is-manual-owned' : 'is-order-owned'; ?>">
										<?php if ( function_exists( 'akb_loop_title_markup' ) ) : ?>
											<?php if ( '' !== $akibara_product['url'] ) : ?>
												<a href="<?php echo esc_url( (string) $akibara_product['url'] ); ?>"><?php echo akb_loop_title_markup( (string) $akibara_product['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapa cada parte. ?></a>
											<?php else : ?>
												<?php echo akb_loop_title_markup( (string) $akibara_product['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapa cada parte. ?>
											<?php endif; ?>
										<?php else : ?>
											<?php if ( '' !== $akibara_product['url'] ) : ?>
												<a href="<?php echo esc_url( (string) $akibara_product['url'] ); ?>"><?php echo esc_html( (string) $akibara_product['title'] ); ?></a>
											<?php else : ?>
												<?php echo esc_html( (string) $akibara_product['title'] ); ?>
											<?php endif; ?>
										<?php endif; ?>
										<?php if ( $akibara_is_manual ) : ?>
											<span class="akb-account-collection__manual-badge">En tu estantería</span>
											<button type="button" class="akb-account-collection__unmark-owned" data-product-id="<?php echo esc_attr( (string) $akibara_product['id'] ); ?>" data-action="remove" title="Quitar de tu estantería" aria-label="<?php echo esc_attr( 'Quitar ' . (string) $akibara_product['title'] . ' de tu estantería' ); ?>">✕ Quitar</button>
										<?php else : ?>
											<span class="akb-account-collection__verified-badge" title="<?php echo esc_attr( '' !== (string) ( $data['rewards_points'] ?? '' ) ? sprintf( 'Compra verificada en Akibara — Invitación a reseña con foto (%s) disponible en Mis pedidos', (string) $data['rewards_points'] ) : 'Compra verificada en Akibara' ); ?>">✓ Compra verificada</span>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
							<?php else : ?>
								<p>El detalle de estos tomos todavía no está disponible.</p>
							<?php endif; ?>
						<?php if ( $akibara_historical_count > 0 ) : ?>
							<p class="akb-account-collection__historical"><?php echo esc_html( (string) $akibara_historical_count ); ?> <?php echo esc_html( $akibara_plural( $akibara_historical_count, 'tomo comprado ya no está', 'tomos comprados ya no están' ) ); ?> en el catálogo actual.</p>
							<?php endif; ?>
						</details>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
