<?php
/**
 * Chilean commune directory sourced from the Blue Express catalog.
 *
 * @package Akibara\CartCheckoutBlocks
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves canonical Chilean communes per region for Checkout Blocks.
 *
 * The catalog is read from the third-party Blue Express plugin (never
 * modified) because it is the exact list its quoting API accepts. Every
 * consumer must fail open: when the vendor data is missing or malformed the
 * directory reports unavailable and the checkout keeps its free-text field.
 *
 * @since 2.2.0
 * @api
 */
final class ComunaDirectory {

	private const VENDOR_DATA_RELATIVE = 'bluex-for-woocommerce/includes/data/chile-communes.php';

	/**
	 * Acentos precompuestos (NFC) a su letra base.
	 *
	 * Cubre la forma en que el catálogo del proveedor escribe los nombres. La
	 * forma descompuesta (NFD) la resuelve el borrado de marcas combinantes de
	 * `normalize()`, no este mapa.
	 *
	 * @var array<string,string>
	 */
	private const ACCENT_MAP = array(
		'Á' => 'a',
		'À' => 'a',
		'Ä' => 'a',
		'Â' => 'a',
		'á' => 'a',
		'à' => 'a',
		'ä' => 'a',
		'â' => 'a',
		'É' => 'e',
		'È' => 'e',
		'Ë' => 'e',
		'Ê' => 'e',
		'é' => 'e',
		'è' => 'e',
		'ë' => 'e',
		'ê' => 'e',
		'Í' => 'i',
		'Ì' => 'i',
		'Ï' => 'i',
		'Î' => 'i',
		'í' => 'i',
		'ì' => 'i',
		'ï' => 'i',
		'î' => 'i',
		'Ó' => 'o',
		'Ò' => 'o',
		'Ö' => 'o',
		'Ô' => 'o',
		'ó' => 'o',
		'ò' => 'o',
		'ö' => 'o',
		'ô' => 'o',
		'Ú' => 'u',
		'Ù' => 'u',
		'Ü' => 'u',
		'Û' => 'u',
		'ú' => 'u',
		'ù' => 'u',
		'ü' => 'u',
		'û' => 'u',
		'Ñ' => 'n',
		'ñ' => 'n',
	);

	/**
	 * Region code => canonical commune names, or null before the first load.
	 *
	 * @var array<string,list<string>>|null
	 */
	private ?array $by_region = null;

	/**
	 * Normalized commune name => array{name:string,region:string}.
	 *
	 * @var array<string,array{name:string,region:string}>
	 */
	private array $index = array();

	/**
	 * Optional loader override used by tests.
	 *
	 * @var callable():mixed|null
	 */
	private $loader;

	/**
	 * Optional vendor file path override used by tests.
	 *
	 * @var string|null
	 */
	private ?string $vendor_data_file;

	/**
	 * Initializes the directory.
	 *
	 * @param callable():mixed|null $loader           Returns the raw vendor structure
	 *                                                (region => list of ['name' => ...]).
	 * @param string|null           $vendor_data_file Optional filesystem path to the vendor catalog.
	 *
	 * @since 2.2.0
	 */
	public function __construct( ?callable $loader = null, ?string $vendor_data_file = null ) {
		$this->loader           = $loader;
		$this->vendor_data_file = $vendor_data_file;
	}

	/**
	 * Returns whether the commune catalog could be loaded.
	 *
	 * @since 2.2.0
	 */
	public function is_available(): bool {
		return array() !== $this->load();
	}

	/**
	 * Returns the canonical commune names keyed by region code.
	 *
	 * @return array<string,list<string>>
	 *
	 * @since 2.2.0
	 */
	public function all_by_region(): array {
		return $this->load();
	}

	/**
	 * Resolves a typed commune to its canonical entry.
	 *
	 * Comparison is accent- and case-insensitive. Commune names are globally
	 * unique in the catalog, so the entry also carries its region: callers can
	 * detect a region mismatch instead of reporting a missing commune.
	 *
	 * @param string $city Free-text commune as typed by the customer.
	 * @return array{name:string,region:string}|null
	 *
	 * @since 2.2.0
	 */
	public function match( string $city ): ?array {
		$this->load();

		$normalized = self::normalize( $city );
		if ( '' === $normalized || ! isset( $this->index[ $normalized ] ) ) {
			return null;
		}

		return $this->index[ $normalized ];
	}

	/**
	 * Suggests the closest canonical commune for an unmatched value.
	 *
	 * @param string $city   Free-text commune as typed by the customer.
	 * @param string $region Region code used to rank same-region candidates first.
	 * @return string|null Canonical name, or null when nothing is close enough.
	 *
	 * @since 2.2.0
	 */
	public function suggest( string $city, string $region = '' ): ?string {
		$this->load();

		$normalized = self::normalize( $city );
		if ( '' === $normalized ) {
			return null;
		}

		$best_name     = null;
		$best_distance = PHP_INT_MAX;
		$length        = strlen( $normalized );

		// Umbral de Levenshtein proporcional a la longitud para evitar coincidencias espurias en cadenas cortas.
		if ( $length <= 4 ) {
			$threshold = 1;
		} elseif ( $length < 8 ) {
			$threshold = 2;
		} else {
			$threshold = 3;
		}

		foreach ( $this->index as $candidate => $entry ) {
			// La región es un FILTRO, no un desempate. Antes puntuaba
			// `distancia * 2 + ( misma_región ? 0 : 1 )`, así que una comuna de
			// otra región a distancia 1 (score 3) le ganaba a la correcta a
			// distancia 2 (score 4): «Renaca» proponía «Renca» —Santiago— aunque
			// la región elegida fuera Valparaíso. Aceptarla dejaba la dirección
			// en otra región: cotización de envío equivocada y paquete a un
			// destino que el cliente nunca pidió. Si no hay ninguna candidata en
			// su región, es mejor no sugerir nada: el campo ya le dice que
			// seleccione de la lista.
			if ( '' !== $region && $entry['region'] !== $region ) {
				continue;
			}

			$distance = levenshtein( $normalized, $candidate );
			if ( $distance > $threshold ) {
				continue;
			}

			if ( $distance < $best_distance ) {
				$best_distance = $distance;
				$best_name     = $entry['name'];
			}
		}

		return $best_name;
	}

	/**
	 * Normalizes a commune for comparison: accents, case and spacing.
	 *
	 * Mirrors (and extends) the vendor's own `normalizeString()` so a value
	 * that canonicalizes here is also one Blue Express geolocation accepts.
	 *
	 * ESTE ES EL CRITERIO AUTORITATIVO, y el gemelo JS
	 * (`assets/js/comuna-combobox.js::normalize`) tiene que producir
	 * exactamente lo mismo. Cuando los dos se separan, el combobox acepta una
	 * comuna que `CheckoutFields::validate_chilean_comuna()` rechaza y el
	 * cliente NO puede pagar. La igualdad sobre el catálogo real la exige
	 * `tests/comuna-normalize-parity.test.js`.
	 *
	 * @param string $value Raw commune text.
	 *
	 * @since 2.2.0
	 */
	public static function normalize( string $value ): string {
		// Las marcas combinantes se borran PRIMERO: en forma descompuesta (NFD)
		// —la que producen los teclados de iOS/macOS y buena parte del texto
		// pegado desde otra web— «Ñuñoa» es 'N' + U+0303, que el mapa de
		// precompuestos no ve. El gemelo JS descompone y las borra siempre.
		$value = self::replace( '/[\x{0300}-\x{036F}]/u', '', $value );
		$value = strtr( $value, self::ACCENT_MAP );

		// `\s` sin `/u` es byte a byte y NO ve el espacio duro (U+00A0) que
		// inyectan los autocompletados de dirección; el gemelo JS sí lo colapsa.
		// Esa asimetría bloqueaba el pago con una comuna que la lista aceptó.
		$value = self::replace( '/[\s\x{00A0}]+/u', ' ', $value );

		// `strtolower()` sólo baja ASCII; `toLowerCase()` del gemelo JS baja
		// todo Unicode. mbstring lo iguala, y se comprueba porque es una
		// extensión y puede faltar (mismo guard que usa el propio core).
		$value = function_exists( 'mb_strtolower' )
			? mb_strtolower( trim( $value ), 'UTF-8' )
			: strtolower( trim( $value ) );

		return trim( $value, " .,;'\"" );
	}

	/**
	 * `preg_replace` que conserva el sujeto cuando el patrón no puede aplicarse.
	 *
	 * Con `/u` un sujeto que no sea UTF-8 válido devuelve null; castearlo a
	 * string vaciaría la comuna, y una comuna vacía no es «no coincide», es
	 * «no la escribiste».
	 *
	 * @param string $pattern     Patrón PCRE.
	 * @param string $replacement Reemplazo.
	 * @param string $subject     Texto de entrada.
	 */
	private static function replace( string $pattern, string $replacement, string $subject ): string {
		$result = preg_replace( $pattern, $replacement, $subject );

		return is_string( $result ) ? $result : $subject;
	}

	/**
	 * Loads and validates the vendor catalog once per request.
	 *
	 * @return array<string,list<string>>
	 */
	private function load(): array {
		if ( null !== $this->by_region ) {
			return $this->by_region;
		}

		$this->by_region = array();
		$this->index     = array();

		$raw = $this->fetch_raw_catalog();
		if ( ! is_array( $raw ) ) {
			return $this->by_region;
		}

		$clean = $this->index_catalog( $raw, true );

		/**
		 * Filters the commune catalog used by the checkout combobox.
		 *
		 * Returning an empty array disables the enhancement and the
		 * server-side commune validation entirely (emergency kill-switch).
		 *
		 * @since 2.2.0
		 *
		 * @param array<string,list<string>> $clean Region code => commune names.
		 */
		$filtered = $this->apply_directory_filter( $clean );
		if ( is_array( $filtered ) && $filtered !== $clean ) {
			$this->index     = array();
			$this->by_region = $this->index_catalog( $filtered, false );

			return $this->by_region;
		}

		$this->by_region = $clean;

		return $this->by_region;
	}

	/**
	 * Applies the public kill-switch/override filter while keeping its return mixed for fail-open checks.
	 *
	 * @param array<string,list<string>> $clean Region code => commune names.
	 * @return mixed
	 */
	private function apply_directory_filter( array $clean ) {
		return apply_filters( 'akibara_comuna_directory', $clean );
	}

	/**
	 * Fetches raw catalog data from the configured loader, failing open on errors.
	 *
	 * @return mixed
	 */
	private function fetch_raw_catalog() {
		try {
			return null !== $this->loader ? ( $this->loader )() : $this->load_from_vendor();
		} catch ( \Throwable $throwable ) {
			PudoLog::failure( 'comuna_directory_load', $throwable );

			return null;
		}
	}

	/**
	 * Builds a deduplicated region => names map and populates the lookup index.
	 *
	 * @param array<mixed> $source      Raw vendor catalog or filtered string catalog.
	 * @param bool         $from_vendor Whether leaf entries are vendor arrays with a `name` key.
	 * @return array<string,list<string>>
	 */
	private function index_catalog( array $source, bool $from_vendor ): array {
		$catalog = array();

		foreach ( $source as $region => $communes ) {
			if ( ! is_string( $region ) || '' === $region || ! is_array( $communes ) ) {
				continue;
			}

			foreach ( $communes as $entry ) {
				$name = $this->extract_commune_name( $entry, $from_vendor );
				if ( null === $name ) {
					continue;
				}

				$this->register_commune( $catalog, $region, $name );
			}
		}

		return $catalog;
	}

	/**
	 * Extracts a trimmed commune name from a vendor record or filtered string.
	 *
	 * @param mixed $entry       Vendor commune entry or filtered string.
	 * @param bool  $from_vendor Whether entries expect an array with a `name` key.
	 */
	private function extract_commune_name( $entry, bool $from_vendor ): ?string {
		$raw = $entry;
		if ( $from_vendor ) {
			$raw = is_array( $entry ) ? ( $entry['name'] ?? null ) : null;
		}

		if ( ! is_string( $raw ) ) {
			return null;
		}

		$trimmed = trim( $raw );

		return '' === $trimmed ? null : $trimmed;
	}

	/**
	 * Adds a commune to both the regional list and the normalized lookup index.
	 *
	 * @param array<string,list<string>> $catalog Regional map passed by reference.
	 * @param string                     $region  WooCommerce region code.
	 * @param string                     $name    Trimmed commune name.
	 */
	private function register_commune( array &$catalog, string $region, string $name ): void {
		$normalized = self::normalize( $name );
		if ( '' === $normalized || isset( $this->index[ $normalized ] ) ) {
			return;
		}

		$catalog[ $region ][]       = $name;
		$this->index[ $normalized ] = array(
			'name'   => $name,
			'region' => $region,
		);
	}

	/**
	 * Default loader: reads the catalog from the Blue Express plugin.
	 *
	 * @return mixed
	 */
	private function load_from_vendor() {
		$this->include_vendor_catalog_if_needed();

		if ( ! function_exists( 'bluex_get_communes_by_region' ) ) {
			return array();
		}

		return bluex_get_communes_by_region();
	}

	/**
	 * Loads the Blue Express catalog file when the lookup function is not yet defined.
	 */
	private function include_vendor_catalog_if_needed(): void {
		if ( function_exists( 'bluex_get_communes_by_region' ) ) {
			return;
		}

		$file = $this->resolve_vendor_data_path();
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Resolves the filesystem path to the vendor commune catalog.
	 */
	private function resolve_vendor_data_path(): string {
		if ( null !== $this->vendor_data_file && '' !== $this->vendor_data_file ) {
			return $this->vendor_data_file;
		}

		$plugins_root = defined( 'WP_PLUGIN_DIR' )
			? rtrim( WP_PLUGIN_DIR, '/\\' )
			: dirname( __DIR__, 2 );

		return $plugins_root . '/' . self::VENDOR_DATA_RELATIVE;
	}
}
