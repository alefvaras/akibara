<?php
/**
 * Registro de mensajes de contacto conservados antes de intentar correo.
 * Cada mensaje ocupa una option akb_contacto_log_{uuid} sin autoload, creada
 * con OptionRow::insert_if_absent e INSERT IGNORE sobre clave única.
 * Los escritores concurrentes no comparten una lista serializada ni pueden
 * reemplazar otra entrada. La agregación ocurre al leer y mark_mailed sólo
 * actualiza la fila del mensaje correspondiente.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Forms;

use Akibara\Business\Utilities\OptionRow;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.108.19
 */
final class ContactoLog {

	/** Prefijo de la fila de cada mensaje. El sufijo es el id de la entrada. */
	public const OPTION_PREFIX = 'akb_contacto_log_';

	/**
	 * La bitácora ANTERIOR: una sola option con todas las entradas dentro.
	 * Se reparte en filas la primera vez que alguien lee y desaparece; sin esa
	 * migración, actualizar el plugin descartaría en silencio los mensajes que
	 * el dueño todavía no leyó.
	 */
	private const LEGACY_OPTION = 'akb_contacto_log';

	/**
	 * Tope de mensajes conservados.
	 *
	 * La bitácora existe para que nada se pierda entre que llega y el dueño lo
	 * lee, no para ser un archivo histórico. Cien entradas cubren de sobra
	 * cualquier racha de fallos de correo.
	 */
	private const MAX_ENTRIES = 100;

	/** Largo máximo guardado del cuerpo; el correo lleva el texto completo. */
	private const MAX_MESSAGE_CHARS = 4000;

	/**
	 * Registra un mensaje y devuelve su identificador.
	 *
	 * @return string Identificador para marcarlo como enviado, '' si no se pudo guardar.
	 */
	public static function record( string $nombre, string $email, string $mensaje ): string {
		$id = self::next_id();

		$entry = array(
			'id'      => $id,
			'time'    => time(),
			'nombre'  => $nombre,
			'email'   => $email,
			'mensaje' => self::truncate( $mensaje ),
			'mailed'  => false,
		);

		// El registro no se carga en alloptions. INSERT IGNORE conserva cualquier
		// mensaje que ya ocupe el ID, incluso con object cache desfasado; no se usa
		// el upsert de add_option para insertar una entrada inmutable.
		if ( ! OptionRow::insert_if_absent( self::OPTION_PREFIX . $id, $entry ) ) {
			return '';
		}

		self::prune();

		return $id;
	}

	/**
	 * Marca una entrada como entregada por correo.
	 *
	 * Escribe SOLO la fila de ese mensaje, que nadie más toca: aunque entren
	 * cinco formularios a la vez, ninguna marca puede pisar a otra.
	 */
	public static function mark_mailed( string $id ): void {
		if ( '' === $id ) {
			return;
		}

		$name  = self::OPTION_PREFIX . $id;
		$entry = get_option( $name, false );
		if ( ! is_array( $entry ) ) {
			return;
		}

		$entry['mailed'] = true;
		update_option( $name, $entry, false );
	}

	/**
	 * Mensajes que nunca salieron por correo, del más reciente al más antiguo.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function undelivered(): array {
		$pending = array_values(
			array_filter( self::all(), static fn( array $entry ): bool => true !== ( $entry['mailed'] ?? false ) )
		);

		return array_reverse( $pending );
	}

	/**
	 * Descarta las entradas no entregadas (el dueño ya las leyó).
	 */
	public static function forget_undelivered(): void {
		foreach ( self::all() as $entry ) {
			if ( true === ( $entry['mailed'] ?? false ) ) {
				continue;
			}
			self::forget( (string) ( $entry['id'] ?? '' ) );
		}
	}

	/**
	 * Lista los mensajes de un correo por antigüedad. Es la fuente común del
	 * exportador y borrador de privacidad; ContactoLog gobierna esa comparación.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function for_email( string $email ): array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return array();
		}

		return array_values(
			array_filter(
				self::all(),
				static fn( array $entry ): bool => strtolower( trim( (string) ( $entry['email'] ?? '' ) ) ) === $email
			)
		);
	}

	/**
	 * Elimina físicamente los mensajes asociados al correo solicitado;
	 * no conserva mensajes de contacto sin titular.
	 *
	 * @return int Cuántas entradas borró.
	 */
	public static function erase_email( string $email ): int {
		$borradas = 0;
		foreach ( self::for_email( $email ) as $entry ) {
			self::forget( (string) ( $entry['id'] ?? '' ) );
			++$borradas;
		}

		return $borradas;
	}

	/**
	 * Todas las entradas, de la más antigua a la más reciente.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function all(): array {
		self::migrate_legacy();

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- la agregación es el precio de que cada mensaje tenga su fila; sólo corre en el endpoint de contacto y en el panel.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%'
			)
		);

		$entries = array();
		foreach ( (array) $rows as $raw ) {
			$entry = maybe_unserialize( $raw );
			if ( is_array( $entry ) && '' !== (string) ( $entry['id'] ?? '' ) ) {
				$entries[] = $entry;
			}
		}

		// El orden de `wp_options` no es el de llegada: se ordena por marca de
		// tiempo, con el id como desempate para que dos mensajes del mismo
		// segundo no bailen entre lecturas.
		usort(
			$entries,
			static function ( array $a, array $b ): int {
				$por_tiempo = (int) ( $a['time'] ?? 0 ) <=> (int) ( $b['time'] ?? 0 );

				return 0 !== $por_tiempo
					? $por_tiempo
					: strcmp( (string) ( $a['id'] ?? '' ), (string) ( $b['id'] ?? '' ) );
			}
		);

		return $entries;
	}

	/**
	 * Borra la fila de una entrada.
	 */
	private static function forget( string $id ): void {
		if ( '' === $id ) {
			return;
		}

		delete_option( self::OPTION_PREFIX . $id );
	}

	/**
	 * Acota a MAX_ENTRIES eliminando primero los mensajes entregados más antiguos.
	 * Sólo si no bastan elimina pendientes por antigüedad, preservando tantos
	 * mensajes sin entregar como permita el límite.
	 */
	private static function prune(): void {
		$entries = self::all();
		$sobran  = count( $entries ) - self::MAX_ENTRIES;
		if ( $sobran <= 0 ) {
			return;
		}

		$entregadas   = array();
		$sin_entregar = array();
		foreach ( $entries as $entry ) {
			if ( true === ( $entry['mailed'] ?? false ) ) {
				$entregadas[] = $entry;
			} else {
				$sin_entregar[] = $entry;
			}
		}

		// Cada grupo ya viene de más antiguo a más reciente (orden de `all()`);
		// concatenar entregadas + sin_entregar y cortar por `$sobran` prioriza
		// las entregadas SIN reordenarlas entre sí.
		foreach ( array_slice( array_merge( $entregadas, $sin_entregar ), 0, $sobran ) as $entry ) {
			self::forget( (string) ( $entry['id'] ?? '' ) );
		}
	}

	/**
	 * Reparte la bitácora vieja —una option con todas las entradas dentro— en
	 * una fila por mensaje, y la retira. Corre una sola vez: después de borrar
	 * la option, `get_option()` responde desde `notoptions` sin tocar la base.
	 */
	private static function migrate_legacy(): void {
		$raw = get_option( self::LEGACY_OPTION, false );
		if ( ! is_array( $raw ) ) {
			return;
		}

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id = (string) ( $entry['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			OptionRow::insert_if_absent( self::OPTION_PREFIX . $id, $entry );
		}

		delete_option( self::LEGACY_OPTION );
	}

	private static function truncate( string $value ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, self::MAX_MESSAGE_CHARS );
		}

		return substr( $value, 0, self::MAX_MESSAGE_CHARS );
	}

	private static function next_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return (string) wp_generate_uuid4();
		}

		return uniqid( 'akb', true );
	}
}
