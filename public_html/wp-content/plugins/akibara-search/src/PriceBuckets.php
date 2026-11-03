<?php
/**
 * PriceBuckets — derivación automática de los rangos de la faceta Precio desde
 * los precios REALES del catálogo (pedido del dueño: nada hardcodeado).
 *
 * `derive()` es PURA (sin WP): cuantiles del precio efectivo snapeados a $1.000,
 * N adaptativo (piso 2 buckets) con dedupe en CASCADA a la próxima frontera
 * distinta que separe poblaciones reales. Garantía de integridad:
 * la salida SIEMPRE pasa `FacetRegistry::validate_precio_buckets()` o es `[]`
 * (faceta oculta) — nunca un set inválido, porque el resolver re-valida al leer
 * y un set inválido persistido caería en silencio a la const hardcodeada.
 *
 * Las etiquetas se derivan estrictamente del rango numérico (`derive_label()`):
 * "$14.990" representa el techo de display (máximo exclusivo - 10,
 * alineado a la convención comercial de terminación en 990).
 *
 * La PERSISTENCIA vive acá (`refresh()`) y solo se invoca desde reindex
 * (`RebuildJob::finish()`, solo modo AUTO), admin (Recalcular) o activación —
 * NUNCA desde el getter `FacetRegistry::precio_buckets()`, que es lectura pura
 * para prevenir escrituras en operaciones GET y estampidas de concurrencia.
 *
 * @package Akibara\Search
 * @since 1.60.0
 */

declare( strict_types = 1 );

namespace Akibara\Search;

/**
 * Derivación y modo (AUTO/MANUAL) de los buckets de precio.
 */
final class PriceBuckets {

	/**
	 * Modo de los buckets: option SEPARADA de la de los buckets [gate v3.1 §3 —
	 * como clave hermana rompería el `array_values` del resolver]. Ausente o
	 * 'auto' = AUTO (el reindex re-deriva); 'manual' = curaduría del dueño
	 * (el reindex NO la pisa).
	 */
	public const OPTION_MODE = 'akb_search_precio_mode';

	/** Cantidad objetivo de tramos (adaptativa hacia abajo, piso 2). */
	public const TARGET = 4;

	/** Snap de breakpoints: múltiplos de $1.000 (montos chilenos lindos). */
	private const STEP = 1000;

	/** Techo de display del max exclusivo: 10000 → "$9.990" (pricing en 990). */
	private const LABEL_EPS = 10;

	/** Barrera breve para cubrir crash entre pre-intent y option write. */
	private const PUBLICATION_DELAY_SECONDS = 10;

	/** Políticas internas de la transición atómica mode+buckets. */
	private const MODE_AUTO              = 'auto';
	private const MODE_MANUAL_IF_CHANGED = 'manual_if_changed';

	/** Evita que hooks genéricos dupliquen el outbox gestionado aquí. */
	private static int $publication_context = 0;

	/**
	 * ¿Modo automático? (option ausente = AUTO; activación arranca en AUTO).
	 *
	 * @return bool
	 */
	public static function is_auto(): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return true;
		}
		return 'manual' !== SemanticPublication::authoritative_value( self::OPTION_MODE, 'auto' );
	}

	/**
	 * Deriva los buckets desde una lista de precios (PURA — sin WP).
	 *
	 * Algoritmo blindado [gate DISEÑO v3.1]:
	 *   1. Candidatos = cuantiles i/TARGET snapeados al $1.000 más cercano.
	 *   2. Cascada: cada breakpoint debe ser estrictamente mayor que el anterior
	 *      Y dejar población real en el segmento previo; si no, avanza de a
	 *      $1.000 hasta la próxima frontera viable, o se DESCARTA (N baja).
	 *   3. Piso 2 buckets (≥1 breakpoint); si no hay frontera viable → `[]`.
	 *   4. Cinturón final: si el set no pasa el validador → `[]`. NUNCA inválido.
	 *
	 * @param array<int, int|float|string> $prices Precios efectivos (>0).
	 * @param int                          $target Tramos objetivo (default 4).
	 * @return array<int, array{min: int|null, max: int|null, label: string}>
	 */
	public static function derive( array $prices, int $target = self::TARGET ): array {
		$vals = array();
		foreach ( $prices as $p ) {
			$v = (int) round( (float) $p );
			if ( $v > 0 ) {
				$vals[] = $v;
			}
		}
		sort( $vals );
		$n = count( $vals );
		if ( $n < 2 ) {
			return array();
		}
		$min = $vals[0];
		$max = $vals[ $n - 1 ];

		$breaks = array();
		$prev   = null; // último breakpoint aceptado.
		for ( $i = 1; $i < $target; $i++ ) {
			$q  = $vals[ (int) floor( $i / $target * ( $n - 1 ) ) ];
			$bp = (int) round( $q / self::STEP ) * self::STEP;

			// Frontera mínima viable: próximo múltiplo de STEP ESTRICTAMENTE
			// mayor que el piso (el min del catálogo para el primer break — así
			// el primer bucket nunca queda vacío — o el break anterior).
			$floor_bound = null === $prev ? $min : $prev;
			$min_bp      = ( (int) floor( $floor_bound / self::STEP ) + 1 ) * self::STEP;
			if ( $bp < $min_bp ) {
				$bp = $min_bp;
			}

			// Cascada: el segmento previo [prev|-inf, bp) debe tener población
			// real; si no, avanzar. bp > max = sin frontera viable → descartar
			// este y los siguientes (los cuantiles restantes son >= este).
			while ( $bp <= $max && 0 === self::population( $vals, $prev, $bp ) ) {
				$bp += self::STEP;
			}
			if ( $bp > $max ) {
				break;
			}

			$breaks[] = $bp;
			$prev     = $bp;
		}

		if ( empty( $breaks ) ) {
			return array(); // sin ≥2 bandas viables → faceta oculta, no inventar.
		}

		$rows  = array();
		$count = count( $breaks );
		for ( $i = 0; $i <= $count; $i++ ) {
			$b_min  = 0 === $i ? null : $breaks[ $i - 1 ];
			$b_max  = $count === $i ? null : $breaks[ $i ];
			$rows[] = array(
				'min'   => $b_min,
				'max'   => $b_max,
				'label' => self::derive_label( $b_min, $b_max ),
			);
		}

		// Cinturón final de la GARANTÍA DURA: nunca devolver un set inválido.
		if ( null !== FacetRegistry::validate_precio_buckets( $rows ) ) {
			return array();
		}

		return $rows;
	}

	/**
	 * Label DERIVADO del rango `[min, max)` — no puede mentir sobre el corte.
	 * El techo de display es `max − 10` ("hasta $9.990" para max exclusivo
	 * 10000): un límite superior de exhibición, no un precio que deba existir.
	 *
	 * @param int|null $min Piso inclusivo (null = sin tope inferior).
	 * @param int|null $max Techo EXCLUSIVO (null = sin tope superior).
	 * @return string
	 */
	public static function derive_label( ?int $min, ?int $max ): string {
		if ( null === $min && null !== $max ) {
			return 'Hasta $' . self::fmt( $max - self::LABEL_EPS );
		}
		if ( null !== $min && null === $max ) {
			return '$' . self::fmt( $min ) . ' o más';
		}
		if ( null !== $min && null !== $max ) {
			return '$' . self::fmt( $min ) . ' – $' . self::fmt( $max - self::LABEL_EPS );
		}
		return 'Precio';
	}

	/**
	 * Deriva desde los precios reales del índice (`value_num`, el MISMO dato que
	 * filtra — DocumentBuilder solo materializa la fila `precio` si el precio
	 * efectivo es > 0).
	 *
	 * @return array<int, array{min: int|null, max: int|null, label: string}>|null
	 *         null distingue un SELECT fallido de un índice válidamente vacío.
	 */
	public static function derive_from_index(): ?array {
		if ( ! isset( $GLOBALS['wpdb'] ) ) {
			return null;
		}
		$db     = $GLOBALS['wpdb'];
		$facets = Install::facets_table();
		$db->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- lectura directa de precios desde la tabla interna de facetas del plugin.
		$prices = $db->get_col( "SELECT value_num FROM {$facets} WHERE facet = 'precio' AND value_num IS NOT NULL" );
		if ( ! is_array( $prices ) || '' !== (string) ( $db->last_error ?? '' ) ) {
			return null;
		}

		return self::derive( $prices );
	}

	/**
	 * Re-deriva y persiste el par mode+buckets de forma atómica (contextos:
	 * reindex AUTO, admin Recalcular y activación con índice). Sin bandas
	 * viables persiste `[]`: `has_precio_buckets()` mantiene la faceta oculta,
	 * pero la fila hermana continúa formando un par explícito con mode.
	 *
	 * La derivación ocurre antes de tomar locks; la decisión de aplicar se toma
	 * otra vez bajo lock. `only_if_auto` nunca pisa una curaduría manual que ganó
	 * mientras se calculaban los cuantiles.
	 *
	 * @param bool $only_if_auto Abortar el write si el modo ya no es AUTO.
	 * @param bool $publish      Gestionar outbox+purge; false si el caller ya
	 *                           publicará un target propio (rebuild post-swap).
	 * @return int|false Cantidad persistida; false ante lectura/write fallida.
	 *                   Por defecto esta clase publica; sólo el rebuild la
	 *                   suprime porque su target final cubre la misma mutación.
	 */
	public static function refresh( bool $only_if_auto = false, bool $publish = true ) {
		$rows = self::derive_from_index();
		if ( null === $rows ) {
			return false;
		}

		return self::transition( $rows, self::MODE_AUTO, $only_if_auto, $publish );
	}

	/**
	 * Guarda una curaduría manual mediante la misma primitiva que Recalcular.
	 * El modo sólo cambia a MANUAL cuando la secuencia de bounds difiere de la
	 * foto bloqueada; un POST construido sobre una foto vieja no decide fuera de
	 * la transacción.
	 *
	 * @param array<int, array{min: int|null, max: int|null, label: string}> $rows Buckets validados.
	 * @return bool True sólo si el par quedó coherente y su publicación fue verificada.
	 */
	public static function save_manual( array $rows ): bool {
		if ( null !== FacetRegistry::validate_precio_buckets( $rows ) ) {
			return false;
		}

		return false !== self::transition( array_values( $rows ), self::MODE_MANUAL_IF_CHANGED, false, true );
	}

	/**
	 * Refresh SOLO si el modo parece AUTO. El chequeo de acá es el corte barato
	 * (manual evita la SELECT); el contrato que cierra la carrera vive en el SQL
	 * condicional de `refresh( true )`. Es el hook que usa RebuildJob post-swap.
	 *
	 * @return bool True si no correspondía escribir o si persistencia+purge se verificaron.
	 */
	public static function maybe_refresh_auto( bool $publish = true ): bool {
		return ! self::is_auto() || false !== self::refresh( true, $publish );
	}

	/**
	 * Los hooks pre/post de options deben omitir su outbox mientras esta clase
	 * gestiona una mutación; así activation/admin no publican dos veces.
	 *
	 * @return bool
	 */
	public static function manages_publication_context(): bool {
		return 0 < self::$publication_context;
	}

	/**
	 * Confirma la misma barrera pre-write y exige publicación física verificable.
	 *
	 * @param array{token: string, seq: int, target: string, not_before?: int} $intent      Intent pre-write.
	 * @param string                                                         $fingerprint Foto semántica post-write y pre-publicación.
	 * @return bool
	 */
	private static function complete_publication( array $intent, string $fingerprint ): bool {
		$post = Install::request_generation_publication(
			'price_buckets_postwrite',
			(string) ( $intent['target'] ?? '' ),
			0
		);
		return is_array( $post )
			&& Install::consume_generation_publications()
			&& Install::generation_publication_satisfied( $post )
			&& SemanticPublication::seal_external_fingerprint( $fingerprint, (string) $post['target'] );
	}

	/**
	 * Primitiva única para Guardar manual, Recalcular, activación y refresh de
	 * rebuild. Con publicación propia persiste PRE antes de adquirir el fence; un
	 * crash antes del COMMIT deja deuda inocua y uno posterior deja deuda real.
	 *
	 * El rebuild llama con `$publish=false` porque ya posee WriteFence y publica
	 * su target final. Los caminos administrativos usan siempre `$publish=true`.
	 *
	 * @param array<int, array{min: int|null, max: int|null, label: string}> $rows Buckets derivados.
	 * @param string                                                         $mode_policy MODE_AUTO o MODE_MANUAL_IF_CHANGED.
	 * @param bool                                                           $only_if_auto Omitir si MANUAL ganó bajo lock.
	 * @param bool                                                           $publish Gestionar PRE/POST y WriteFence propio.
	 * @return int|false Cantidad de buckets, 0 si MANUAL ganó, false en error.
	 */
	private static function transition( array $rows, string $mode_policy, bool $only_if_auto, bool $publish ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return false;
		}
		if ( ! in_array( $mode_policy, array( self::MODE_AUTO, self::MODE_MANUAL_IF_CHANGED ), true ) ) {
			return false;
		}

		if ( $publish ) {
			++self::$publication_context;
		}
		try {
			$intent = $publish
				? Install::request_generation_publication( 'price_pair_prewrite', null, self::PUBLICATION_DELAY_SECONDS )
				: null;
			if ( $publish && ! is_array( $intent ) ) {
				return false;
			}

			$fence = null;
			if ( $publish ) {
				$fence = new WriteFence( $wpdb );
				if ( ! $fence->acquire() ) {
					return false;
				}
			}

			$persisted            = false;
			$released             = true;
			$persistence_attempted = false;
			try {
				// Desde este punto un false/throw de COMMIT es ambiguo: MariaDB
				// pudo aplicar el par aunque el ACK no llegara al proceso.
				$persistence_attempted = true;
				$persisted = self::persist_pair_transaction( $rows, $mode_policy, $only_if_auto );
			} catch ( \Throwable $throwable ) {
				$persisted = false;
			} finally {
				// Direct SQL no actualiza el object cache de WordPress. Se invalida
				// incluso si ganó ROLLBACK, se perdió el ACK de COMMIT o falló la
				// sonda de release: borrar una entrada que no cambió es inocuo;
				// conservar una entrada vieja tras un COMMIT ambiguo no lo es.
				if ( $persistence_attempted ) {
					self::invalidate_pair_caches();
				}
				if ( $fence instanceof WriteFence ) {
					try {
						$released = $fence->release();
					} catch ( \Throwable $throwable ) {
						$released = false;
					}
				}
			}
			if ( false === $persisted || ! $released ) {
				return false;
			}

			if ( $publish ) {
				$fingerprint = SemanticPublication::current_fingerprint();
				if ( null === $fingerprint || ! self::complete_publication( $intent, $fingerprint ) ) {
					return false;
				}
			}

			return $persisted['applied'] ? count( $rows ) : 0;
		} finally {
			if ( $publish ) {
				--self::$publication_context;
			}
		}
	}

	/**
	 * Bloquea ambas keys en orden léxico, decide el modo sobre esa foto y aplica
	 * el par mediante UN solo UPSERT multi-row. Aunque wpdb reconectara antes del
	 * DML, nunca existe una sentencia que pueda escribir sólo una mitad.
	 *
	 * @param array<int, array{min: int|null, max: int|null, label: string}> $rows Buckets destino.
	 * @param string                                                         $mode_policy Política de modo.
	 * @param bool                                                           $only_if_auto Omitir ante MANUAL.
	 * @return array{applied: bool, changed: bool}|false
	 */
	private static function persist_pair_transaction( array $rows, string $mode_policy, bool $only_if_auto ) {
		global $wpdb;

		$transaction_open = false;
		$wpdb->last_error = '';
		if ( false === $wpdb->query( 'START TRANSACTION' ) || '' !== (string) ( $wpdb->last_error ?? '' ) ) {
			return false;
		}
		$transaction_open = true;

		try {
			$current = self::lock_pair_rows();
			if ( null === $current ) {
				return false;
			}

			$current_mode = $current[ self::OPTION_MODE ]['present']
				&& 'manual' === $current[ self::OPTION_MODE ]['raw']
					? 'manual'
					: 'auto';
			if ( $only_if_auto && 'manual' === $current_mode ) {
				if ( false === $wpdb->query( 'COMMIT' ) || '' !== (string) ( $wpdb->last_error ?? '' ) ) {
					return false;
				}
				$transaction_open = false;
				return array( 'applied' => false, 'changed' => false );
			}

			$target_mode = 'auto';
			if ( self::MODE_MANUAL_IF_CHANGED === $mode_policy ) {
				$stored = $current[ FacetRegistry::OPTION_PRECIO_BUCKETS ]['present']
					? Support\OptionCodec::decode( $current[ FacetRegistry::OPTION_PRECIO_BUCKETS ]['raw'] )
					: null;
				$target_mode = self::bounds( $rows ) !== self::bounds( $stored ) ? 'manual' : $current_mode;
			}

			$desired = array(
				FacetRegistry::OPTION_PRECIO_BUCKETS => Support\OptionCodec::encode( array_values( $rows ) ),
				self::OPTION_MODE                    => $target_mode,
			);
			ksort( $desired, SORT_STRING );

			$changed = false;
			foreach ( $desired as $option => $raw ) {
				$state = $current[ $option ];
				if ( ! $state['present'] || $raw !== $state['raw'] || 'no' !== $state['autoload'] ) {
					$changed = true;
					break;
				}
			}

			if ( $changed ) {
				$names = array_keys( $desired );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabla core y seis placeholders preparados.
				$sql = $wpdb->prepare(
					"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
					VALUES (%s, %s, %s), (%s, %s, %s)
					ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)",
					$names[0],
					$desired[ $names[0] ],
					'no',
					$names[1],
					$desired[ $names[1] ],
					'no'
				);
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- seis valores ya fueron preparados arriba.
				if ( false === $wpdb->query( $sql ) || '' !== (string) ( $wpdb->last_error ?? '' ) ) {
					return false;
				}

				$observed = self::lock_pair_rows();
				if ( null === $observed ) {
					return false;
				}
				foreach ( $desired as $option => $raw ) {
					if ( ! $observed[ $option ]['present'] || $raw !== $observed[ $option ]['raw'] || 'no' !== $observed[ $option ]['autoload'] ) {
						return false;
					}
				}
			}

			$wpdb->last_error = '';
			if ( false === $wpdb->query( 'COMMIT' ) || '' !== (string) ( $wpdb->last_error ?? '' ) ) {
				return false;
			}
			$transaction_open = false;

			return array( 'applied' => true, 'changed' => $changed );
		} finally {
			if ( $transaction_open ) {
				$wpdb->query( 'ROLLBACK' );
			}
		}
	}

	/**
	 * @return array<string, array{present: bool, raw: string, autoload: string}>|null
	 */
	private static function lock_pair_rows(): ?array {
		global $wpdb;

		$options = array( self::OPTION_MODE, FacetRegistry::OPTION_PRECIO_BUCKETS );
		sort( $options, SORT_STRING );
		$states = array();
		foreach ( $options as $option ) {
			$wpdb->last_error = '';
			// Punto único (o gap lock si falta) y orden estable entre conexiones.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1 FOR UPDATE",
					$option
				)
			);
			if ( '' !== (string) ( $wpdb->last_error ?? '' ) ) {
				return null;
			}
			$states[ $option ] = null === $row
				? array( 'present' => false, 'raw' => '', 'autoload' => '' )
				: array(
					'present'  => true,
					'raw'      => (string) ( is_object( $row ) ? ( $row->option_value ?? '' ) : ( $row['option_value'] ?? '' ) ),
					'autoload' => (string) ( is_object( $row ) ? ( $row->autoload ?? '' ) : ( $row['autoload'] ?? '' ) ),
				);
		}

		return $states;
	}

	/** @param mixed $rows Filas persistidas. @return array<int, array{0: mixed, 1: mixed}>|null */
	private static function bounds( $rows ): ?array {
		if ( ! is_array( $rows ) ) {
			return null;
		}
		$bounds = array();
		foreach ( array_values( $rows ) as $row ) {
			if ( ! is_array( $row ) ) {
				return null;
			}
			$bounds[] = array( $row['min'] ?? null, $row['max'] ?? null );
		}
		return $bounds;
	}

	/** Invalida ambas fuentes del par y los agregados del object cache. */
	private static function invalidate_pair_caches(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			foreach ( array( self::OPTION_MODE, FacetRegistry::OPTION_PRECIO_BUCKETS, 'alloptions', 'notoptions' ) as $key ) {
				try {
					wp_cache_delete( $key, 'options' );
				} catch ( \Throwable $throwable ) {
					// Un backend de cache defectuoso no debe impedir el release ni el
					// reset del memo local; las demás keys aún se intentan borrar.
				}
			}
		}
		SemanticPublication::reset_authoritative_snapshot();
		FacetRegistry::reset_cache();
	}

	/**
	 * Población de precios en `[from|-inf, to)` sobre la lista ORDENADA.
	 *
	 * @param array<int, int> $vals Precios ordenados asc.
	 * @param int|null        $from Piso inclusivo (null = sin piso).
	 * @param int             $to   Techo exclusivo.
	 * @return int
	 */
	private static function population( array $vals, ?int $from, int $to ): int {
		$count = 0;
		foreach ( $vals as $v ) {
			if ( $v >= $to ) {
				break; // ordenado: nada más que contar.
			}
			if ( null === $from || $v >= $from ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Formato de monto chileno: miles con punto, sin decimales.
	 *
	 * @param int $v Monto.
	 * @return string
	 */
	private static function fmt( int $v ): string {
		return number_format( $v, 0, ',', '.' );
	}
}
