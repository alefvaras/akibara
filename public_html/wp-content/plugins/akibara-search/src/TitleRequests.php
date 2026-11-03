<?php
/**
 * TitleRequests — gestión y captura local de solicitudes de títulos no disponibles.
 *
 * Registra y administra las peticiones de tomos que los clientes buscan y la
 * tienda no tiene en bodega física (a pedido, preventa o agotado editorial).
 * Los datos se conservan 100% en local en MariaDB (`akb_search_requests`),
 * alimentando directamente el radar de compras del Centro de Mando («Lo más solicitado»,
 * barra morada) sin depender de servicios de terceros como Brevo ni pagar latencia externa.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.5
 */
final class TitleRequests {

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} sale de self::table_name() ($wpdb->prefix + literal fijo); el prefijo no admite placeholders.

	public const DB_VERSION = '1.1.0';
	public const OPTION_DB_VERSION = 'akb_search_requests_db_version';

	public const TABLE_BASE = 'akb_search_requests';

	public const STATUS_PENDING   = 'pending';
	public const STATUS_QUOTED    = 'quoted';
	public const STATUS_ORDERED   = 'ordered';
	public const STATUS_FULFILLED = 'fulfilled';
	public const STATUS_DISMISSED = 'dismissed';

	public const STATUSES = array(
		self::STATUS_PENDING,
		self::STATUS_QUOTED,
		self::STATUS_ORDERED,
		self::STATUS_FULFILLED,
		self::STATUS_DISMISSED,
	);

	public const INTENT_A_PEDIDO = 'a_pedido';
	public const INTENT_PREVENTA = 'preventa';
	public const INTENT_AGOTADO  = 'agotado';

	public const INTENTS = array(
		self::INTENT_A_PEDIDO,
		self::INTENT_PREVENTA,
		self::INTENT_AGOTADO,
	);

	/**
	 * Registra hooks del modelo.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'akb_search_send_request_email', array( __CLASS__, 'handle_async_email' ) );
		self::maybe_install();
	}

	/**
	 * Verifica e instala la tabla si la versión de esquema difiere.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}
		$version = (string) get_option( self::OPTION_DB_VERSION, '' );
		if ( self::DB_VERSION !== $version ) {
			self::install_table();
		}
	}

	/**
	 * Nombre completo de la tabla en MariaDB.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_BASE;
	}

	/**
	 * Crea o actualiza la tabla en MariaDB mediante dbDelta.
	 *
	 * @return void
	 */
	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			query_norm VARCHAR(191) NOT NULL DEFAULT '',
			title VARCHAR(255) NOT NULL DEFAULT '',
			editorial VARCHAR(100) NOT NULL DEFAULT '',
			isbn VARCHAR(32) NOT NULL DEFAULT '',
			status_intended VARCHAR(20) NOT NULL DEFAULT 'a_pedido',
			customer_name VARCHAR(100) NOT NULL DEFAULT '',
			customer_contact VARCHAR(100) NOT NULL DEFAULT '',
			contact_type VARCHAR(10) NOT NULL DEFAULT 'email',
			user_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			notes TEXT NULL,
			ip_hash CHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_product_id (product_id),
			KEY idx_query_norm (query_norm),
			KEY idx_status (status),
			KEY idx_created (created_at),
			KEY idx_editorial (editorial),
			KEY idx_rl_ip (ip_hash, created_at)
		) ENGINE=InnoDB {$charset};";

		dbDelta( $sql );
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_DB_VERSION, self::DB_VERSION, true );
		}
	}

	/**
	 * Registra una solicitud de título en base de datos local.
	 *
	 * @param array<string, mixed> $payload Datos del formulario.
	 * @param string               $ip      IP de origen para rate limit y hash antispam.
	 * @return array{success: bool, id?: int, message: string}
	 */
	public static function record( array $payload, string $ip = '' ): array {
		global $wpdb;

		$title   = sanitize_text_field( trim( (string) ( $payload['title'] ?? '' ) ) );
		$query   = sanitize_text_field( trim( (string) ( $payload['query'] ?? ( $payload['query_norm'] ?? '' ) ) ) );
		$contact = sanitize_text_field( trim( (string) ( $payload['contact'] ?? ( $payload['customer_contact'] ?? '' ) ) ) );
		$name    = sanitize_text_field( trim( (string) ( $payload['name'] ?? ( $payload['customer_name'] ?? '' ) ) ) );
		$editor  = sanitize_text_field( trim( (string) ( $payload['editorial'] ?? '' ) ) );
		$intent  = sanitize_key( (string) ( $payload['status_intended'] ?? self::INTENT_A_PEDIDO ) );
		$isbn    = sanitize_text_field( trim( (string) ( $payload['isbn'] ?? '' ) ) );

		if ( '' === $title && '' === $query ) {
			return array(
				'success' => false,
				'message' => 'Debes indicar el título o tomo que estás buscando.',
			);
		}

		if ( '' === $contact ) {
			return array(
				'success' => false,
				'message' => 'Por favor ingresa un correo o WhatsApp de contacto.',
			);
		}

		// Identificar tipo de contacto
		$contact_type = 'email';
		if ( is_email( $contact ) ) {
			$contact_type = 'email';
		} elseif ( preg_match( '/^[0-9+ \-()]{7,20}$/', $contact ) ) {
			$contact_type = 'whatsapp';
		} else {
			return array(
				'success' => false,
				'message' => 'El formato del correo o WhatsApp ingresado no es válido.',
			);
		}

		if ( ! in_array( $intent, self::INTENTS, true ) ) {
			$intent = self::INTENT_A_PEDIDO;
		}

		$query_norm = Normalizer::normalize( '' !== $title ? $title : $query );
		$ip_hash    = '' !== $ip ? hash( 'sha256', $ip . wp_salt() ) : '';

		// Rate limiting: evitar registros idénticos del mismo contacto en menos de 5 minutos
		$table = self::table_name();
		$five_min_ago = gmdate( 'Y-m-d H:i:s', time() - 300 );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} sale de self::table_name() ($wpdb->prefix + literal fijo).
		$duplicado = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE customer_contact = %s AND query_norm = %s AND created_at >= %s LIMIT 1",
				$contact,
				$query_norm,
				$five_min_ago
			)
		);

		if ( $duplicado > 0 ) {
			return array(
				'success' => true,
				'id'      => $duplicado,
				'message' => 'Tu solicitud ya está registrada. ¡Te contactaremos en cuanto tengamos novedades!',
			);
		}

		$user_id = get_current_user_id() ?: null;

		$product_id = isset( $payload['product_id'] ) && is_numeric( $payload['product_id'] ) && (int) $payload['product_id'] > 0
			? (int) $payload['product_id']
			: null;

		$insert_data = array(
			'query_norm'       => $query_norm,
			'title'            => '' !== $title ? $title : $query,
			'editorial'        => $editor,
			'isbn'             => $isbn,
			'status_intended'  => $intent,
			'customer_name'    => $name,
			'customer_contact' => $contact,
			'contact_type'     => $contact_type,
			'user_id'          => $user_id,
			'status'           => self::STATUS_PENDING,
			'ip_hash'          => $ip_hash,
			'created_at'       => current_time( 'mysql', true ),
		);
		$insert_format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' );

		if ( null !== $product_id ) {
			$insert_data['product_id'] = $product_id;
			$insert_format[]           = '%d';
		}

		$inserted = $wpdb->insert(
			$table,
			$insert_data,
			$insert_format
		);

		if ( false === $inserted ) {
			return array(
				'success' => false,
				'message' => 'No pudimos registrar tu solicitud en este momento. Por favor reintenta.',
			);
		}

		$req_id = (int) $wpdb->insert_id;

		// Invalidar caché del ranking del panel para que la nueva señal aparezca de inmediato
		delete_transient( 'akb_demand_ranking_v1' );

		// Notificación por correo al administrador (asíncrona verdaderamente no bloqueante)
		self::schedule_async_notification( $req_id );

		return array(
			'success' => true,
			'id'      => $req_id,
			'message' => '¡Solicitud recibida con éxito! Revisaremos disponibilidad con la editorial y te avisaremos.',
		);
	}

	/**
	 * Encola la notificación al administrador de forma asíncrona vía Action Scheduler.
	 *
	 * @param int $request_id ID de la solicitud registrada.
	 * @return void
	 */
	public static function schedule_async_notification( int $request_id ): void {
		if ( $request_id <= 0 ) {
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			try {
				as_enqueue_async_action(
					'akb_search_send_request_email',
					array( 'request_id' => $request_id ),
					'akibara-search'
				);
				return;
			} catch ( \Throwable $e ) {
				// Fallback si Action Scheduler falla
			}
		}

		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time(), 'akb_search_send_request_email', array( 'request_id' => $request_id ) );
		} else {
			self::handle_async_email( $request_id );
		}
	}

	/**
	 * Callback asíncrono para enviar el correo de notificación al administrador.
	 *
	 * @param int|array<string, mixed> $request_id_or_args ID de solicitud o array de args.
	 * @return void
	 */
	public static function handle_async_email( $request_id_or_args ): void {
		global $wpdb;

		$request_id = 0;
		if ( is_array( $request_id_or_args ) ) {
			$request_id = (int) ( $request_id_or_args['request_id'] ?? ( $request_id_or_args[0] ?? 0 ) );
		} elseif ( is_numeric( $request_id_or_args ) ) {
			$request_id = (int) $request_id_or_args;
		}

		if ( $request_id <= 0 ) {
			return;
		}

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} sale de self::table_name() ($wpdb->prefix + literal fijo).
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				$request_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || empty( $row ) ) {
			return;
		}

		self::send_admin_notification( $row );
	}

	/**
	 * Notifica al administrador de la tienda por correo sobre una nueva solicitud de encargo.
	 *
	 * @param array<string, mixed> $data Datos de la solicitud.
	 * @return bool
	 */
	public static function send_admin_notification( array $data ): bool {
		$admin_email = get_option( 'admin_email' );
		if ( ! is_email( (string) $admin_email ) ) {
			return false;
		}

		$title     = sanitize_text_field( (string) ( $data['title'] ?? 'Tomo manga' ) );
		$subject   = sprintf( '[Akibara] Nueva solicitud a pedido: %s', $title );
		$panel_url = admin_url( 'admin.php?page=akibara-search&sub=encargos' );
		$contact   = sanitize_text_field( (string) ( $data['customer_contact'] ?? '' ) );
		$type      = sanitize_text_field( (string) ( $data['contact_type'] ?? '' ) );
		$name      = sanitize_text_field( (string) ( $data['customer_name'] ?? 'Cliente' ) );
		$editorial = sanitize_text_field( (string) ( $data['editorial'] ?? 'Sin editorial' ) );
		$intent    = sanitize_text_field( (string) ( $data['status_intended'] ?? 'a_pedido' ) );

		$product_str = '';
		if ( isset( $data['product_id'] ) && (int) $data['product_id'] > 0 ) {
			$pid         = (int) $data['product_id'];
			$product_str = sprintf( "• Producto catálogo: #%d (%s)\n", $pid, admin_url( 'post.php?post=' . $pid . '&action=edit' ) );
		}

		$message = "Hola,\n\n"
			. "Un cliente ha registrado una solicitud de tomo a pedido en el buscador de Akibara:\n\n"
			. "• Título: {$title}\n"
			. "• Editorial: {$editorial}\n"
			. "• Modalidad: {$intent}\n"
			. "• Cliente: {$name}\n"
			. "• Contacto: {$contact} ({$type})\n"
			. $product_str . "\n"
			. "Puedes gestionar esta solicitud, contactar al cliente o marcarla como pedida/resuelta aquí:\n"
			. "{$panel_url}\n\n"
			. "--\nBuscador Akibara";

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		return (bool) wp_mail( (string) $admin_email, $subject, $message, $headers );
	}

	/**
	 * Señales agregadas para el ranking «Lo más solicitado» de akibara-business.
	 *
	 * Agrupa por `query_norm` y devuelve el conteo de solicitudes pendientes en los últimos N días.
	 *
	 * @param int $days Ventana en días (default 90).
	 * @return array<string, array{title: string, editorial: string, intent: string, count: int}>
	 */
	public static function demand_signals( int $days = 90 ): array {
		global $wpdb;
		$table = self::table_name();
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} sale de self::table_name() ($wpdb->prefix + literal fijo).
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT query_norm, title, editorial, status_intended, COUNT(*) as c
				FROM {$table}
				WHERE created_at >= %s AND status = %s
				GROUP BY query_norm
				ORDER BY c DESC LIMIT 30",
				$since,
				self::STATUS_PENDING
			)
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$norm = (string) $row->query_norm;
			$out[ $norm ] = array(
				'title'     => (string) $row->title,
				'editorial' => (string) $row->editorial,
				'intent'    => (string) $row->status_intended,
				'count'     => (int) $row->c,
			);
		}

		return $out;
	}

	/**
	 * Lista de solicitudes pendientes para revisión en admin.
	 *
	 * @param int $limit Límite de filas.
	 * @return array<int, object>
	 */
	public static function pending_list( int $limit = 50 ): array {
		return self::list_requests( self::STATUS_PENDING, $limit );
	}

	/**
	 * Devuelve solicitudes filtradas por estado o todas.
	 *
	 * @param string $status Estado ('all' o uno de STATUSES).
	 * @param int    $limit  Límite de filas.
	 * @param int    $offset Desplazamiento.
	 * @return array<int, object>
	 */
	public static function list_requests( string $status = 'pending', int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$table  = self::table_name();
		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );

		if ( 'all' === $status || '' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} sale de self::table_name() ($wpdb->prefix + literal fijo).
			return (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$limit,
					$offset
				)
			);
		}

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = self::STATUS_PENDING;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} sale de self::table_name() ($wpdb->prefix + literal fijo).
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$status,
				$limit,
				$offset
			)
		);
	}

	/**
	 * Conteo de solicitudes por estado para las pestañas de admin.
	 *
	 * @return array<string, int>
	 */
	public static function count_by_status(): array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} sale de self::table_name() ($wpdb->prefix + literal fijo).
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) as c FROM {$table} GROUP BY status" );

		$counts = array(
			'all'                   => 0,
			self::STATUS_PENDING   => 0,
			self::STATUS_QUOTED    => 0,
			self::STATUS_ORDERED   => 0,
			self::STATUS_FULFILLED => 0,
			self::STATUS_DISMISSED => 0,
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$s = (string) $row->status;
				$c = (int) $row->c;
				if ( isset( $counts[ $s ] ) ) {
					$counts[ $s ] = $c;
				}
				$counts['all'] += $c;
			}
		}

		return $counts;
	}

	/**
	 * Actualiza el estado y notas de una solicitud.
	 *
	 * @param int    $id     ID de solicitud.
	 * @param string $status Nuevo estado.
	 * @param string $notes  Notas internas.
	 * @return bool
	 */
	public static function update_status( int $id, string $status, string $notes = '' ): bool {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}

		$table  = self::table_name();
		$data   = array( 'status' => $status );
		$format = array( '%s' );

		if ( '' !== $notes ) {
			$data['notes'] = sanitize_textarea_field( $notes );
			$format[]      = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update(
			$table,
			$data,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		if ( false !== $updated ) {
			delete_transient( 'akb_demand_ranking_v1' );
			return true;
		}

		return false;
	}
}
