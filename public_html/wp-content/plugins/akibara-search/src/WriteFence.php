<?php
/**
 * WriteFence — cerrojo MariaDB por conexión para publicar el índice.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Serializa writers live, transiciones y swap sobre una sola conexión física.
 *
 * @since 1.55.0
 */
final class WriteFence {

	/** @var object Instancia wpdb del caller. */
	private object $wpdb;

	/** Nombre MariaDB estable (máximo 64 caracteres). */
	private string $lock_name;

	/** Dueño CONNECTION_ID observado después de GET_LOCK. */
	private ?int $owner_connection_id = null;

	/** Evita adquisición anidada o reentrante en esta instancia. */
	private bool $acquired = false;

	/**
	 * @param object      $wpdb      Conexión wpdb que ejecutará el trabajo cercado.
	 * @param string|null $lock_name Nombre explícito para pruebas/integraciones.
	 */
	public function __construct( object $wpdb, ?string $lock_name = null ) {
		$this->wpdb      = $wpdb;
		$this->lock_name = $this->normalize_lock_name( $lock_name );
	}

	/**
	 * Devuelve el nombre estable usado por MariaDB.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->lock_name;
	}

	/**
	 * Adquiere el fence una sola vez y fija su CONNECTION_ID.
	 *
	 * @param int $timeout_seconds Espera máxima de MariaDB.
	 * @return bool True sólo para GET_LOCK=1 y dueño verificable.
	 */
	public function acquire( int $timeout_seconds = 5 ): bool {
		if ( $this->acquired || 0 > $timeout_seconds ) {
			return false;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- la consulta es un literal con marcadores que $this->wpdb->prepare() rellena aquí mismo; el sniff sólo reconoce la variable $wpdb y aquí la conexión es el alias inyectado $this->wpdb.
		$get_lock = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT GET_LOCK(%s, %d)',
				$this->lock_name,
				$timeout_seconds
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		if ( 1 !== $this->integer_result( $get_lock ) ) {
			return false;
		}

		$owner = $this->connection_id();
		if ( null === $owner || $owner !== $this->used_lock_owner() ) {
			$this->release_unverified_lock();
			return false;
		}

		$this->owner_connection_id = $owner;
		$this->acquired            = true;

		return true;
	}

	/**
	 * Comprueba conexión física y dueño real del lock.
	 *
	 * @return bool
	 */
	public function is_owned(): bool {
		if ( ! $this->acquired || null === $this->owner_connection_id ) {
			return false;
		}

		return $this->owner_connection_id === $this->connection_id()
			&& $this->owner_connection_id === $this->used_lock_owner();
	}

	/**
	 * Exige que el caller conserve el mismo lock en la misma conexión.
	 *
	 * @throws \RuntimeException Cuando el fence se perdió o cambió de conexión.
	 * @return void
	 */
	public function assert_owned(): void {
		if ( ! $this->is_owned() ) {
			throw new \RuntimeException( 'Akibara Search write fence is not owned by this connection.' );
		}
	}

	/**
	 * Libera una sola vez, sin liberar nunca el lock de otra conexión.
	 *
	 * @return bool True sólo si RELEASE_LOCK devolvió exactamente 1.
	 */
	public function release(): bool {
		if ( ! $this->acquired || null === $this->owner_connection_id ) {
			return false;
		}

		$owner   = $this->owner_connection_id;
		$current = $this->connection_id();
		if ( null === $current ) {
			// Una sonda fallida no demuestra que el lock haya desaparecido. El
			// estado local se conserva para permitir un release posterior.
			return false;
		}
		if ( $owner !== $current ) {
			$this->forget_ownership();
			return false;
		}

		$used_by = $this->used_lock_owner();
		if ( null === $used_by ) {
			if ( ! $this->has_database_error() ) {
				// IS_USED_LOCK=NULL sin error confirma que el nombre ya no tiene
				// dueño; no queda una adquisición local que reintentar.
				$this->forget_ownership();
			}
			return false;
		}
		if ( $owner !== $used_by ) {
			$this->forget_ownership();
			return false;
		}

		$released = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- la consulta es un literal con marcadores que $this->wpdb->prepare() rellena aquí mismo; el sniff sólo reconoce la variable $wpdb y aquí la conexión es el alias inyectado $this->wpdb.
		);
		if ( 1 !== $this->integer_result( $released ) ) {
			// RELEASE_LOCK ambiguo/fallido mantiene el ownership local: así no
			// puede ocurrir una adquisición recursiva y el caller puede reintentar.
			return false;
		}

		$this->forget_ownership();

		return true;
	}

	/**
	 * Olvida ownership sólo después de liberarlo o comprobar que ya no es local.
	 *
	 * @return void
	 */
	private function forget_ownership(): void {
		$this->acquired            = false;
		$this->owner_connection_id = null;
	}

	/**
	 * Indica si la última sonda dejó un error SQL observable en wpdb.
	 *
	 * @return bool
	 */
	private function has_database_error(): bool {
		$properties = get_object_vars( $this->wpdb );

		return '' !== trim( (string) ( $properties['last_error'] ?? '' ) );
	}

	/**
	 * Crea un nombre portable entre procesos del mismo sitio.
	 *
	 * @param string|null $requested Nombre solicitado.
	 * @return string
	 */
	private function normalize_lock_name( ?string $requested ): string {
		if ( null !== $requested && '' !== trim( $requested ) ) {
			$requested = trim( $requested );
			return 64 >= strlen( $requested )
				? $requested
				: 'akb-search:' . substr( hash( 'sha256', $requested ), 0, 53 );
		}

		$database          = defined( 'DB_NAME' ) ? (string) constant( 'DB_NAME' ) : '';
		$public_properties = get_object_vars( $this->wpdb );
		$prefix            = (string) ( $public_properties['prefix'] ?? '' );

		return 'akb-search:' . substr( hash( 'sha256', $database . '|' . $prefix ), 0, 53 );
	}

	/**
	 * CONNECTION_ID actual o null si no es un entero positivo.
	 *
	 * @return int|null
	 */
	private function connection_id(): ?int {
		$connection_id = $this->integer_result( $this->wpdb->get_var( 'SELECT CONNECTION_ID()' ) );

		return null !== $connection_id && 0 < $connection_id ? $connection_id : null;
	}

	/**
	 * Dueño real según IS_USED_LOCK o null si no existe/no es válido.
	 *
	 * @return int|null
	 */
	private function used_lock_owner(): ?int {
		$owner = $this->integer_result(
			$this->wpdb->get_var(
				$this->wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $this->lock_name ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- la consulta es un literal con marcadores que $this->wpdb->prepare() rellena aquí mismo; el sniff sólo reconoce la variable $wpdb y aquí la conexión es el alias inyectado $this->wpdb.
			)
		);

		return null !== $owner && 0 < $owner ? $owner : null;
	}

	/**
	 * Intenta limpiar el GET_LOCK cuando no fue posible comprobar su dueño.
	 *
	 * @return void
	 */
	private function release_unverified_lock(): void {
		$this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- la consulta es un literal con marcadores que $this->wpdb->prepare() rellena aquí mismo; el sniff sólo reconoce la variable $wpdb y aquí la conexión es el alias inyectado $this->wpdb.
		);
	}

	/**
	 * Normaliza sólo respuestas SQL enteras; null/false/texto quedan inválidos.
	 *
	 * @param mixed $value Respuesta de wpdb.
	 * @return int|null
	 */
	private function integer_result( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^-?[0-9]+$/D', $value ) ) {
			return (int) $value;
		}

		return null;
	}
}
