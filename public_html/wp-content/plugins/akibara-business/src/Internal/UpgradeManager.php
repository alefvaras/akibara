<?php
/**
 * Upgrade and schema migration manager for Akibara Business.
 *
 * @package Akibara\Business\Internal
 */

declare( strict_types = 1 );

namespace Akibara\Business\Internal;

use Akibara\Business\Plugin;

/**
 * Manages the version check, compare-and-swap upgrade claims, and search feed contract reconciliations.
 *
 * @since 1.99.16
 */
final class UpgradeManager {

	/**
	 * Option key donde se persiste la version installed del plugin. Cuando difiere
	 * de `AKIBARA_BUSINESS_VERSION`, `maybe_upgrade()` dispara el flush.
	 */
	public const OPTION_VERSION_INSTALLED = 'akibara_business_version_installed';

	/**
	 * Version del contrato con que Business alimenta documentos de Search.
	 */
	public const SEARCH_FEED_CONTRACT_VERSION = '2';

	/**
	 * Baseline instalada sin rebuild durante la adopción en Business 1.95.1.
	 */
	public const SEARCH_FEED_CONTRACT_ADOPTION_VERSION = '1';

	/**
	 * Option key donde se persiste la version instalada del contrato de Search.
	 */
	public const OPTION_SEARCH_FEED_CONTRACT_VERSION = 'akibara_business_search_feed_contract_version';

	/**
	 * Marcador "tareas del upgrade pendientes" (autoload off).
	 */
	public const OPTION_UPGRADE_TASKS_PENDING = 'akibara_business_upgrade_tasks_pending';

	/** Tiempo máximo durante el que un request posee las tareas del upgrade. */
	public const UPGRADE_TASK_LEASE_TTL = 600;

	/**
	 * Reconcilia el contrato de Search y ejecuta los upgrades de Business bajo lease.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		if ( function_exists( 'wp_prime_option_caches' ) ) {
			wp_prime_option_caches(
				array(
					self::OPTION_SEARCH_FEED_CONTRACT_VERSION,
					self::OPTION_UPGRADE_TASKS_PENDING,
					self::OPTION_VERSION_INSTALLED,
				)
			);
		}

		$this->maybe_upgrade_search_feed_contract();

		$current = defined( 'AKIBARA_BUSINESS_VERSION' ) ? AKIBARA_BUSINESS_VERSION : '1.0.0';

		for ( $attempt = 0; $attempt < 4; ++$attempt ) {
			$pending = (string) get_option( self::OPTION_UPGRADE_TASKS_PENDING, '' );
			if ( '' !== $pending ) {
				if ( ! $this->maybe_resume_upgrade_tasks( $current, $pending ) ) {
					return;
				}
				continue;
			}

			$stored = (string) get_option( self::OPTION_VERSION_INSTALLED, '' );
			if ( $stored === $current ) {
				return;
			}

			$queued = $this->encode_upgrade_task_state( 'queued', $current, $stored, '', 0 );
			if ( ! $this->claim_upgrade_queue( $queued, $stored ) ) {
				continue;
			}

			$this->maybe_resume_upgrade_tasks( $current, $queued );
			return;
		}
	}

	/**
	 * Ejecuta las tareas del bump: flush de rewrites + hook público.
	 *
	 * @param string $current Nueva version (AKIBARA_BUSINESS_VERSION).
	 * @param string $stored  Version previa ('' en fresh install).
	 * @return void
	 */
	public function run_upgrade_tasks( string $current, string $stored ): void {
		flush_rewrite_rules( true );

		/**
		 * Fires después de auto-flush al detectar upgrade del plugin.
		 *
		 * @since 1.16.2
		 *
		 * @param string $current_version Nueva version (AKIBARA_BUSINESS_VERSION).
		 * @param string $previous_version Version stored (puede ser '' en fresh install).
		 */
		do_action( 'akibara_business_upgraded', $current, $stored );
	}

	/**
	 * Reanuda una tarea publicada que quedó sin terminar antes o después del
	 * sello de versión (proceso muerto o Throwable de un listener).
	 *
	 * @param string      $current       Version actual (AKIBARA_BUSINESS_VERSION).
	 * @param string|null $known_pending Snapshot ya leído por el caller.
	 * @return bool True cuando el marcador quedó consumido y se puede continuar.
	 */
	public function maybe_resume_upgrade_tasks( string $current, ?string $known_pending = null ): bool {
		$pending = null === $known_pending
			? (string) get_option( self::OPTION_UPGRADE_TASKS_PENDING, '' )
			: $known_pending;
		if ( '' === $pending ) {
			return true;
		}

		$state = $this->decode_upgrade_task_state( $pending );
		if ( null === $state ) {
			return $this->claim_version_option( self::OPTION_UPGRADE_TASKS_PENDING, '', $pending );
		}

		if ( version_compare( $state['current'], $current, '>' ) ) {
			return false;
		}

		$now = time();
		if ( 'running' === $state['state'] && $state['expires_at'] > $now ) {
			return false;
		}

		if ( ! $this->upgrade_state_matches_installed_version( $state ) ) {
			return false;
		}

		$queued  = $this->encode_upgrade_task_state( 'queued', $state['current'], $state['previous'], '', 0 );
		$token   = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : hash( 'sha256', uniqid( '', true ) );
		$running = $this->encode_upgrade_task_state(
			'running',
			$state['current'],
			$state['previous'],
			$token,
			$now + self::UPGRADE_TASK_LEASE_TTL
		);
		if ( ! $this->claim_version_option( self::OPTION_UPGRADE_TASKS_PENDING, $running, $pending ) ) {
			return false;
		}

		if ( ! $this->seal_upgrade_version( $state ) ) {
			$this->claim_version_option( self::OPTION_UPGRADE_TASKS_PENDING, $queued, $running );
			return false;
		}
		if ( (string) get_option( self::OPTION_UPGRADE_TASKS_PENDING, '' ) !== $running ) {
			return false;
		}

		try {
			$this->run_upgrade_tasks( $state['current'], $state['previous'] );
		} catch ( UpgradeRetryRequired $error ) {
			unset( $error );
			$this->claim_version_option( self::OPTION_UPGRADE_TASKS_PENDING, $queued, $running );
			return false;
		}

		return $this->claim_version_option( self::OPTION_UPGRADE_TASKS_PENDING, '', $running );
	}

	/**
	 * Comprueba que la tarea puede sellar o ya selló su transición de versión.
	 *
	 * @param array{state:string,current:string,previous:string,token:string,expires_at:int} $state Estado decodificado.
	 * @return bool
	 */
	public function upgrade_state_matches_installed_version( array $state ): bool {
		$installed = (string) get_option( self::OPTION_VERSION_INSTALLED, '' );

		return $installed === $state['previous']
			|| $installed === $state['current']
			|| ( '' !== $installed && version_compare( $installed, $state['current'], '>' ) );
	}

	/**
	 * Sella la versión bajo el lease o confirma que otro owner ya la adelantó.
	 *
	 * @param array{state:string,current:string,previous:string,token:string,expires_at:int} $state Estado reclamado.
	 * @return bool
	 */
	public function seal_upgrade_version( array $state ): bool {
		$installed = (string) get_option( self::OPTION_VERSION_INSTALLED, '' );
		if (
			$installed === $state['current']
			|| ( '' !== $installed && version_compare( $installed, $state['current'], '>' ) )
		) {
			return true;
		}

		if ( $installed !== $state['previous'] ) {
			return false;
		}

		if ( $this->claim_upgrade( $state['current'], $state['previous'] ) ) {
			return true;
		}

		$installed = (string) get_option( self::OPTION_VERSION_INSTALLED, '' );

		return $installed === $state['current']
			|| ( '' !== $installed && version_compare( $installed, $state['current'], '>' ) );
	}

	/**
	 * Serializa el estado canónico del lease de tareas.
	 */
	public function encode_upgrade_task_state(
		string $state,
		string $current,
		string $previous,
		string $token,
		int $expires_at
	): string {
		$encoded = wp_json_encode(
			array(
				'state'      => $state,
				'current'    => $current,
				'previous'   => $previous,
				'token'      => $token,
				'expires_at' => $expires_at,
			)
		);

		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Lee JSON v2 y degrada el marcador legacy `current|previous` a queued.
	 *
	 * @param string $raw Cadena sin procesar.
	 * @return array{state:string,current:string,previous:string,token:string,expires_at:int}|null
	 */
	public function decode_upgrade_task_state( string $raw ): ?array {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			$state      = (string) ( $decoded['state'] ?? '' );
			$current    = (string) ( $decoded['current'] ?? '' );
			$previous   = (string) ( $decoded['previous'] ?? '' );
			$token      = (string) ( $decoded['token'] ?? '' );
			$expires_at = max( 0, (int) ( $decoded['expires_at'] ?? 0 ) );
			if (
				'' === $current
				|| ! self::is_upgrade_version( $current )
				|| ( '' !== $previous && ! self::is_upgrade_version( $previous ) )
				|| ! in_array( $state, array( 'queued', 'running' ), true )
				|| ( 'running' === $state && ( '' === $token || 0 === $expires_at ) )
			) {
				return null;
			}

			return array(
				'state'      => $state,
				'current'    => $current,
				'previous'   => $previous,
				'token'      => $token,
				'expires_at' => $expires_at,
			);
		}

		if ( 1 !== substr_count( $raw, '|' ) ) {
			return null;
		}

		$legacy = explode( '|', $raw, 2 );
		if (
			! self::is_upgrade_version( $legacy[0] )
			|| ( '' !== $legacy[1] && ! self::is_upgrade_version( $legacy[1] ) )
		) {
			return null;
		}

		return array(
			'state'      => 'queued',
			'current'    => (string) $legacy[0],
			'previous'   => (string) $legacy[1],
			'token'      => '',
			'expires_at' => 0,
		);
	}

	/**
	 * Indica si un identificador de versión es apto para ejecutar el hook.
	 *
	 * @param string $version Identificador a validar.
	 * @return bool
	 */
	public static function is_upgrade_version( string $version ): bool {
		return 1 === preg_match( '/^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/D', $version );
	}

	/**
	 * Publica `queued` sólo si no hay otra tarea y la versión sigue sin sellar.
	 *
	 * @param string $queued Estado queued serializado.
	 * @param string $stored Versión instalada esperada.
	 * @return bool True si este request publicó la cola.
	 */
	public function claim_upgrade_queue( string $queued, string $stored ): bool {
		global $wpdb;

		$claimed = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} AS pending
				LEFT JOIN {$wpdb->options} AS installed ON installed.option_name = %s
				SET pending.option_value = %s
				WHERE pending.option_name = %s
					AND pending.option_value = ''
					AND ( installed.option_value = %s OR ( %s = '' AND installed.option_name IS NULL ) )",
				self::OPTION_VERSION_INSTALLED,
				$queued,
				self::OPTION_UPGRADE_TASKS_PENDING,
				$stored,
				$stored
			)
		);

		if ( 0 === $claimed ) {
			$claimed = (int) $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload )
					SELECT %s, %s, 'off'
					FROM ( SELECT 1 ) AS seed
					LEFT JOIN {$wpdb->options} AS installed ON installed.option_name = %s
					WHERE installed.option_value = %s OR ( %s = '' AND installed.option_name IS NULL )",
					self::OPTION_UPGRADE_TASKS_PENDING,
					$queued,
					self::OPTION_VERSION_INSTALLED,
					$stored,
					$stored
				)
			);
		}

		if ( $claimed > 0 ) {
			$this->purge_option_cache( self::OPTION_UPGRADE_TASKS_PENDING );
		}

		return $claimed > 0;
	}

	/**
	 * Claim atómico del upgrade: sella `version_installed` $stored → $current.
	 *
	 * @param string $current Version nueva (AKIBARA_BUSINESS_VERSION).
	 * @param string $stored  Version leída de la option ('' en fresh install).
	 * @return bool True si este request ganó el claim.
	 */
	public function claim_upgrade( string $current, string $stored ): bool {
		return $this->claim_version_option(
			self::OPTION_VERSION_INSTALLED,
			$current,
			$stored
		);
	}

	/**
	 * Sella una transición de versión con CAS sobre una option.
	 *
	 * @param string $option_name Option versionada.
	 * @param string $current     Versión nueva.
	 * @param string $stored      Versión leída.
	 * @return bool True si este request ganó el claim.
	 */
	public function claim_version_option( string $option_name, string $current, string $stored ): bool {
		global $wpdb;

		$claimed = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$current,
				$option_name,
				$stored
			)
		);

		if ( 0 === $claimed && '' === $stored ) {
			$claimed = (int) $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
					$option_name,
					$current
				)
			);
		}

		if ( $claimed > 0 ) {
			$this->purge_option_cache( $option_name );
		}

		return $claimed > 0;
	}

	/**
	 * Purgar caches después de un write directo sobre wp_options.
	 *
	 * @param string $option_name Nombre de la opción a purgar.
	 * @return void
	 */
	public function purge_option_cache( string $option_name ): void {
		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Reconcilia la versión del contrato Business → Search.
	 *
	 * @return void
	 */
	public function maybe_upgrade_search_feed_contract(): void {
		$current = self::SEARCH_FEED_CONTRACT_VERSION;
		$stored  = get_option( self::OPTION_SEARCH_FEED_CONTRACT_VERSION, null );

		if ( null === $stored ) {
			if (
				! $this->claim_version_option(
					self::OPTION_SEARCH_FEED_CONTRACT_VERSION,
					self::SEARCH_FEED_CONTRACT_ADOPTION_VERSION,
					''
				)
			) {
				return;
			}

			$stored = self::SEARCH_FEED_CONTRACT_ADOPTION_VERSION;
		}

		$stored = (string) $stored;
		if ( $stored === $current ) {
			return;
		}

		if ( $this->claim_version_option( self::OPTION_SEARCH_FEED_CONTRACT_VERSION, $current, $stored ) ) {
			$this->schedule_search_reindex();
		}
	}

	/**
	 * Encola el rebuild completo cuando cambia el contrato de alimentación.
	 *
	 * @return void
	 */
	public function schedule_search_reindex(): void {
		if ( class_exists( '\Akibara\Search\RebuildJob' ) ) {
			\Akibara\Search\RebuildJob::schedule( true );
		}
	}
}
