<?php
/**
 * Integra ContactoLog en las herramientas de privacidad de WordPress.
 * El exportador devuelve una entrada por mensaje y el borrador elimina
 * físicamente los mensajes del correo solicitado. El formulario admite
 * invitados: se busca por email, sin exigir cuenta ni user_id.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.108.20
 */
final class PrivacyHandler {

	private const GROUP_ID = 'akb-contacto';

	/**
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * @param array<string, mixed> $exporters Exporters registrados.
	 * @return array<string, mixed>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['akibara-contacto'] = array(
			'exporter_friendly_name' => 'Mensajes de contacto Akibara',
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array<string, mixed> $erasers Erasers registrados.
	 * @return array<string, mixed>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['akibara-contacto'] = array(
			'eraser_friendly_name' => 'Mensajes de contacto Akibara',
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exporter de WP: una entrada por mensaje de contacto del email.
	 *
	 * @param string $email Email del titular.
	 * @param int    $page  Página (la bitácora es chica: una sola pasada).
	 * @return array{data: array<int, mixed>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$data = array();

		foreach ( ContactoLog::for_email( $email ) as $entry ) {
			$data[] = array(
				'group_id'    => self::GROUP_ID,
				'group_label' => 'Mensajes enviados por el formulario de contacto',
				'item_id'     => 'akb-contacto-' . ( $entry['id'] ?? '' ),
				'data'        => array(
					array( 'name' => 'Nombre', 'value' => (string) ( $entry['nombre'] ?? '' ) ),
					array( 'name' => 'Email', 'value' => (string) ( $entry['email'] ?? '' ) ),
					array( 'name' => 'Mensaje', 'value' => (string) ( $entry['mensaje'] ?? '' ) ),
					array( 'name' => 'Enviado (UTC)', 'value' => gmdate( 'Y-m-d H:i:s', (int) ( $entry['time'] ?? 0 ) ) ),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Eraser de WP: borrado físico de todos los mensajes de contacto del email.
	 *
	 * @param string $email Email del titular.
	 * @param int    $page  Página.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		$removed = ContactoLog::erase_email( $email );

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
