<?php
/**
 * Autocompletado de direcciones y compatibilidad con Mi cuenta.
 * Carga Maps al enfocar el primer campo de dirección. La clave se obtiene
 * del registro de accesos, que prioriza la constante sobre la option y
 * revalida su formato antes de exponerla al navegador.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\AddressAutocomplete;

use Akibara\Business\Utilities\PluginAssetVersion;
use Akibara\ProviderAccess\Access;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.4.0
 */
final class AddressAutocomplete {

	private const OPTION_API_KEY = 'akibara_google_maps_api_key';
	private const OPTION_ENABLED = 'akibara_address_autocomplete_enabled';

	/** Identificador de la clave en el catálogo de accesos. */
	private const ACCESS_ID = 'google_maps';

	public function register(): void {
		add_filter( 'pre_update_option_' . self::OPTION_API_KEY, array( $this, 'sanitize_api_key' ) );
		add_action( 'admin_notices',     array( $this, 'maybe_render_misconfig_notice' ) );

		if ( ! is_admin() ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		}
	}

	/**
	 * Resuelve la API key pidiéndosela al catálogo. Vacío si no hay o no valida.
	 *
	 * Una clave que no casa con el patrón del catálogo se devuelve como cadena
	 * vacía: es lo que impide que una clave truncada viaje al navegador y falle
	 * allá, donde el dueño no lo ve.
	 */
	public function get_api_key(): string {
		if ( ! self::catalog_ready() ) {
			return '';
		}

		try {
			return Access::get( self::ACCESS_ID );
		} catch ( \Throwable $excepcion ) {
			// `Access::get()` pasa por `Registry::entry()`, que LANZA si el id no
			// está declarado —un renombrado de la entrada del catálogo, por
			// ejemplo—. Eso es un error de programación, y el sitio donde se ve es
			// el inventario de claves, no el checkout de un cliente: aquí se cae a
			// «sin clave», que es el comportamiento seguro (el campo de dirección
			// sigue funcionando escribiendo a mano).
			unset( $excepcion );

			return '';
		}
	}

	/**
	 * True si el módulo está activo Y hay API key utilizable.
	 */
	public function is_ready(): bool {
		if ( 'yes' !== get_option( self::OPTION_ENABLED, 'no' ) ) {
			return false;
		}

		return $this->has_api_key();
	}

	/**
	 * ¿Hay clave utilizable? Sin traérsela: la pregunta no necesita el valor.
	 */
	private function has_api_key(): bool {
		if ( ! self::catalog_ready() ) {
			return false;
		}

		try {
			return Access::is_configured( self::ACCESS_ID );
		} catch ( \Throwable $excepcion ) {
			// Mismo cinturón que `get_api_key()`: un id renombrado no puede
			// convertirse en un fatal en la caja de pago.
			unset( $excepcion );

			return false;
		}
	}

	/**
	 * ¿Está cargado el catálogo de accesos?
	 *
	 * En WordPress los mu-plugins cargan antes que los plugins, así que en
	 * producción la respuesta es siempre sí. Existe para el caso en que el
	 * archivo desaparezca —ya pasó con el mu-plugin del correo— y para que
	 * entonces el módulo se apague diciendo POR QUÉ, en vez de comportarse como
	 * si no hubiera clave y mandar al dueño a escribir la que ya escribió.
	 */
	private static function catalog_ready(): bool {
		return class_exists( Access::class );
	}

	/**
	 * Fila de esta clave en el inventario, o array vacío.
	 *
	 * `inventory()` no devuelve valores: sólo estado. Es la superficie correcta
	 * para pintar el panel.
	 *
	 * @return array<string, mixed>
	 */
	private function catalog_row(): array {
		if ( ! self::catalog_ready() ) {
			return array();
		}

		foreach ( Access::inventory() as $fila ) {
			if ( self::ACCESS_ID === ( $fila['id'] ?? '' ) ) {
				return $fila;
			}
		}

		return array();
	}

	/**
	 * Estado de la clave para las pantallas del admin.
	 *
	 * @return array{catalog:bool,configured:bool,malformed:bool,constant_wins:bool}
	 */
	private function key_state(): array {
		$fila = $this->catalog_row();

		if ( array() === $fila ) {
			return array(
				'catalog'       => false,
				'configured'    => false,
				'malformed'     => false,
				'constant_wins' => false,
			);
		}

		$malformed  = (bool) $fila['malformed'];
		$en_la_base = '' !== trim( (string) get_option( self::OPTION_API_KEY, '' ) );

		return array(
			'catalog'       => true,
			'configured'    => (bool) $fila['configured'],
			// «Escrita y rechazada», que NO es lo mismo que «falta»: la primera
			// manda a corregir, la segunda manda a escribir lo que ya está.
			'malformed'     => $malformed,
			// El inventario dice si el valor efectivo vino del wp-config, pero
			// cuando NO hay valor efectivo no dice cuál de las dos fuentes quedó
			// escrita. La option sí es nuestra y se puede mirar: si está vacía,
			// la escrita es la constante, y entonces el campo del panel no la
			// reemplaza —`resolve()` sólo cae a la base de datos cuando la
			// constante está ausente o vacía, nunca cuando está y no valida—.
			'constant_wins' => 'wp-config.php' === (string) $fila['source'] || ( $malformed && ! $en_la_base ),
		);
	}

	/**
	 * Trim + validación blanda del formato (AIza + 35 chars). Retorna '' si no parsea.
	 *
	 * @param mixed $value
	 * @return string
	 */
	public function sanitize_api_key( $value ): string {
		$key = trim( (string) $value );
		if ( '' === $key ) {
			return '';
		}
		if ( ! preg_match( '/^AIza[0-9A-Za-z_-]{35}$/', $key ) ) {
			add_settings_error(
				self::OPTION_API_KEY,
				'akb_invalid_key',
				'La API key no parece válida. Debería empezar con <code>AIza</code> y tener 39 caracteres.',
				'error'
			);
			// Mantener key previa: rechazar el cambio.
			return (string) get_option( self::OPTION_API_KEY, '' );
		}
		return $key;
	}

	/**
	 * Aviso en WC settings/dashboard si está enabled pero la clave no sirve.
	 *
	 * Tres desenlaces y no uno: «falta», «está escrita pero no valida» y «no hay
	 * catálogo que la lea» mandan al dueño a tres sitios distintos, y el primero
	 * lo mandaba a escribir lo que ya estaba escrito. Aquí se usa
	 * `Access::is_configured()`, que responde la pregunta sin traerse el valor.
	 */
	public function maybe_render_misconfig_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( 'yes' !== get_option( self::OPTION_ENABLED, 'no' ) ) {
			return;
		}

		$estado = $this->key_state();
		if ( $estado['configured'] ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ok     = array( 'dashboard', 'plugins', 'woocommerce_page_wc-settings', 'toplevel_page_akibara-panel' );
		if ( $screen && ! in_array( $screen->id, $ok, true ) ) {
			return;
		}

		$panel = admin_url( 'admin.php?page=akibara-panel&tab=address' );

		if ( ! $estado['catalog'] ) {
			echo '<div class="notice notice-error"><p><strong>Akibara — Autocompletar de direcciones activo, pero falta el catálogo de accesos.</strong> '
				. 'El archivo <code>mu-plugins/akibara-provider-access.php</code> no está cargado y es quien entrega la API key: '
				. 'mientras no vuelva, los clientes ven el campo dirección normal sin sugerencias, y escribir la clave en el panel no cambia nada.</p></div>';

			return;
		}

		if ( $estado['malformed'] && $estado['constant_wins'] ) {
			echo '<div class="notice notice-warning"><p><strong>Akibara — La API key de direcciones está escrita pero su formato no valida.</strong> '
				. 'Debe empezar con <code>AIza</code> y tener 39 caracteres; una clave pegada a medias es el fallo más común. '
				. 'Está en <code>wp-config.php</code> (constante <code>AKB_GOOGLE_MAPS_API_KEY</code>), y lo que se escriba en el panel no la reemplaza. '
				. '<a href="' . esc_url( $panel ) . '">Revisar →</a></p></div>';

			return;
		}

		if ( $estado['malformed'] ) {
			echo '<div class="notice notice-warning"><p><strong>Akibara — La API key de direcciones está escrita pero su formato no valida.</strong> '
				. 'Debe empezar con <code>AIza</code> y tener 39 caracteres; una clave pegada a medias es el fallo más común. '
				. '<a href="' . esc_url( $panel ) . '">Corregirla →</a></p></div>';

			return;
		}

		echo '<div class="notice notice-warning"><p><strong>Akibara — Autocompletar de direcciones activo sin API key.</strong> '
			. 'Los clientes ven el campo dirección normal sin sugerencias. '
			. '<a href="' . esc_url( $panel ) . '">Configurar key →</a></p></div>';
	}

	public function maybe_enqueue_assets(): void {
		if ( ! $this->is_ready() ) {
			return;
		}
		if ( ! $this->should_enqueue_here() ) {
			return;
		}

		wp_enqueue_style(
			'akibara-address-autocomplete',
			AKIBARA_BUSINESS_URL . 'assets/css/address-autocomplete.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/address-autocomplete.css' )
		);

		wp_enqueue_script(
			'akibara-address-autocomplete',
			AKIBARA_BUSINESS_URL . 'assets/js/address-autocomplete.js',
			array(),
			PluginAssetVersion::for_path( 'assets/js/address-autocomplete.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script(
			'akibara-address-autocomplete',
			'window.akbAddrAuto=' . wp_json_encode( $this->build_js_config() ) . ';',
			'before'
		);
	}

	/**
	 * Loads the legacy DOM bridge only on My Account address editing.
	 *
	 * Checkout uses WooCommerce's official address-provider API through Akibara
	 * Cart and Checkout Blocks and must never receive this classic script.
	 */
	private function should_enqueue_here(): bool {
		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'edit-address' );
	}

	/**
	 * Config inyectada al JS. Loader URL ya viene firmado con la key.
	 */
	private function build_js_config(): array {
		$loader = add_query_arg(
			array(
				'key'       => $this->get_api_key(),
				'libraries' => 'places',
				'loading'   => 'async',
				'callback'  => 'akbAddrAutoBoot',
				'v'         => 'weekly',
				'language'  => 'es',
				'region'    => 'CL',
			),
			'https://maps.googleapis.com/maps/api/js'
		);

		return array(
			'loaderUrl' => $loader,
			'country'   => array( 'cl' ),
			'targets'   => array(
				array( 'input' => '#billing_address_1',  'prefix' => 'billing' ),
				array( 'input' => '#shipping_address_1', 'prefix' => 'shipping' ),
			),
			'regionMap' => $this->region_map(),
		);
	}

	/**
	 * Google short_name (admin_area_level_1) → código WC.
	 *
	 * @return array<string,string>
	 *
	 * @since 1.95.0
	 */
	public function region_map(): array {
		return array(
			'RM' => 'CL-RM', 'AP' => 'CL-AP', 'TA' => 'CL-TA', 'AN' => 'CL-AN',
			'AT' => 'CL-AT', 'CO' => 'CL-CO', 'VS' => 'CL-VS', 'LI' => 'CL-LI',
			'ML' => 'CL-ML', 'NB' => 'CL-NB', 'BI' => 'CL-BI', 'AR' => 'CL-AR',
			'LR' => 'CL-LR', 'LL' => 'CL-LL', 'AI' => 'CL-AI', 'MA' => 'CL-MA',
		);
	}

	/**
	 * Settings del panel Akibara. Header dinámico con el estado actual.
	 */
	public function get_settings(): array {
		$estado  = $this->key_state();
		$enabled = 'yes' === get_option( self::OPTION_ENABLED, 'no' );
		$has_key = $estado['configured'];

		if ( ! $estado['catalog'] ) {
			$status = '<span style="color:#EF4444;font-weight:600;">⚠ Falta el catálogo de accesos (mu-plugin)</span>';
		} elseif ( $enabled && $has_key ) {
			$status = '<span style="color:#10B981;font-weight:600;">✓ Activo</span>';
		} elseif ( $estado['malformed'] ) {
			$status = '<span style="color:#F59E0B;font-weight:600;">⚠ Clave escrita pero con formato inválido</span>';
		} elseif ( $enabled ) {
			$status = '<span style="color:#F59E0B;font-weight:600;">⚠ Activado pero falta API key</span>';
		} elseif ( $has_key ) {
			$status = '<span style="color:#6B7280;">⚫ Desactivado (key configurada)</span>';
		} else {
			$status = '<span style="color:#6B7280;">⚫ Desactivado</span>';
		}

		// El formato se revalida en la LECTURA, así que el campo puede decir la
		// verdad entera: si la clave está escrita y rechazada, dónde está escrita.
		if ( $estado['malformed'] && $estado['constant_wins'] ) {
			$key_desc = '<strong>Definida en wp-config.php (constante <code>AKB_GOOGLE_MAPS_API_KEY</code>) pero con un formato que no valida.</strong> '
				. 'Debe empezar con <code>AIza</code> y tener 39 caracteres. Mientras siga así el autocompletar queda apagado, y lo que escribas acá no la reemplaza: corrígela en el <code>wp-config.php</code>.';
		} elseif ( $estado['constant_wins'] ) {
			$key_desc = '<strong>Definida en wp-config.php (constante <code>AKB_GOOGLE_MAPS_API_KEY</code>).</strong> Lo que escribas acá se ignora.';
		} elseif ( $estado['malformed'] ) {
			$key_desc = '<strong>La clave guardada no valida, así que no se está usando.</strong> Vuelve a pegarla completa: empieza con <code>AIza</code> y tiene 39 caracteres.';
		} else {
			$key_desc = 'Empieza con <code>AIza</code> (39 caracteres). Restringe la key a los referrers <code>https://akibara.cl/*</code> en Google Cloud Console.';
		}

		$key_field = array(
			'title' => 'Google Maps API key',
			'id'    => self::OPTION_API_KEY,
			'type'  => 'text',
			'css'   => 'width:420px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;',
			'desc'  => $key_desc,
		);

		if ( $estado['constant_wins'] ) {
			$key_field['custom_attributes'] = array( 'readonly' => 'readonly' );
			$key_field['placeholder']       = 'AIza••• (en wp-config.php)';
		}

		return array(
			array(
				'title' => 'Autocompletar dirección',
				'type'  => 'title',
					'desc'  => 'Estado: ' . $status . '<br>'
						. 'Sugerencias de dirección mediante Google Places (API New). Checkout usa el proveedor nativo de WooCommerce Blocks; Mi Cuenta conserva el puente de edición de dirección. '
					. '<a href="https://console.cloud.google.com/google/maps-apis/credentials" target="_blank" rel="noopener">Obtener API key →</a>',
				'id'    => 'akibara_address_autocomplete_section',
			),
			array(
				'title'   => 'Activar',
					'desc'    => 'Habilita el proveedor nativo en Checkout Blocks y el editor de dirección de Mi Cuenta. Lazy-load: Google solo baja al primer uso.',
				'id'      => self::OPTION_ENABLED,
				'type'    => 'checkbox',
				'default' => 'no',
			),
			$key_field,
			array(
				'type' => 'sectionend',
				'id'   => 'akibara_address_autocomplete_section',
			),
		);
	}
}
