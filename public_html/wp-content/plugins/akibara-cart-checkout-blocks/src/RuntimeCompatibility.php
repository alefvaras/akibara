<?php
/**
 * Runtime compatibility for third-party checkout assets.
 *
 * @package Akibara\CartCheckoutBlocks
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks;

defined( 'ABSPATH' ) || exit;

/**
 * Contains narrowly scoped compatibility workarounds around Woo Blocks.
 *
 * @since 2.0.0
 */
final class RuntimeCompatibility {

	/**
	 * Última observación real del desencolado de Mercado Pago.
	 *
	 * Site Health corre en wp-admin, donde la cola del carrito no existe. Sin
	 * este registro sólo podía preguntarle al CÓDIGO de Mercado Pago si seguía
	 * declarando los handles, que es otra cosa: puede declararlos y no encolarlos
	 * nunca.
	 *
	 * La escriben las cargas del carrito (`record_mercado_pago_dequeue`) y la
	 * lee tanto el carrito —para decidir si hace falta escribir— como la
	 * pantalla de salud. Aun así se queda FUERA de `alloptions`: `autoload` no
	 * es «esta option se lee mucho», es «se necesita en la mayoría de las
	 * peticiones», y ésta sólo hace falta en /carrito/ y en Site Health.
	 * Autoloadarla se la cobraría a cada petición del sitio que jamás toca el
	 * carrito.
	 */
	private const OPTION_MP_DEQUEUE_OBSERVED = 'akb_blocks_mp_dequeue_observed';

	/**
	 * Observadores clásicos de Mercado Pago que el carrito Blocks no requiere.
	 *
	 * Lista explícita y auditable para remover únicamente handles incompatibles sin afectar
	 * scripts legítimos de pasarela en frontend.
	 *
	 * @var array<int,string>
	 */
	private const MERCADO_PAGO_CLASSIC_HANDLES = array(
		'wc_mercadopago_checkout_error_dispatcher',
		'wc_mercadopago_checkout_fields_dispatcher',
		'wc_mercadopago_checkout_session_data_register',
		'wc_mercadopago_checkout_components',
		'wc_mercadopago_checkout_update',
		'wc_mercadopago_checkout_metrics',
		'wc_mercadopago_sdk_metrics',
		'wc_mercadopago_custom_card_form',
		'wc_mercadopago_custom_three_ds_handler',
		'wc_mercadopago_custom_mobile_checkout_classic_observer',
		'wc_mercadopago_custom_event_handler',
		'wc_mercadopago_custom_page',
		'wc_mercadopago_custom_elements',
		'wc_mercadopago_custom_checkout',
		'wc_mercadopago_supertoken',
	);

	/**
	 * Ficheros del plugin de Mercado Pago donde viven esos handles.
	 *
	 * Se leen SOLO desde Site Health (a demanda, en admin): 9 ficheros planos,
	 * ninguna lectura en el frontend.
	 *
	 * @var array<int,string>
	 */
	private const MERCADO_PAGO_SOURCE_DIRS = array(
		'/woocommerce-mercadopago/src/Gateways',
		'/woocommerce-mercadopago/src/Blocks',
	);

	/**
	 * Archivo de dependencias frontend de Flow.
	 *
	 * Registra dependencias necesarias (`wc-settings`, `wp-element`) si el paquete
	 * upstream no suministra `blocks.asset.php`, asegurando la carga del método de pago.
	 */
	private const FLOW_ASSET_FILE = '/flowpaymentfl/assets/js/frontend/blocks.asset.php';

	private BlocksPage $blocks_page;

	/**
	 * Initializes compatibility checks.
	 *
	 * @param BlocksPage $blocks_page Blocks page resolver.
	 *
	 * @since 2.0.0
	 */
	public function __construct( BlocksPage $blocks_page ) {
		$this->blocks_page = $blocks_page;
	}

	/**
	 * Registers compatibility hooks.
	 *
	 * @since 2.0.0
	 */
	public function register(): void {
		add_filter( 'litespeed_optm_uri_exc', array( $this, 'exclude_checkout_from_litespeed' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_classic_mercado_pago_assets' ), 999 );
		add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_classic_city_select' ), 999 );
		add_action( 'wp_enqueue_scripts', array( $this, 'repair_flow_blocks_dependencies' ), 1000 );
		add_action( 'wp_print_footer_scripts', array( $this, 'repair_flow_blocks_dependencies' ), 0 );
		add_filter( 'generate_show_title', array( $this, 'hide_checkout_entry_title' ), 100 );
		add_filter( 'site_status_tests', array( $this, 'register_compatibility_health_test' ) );
	}

	/**
	 * Keeps the payment funnel out of JavaScript reordering optimizations.
	 *
	 * @internal
	 *
	 * @param array<int,string> $uris Excluded URI fragments.
	 * @return array<int,string>
	 */
	public function exclude_checkout_from_litespeed( array $uris ): array {
		$uris[] = 'checkout';

		return array_values( array_unique( $uris ) );
	}

	/**
	 * Removes Mercado Pago's classic observers from the Blocks cart.
	 *
	 * @internal
	 */
	public function dequeue_classic_mercado_pago_assets(): void {
		if ( ! $this->blocks_page->is_cart() ) {
			return;
		}

		// Se cuenta lo que de verdad había en la cola, no lo que la lista espera.
		//
		// La señal de Site Health miraba si Mercado Pago SEGUÍA DECLARANDO estos
		// handles en su código, que es otra pregunta: un handle puede estar
		// declarado y no llegar nunca a encolarse en el carrito Blocks. Un parche
		// sobreviviendo a su motivo con una señal que dice lo contrario es
		// exactamente lo que esa señal venía a evitar.
		//
		// Cuántos se retiran HOY no se congela aquí: una medición con fecha
		// caduca y termina contradiciendo al contador. La verdad vigente la
		// publica `akibara_blocks_third_party_patches` en Site Health.
		$retirados = 0;
		foreach ( self::MERCADO_PAGO_CLASSIC_HANDLES as $handle ) {
			if ( wp_script_is( $handle, 'enqueued' ) ) {
				++$retirados;
			}
			wp_dequeue_script( $handle );
		}

		$this->record_mercado_pago_dequeue( $retirados );
	}

	/**
	 * Deja constancia de la última pasada del desencolado.
	 *
	 * Site Health corre en wp-admin, donde la cola del carrito no existe, así
	 * que no puede observar esto por su cuenta: tiene que leerlo de aquí.
	 *
	 * Sólo escribe cuando el número CAMBIA, para no pagar un `update_option()`
	 * en cada carga del carrito. Por eso `at` es el sello del último CAMBIO y
	 * NO el de la última carga: la pantalla de salud tiene que decirlo así o a
	 * los seis meses de operación normal informará «hace 6 meses» de un carrito
	 * que se carga a diario.
	 *
	 * @param int $retirados Handles de la lista que estaban realmente encolados.
	 */
	private function record_mercado_pago_dequeue( int $retirados ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		$previo = get_option( self::OPTION_MP_DEQUEUE_OBSERVED, null );
		$actual = array( 'retirados' => $retirados, 'at' => time() );

		if ( is_array( $previo ) && (int) ( $previo['retirados'] ?? -1 ) === $retirados ) {
			return;
		}

		update_option( self::OPTION_MP_DEQUEUE_OBSERVED, $actual, false );
	}

	/**
	 * Retira selectores de comunas clásicos heredados en páginas de checkout Blocks.
	 *
	 * Previene duplicación de catálogos y conflictos de hidratación en inputs controlados por React.
	 *
	 * @internal
	 */
	public function dequeue_classic_city_select(): void {
		if ( ! $this->blocks_page->is_cart() && ! $this->blocks_page->is_checkout() ) {
			return;
		}

		// El endpoint clásico de direcciones de Mi cuenta queda intacto: allí
		// `is_cart()`/`is_checkout()` son false y el selector de Blue sigue
		// siendo el único que hay.
		wp_dequeue_script( 'wc-city-select' );
		wp_dequeue_script( 'bluex-blocks-city-select' );
	}

	/**
	 * Restores dependencies omitted by the Flow checkout-block build.
	 *
	 * @internal
	 */
	public function repair_flow_blocks_dependencies(): void {
		if ( ! $this->blocks_page->is_checkout() && ! $this->blocks_page->is_cart() ) {
			return;
		}

		$scripts = wp_scripts();
		$flow    = $scripts->registered['wc-flowpayment-blocks'] ?? null;
		if ( ! $flow instanceof \_WP_Dependency ) {
			return;
		}

		$flow->deps = array_values(
			array_unique(
				array_merge(
					$flow->deps,
					array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' )
				)
			)
		);
	}

	/**
	 * Publica la comprobación de Site Health de los parches de terceros.
	 *
	 * Los tres parches de este fichero compensan defectos de Mercado Pago,
	 * Flow y Blue Express. Ninguno dejaba rastro: el día que el tercero lo
	 * arregle, el parche se queda ahí para siempre, sin señal. Esta prueba es
	 * esa señal, y es SÓLO informativa: no toca ningún enqueue.
	 *
	 * @internal
	 *
	 * @param array<string,array<string,mixed>> $tests Pruebas registradas.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_compatibility_health_test( array $tests ): array {
		$tests['direct']['akibara_blocks_third_party_patches'] = array(
			'label' => 'Parches de compatibilidad del carrito y checkout Blocks',
			'test'  => array( $this, 'run_compatibility_health_test' ),
		);

		return $tests;
	}

	/**
	 * Informa si los parches de terceros siguen haciendo falta.
	 *
	 * Mira el CÓDIGO instalado del tercero, no la cola de scripts: en admin la
	 * cola del frontend no se ha construido y cualquier lectura de
	 * `wp_scripts()` diría siempre «ya no está registrado», que es mentira y
	 * peor que no informar nada.
	 *
	 * @internal
	 *
	 * @return array<string,mixed>
	 */
	public function run_compatibility_health_test(): array {
		$flow_installed = is_dir( WP_PLUGIN_DIR . '/flowpaymentfl' );
		$flow_fixed     = $flow_installed && is_readable( WP_PLUGIN_DIR . self::FLOW_ASSET_FILE );

		$mercado_pago_installed = is_dir( WP_PLUGIN_DIR . '/woocommerce-mercadopago' );
		$mercado_pago_declared  = $mercado_pago_installed ? $this->mercado_pago_handles_still_declared() : array();
		$mercado_pago_stale     = $mercado_pago_installed && array() === $mercado_pago_declared;

		// Lo que el desencolado observó de verdad, y CUÁNDO cambió esa cuenta
		// por última vez: la option sólo se reescribe cuando el número se mueve,
		// así que este sello no es el de la última carga del carrito.
		$observado                = get_option( self::OPTION_MP_DEQUEUE_OBSERVED, null );
		$mercado_pago_observed    = is_array( $observado ) ? (int) ( $observado['retirados'] ?? 0 ) : 0;
		$mercado_pago_changed_at  = is_array( $observado ) && ! empty( $observado['at'] ) ? (int) $observado['at'] : null;

		$lines = array();

		if ( ! $flow_installed ) {
			$lines[] = 'Flow no está instalado: el parche de dependencias de <code>wc-flowpayment-blocks</code> ya no aplica y puede quitarse.';
		} elseif ( $flow_fixed ) {
			$lines[] = 'Flow ya empaqueta <code>blocks.asset.php</code>: declara sus propias dependencias, así que <code>repair_flow_blocks_dependencies()</code> sobra. Revisá quitarlo.';
		} else {
			$lines[] = 'Flow sigue sin empaquetar <code>blocks.asset.php</code>, así que registra <code>wc-flowpayment-blocks</code> sin dependencias. El parche sigue siendo necesario.';
		}

		if ( ! $mercado_pago_installed ) {
			$lines[] = 'Mercado Pago no está instalado: la lista de observadores clásicos ya no aplica y puede quitarse.';
		} elseif ( $mercado_pago_stale ) {
			$lines[] = 'Mercado Pago ya no declara NINGUNO de los observadores clásicos que el carrito Blocks retira: la lista quedó obsoleta y hay que revisarla.';
		} elseif ( 0 === $mercado_pago_observed && null !== $mercado_pago_changed_at ) {
			// Declarados en el código pero NUNCA encolados en el carrito: el
			// parche existe y no hace nada. Esto es lo que la señal anterior
			// reportaba como «los sigue retirando».
			$lines[] = sprintf(
				'Mercado Pago declara %d observadores clásicos en su código, pero la última vez que esa cuenta cambió (%s) el carrito Blocks no encontró NINGUNO encolado: el desencolado no está retirando nada y se puede revisar si sigue haciendo falta.',
				count( $mercado_pago_declared ),
				esc_html( $this->human_when( $mercado_pago_changed_at ) )
			);
		} else {
			$lines[] = sprintf(
				'Mercado Pago sigue declarando %d de %d observadores clásicos%s.',
				count( $mercado_pago_declared ),
				count( self::MERCADO_PAGO_CLASSIC_HANDLES ),
				null === $mercado_pago_changed_at
					? ' (todavía sin observación del carrito: se registra en la primera carga)'
					: sprintf(
						' y el carrito Blocks retiró %d la última vez que esa cuenta cambió (%s)',
						$mercado_pago_observed,
						esc_html( $this->human_when( $mercado_pago_changed_at ) )
					)
			);

			$missing = array_values( array_diff( self::MERCADO_PAGO_CLASSIC_HANDLES, $mercado_pago_declared ) );
			if ( array() !== $missing ) {
				// Escapa cada nombre de script antes de envolverlo en code, para conservar
				// el marcado de la lista sin interpretar los nombres como HTML.
				$etiquetados = array_map(
					static fn( string $handle ): string => '<code>' . esc_html( $handle ) . '</code>',
					$missing
				);
				$lines[]     = 'Ya no aparecen en el código instalado: ' . implode( ', ', $etiquetados ) . '.';
			}
		}

		$needs_review = $flow_fixed || $mercado_pago_stale || ! $flow_installed || ! $mercado_pago_installed
			|| ( $mercado_pago_installed && 0 === $mercado_pago_observed && null !== $mercado_pago_changed_at );

		$description = '';
		foreach ( $lines as $line ) {
			$description .= '<p>' . $line . '</p>';
		}

		return array(
			'label'       => $needs_review
				? 'Hay parches de compatibilidad que ya podrían sobrar'
				: 'Los parches de compatibilidad siguen haciendo falta',
			'status'      => $needs_review ? 'recommended' : 'good',
			'badge'       => array(
				'label' => 'Akibara',
				'color' => 'blue',
			),
			'description' => $description,
			'actions'     => '',
			'test'        => 'akibara_blocks_third_party_patches',
		);
	}

	/**
	 * Fecha legible de una observación.
	 *
	 * @param int $timestamp Sello Unix de la observación.
	 */
	private function human_when( int $timestamp ): string {
		if ( function_exists( 'human_time_diff' ) ) {
			return 'hace ' . human_time_diff( $timestamp, time() );
		}

		return function_exists( 'wp_date' ) ? (string) wp_date( 'j M H:i', $timestamp ) : (string) $timestamp;
	}

	/**
	 * Devuelve qué observadores clásicos sigue declarando Mercado Pago.
	 *
	 * Lee sólo los dos directorios planos donde viven (9 ficheros), y sólo
	 * cuando alguien abre Site Health.
	 *
	 * @return array<int,string>
	 */
	private function mercado_pago_handles_still_declared(): array {
		$found = array();

		foreach ( self::MERCADO_PAGO_SOURCE_DIRS as $relative ) {
			$files = glob( WP_PLUGIN_DIR . $relative . '/*.php' );
			if ( ! is_array( $files ) ) {
				continue;
			}

			foreach ( $files as $file ) {
				$source = file_get_contents( $file );
				if ( ! is_string( $source ) ) {
					continue;
				}

				foreach ( self::MERCADO_PAGO_CLASSIC_HANDLES as $handle ) {
					if ( ! isset( $found[ $handle ] ) && false !== strpos( $source, "'" . $handle . "'" ) ) {
						$found[ $handle ] = true;
					}
				}
			}
		}

		return array_keys( $found );
	}

	/**
	 * Hides the theme page title only for the Blocks checkout.
	 *
	 * @internal
	 *
	 * @param bool $show Current theme decision.
	 * @return bool
	 */
	public function hide_checkout_entry_title( bool $show ): bool {
		return $this->blocks_page->is_checkout() ? false : $show;
	}
}
