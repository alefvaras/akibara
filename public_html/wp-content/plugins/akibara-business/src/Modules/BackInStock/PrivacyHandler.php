<?php
/**
 * PrivacyHandler — exporter y eraser de datos personales (GDPR/Ley 19.628).
 *
 * El módulo almacena emails de clientes en akb_bis_subscribers: las
 * herramientas nativas de WP (Herramientas → Exportar/Borrar datos
 * personales) deben verlos. Fase 5 del spec BIS.
 *
 *   - Exporter: lista cada suscripción del email (producto, estado, fechas).
 *   - Eraser: borrado FÍSICO — sin el email la fila no tiene valor de
 *     negocio ni obligación de retención (no es transaccional/contable).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BackInStock;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.54.0
 */
final class PrivacyHandler {

	private const GROUP_ID = 'akb-bis-subscriptions';

	/** @var SubscriberRepository */
	private SubscriberRepository $repository;

	/**
	 * @param SubscriberRepository $repository Persistencia.
	 */
	public function __construct( SubscriberRepository $repository ) {
		$this->repository = $repository;
	}

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
		$exporters['akibara-back-in-stock'] = array(
			'exporter_friendly_name' => 'Lista de espera Akibara',
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array<string, mixed> $erasers Erasers registrados.
	 * @return array<string, mixed>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['akibara-back-in-stock'] = array(
			'eraser_friendly_name' => 'Lista de espera Akibara',
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exporter de WP: una entrada por suscripción del email.
	 *
	 * @param string $email Email del titular.
	 * @param int    $page  Página (la tabla es chica: una sola pasada).
	 * @return array{data: array<int, mixed>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$data = array();

		foreach ( $this->repository->for_email( $email ) as $subscriber ) {
			$product = function_exists( 'get_the_title' ) ? get_the_title( $subscriber->product_id ) : '';

			$data[] = array(
				'group_id'    => self::GROUP_ID,
				'group_label' => 'Lista de espera (avísame cuando vuelva)',
				'item_id'     => 'akb-bis-' . $subscriber->id,
				'data'        => array(
					array( 'name' => 'Producto',       'value' => '' !== $product ? $product : ( '#' . $subscriber->product_id ) ),
					array( 'name' => 'Email',          'value' => $subscriber->email ),
					array( 'name' => 'Estado',         'value' => $subscriber->status ),
					array( 'name' => 'Alta (UTC)',     'value' => $subscriber->created_at ),
					array( 'name' => 'Avisado (UTC)',  'value' => $subscriber->notified_at ?? '—' ),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Eraser de WP: borrado físico de todas las suscripciones del email.
	 *
	 * @param string $email Email del titular.
	 * @param int    $page  Página.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		$removed = $this->repository->erase_email( $email );

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
