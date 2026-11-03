<?php
/**
 * Series Bundles — Admin\BypConfigController.
 *
 * Configuración de Build_Your_Pack por término de la taxonomía `pa_serie`
 * (Req 2.1–2.4) desde wp-admin. No usa el form de
 * `woocommerce_admin_fields` del `AdminPanel`: tiene su propio handler
 * sobre `admin-post` (patrón `UpsellsAutofill::handle_admin_run`), porque el
 * modelo de datos (mapa `term_id => {enabled, tiers[]}`) no encaja en el
 * guardado campo-a-campo de WC settings.
 *
 * Handler registrado en `register()`:
 *   - `admin_post_akb_sb_save_byp` → guardado de la config BYP de todas las
 *     series enviadas (Req 2.1–2.4).
 *
 * El handler verifica nonce (`check_admin_referer`) y capability
 * (`current_user_can('manage_woocommerce')`) antes de operar. Por cada término
 * `pa_serie` recibido, lee el toggle `enabled` (checkbox) y sus Volume_Tiers
 * (pares `{min, pct}`), los sanitiza con `absint` y delega la validación de
 * negocio en `VolumeTierResolver::normalize_tiers()` — NO se reduplica la
 * validación de orden/duplicados/rangos.
 *
 * Comportamiento ante validación (Req 2.4):
 *   - Si una serie habilitada produce un `Result` de error (p. ej. cantidades
 *     mínimas duplicadas → "Cada tramo debe tener una cantidad mínima
 *     distinta"), se acumula el mensaje en el transient de avisos y esa serie
 *     NO se persiste; el resto del guardado continúa.
 *   - Si el `Result` es ok, se persiste la serie habilitada con sus tramos ya
 *     ordenados ascendentemente (Req 2.1, 2.3) vía `Settings::save_byp_config()`.
 *   - Una serie deshabilitada se persiste con `enabled => false`, conservando
 *     los tramos saneados para no perder la configuración del owner.
 *
 * Los avisos usan un transient por usuario (`akb_sb_byp_notices_{userid}`) que
 * sobrevive el redirect post-handler.
 *
 * Sin funciones i18n (Req 11.4): textos en español literal. Toda la salida se
 * escapa (`esc_html`, `esc_attr`, `esc_url`) y toda la entrada se sanitiza
 * (`absint`, `sanitize_text_field`).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles\Admin;

use Akibara\Business\Modules\SeriesBundles\PromotionPolicy;
use Akibara\Business\Modules\SeriesBundles\Settings;
use Akibara\Business\Modules\SeriesBundles\VolumeTierResolver;

defined( 'ABSPATH' ) || exit;

final class BypConfigController {

	/** Acción admin-post de guardado de la config Build_Your_Pack. */
	public const ACTION_SAVE = 'akb_sb_save_byp';

	/** Acción admin-post: habilitar en lote las series alcanzables sin configurar. */
	public const ACTION_BULK = 'akb_sb_bulk_enable';

	/**
	 * Nombre de nonce específico para cada formulario de la pestaña.
	 *
	 * Los formularios conviven con el del panel en un mismo documento. Cada uno
	 * requiere un id distinto en lugar de repetir el _wpnonce predeterminado.
	 *
	 * @var array<string, string>
	 */
	private const NONCE_NAMES = array(
		self::ACTION_SAVE => 'akb_sb_byp_save_nonce',
		self::ACTION_BULK => 'akb_sb_byp_bulk_nonce',
	);

	/** Slug de la pestaña del Admin_Panel donde vive la config BYP. */
	public const PANEL_TAB = 'series_bundles';

	/** Taxonomía de serie sobre la que se configura Build_Your_Pack. */
	public const TAXONOMY = 'pa_serie';

	/** Prefijo del transient de avisos por usuario (sobrevive el redirect). */
	private const NOTICE_TRANSIENT = 'akb_sb_byp_notices_';

	public function __construct(
		private readonly Settings $settings,
		private readonly VolumeTierResolver $tiers,
		private readonly PromotionPolicy $promotion_policy
	) {
	}

	/**
	 * Conecta el handler admin-post del guardado (solo en admin).
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_BULK, array( $this, 'handle_bulk_enable' ) );
	}

	// =========================================================================
	// Handler admin-post
	// =========================================================================

	/**
	 * Handler de guardado de la config Build_Your_Pack (Req 2.1–2.4).
	 *
	 * Verifica nonce + capability, recorre las series enviadas, sanitiza sus
	 * tramos y valida cada serie habilitada con `VolumeTierResolver`. Las series
	 * con tramos inválidos se omiten (con su mensaje de error en el transient,
	 * Req 2.4); el resto se persiste. Redirige a la pestaña al terminar.
	 */
	public function handle_save(): void {
		$this->guard( self::ACTION_SAVE );

		$validated = $this->validate_submitted_config(
			$this->settings->get_byp_config(),
			$this->parse_submitted_series()
		);
		$config    = $validated['config'];
		$notices   = $validated['notices'];

		// Lo rechazado se guarda para volver a pintarlo en el formulario. Sin
		// esto el redirect repintaba la configuración ANTERIOR: el dueño leía
		// «revisa los errores» y encontraba sus tramos borrados, con lo que
		// tenía que reescribirlos de memoria antes de poder corregir nada.
		$this->stash_rejected( $validated['rejected'] );

		$this->settings->save_byp_config( $config );

		if ( empty( $notices ) ) {
			$notices[] = array( 'type' => 'success', 'message' => 'Configuración de "arma tu pack" guardada.' );
		} else {
			// Hubo series rechazadas, pero el resto se guardó igual.
				array_unshift(
					$notices,
					array(
						'type'    => 'warning',
						'message' => 'Algunas series no se guardaron por tramos inválidos o inalcanzables, o por tomos compartidos. Revisa los mensajes de error.',
					)
				);
		}

		$this->set_notices( $notices );
		$this->redirect();
	}

	/**
	 * Nombres de las series enviadas, en una sola consulta.
	 *
	 * Los errores decían «Serie #364». El dueño administra 119 series y ese
	 * número no aparece en ninguna otra parte de la pantalla, así que el aviso
	 * le señalaba un problema sin decirle dónde: tenía que abrir los acordeones
	 * uno por uno hasta dar con el que fallaba.
	 *
	 * @param list<int> $term_ids
	 * @return array<int,string>
	 */
	private function series_names( array $term_ids ): array {
		$term_ids = array_values( array_filter( $term_ids, static fn( int $id ): bool => $id > 0 ) );
		// La guarda comprueba el símbolo que de VERDAD se va a llamar. PHP resuelve
		// `get_terms()` primero dentro de este namespace y sólo después en el
		// global, así que preguntar por `function_exists( 'get_terms')` a secas
		// pregunta por otra función: puede decir «no existe» cuando la llamada sí
		// funcionaría. En producción coinciden y no se nota; en las pruebas hacía
		// que este método devolviera IDs en vez de nombres según qué otro fichero
		// se hubiera ejecutado antes.
		$hay_get_terms = function_exists( __NAMESPACE__ . '\\get_terms' ) || function_exists( 'get_terms' );

		if ( array() === $term_ids || ! $hay_get_terms ) {
			// Sin taxonomías disponibles el aviso vuelve a nombrar la serie por
			// su ID. Es peor, pero es exactamente lo que había antes: nunca vale
			// la pena caerse por un texto.
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'pa_serie',
				'include'    => $term_ids,
				'hide_empty' => false,
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$names = array();
		foreach ( $terms as $term ) {
			if ( isset( $term->term_id, $term->name ) ) {
				$names[ (int) $term->term_id ] = (string) $term->name;
			}
		}

		return $names;
	}

	/**
	 * Construye el snapshot a persistir sin reemplazar una entrada rechazada.
	 *
	 * Las desactivaciones se aplican primero para que la validación de cada alta
	 * observe el conjunto completo que quedará activo. Las altas válidas se
	 * incorporan en orden: si dos series comparten un tomo, la segunda falla
	 * cerrada y conserva exactamente su configuración anterior.
	 *
	 * @param array<string|int,mixed> $current   Configuración persistida.
	 * @param array<int,array{enabled:bool,tiers:array<int,array{min:int,pct:int}>}> $submitted Entrada saneada.
	 * @return array{config:array<string|int,mixed>,notices:array<int,array{type:string,message:string}>,rejected:array<int,array<string,mixed>>}
	 */
	private function validate_submitted_config( array $current, array $submitted ): array {
		$config   = $current;
		$pending  = array();
		$notices  = array();
		$rejected = array();
		$names    = $this->series_names( array_map( 'intval', array_keys( $submitted ) ) );

		foreach ( $submitted as $term_id => $payload ) {
			$tiers = $payload['tiers'];
			if ( empty( $payload['enabled'] ) ) {
				$config[ (string) $term_id ] = array(
					'enabled' => false,
					'tiers'   => $this->tiers->sort_tiers( $tiers ),
				);
				continue;
			}

			// Coalescer es pricing-neutral y sanea configuraciones legacy antes
			// de aplicar las reglas actuales de progresión.
			$tiers  = $this->tiers->coalesce_equal_pct_tiers( $this->tiers->sort_tiers( $tiers ) );
			$result = $this->tiers->normalize_tiers( $tiers );
			if ( ! $result->is_ok() ) {
				$notices[]             = array(
					'type'    => 'error',
					'message' => sprintf( '%s: %s', $names[ (int) $term_id ] ?? ( 'Serie #' . $term_id ), $result->message() ),
				);
				$rejected[ $term_id ] = $payload;
				continue;
			}

			$pending[ $term_id ] = $tiers;
		}

		foreach ( $pending as $term_id => $tiers ) {
			// Una serie cuya configuración no cambió conserva su valor sin revalidarse.
			// El formulario envía todas las filas; sólo la fila modificada debe producir
			// validación. reachable_tiers() protege los descuentos públicos aunque el
			// catálogo de otra serie cambie entre guardados.

			if ( $this->propuesta_sin_cambios( $current, (int) $term_id, $tiers ) ) {
				$config[ (string) $term_id ] = $current[ (string) $term_id ];
				continue;
			}

			$error = $this->promotion_policy->validate_enabled_tiers( (int) $term_id, $tiers, $config );
			if ( null !== $error ) {
				$notices[]             = array(
					'type'    => 'error',
					'message' => sprintf( '%s: %s', $names[ (int) $term_id ] ?? ( 'Serie #' . $term_id ), $error ),
				);
				$rejected[ $term_id ] = array( 'enabled' => true, 'tiers' => $tiers );
				continue;
			}

			$config[ (string) $term_id ] = array(
				'enabled' => true,
				'tiers'   => $tiers,
			);
		}

		return array( 'config' => $config, 'notices' => $notices, 'rejected' => $rejected );
	}

	/**
	 * ¿La serie llega igual que estaba guardada?
	 *
	 * Compara el estado y los tramos ya normalizados: si lo enviado coincide con
	 * lo persistido, no hay nada que decidir y no hay nada que reprochar.
	 *
	 * Se exige que la guardada estuviera HABILITADA. Pasar de apagada a
	 * encendida es un cambio aunque los tramos coincidan, y ahí la validación es
	 * exactamente lo que se quiere.
	 *
	 * @param array<string|int,mixed>              $current Configuración persistida.
	 * @param int                                  $term_id Serie.
	 * @param array<int,array{min:int,pct:int}>    $tiers   Tramos propuestos, ya normalizados.
	 * @return bool
	 */
	private function propuesta_sin_cambios( array $current, int $term_id, array $tiers ): bool {
		$guardada = $current[ (string) $term_id ] ?? null;
		if ( ! is_array( $guardada ) || empty( $guardada['enabled'] ) ) {
			return false;
		}

		$antes = is_array( $guardada['tiers'] ?? null ) ? $guardada['tiers'] : array();

		return $this->huella_de_tramos( $antes ) === $this->huella_de_tramos( $tiers );
	}

	/**
	 * Forma comparable de un conjunto de tramos: sólo `min` y `pct`, en orden.
	 *
	 * Sin esto, una clave de más en el array guardado —o el mismo tramo escrito
	 * con `'3'` en vez de `3`— haría creer que hubo un cambio y devolvería el
	 * error que este arreglo viene a quitar.
	 *
	 * @param array<int,mixed> $tiers Tramos.
	 * @return array<int,array{min:int,pct:int}>
	 */
	private function huella_de_tramos( array $tiers ): array {
		$huella = array();
		foreach ( $tiers as $tier ) {
			if ( ! is_array( $tier ) ) {
				continue;
			}
			$huella[] = array(
				'min' => (int) ( $tier['min'] ?? 0 ),
				'pct' => (int) ( $tier['pct'] ?? 0 ),
			);
		}

		usort(
			$huella,
			static fn ( array $a, array $b ): int => $a['min'] <=> $b['min']
		);

		return $huella;
	}

	/**
	 * Handler: habilita "arma tu pack" en lote para las series ALCANZABLES
	 * (≥ {@see PromotionPolicy::FIRST_TIER_MIN} tomos comprables) que todavía NO
	 * tienen entrada en la config, aplicando la política uniforme por profundidad.
	 *
	 * Es ADITIVO a propósito: nunca pisa una serie ya configurada, así preserva el
	 * holdout (`enabled=false`) y cualquier ajuste manual. Resuelve el dolor de
	 * habilitar decenas de series a mano por el acordeón.
	 */
	public function handle_bulk_enable(): void {
		$this->guard( self::ACTION_BULK );

		$term_ids = array_map(
			static fn( $term ): int => (int) ( $term->term_id ?? 0 ),
			$this->get_series_terms()
		);
		$plan     = $this->promotion_policy->plan_bulk_enable( $this->settings->get_byp_config(), $term_ids );
		$config   = $plan['config'];
		$enabled  = $plan['enabled'];
		$shallow  = $plan['shallow'];

		$this->settings->save_byp_config( $config );
		$this->set_notices(
			array(
				array(
					'type'    => $enabled > 0 ? 'success' : 'warning',
						'message' => sprintf(
							'%d serie(s) nueva(s) habilitada(s) con tramos sugeridos; %d no tenían suficientes tomos disponibles. Las series ya configuradas no se modificaron.',
							$enabled,
						$shallow
					),
				),
			)
		);
		$this->redirect();
	}

	// =========================================================================
	// Render de la config BYP (invocado desde la sección admin — Tarea 16 lo cablea)
	// =========================================================================

	/**
	 * Pinta la UI completa de Build_Your_Pack: avisos pendientes y, por cada
	 * término `pa_serie`, un toggle "habilitar BYP" y una mini-tabla editable de
	 * tramos `{cantidad mínima, % descuento}`. Es público para que
	 * `Admin\AdminSection` lo invoque desde el header de la pestaña del
	 * `AdminPanel`.
	 */
	public function render(): void {
		$notices = $this->consume_notices();

		foreach ( $notices as $notice ) {
			$class = 'notice-success';
			if ( 'error' === ( $notice['type'] ?? '' ) ) {
				$class = 'notice-error';
			} elseif ( 'warning' === ( $notice['type'] ?? '' ) ) {
				$class = 'notice-warning';
			}
			printf(
				'<div class="notice %s is-dismissible"><p>%s</p></div>',
				esc_attr( $class ),
				esc_html( (string) ( $notice['message'] ?? '' ) )
			);
		}

		$terms  = $this->get_series_terms();
		$config = $this->settings->get_byp_config();

		// Las series que el guardado rechazó vuelven al formulario con lo que el
		// dueño escribió, no con lo que había antes. Corregir un tramo inválido
		// exige verlo.
		$rejected = $this->consume_rejected();
		foreach ( $rejected as $term_id => $entry ) {
			if ( is_array( $entry ) ) {
				$config[ (string) (int) $term_id ] = $entry;
			}
		}
		?>
			<section class="akb-sb-byp" aria-labelledby="akb-sb-byp-heading">
				<h2 id="akb-sb-byp-heading">Arma tu pack por serie</h2>
				<p class="description">
					Activa el pack solo en las series que quieras promocionar. Cada tramo define cuántos
					tomos distintos debe elegir el cliente y qué descuento obtiene. Los descuentos deben
					aumentar junto con la cantidad y no pueden superar el <?php echo (int) VolumeTierResolver::pct_max(); ?>%
					(el tope de la tienda, en Akibara → Descuentos).
				</p>

			<?php if ( empty( $terms ) ) : ?>
				<p>No hay series (<code>pa_serie</code>) disponibles todavía.</p>
			<?php else : ?>
				<?php
				$enabled_count = 0;
				foreach ( $config as $entry ) {
					if ( is_array( $entry ) && ! empty( $entry['enabled'] ) ) {
						++$enabled_count;
					}
				}
				?>
				<p class="akb-sb-byp-summary">
					<strong><?php echo (int) $enabled_count; ?></strong> de <?php echo (int) count( $terms ); ?> series con "arma tu pack" habilitado.
				</p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="akb-sb-byp-bulk"
						data-akb-confirm="¿Activar packs con tramos sugeridos en las series de 5 o más tomos que todavía no están configuradas? Las configuraciones existentes no cambiarán.">
						<?php wp_nonce_field( self::ACTION_BULK, self::NONCE_NAMES[ self::ACTION_BULK ] ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_BULK ); ?>">
						<button type="submit" class="button">Activar packs sugeridos en series sin configurar</button>
						<span class="description">Solo afecta series con 5 o más tomos comprables. Las configuraciones existentes no cambian.</span>
					</form>

					<div class="akb-sb-byp-filter">
						<span class="dashicons dashicons-search"></span>
						<label for="akb-sb-byp-search">Buscar series</label>
						<input type="search" id="akb-sb-byp-search" class="regular-text" placeholder="Escribe serie, editorial (ej. Planeta) o estado (Activa/Inactiva)">
					</div>

				<?php $publishers_map = $this->get_series_publishers_map(); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="akb-sb-byp-form">
					<?php wp_nonce_field( self::ACTION_SAVE, self::NONCE_NAMES[ self::ACTION_SAVE ] ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">

					<?php foreach ( $terms as $term ) : ?>
						<?php
						$tid = (int) ( $term->term_id ?? 0 );
						$this->render_series_block( $term, $config, isset( $rejected[ $tid ] ), $publishers_map[ $tid ] ?? '' );
						?>
					<?php endforeach; ?>

					<p class="submit">
						<button type="submit" class="button button-primary">Guardar configuración</button>
					</p>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render del bloque editable de una serie: toggle + mini-tabla de tramos.
	 *
	 * @param \WP_Term|object          $term      Término de la taxonomía pa_serie.
	 * @param array<string|int, mixed> $config    Config BYP actual.
	 * @param bool                     $rejected  Si el último guardado rechazó esta serie.
	 * @param string                   $publisher Editorial(es) asociada(s) a los productos de la serie.
	 */
	private function render_series_block( $term, array $config, bool $rejected = false, string $publisher = '' ): void {
		$term_id   = (int) ( $term->term_id ?? 0 );
		$term_name = (string) ( $term->name ?? '' );
		$entry     = isset( $config[ (string) $term_id ] ) && is_array( $config[ (string) $term_id ] )
			? $config[ (string) $term_id ]
			: array();
		$enabled   = ! empty( $entry['enabled'] );
		$tiers     = ( isset( $entry['tiers'] ) && is_array( $entry['tiers'] ) ) ? $entry['tiers'] : array();
		$depth     = $this->promotion_policy->eligible_depth( $term_id );

		// Filas a pintar: las configuradas; al menos 1 fila para empezar.
		$rows = array();
		foreach ( $tiers as $tier ) {
			if ( is_array( $tier ) && isset( $tier['min'], $tier['pct'] ) ) {
				$rows[] = array( 'min' => (int) $tier['min'], 'pct' => (int) $tier['pct'] );
			}
		}
		if ( empty( $rows ) ) {
			$rows[] = array( 'min' => 0, 'pct' => 0 );
		}

			$base        = 'series[' . $term_id . ']';
			$state_label = $enabled ? 'Activa' : 'Inactiva';
			$tier_label  = 1 === count( $tiers ) ? '1 tramo' : count( $tiers ) . ' tramos';
			$meta_label  = ( '' !== $publisher ? $publisher . ' · ' : '' ) . $state_label . ' · ' . $tier_label;
			?>
		<details class="akb-accordion akb-sb-series<?php echo $rejected ? ' akb-sb-series--rejected' : ''; ?>" <?php echo ( $enabled || $rejected ) ? 'open' : ''; ?>>
				<summary class="akb-accordion__summary">
					<span class="akb-accordion__title"><?php echo esc_html( '' !== $term_name ? $term_name : ( 'Serie #' . $term_id ) ); ?></span>
					<span class="akb-accordion__meta"><?php echo esc_html( $rejected ? 'Sin guardar · revisa los tramos' : $meta_label ); ?></span>
				</summary>
			<div class="akb-accordion__body">
				<p class="description"><strong><?php echo null === $depth ? 'Profundidad no verificable' : (int) $depth . ' tomos elegibles hoy'; ?></strong>. Cada tomo distinto cuenta una vez y el descuento cubre una unidad de cada tomo elegible.</p>
				<p>
					<label>
						<input type="hidden" name="<?php echo esc_attr( $base . '[enabled]' ); ?>" value="0">
						<input type="checkbox" name="<?php echo esc_attr( $base . '[enabled]' ); ?>" value="1" <?php checked( $enabled ); ?>>
						Habilitar "arma tu pack" para esta serie
					</label>
				</p>

				<table class="widefat striped akb-sb-tier-table" data-akb-sb-tiers data-term="<?php echo (int) $term_id; ?>">
						<thead>
							<tr>
								<th scope="col">Desde cuántos tomos</th>
								<th scope="col">Descuento</th>
							<th scope="col" class="akb-sb-tier-table__actions"></th>
						</tr>
					</thead>
					<tbody data-akb-sb-tier-body>
							<?php foreach ( $rows as $i => $row ) : ?>
								<?php
								$min_id = 'akb-sb-tier-min-' . $term_id . '-' . $i;
								$pct_id = 'akb-sb-tier-pct-' . $term_id . '-' . $i;
								?>
								<tr data-akb-sb-tier-row>
									<td>
										<label class="screen-reader-text" for="<?php echo esc_attr( $min_id ); ?>">Cantidad mínima de tomos</label>
										<input type="number" min="2" step="1"
											id="<?php echo esc_attr( $min_id ); ?>"
											class="akb-sb-tier-input akb-sb-tier-input--min"
											name="<?php echo esc_attr( $base . '[tiers][' . $i . '][min]' ); ?>"
											value="<?php echo $row['min'] > 0 ? esc_attr( (string) $row['min'] ) : ''; ?>"
											placeholder="Ej. 5">
									</td>
									<td>
										<label class="screen-reader-text" for="<?php echo esc_attr( $pct_id ); ?>">Porcentaje de descuento</label>
										<input type="number" min="<?php echo (int) VolumeTierResolver::PCT_MIN; ?>" max="<?php echo (int) VolumeTierResolver::pct_max(); ?>" step="1"
											id="<?php echo esc_attr( $pct_id ); ?>"
											class="akb-sb-tier-input akb-sb-tier-input--pct"
											name="<?php echo esc_attr( $base . '[tiers][' . $i . '][pct]' ); ?>"
											value="<?php echo $row['min'] > 0 ? esc_attr( (string) $row['pct'] ) : ''; ?>"
											placeholder="Ej. 10">
								</td>
								<td class="akb-sb-tier-table__actions">
									<button type="button" class="button-link akb-sb-tier-remove" data-akb-sb-tier-remove aria-label="Quitar tramo">&times;</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<button type="button" class="button button-small" data-akb-sb-tier-add
						data-base="<?php echo esc_attr( $base ); ?>">
						+ Agregar tramo
					</button>
				</p>
					<p class="description">Ejemplo: desde 5 tomos, 8%; desde 7 tomos, 10%. Una serie activa debe tener al menos un tramo.</p>
			</div>
		</details>
		<?php
	}

	// =========================================================================
	// Parseo de la entrada
	// =========================================================================

	/**
	 * Sanitiza y normaliza la entrada `$_POST['series']` a una estructura
	 * `term_id => {enabled:bool, tiers:array<{min:int,pct:int}>}`.
	 *
	 * Solo se conservan los tramos con `min > 0` (las filas vacías de la UI se
	 * descartan). Toda la entrada pasa por `absint`.
	 *
	 * @return array<int, array{enabled:bool, tiers:array<int, array{min:int,pct:int}>}>
	 */
	private function parse_submitted_series(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verificado en guard(); la estructura anidada se sanea elemento a elemento con absint() en el bucle de abajo.
		$raw = isset( $_POST['series'] ) && is_array( $_POST['series'] ) ? wp_unslash( $_POST['series'] ) : array();

		$out = array();

		foreach ( (array) $raw as $term_id => $payload ) {
			$term_id = absint( $term_id );
			if ( 0 === $term_id || ! is_array( $payload ) ) {
				continue;
			}

			$enabled   = ! empty( $payload['enabled'] ) && '0' !== (string) $payload['enabled'];
			$raw_tiers = isset( $payload['tiers'] ) && is_array( $payload['tiers'] ) ? $payload['tiers'] : array();

			$tiers = array();
			foreach ( $raw_tiers as $tier ) {
				if ( ! is_array( $tier ) ) {
					continue;
				}
				$min = isset( $tier['min'] ) ? absint( $tier['min'] ) : 0;
				$pct = isset( $tier['pct'] ) ? absint( $tier['pct'] ) : 0;

				// Descartar filas vacías (sin cantidad mínima).
				if ( 0 === $min ) {
					continue;
				}

				$tiers[] = array( 'min' => $min, 'pct' => $pct );
			}

			$out[ $term_id ] = array(
				'enabled' => $enabled,
				'tiers'   => $tiers,
			);
		}

		return $out;
	}

	/**
	 * Lista los términos de la taxonomía `pa_serie` para construir la UI
	 * (patrón `TaxonomyDiscount::build_term_fields`). Fail-safe a array vacío.
	 *
	 * @return array<int, object>
	 */
	private function get_series_terms(): array {
		if ( ! function_exists( 'get_terms' ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( ! is_array( $terms ) || ( function_exists( 'is_wp_error' ) && is_wp_error( $terms ) ) ) {
			return array();
		}

		usort(
			$terms,
			static fn( $a, $b ): int => strcmp( (string) ( $a->name ?? '' ), (string) ( $b->name ?? '' ) )
		);

		return $terms;
	}

	/**
	 * Mapa en lote `term_id (pa_serie) => "Editorial"` en una sola consulta SQL.
	 *
	 * Permite al dueño distinguir y filtrar en el panel qué series pertenecen a
	 * cada editorial (ej. Planeta Cómic, Ivrea, Norma) sin generar consultas N+1.
	 *
	 * @return array<int, string>
	 */
	private function get_series_publishers_map(): array {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) || empty( $wpdb->term_relationships ) ) {
			return array();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT tt_s.term_id AS serie_id, GROUP_CONCAT(DISTINCT t_e.name ORDER BY t_e.name SEPARATOR ', ') AS editoriales
			 FROM {$wpdb->term_relationships} tr_s
			 INNER JOIN {$wpdb->term_taxonomy} tt_s ON tr_s.term_taxonomy_id = tt_s.term_taxonomy_id AND tt_s.taxonomy = 'pa_serie'
			 INNER JOIN {$wpdb->term_relationships} tr_e ON tr_s.object_id = tr_e.object_id
			 INNER JOIN {$wpdb->term_taxonomy} tt_e ON tr_e.term_taxonomy_id = tt_e.term_taxonomy_id AND tt_e.taxonomy = 'product_brand'
			 INNER JOIN {$wpdb->terms} t_e ON tt_e.term_id = t_e.term_id
			 GROUP BY tt_s.term_id"
		);
		// phpcs:enable

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$map = array();
		foreach ( $rows as $row ) {
			$sid  = (int) ( $row->serie_id ?? 0 );
			$edit = trim( (string) ( $row->editoriales ?? '' ) );
			if ( $sid > 0 && '' !== $edit ) {
				$map[ $sid ] = $edit;
			}
		}

		return $map;
	}

	// =========================================================================
	// Helpers internos
	// =========================================================================

	/**
	 * Guard común del handler: nonce + capability. `wp_die` si falla.
	 */
	private function guard( string $action ): void {
		check_admin_referer( $action, self::NONCE_NAMES[ $action ] ?? '_wpnonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos.', 'akibara', array( 'response' => 403 ) );
		}
	}

	/**
	 * Persiste los avisos en un transient por usuario, para sobrevivir el
	 * redirect post-handler.
	 *
	 * @param array<int, array{type:string,message:string}> $notices
	 */
	/**
	 * Conserva las series rechazadas hasta el siguiente render.
	 *
	 * @param array<int,array<string,mixed>> $rejected
	 */
	private function stash_rejected( array $rejected ): void {
		if ( array() === $rejected ) {
			delete_transient( $this->rejected_key() );
			return;
		}

		set_transient( $this->rejected_key(), $rejected, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Lee y limpia las series rechazadas.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function consume_rejected(): array {
		$payload = get_transient( $this->rejected_key() );
		if ( ! is_array( $payload ) ) {
			return array();
		}

		delete_transient( $this->rejected_key() );

		return $payload;
	}

	private function rejected_key(): string {
		return 'akb_sb_byp_rejected_' . get_current_user_id();
	}

	private function set_notices( array $notices ): void {
		set_transient( $this->notice_key(), array( 'notices' => $notices ), 60 );
	}

	/**
	 * Lee y limpia el transient de avisos.
	 *
	 * @return array<int, array{type:string,message:string}>
	 */
	private function consume_notices(): array {
		$payload = get_transient( $this->notice_key() );
		if ( ! is_array( $payload ) ) {
			return array();
		}

		delete_transient( $this->notice_key() );

		return isset( $payload['notices'] ) && is_array( $payload['notices'] ) ? $payload['notices'] : array();
	}

	private function notice_key(): string {
		return self::NOTICE_TRANSIENT . get_current_user_id();
	}

	/**
	 * URL de la pestaña del Admin_Panel con args opcionales.
	 *
	 * @param array<string, string> $extra
	 */
	private function tab_url( array $extra = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => 'akibara-panel',
					'tab'  => self::PANEL_TAB,
				),
				$extra
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Redirige a la pestaña de la config BYP y termina la ejecución.
	 *
	 * @param array<string, string> $extra
	 */
	private function redirect( array $extra = array() ): void {
		wp_safe_redirect( $this->tab_url( $extra ) );
		exit;
	}
}
