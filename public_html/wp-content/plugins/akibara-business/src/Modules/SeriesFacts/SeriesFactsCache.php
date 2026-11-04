<?php
/**
 * Gestión de claves, snapshots generacionales e invalidación de caché para hechos de serie.
 *
 * Mantiene la consistencia de caché frente a invalidaciones concurrentes utilizando
 * un modelo generacional (epoch) durable almacenado en options de WordPress,
 * combinando una generación global con generaciones por serie.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFacts;

defined( 'ABSPATH' ) || exit;

/**
 * Gestor de caché y generaciones de SeriesFacts.
 *
 * @since 1.120.4
 */
final class SeriesFactsCache {

	/**
	 * Prefijo versionado del transient; el sufijo identifica term_taxonomy_id.
	 */
	public const TRANSIENT_PREFIX = 'akb_series_facts_v5_';

	/** Generación global excepcional para invalidar facts sin conocer sus TTID. */
	public const CACHE_GENERATION_OPTION = 'akb_series_facts_generation';

	/** Prefijo de la generación durable y no autoload de cada serie. */
	public const SERIES_CACHE_GENERATION_OPTION_PREFIX = 'akb_series_facts_generation_';

	/** Generación leída o rotada en este request. */
	private ?string $cache_generation = null;

	/** @var array<int,string> Generación por term_taxonomy_id leída en el request. */
	private array $series_cache_generations = array();

	/**
	 * Clave de transient para una serie bajo la generación actualmente observada.
	 *
	 * @param int $term_taxonomy_id Término de la serie.
	 * @return string Clave de transient completa.
	 */
	public function cache_key( int $term_taxonomy_id ): string {
		$generation = $this->cache_generation_snapshot( $term_taxonomy_id );

		return $this->cache_key_for_generation(
			$term_taxonomy_id,
			null === $generation ? $this->request_local_cache_generation( $term_taxonomy_id ) : $generation
		);
	}

	/**
	 * Generación global + local, o null si la lectura falló.
	 *
	 * @param int  $term_taxonomy_id Término de la serie.
	 * @param bool $refresh          Si debe descartar el memo privado del request.
	 * @return string|null
	 */
	public function cache_generation_snapshot( int $term_taxonomy_id, bool $refresh = false ): ?string {
		if ( $refresh ) {
			$this->cache_generation = null;
			unset( $this->series_cache_generations[ $term_taxonomy_id ] );
		}

		if ( null === $this->cache_generation || ! array_key_exists( $term_taxonomy_id, $this->series_cache_generations ) ) {
			$values = $this->read_cache_generation_options( $term_taxonomy_id );
			if ( null === $values ) {
				return null;
			}
			$this->cache_generation                              = $values['global'];
			$this->series_cache_generations[ $term_taxonomy_id ] = $values['series'];
		}

		return $this->request_local_cache_generation( $term_taxonomy_id );
	}

	/**
	 * Lee ambas generaciones en un solo snapshot autoritativo.
	 *
	 * @param int $term_taxonomy_id Término de la serie.
	 * @return array{global:string,series:string}|null
	 */
	public function read_cache_generation_options( int $term_taxonomy_id ): ?array {
		$series_option = self::SERIES_CACHE_GENERATION_OPTION_PREFIX . $term_taxonomy_id;
		global $wpdb;

		if (
			isset( $wpdb )
			&& is_object( $wpdb )
			&& isset( $wpdb->options )
			&& method_exists( $wpdb, 'get_results' )
			&& method_exists( $wpdb, 'prepare' )
		) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- snapshot de epoch que deliberadamente salta el object cache local.
				$wpdb->prepare(
					"SELECT option_name, option_value
					FROM {$wpdb->options}
					WHERE option_name IN (%s, %s)",
					self::CACHE_GENERATION_OPTION,
					$series_option
				),
				ARRAY_A
			);
			if (
				! is_array( $rows )
				|| ( isset( $wpdb->ready ) && ! (bool) $wpdb->ready )
				|| ( isset( $wpdb->last_error ) && '' !== trim( (string) $wpdb->last_error ) )
			) {
				return null;
			}

			$values = array();
			foreach ( $rows as $row ) {
				if ( is_array( $row ) && isset( $row['option_name'] ) ) {
					$values[ (string) $row['option_name'] ] = $row['option_value'] ?? '';
				}
			}

			return array(
				'global' => self::normalize_cache_generation( $values[ self::CACHE_GENERATION_OPTION ] ?? '' ),
				'series' => self::normalize_cache_generation( $values[ $series_option ] ?? '' ),
			);
		}

		// Rama de respaldo: aquí sí se lee por get_option(), así que el object
		// cache tiene que quedar fuera del camino o devolvería una foto vieja.
		$this->evict_generation_option_read_cache( self::CACHE_GENERATION_OPTION );
		$this->evict_generation_option_read_cache( $series_option );

		$queries_before = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : null;
		$global         = get_option( self::CACHE_GENERATION_OPTION, '' );
		$series         = get_option( $series_option, '' );
		$query_ran      = null === $queries_before
			|| ! isset( $wpdb->num_queries )
			|| (int) $wpdb->num_queries > $queries_before;
		if (
			( isset( $wpdb->ready ) && ! (bool) $wpdb->ready )
			|| ( $query_ran && isset( $wpdb->last_error ) && '' !== trim( (string) $wpdb->last_error ) )
		) {
			return null;
		}

		return array(
			'global' => self::normalize_cache_generation( $global ),
			'series' => self::normalize_cache_generation( $series ),
		);
	}

	/**
	 * Generación ya observada en este objeto, sin tocar almacenamiento.
	 *
	 * @param int $term_taxonomy_id Término de la serie.
	 * @return string Generación combinada.
	 */
	public function request_local_cache_generation( int $term_taxonomy_id ): string {
		return trim(
			( $this->cache_generation ?? '' ) . '_' . ( $this->series_cache_generations[ $term_taxonomy_id ] ?? '' ),
			'_'
		);
	}

	/**
	 * Fuerza una lectura durable en el mismo proceso que está renderizando.
	 *
	 * @param string $option_name Opción a expulsar del cache local.
	 * @return void
	 */
	public function evict_generation_option_read_cache( string $option_name ): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Construye una clave desde una generación ya capturada.
	 *
	 * @param int    $term_taxonomy_id Término de la serie.
	 * @param string $generation       Generación de la clave.
	 * @return string Clave de transient.
	 */
	public function cache_key_for_generation( int $term_taxonomy_id, string $generation ): string {
		$suffix = '' === $generation ? '' : $generation . '_';
		return self::TRANSIENT_PREFIX . $suffix . $term_taxonomy_id;
	}

	/**
	 * Rota la generación durable de un único term_taxonomy_id.
	 *
	 * @param int $term_taxonomy_id Término de la serie.
	 * @return bool True si se persistió exitosamente.
	 */
	public function rotate_series_cache_generation( int $term_taxonomy_id ): bool {
		if ( $term_taxonomy_id < 1 ) {
			return false;
		}

		$option_name = self::SERIES_CACHE_GENERATION_OPTION_PREFIX . $term_taxonomy_id;
		$generation  = $this->persist_cache_generation( $option_name, $this->next_cache_generation() );
		if ( null === $generation ) {
			return false;
		}

		$this->series_cache_generations[ $term_taxonomy_id ] = $generation;
		return true;
	}

	/**
	 * Rota la generación excepcional que vuelve inalcanzable TODO el conjunto.
	 *
	 * @return bool True si se persistió exitosamente.
	 */
	public function rotate_global_cache_generation(): bool {
		$generation = $this->persist_cache_generation( self::CACHE_GENERATION_OPTION, $this->next_cache_generation() );
		if ( null === $generation ) {
			return false;
		}

		$this->cache_generation = $generation;
		return true;
	}

	/**
	 * Token opaco para una nueva generación.
	 *
	 * @return string Token normalizado.
	 */
	public function next_cache_generation(): string {
		$token = function_exists( 'wp_generate_uuid4' )
			? (string) wp_generate_uuid4()
			: str_replace( '.', '-', uniqid( 'facts-', true ) );

		return self::normalize_cache_generation( $token );
	}

	/**
	 * Asigna manualmente la generación global en memoria (para fallbacks de infraestructura).
	 *
	 * @param string|null $generation Token de generación.
	 * @return void
	 */
	public function set_local_global_generation( ?string $generation ): void {
		$this->cache_generation = $generation;
	}

	/**
	 * Persiste y verifica una generación, incluso si update_option() falla.
	 *
	 * @param string $option_name Nombre de la opción.
	 * @param string $token       Token a persistir.
	 * @return string|null Token final verificado o null en falla total.
	 */
	public function persist_cache_generation( string $option_name, string $token ): ?string {
		if ( '' === $token ) {
			return null;
		}

		if ( update_option( $option_name, $token, false ) ) {
			return $token;
		}

		$before = self::normalize_cache_generation( get_option( $option_name, '' ) );
		if ( hash_equals( $token, $before ) ) {
			return $token;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) ) {
			return null;
		}

		$result = false;
		if ( method_exists( $wpdb, 'update' ) ) {
			$result = $wpdb->update(
				(string) $wpdb->options,
				array(
					'option_value' => $token,
					'autoload'     => 'no',
				),
				array( 'option_name' => $option_name ),
				array( '%s', '%s' ),
				array( '%s' )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- recuperación durable tras fallo de la API de opciones.
		}
		if ( 1 !== $result && method_exists( $wpdb, 'insert' ) ) {
			$result = $wpdb->insert(
				(string) $wpdb->options,
				array(
					'option_name'  => $option_name,
					'option_value' => $token,
					'autoload'     => 'no',
				),
				array( '%s', '%s', '%s' )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- el índice único de option_name arbitra la carrera de alta.
		}

		$this->invalidate_generation_option_cache( $option_name );
		$after = self::normalize_cache_generation( get_option( $option_name, '' ) );
		if ( '' !== $after && ( hash_equals( $token, $after ) || ! hash_equals( $before, $after ) ) ) {
			return $after;
		}

		return 1 === $result ? $token : null;
	}

	/**
	 * Invalida las entradas de cache que pueden ocultar una escritura directa.
	 *
	 * @param string $option_name Opción a invalidar.
	 * @return void
	 */
	public function invalidate_generation_option_cache( string $option_name ): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Normaliza datos externos antes de incorporarlos a una clave de cache.
	 *
	 * @param mixed $value Valor a normalizar.
	 * @return string Valor alfanumérico seguro.
	 */
	public static function normalize_cache_generation( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		return substr( (string) preg_replace( '/[^A-Za-z0-9-]/', '', $value ), 0, 64 );
	}

	/**
	 * Convierte los pares SQL term_taxonomy_id:term_id en una lista segura.
	 *
	 * @param mixed $raw_ids Resultado de wpdb::get_col().
	 * @return list<array{term_taxonomy_id:int,term_id:int}>
	 */
	public static function normalize_series_identifiers( $raw_ids ): array {
		$series = array();
		foreach ( is_array( $raw_ids ) ? $raw_ids : array() as $raw ) {
			$parts            = explode( ':', (string) $raw, 2 );
			$term_taxonomy_id = (int) ( $parts[0] ?? 0 );
			$term_id          = (int) ( $parts[1] ?? 0 );
			if ( $term_taxonomy_id > 0 ) {
				$series[] = array(
					'term_taxonomy_id' => $term_taxonomy_id,
					'term_id'          => max( 0, $term_id ),
				);
			}
		}

		return $series;
	}
}
