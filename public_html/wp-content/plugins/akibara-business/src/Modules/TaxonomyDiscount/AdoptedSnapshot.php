<?php
/**
 * Foto de precio capturada por Search y ADOPTADA por Business.
 *
 * Business dejó de tomar su propia foto de configuración: la captura Search, la
 * entrega entera y acá se **valida forma y dominio antes de aceptarla**. Dos
 * lecturas independientes de la misma base pueden diverger —ése fue todo el
 * problema de las rondas anteriores— y además releerla costaba consultas que el
 * presupuesto del gate no tiene.
 *
 * Contrato exacto (orden top-level fijo, sin extras):
 *
 *   1. `contract`           — `akibara_search_price_snapshot_v1`
 *   2. `source_fingerprint` — hex SHA-256 de la foto Search
 *   3. `options`            — las 10 options, `ksort`, cada fila `{present, raw}`
 *   4. `timezone`           — firma IANA o `±HH:MM` canónica
 *   5. `window_active`      — bool
 *   6. `boundary_at`        — int positivo futuro, o null
 *   7. `max_pct`            — int 1..90
 *   8. `decimals`           — int 0..8
 *   9. `taxonomies`         — slugs efectivos, trim, únicos, ordenados
 *  10. `term_meta`          — filas `{meta_id, term_id, raw}` ordenadas por
 *                            term_id/meta_id, una por término, raw entero
 *                            decimal canónico no negativo
 *
 * Lo único que sigue siendo product-local es la relación objeto↔término: depende
 * del producto que se esté indexando y no cabe en una foto global.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\TaxonomyDiscount;

use Akibara\Business\Utilities\DiscountCeiling;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.99.16
 */
final class AdoptedSnapshot {

	public const CONTRACT = 'akibara_search_price_snapshot_v1';

	/** Orden EXACTO del top-level. Ni una clave más, ni una menos. */
	private const TOP_LEVEL = array(
		'contract',
		'source_fingerprint',
		'options',
		'timezone',
		'window_active',
		'boundary_at',
		'max_pct',
		'decimals',
		'taxonomies',
		'term_meta',
	);

	/**
	 * Las 10 options del contrato, en el orden `ksort` que exige la foto.
	 *
	 * LOS DOS LADOS SE MUEVEN JUNTOS: esta lista y
	 * `EditorialDiscountPublication::options()` de akibara-search son el MISMO
	 * contrato — tocar una sin la otra hace que la foto se RECHACE entera y el
	 * buscador desaparezca de la superficie servida (pasó el  al
	 * entrar `akb_discount_max_pct` solo por el lado de search; el candado
	 * cruzado vive en SearchSnapshotContractTest).
	 */
	private const OPTIONS = array(
		'akb_discount_max_pct',
		'akibara_business_search_feed_contract_version',
		'akibara_taxonomy_discount_apply_to',
		'akibara_taxonomy_discount_enabled',
		'akibara_taxonomy_discount_from',
		'akibara_taxonomy_discount_sale_policy',
		'akibara_taxonomy_discount_to',
		'gmt_offset',
		'timezone_string',
		'woocommerce_price_num_decimals',
	);

	/** Contrato de feed que Business declara y Search exige. */
	private const FEED_CONTRACT = '2';

	/** @var array<string, mixed> */
	private array $data;

	/** @var array<int, int> term_id => pct */
	private array $term_pct = array();

	/**
	 * @param array<string, mixed> $data Foto ya validada.
	 * @param array<int, int>      $term_pct Índice term_id => pct.
	 */
	private function __construct( array $data, array $term_pct ) {
		$this->data     = $data;
		$this->term_pct = $term_pct;
	}

	/**
	 * Valida una foto de Search y la adopta, o explica por qué no.
	 *
	 * @param mixed  $snapshot Foto entregada por Search.
	 * @param string $why      Motivo del rechazo (salida).
	 * @return self|null
	 */
	public static function from_search( $snapshot, string &$why, ?int $now = null ): ?self {
		$now = null === $now ? time() : $now;

		if ( ! is_array( $snapshot ) ) {
			$why = 'La foto de Search no es un array.';
			return null;
		}

		if ( self::TOP_LEVEL !== array_keys( $snapshot ) ) {
			$why = 'La foto de Search no tiene exactamente las claves del contrato, en orden.';
			return null;
		}

		if ( self::CONTRACT !== ( $snapshot['contract'] ?? null ) ) {
			$why = 'La foto de Search declara otro contrato.';
			return null;
		}

		if ( ! is_string( $snapshot['source_fingerprint'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $snapshot['source_fingerprint'] ) ) {
			$why = 'source_fingerprint no es un SHA-256 hexadecimal.';
			return null;
		}

		if ( ! self::options_are_valid( $snapshot['options'], $why ) ) {
			return null;
		}

		if ( ! is_string( $snapshot['timezone'] ) || ! self::timezone_is_canonical( $snapshot['timezone'] ) ) {
			$why = 'timezone no es una zona que PHP pueda interpretar.';
			return null;
		}

		if ( ! is_bool( $snapshot['window_active'] ) ) {
			$why = 'window_active no es booleano.';
			return null;
		}

		$boundary = $snapshot['boundary_at'];
		if ( null !== $boundary && ( ! is_int( $boundary ) || $boundary <= $now ) ) {
			// Tipo y "futuro" se comprueban antes de recomputar, para dar un
			// mensaje claro; la igualdad literal con el valor derivado la exige
			// `window_is_coherent()`.
			$why = 'boundary_at debe ser null o un instante estrictamente futuro.';
			return null;
		}

		if ( ! is_int( $snapshot['max_pct'] ) || $snapshot['max_pct'] < 1 || $snapshot['max_pct'] > 90 ) {
			$why = 'max_pct fuera del dominio 1..90.';
			return null;
		}

		if ( ! is_int( $snapshot['decimals'] ) || $snapshot['decimals'] < 0 || $snapshot['decimals'] > 8 ) {
			$why = 'decimals fuera del dominio 0..8.';
			return null;
		}

		if ( ! self::timezone_matches_options( $snapshot['timezone'], $snapshot['options'], $why ) ) {
			return null;
		}

		if ( ! self::window_is_coherent( $snapshot, $why, $now ) ) {
			return null;
		}

		if ( ! self::taxonomies_are_valid( $snapshot['taxonomies'], $why ) ) {
			return null;
		}

		$term_pct = self::term_meta_index( $snapshot['term_meta'], $snapshot['max_pct'], $why );
		if ( null === $term_pct ) {
			return null;
		}

		return new self( $snapshot, $term_pct );
	}

	/**
	 * @param mixed  $options Opciones de la foto.
	 * @param string $why     Motivo del rechazo.
	 * @return bool
	 */
	private static function options_are_valid( $options, string &$why ): bool {
		if ( ! is_array( $options ) || self::OPTIONS !== array_keys( $options ) ) {
			$why = 'Las options no son exactamente las diez del contrato, en orden ksort.';
			return false;
		}

		$decoded_options = array();
		foreach ( $options as $name => $row ) {
			if ( ! is_array( $row ) || array( 'present', 'raw' ) !== array_keys( $row ) ) {
				$why = sprintf( 'La option %s no tiene la forma {present, raw}.', $name );
				return false;
			}
			if ( ! is_bool( $row['present'] ) || ! is_string( $row['raw'] ) ) {
				$why = sprintf( 'La option %s tiene tipos inesperados.', $name );
				return false;
			}
			if ( ! $row['present'] && '' !== $row['raw'] ) {
				$why = sprintf( 'La option %s se declara ausente pero trae raw.', $name );
				return false;
			}

			if ( ! $row['present'] ) {
				continue; // ausencia legítima: manda el default del contrato.
			}

			$decoded = self::decode_raw( $row['raw'] );
			if ( ! $decoded['valid'] ) {
				$why = sprintf( 'La option %s trae un raw serializado ambiguo.', $name );
				return false;
			}
			$decoded_options[ $name ] = (string) $decoded['value'];

			// Igual que WordPress y Search: con timezone_string no vacío,
			// gmt_offset no participa de la zona efectiva. Core conserva en ese
			// estado una fila gmt_offset presente y vacía perfectamente normal.
			$uses_legacy_empty_default = '' === $row['raw']
				&& in_array(
					$name,
					array(
						'akibara_taxonomy_discount_enabled',
						'akibara_taxonomy_discount_apply_to',
						'akibara_taxonomy_discount_sale_policy',
					),
					true
				);
			if ( 'gmt_offset' !== $name && ! $uses_legacy_empty_default
				&& ! self::valid_option_value( $name, $decoded_options[ $name ] ) ) {
				$why = sprintf( 'La option %s está fuera de su dominio semántico.', $name );
				return false;
			}
		}

		$timezone = $decoded_options['timezone_string'] ?? '';
		if ( '' === $timezone && isset( $decoded_options['gmt_offset'] )
			&& ! self::valid_option_value( 'gmt_offset', $decoded_options['gmt_offset'] ) ) {
			$why = 'La option gmt_offset está fuera de su dominio semántico.';
			return false;
		}

		$feed = $options['akibara_business_search_feed_contract_version'];
		if ( ! $feed['present'] || self::FEED_CONTRACT !== (string) self::decode_raw( $feed['raw'] )['value'] ) {
			$why = 'La foto no declara el contrato de feed esperado.';
			return false;
		}

		return true;
	}

	/**
	 * Decodifica un `option_value` crudo con la MISMA semántica que Search: un
	 * `N;`, un serializado que hace round-trip exacto y no esconde objetos, o el
	 * literal tal cual. Un serializado ambiguo no es un valor: es una fila que
	 * los dos lados leerían distinto.
	 *
	 * @param string $raw Valor crudo.
	 * @return array{valid: bool, value: mixed}
	 */
	private static function decode_raw( string $raw ): array {
		if ( 'N;' === $raw ) {
			return array( 'valid' => true, 'value' => null );
		}

		if ( 1 === preg_match( '/^(?:a|O|C|s|i|d|b):/', $raw ) ) {
			$value = @unserialize( $raw, array( 'allowed_classes' => false ) );
			$valid = ( false !== $value || 'b:0;' === $raw )
				&& serialize( $value ) === $raw
				&& ! is_object( $value )
				&& ( is_scalar( $value ) || null === $value );

			return array( 'valid' => $valid, 'value' => $valid ? $value : null );
		}

		return array( 'valid' => true, 'value' => $raw );
	}

	/**
	 * Dominio semántico exacto de cada option, replicando
	 * `valid_source_option_value()` de Search — con una vuelta de tuerca en las
	 * fechas: Search comprueba el patrón `\d{4}-\d{2}-\d{2}`, y acá se exige
	 * además que la fecha EXISTA (`` no es una ventana).
	 *
	 * @param string $name  Option.
	 * @param string $value Valor ya decodificado.
	 * @return bool
	 */
	private static function valid_option_value( string $name, string $value ): bool {
		switch ( $name ) {
			case 'akibara_taxonomy_discount_enabled':
				return in_array( $value, array( 'yes', 'no' ), true );

			case 'akibara_taxonomy_discount_apply_to':
				return in_array( $value, array( 'instock_only', 'instock_and_onbackorder', 'all' ), true );

			case 'akibara_taxonomy_discount_sale_policy':
				return in_array( $value, array( 'greatest_wins', 'skip_if_on_sale', 'from_regular', 'stack_on_sale' ), true );

			case 'akibara_taxonomy_discount_from':
			case 'akibara_taxonomy_discount_to':
				if ( '' === $value ) {
					return true;
				}
				if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) ) {
					return false;
				}
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
				return false !== $date && $date->format( 'Y-m-d' ) === $value;

			case 'akibara_business_search_feed_contract_version':
				return self::FEED_CONTRACT === $value;

			case 'woocommerce_price_num_decimals':
				return 1 === preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) && (int) $value <= 8;

			case 'timezone_string':
				// Más estricto que `valid_source_option_value()` de Search (que
				// sólo mira largo y NUL) y sin embargo EQUIVALENTE: el gate de
				// publicación de Search no es esa función, es
				// `effective_timezone()`, que pasa el literal a `new DateTimeZone`
				// dentro de un try y devuelve null si lanza — y
				// `source_snapshot_from_options()` exige esa zona para emitir la
				// foto. Una zona que PHP no sabe interpretar hace que Search NO
				// publique; no hay foto que un lado publique y el otro rechace.
				// La equivalencia la fija PriceSnapshotTimezoneCrossRailTest;
				// si se toca una de las dos copias, se toca esa prueba.
				if ( '' === $value ) {
					return true;
				}
				if ( strlen( $value ) > 100 || false !== strpos( $value, "\0" ) ) {
					return false;
				}
				try {
					new \DateTimeZone( $value );
					return true;
				} catch ( \Throwable $e ) {
					unset( $e );
					return false;
				}

			case 'akb_discount_max_pct':
				/*
                 * El dominio de akb_discount_max_pct debe coincidir con
                 * Utilities\DiscountCeiling y con la autoridad de Search:
                 * entero entre HARD_MIN y HARD_MAX. La presencia de una fila
                 * válida no puede invalidar una foto aceptada por el otro lector.
                 */
				if ( 1 !== preg_match( '/^(?:[1-9]|[1-8][0-9]|90)$/D', $value ) ) {
					return false;
				}
				$pct = (int) $value;
				return $pct >= DiscountCeiling::HARD_MIN && $pct <= DiscountCeiling::HARD_MAX;

			case 'gmt_offset':
				// Forma numérica MÍNIMA y canónica. Una option presente con raw
				// vacío no es "sin offset": es una configuración que Search y
				// Business podrían leer distinto, así que se rechaza. `-0`,
				// `-0.0` y `-00` describen el mismo instante que `0` con tres
				// escrituras distintas: sólo se acepta `0`.
				if ( 1 !== preg_match( '/^(-?)(0|[1-9][0-9]*)(?:\.(25|5|75))?$/D', $value, $parts ) ) {
					return false;
				}
				if ( '-' === $parts[1] && '0' === $parts[2] && ! isset( $parts[3] ) ) {
					return false;
				}
				$offset = (float) $value;
				return is_finite( $offset ) && $offset >= -14.0 && $offset <= 14.0;
		}

		return false;
	}

	/**
	 * La firma de zona declarada tiene que ser la que se deriva de las options:
	 * si no, la foto dice una cosa y su propia configuración otra.
	 *
	 * @param string               $timezone Firma declarada.
	 * @param array<string, mixed> $options  Options de la foto.
	 * @param string               $why      Motivo del rechazo.
	 * @return bool
	 */
	private static function timezone_matches_options( string $timezone, array $options, string &$why ): bool {
		$string = $options['timezone_string']['present']
			? (string) self::decode_raw( $options['timezone_string']['raw'] )['value']
			: '';

		// Asimetría deliberada, calcada de `effective_timezone()`: con
		// `timezone_string` la firma es el LITERAL —igualdad exacta, no
		// equivalencia de offset: `GMT` y `UTC` apuntan al mismo instante y aun
		// así son firmas distintas— y sin él, la firma tiene que ser el offset
		// canónico derivado.
		if ( '' !== $string ) {
			if ( $string !== $timezone ) {
				$why = 'La firma de timezone no coincide con timezone_string.';
				return false;
			}
			return true;
		}

		$offset_raw = $options['gmt_offset']['present']
			? (string) self::decode_raw( $options['gmt_offset']['raw'] )['value']
			: '0';
		$offset     = (float) $offset_raw;

		$hours    = (int) floor( abs( $offset ) );
		$minutes  = (int) round( ( abs( $offset ) - $hours ) * 60 );
		$expected = sprintf( '%s%02d:%02d', $offset < 0 ? '-' : '+', $hours, $minutes );

		if ( $expected !== $timezone ) {
			$why = sprintf( 'La firma de timezone (%s) no se deriva de gmt_offset (%s).', $timezone, $expected );
			return false;
		}

		return true;
	}

	/**
	 * Coherencia de la ventana: el switch, la vigencia y el límite tienen que
	 * contar la misma historia.
	 *
	 * @param array<string, mixed> $snapshot Foto.
	 * @param string               $why      Motivo del rechazo.
	 * @return bool
	 */
	private static function window_is_coherent( array $snapshot, string &$why, int $now ): bool {
		$computed = self::compute_window( $snapshot['options'], (string) $snapshot['timezone'], $now );

		if ( null === $computed ) {
			$why = 'No se pudo recomputar la ventana declarada por la foto.';
			return false;
		}

		if ( $computed['active'] !== $snapshot['window_active'] ) {
			$why = sprintf(
				'window_active declarado (%s) no coincide con el recomputado (%s).',
				$snapshot['window_active'] ? 'true' : 'false',
				$computed['active'] ? 'true' : 'false'
			);
			return false;
		}

		if ( $computed['boundary'] !== $snapshot['boundary_at'] ) {
			$why = sprintf(
				'boundary_at declarado (%s) no coincide con el recomputado (%s).',
				null === $snapshot['boundary_at'] ? 'null' : (string) $snapshot['boundary_at'],
				null === $computed['boundary'] ? 'null' : (string) $computed['boundary']
			);
			return false;
		}

		return true;
	}

	/**
	 * Recomputa la ventana con la MISMA semántica que Search, desde las options
	 * ya decodificadas, la zona de la foto y el reloj de adopción:
	 *
	 *   - `now` se evalúa EN LA ZONA de la foto;
	 *   - `active` = `enabled === 'yes'` y hoy dentro de `[from, to]`;
	 *   - `boundary` = con el módulo ENCENDIDO, el menor instante FUTURO entre
	 *     `from 00:00` y `to 00:00 + 1 día` (el fin es exclusivo), o `null`;
	 *     con el módulo apagado, siempre `null`.
	 *
	 * Los dos valores declarados por la foto se comparan literalmente contra
	 * esto: confiar en lo que la foto afirma sobre su propia vigencia sería
	 * aceptar la palabra del emisor sobre el dato que decide si hay descuento
	 *.
	 *
	 * @param array<string, mixed> $options  Options de la foto.
	 * @param string               $timezone Firma de zona.
	 * @param int                  $now      Reloj de adopción.
	 * @return array{active: bool, boundary: int|null}|null
	 */
	private static function compute_window( array $options, string $timezone, int $now ): ?array {
		try {
			$zone = new \DateTimeZone( $timezone );
		} catch ( \Throwable $e ) {
			unset( $e );
			return null;
		}

		$enabled = self::option_value( $options, 'akibara_taxonomy_discount_enabled', 'no' );
		$from    = self::option_value( $options, 'akibara_taxonomy_discount_from', '' );
		$to      = self::option_value( $options, 'akibara_taxonomy_discount_to', '' );

		try {
			$moment = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $zone );
			$today  = $moment->format( 'Y-m-d' );

			// Los dos candidatos existen SÓLO con el módulo encendido, igual que
			// en `window_snapshot()` de Search: ambos `$boundaries[] = …` viven
			// bajo `'yes' === $enabled`. Con el módulo apagado no hay nada que
			// esperar, así que no hay límite que declarar aunque el calendario
			// tenga fechas futuras.
			$candidates = array();

			if ( 'yes' === $enabled && '' !== $from ) {
				$start = new \DateTimeImmutable( $from . ' 00:00:00', $zone );
				$candidates[] = $start->getTimestamp();
			}

			if ( 'yes' === $enabled && '' !== $to ) {
				// Calcular la medianoche del día siguiente por fecha civil, no sumando24h.
				// Una medianoche inexistente por cambio horario puede normalizarse a otra hora.
				// La operación debe ser idéntica a window_snapshot() de Search: window_is_coherent()
				// compara esos instantes literalmente antes de adoptar la autoridad.

				$next_day = ( new \DateTimeImmutable( $to, new \DateTimeZone( 'UTC' ) ) )->modify( '+1 day' );
				if ( false === $next_day ) {
					return null;
				}
				$end = new \DateTimeImmutable( $next_day->format( 'Y-m-d' ) . ' 00:00:00', $zone );
				$candidates[] = $end->getTimestamp();
			}
		} catch ( \Throwable $e ) {
			unset( $e );
			return null;
		}

		$active = 'yes' === $enabled
			&& ( '' === $from || $today >= $from )
			&& ( '' === $to || $today <= $to );

		$future = array_filter( $candidates, static fn( int $moment ): bool => $moment > $now );

		return array(
			'active'   => $active,
			'boundary' => empty( $future ) ? null : min( $future ),
		);
	}

	/**
	 * Valor decodificado de una option de la foto, o su default.
	 *
	 * @param array<string, mixed> $options       Options.
	 * @param string               $name          Nombre.
	 * @param string               $default_value Default cuando está ausente.
	 * @return string
	 */
	private static function option_value( array $options, string $name, string $default_value ): string {
		$row = $options[ $name ] ?? null;

		if ( ! is_array( $row ) || ! $row['present'] ) {
			return $default_value;
		}

		$decoded = self::decode_raw( (string) $row['raw'] );

		return $decoded['valid'] && null !== $decoded['value'] ? (string) $decoded['value'] : $default_value;
	}

	/**
	 * ¿La firma declarada es una zona que PHP sabe interpretar?
	 *
	 * Ni más ni menos que eso. Search construye su firma en
	 * `effective_timezone()`: si `timezone_string` no está vacío, lo pasa TAL
	 * CUAL a `new DateTimeZone()` y publica ese mismo literal; sólo cuando está
	 * vacío deriva un offset desde `gmt_offset`. Business exigía además barra o
	 * un `±HH:MM` canónico, así que rechazaba firmas que el productor emite sin
	 * problema —`GMT`, `CET`, `EST`, `Factory`, `+14:45`, `+15:00`— y una foto
	 * que un lado publica y el otro rechaza no es una foto compartida
	 *.
	 *
	 * La estrictez de los offsets no desaparece: vive donde corresponde, en la
	 * derivación desde `gmt_offset` de `timezone_matches_options()`.
	 *
	 * @param string $timezone Firma declarada.
	 * @return bool
	 */
	private static function timezone_is_canonical( string $timezone ): bool {
		if ( '' === $timezone || strlen( $timezone ) > 100 || false !== strpos( $timezone, "\0" ) ) {
			return false;
		}

		try {
			new \DateTimeZone( $timezone );

			return true;
		} catch ( \Throwable $e ) {
			unset( $e );

			return false;
		}
	}

	/**
	 * @param mixed  $taxonomies Slugs efectivos.
	 * @param string $why        Motivo del rechazo.
	 * @return bool
	 */
	private static function taxonomies_are_valid( $taxonomies, string &$why ): bool {
		if ( ! is_array( $taxonomies )
			|| ( array() !== $taxonomies && array_keys( $taxonomies ) !== range( 0, count( $taxonomies ) - 1 ) ) ) {
			$why = 'taxonomies no es una lista.';
			return false;
		}

		$previous = null;
		foreach ( $taxonomies as $slug ) {
			if ( ! is_string( $slug ) || '' === trim( $slug ) || trim( $slug ) !== $slug ) {
				$why = 'taxonomies contiene un slug vacío o sin trim.';
				return false;
			}

			// Dominio real de un nombre de taxonomía en WordPress: hasta 32
			// caracteres de `[a-z0-9_-]`. Un NUL, un espacio o una mayúscula
			// significan que ese slug nunca podría existir.
			if ( 1 !== preg_match( '/^[a-z0-9_-]{1,32}$/D', $slug ) ) {
				$why = sprintf( 'taxonomies contiene un slug fuera de dominio (%s).', $slug );
				return false;
			}
			if ( null !== $previous && strcmp( $slug, $previous ) <= 0 ) {
				$why = 'taxonomies no viene ordenada y sin duplicados.';
				return false;
			}
			$previous = $slug;
		}

		return true;
	}

	/**
	 * Índice `term_id => pct` a partir de las filas adoptadas.
	 *
	 * @param mixed  $rows    Filas de term-meta.
	 * @param int    $max_pct Tope efectivo.
	 * @param string $why     Motivo del rechazo.
	 * @return array<int, int>|null
	 */
	private static function term_meta_index( $rows, int $max_pct, string &$why ): ?array {
		if ( ! is_array( $rows )
			|| ( array() !== $rows && array_keys( $rows ) !== range( 0, count( $rows ) - 1 ) ) ) {
			$why = 'term_meta no es una lista.';
			return null;
		}

		$index         = array();
		$seen_meta_ids = array();
		$previous_term = null;
		$previous_meta = null;

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array( 'meta_id', 'term_id', 'raw' ) !== array_keys( $row ) ) {
				$why = 'Una fila de term_meta no tiene la forma {meta_id, term_id, raw}.';
				return null;
			}

			if ( ! is_int( $row['meta_id'] ) || $row['meta_id'] <= 0
				|| ! is_int( $row['term_id'] ) || $row['term_id'] <= 0 ) {
				$why = 'term_meta trae IDs no positivos.';
				return null;
			}

			if ( ! is_string( $row['raw'] ) || 1 !== preg_match( '/^(0|[1-9][0-9]*)$/D', $row['raw'] ) ) {
				$why = 'term_meta trae un raw que no es entero decimal canónico.';
				return null;
			}

			if ( isset( $index[ $row['term_id'] ] ) ) {
				$why = sprintf( 'term_meta trae más de una fila para el término %d.', $row['term_id'] );
				return null;
			}

			// `meta_id` es la PK de `termmeta`: repetirlo entre términos
			// distintos significa que la foto no salió de una consulta real.
			if ( isset( $seen_meta_ids[ $row['meta_id'] ] ) ) {
				$why = sprintf( 'term_meta repite el meta_id %d en términos distintos.', $row['meta_id'] );
				return null;
			}
			$seen_meta_ids[ $row['meta_id'] ] = true;

			// Orden estable por (term_id, meta_id): sin él, dos fotos con las
			// mismas filas producirían fingerprints distintos.
			if ( null !== $previous_term
				&& ( $row['term_id'] < $previous_term
					|| ( $row['term_id'] === $previous_term && $row['meta_id'] <= $previous_meta ) ) ) {
				$why = 'term_meta no viene ordenada por term_id/meta_id.';
				return null;
			}

			$previous_term = $row['term_id'];
			$previous_meta = $row['meta_id'];

			$index[ $row['term_id'] ] = max( 0, min( $max_pct, (int) $row['raw'] ) );
		}

		return $index;
	}

	/** @return array<string, mixed> La foto tal cual, para comparación literal. */
	public function data(): array {
		return $this->data;
	}

	/** Porcentaje adoptado de un término (0 si la foto no lo trae). */
	public function term_pct( int $term_id ): int {
		return $this->term_pct[ $term_id ] ?? 0;
	}

	public function max_pct(): int {
		return (int) $this->data['max_pct'];
	}

	public function decimals(): int {
		return (int) $this->data['decimals'];
	}

	public function window_active(): bool {
		return (bool) $this->data['window_active'];
	}

	/** @return int|null */
	public function boundary_at() {
		return $this->data['boundary_at'];
	}

	/** @return array<int, string> */
	public function taxonomies(): array {
		return $this->data['taxonomies'];
	}

	/** Valor efectivo de una option adoptada. */
	public function option( string $name, string $default_value = '' ): string {
		$row = $this->data['options'][ $name ] ?? null;

		if ( ! is_array( $row ) || ! $row['present'] ) {
			return $default_value;
		}

		// Se devuelve el valor DECODIFICADO: aceptar `s:3:"yes";` y luego
		// compararlo literalmente contra `'yes'` daría "desactivado" en
		// silencio.
		$decoded = self::decode_raw( (string) $row['raw'] );

		return $decoded['valid'] && null !== $decoded['value'] ? (string) $decoded['value'] : $default_value;
	}

	/**
	 * JSON canónico de la foto, para el token.
	 *
	 * @return string Cadena vacía si el encode falla.
	 */
	public function canonical_json(): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $this->data ) : json_encode( $this->data );

		return is_string( $json ) && '' !== $json ? $json : '';
	}
}
