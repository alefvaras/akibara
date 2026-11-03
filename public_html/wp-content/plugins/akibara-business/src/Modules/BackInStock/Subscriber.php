<?php
/**
 * Subscriber — value object de una suscripción de "avísame cuando vuelva".
 *
 * VO puro sin dependencias de WordPress: representa una fila de la tabla
 * {prefix}akb_bis_subscribers. La lógica de persistencia vive en
 * SubscriberRepository; acá solo datos + los estados válidos del ciclo de
 * vida. Inmutable por convención (propiedades públicas tipadas, sin setters).
 *
 * Ciclo de vida:
 *   subscribed → notified      (claim atómico del dispatcher + email enviado)
 *   subscribed → unsubscribed  (baja one-click por token)
 *   subscribed → failed        (MAX_ATTEMPTS de envío fallidos — estado
 *                               terminal que drena count_waiting y corta el
 *                               re-fire infinito del dispatcher)
 *   notified/unsubscribed → subscribed (reactivación: re-alta del mismo email
 *                               cuando el producto vuelve a agotarse)
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BackInStock;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.53.0
 */
final class Subscriber {

	public const STATUS_SUBSCRIBED   = 'subscribed';
	public const STATUS_NOTIFIED     = 'notified';
	public const STATUS_UNSUBSCRIBED = 'unsubscribed';
	public const STATUS_FAILED       = 'failed';

	/**
	 * Estados admitidos por el repositorio; los demás valores son inválidos.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = array(
		self::STATUS_SUBSCRIBED,
		self::STATUS_NOTIFIED,
		self::STATUS_UNSUBSCRIBED,
		self::STATUS_FAILED,
	);

	/** @var int */
	public int $id;

	/** @var int */
	public int $product_id;

	/** @var string */
	public string $email;

	/** @var int|null Usuario WP si estaba logueado al suscribirse. */
	public ?int $user_id;

	/** @var string Token hex de 64 chars para unsubscribe one-click. */
	public string $token;

	/** @var string Uno de self::STATUSES. */
	public string $status;

	/** @var int Intentos de envío fallidos acumulados. */
	public int $notify_attempts;

	/** @var string DATETIME UTC de alta. */
	public string $created_at;

	/** @var string|null DATETIME UTC del envío exitoso. */
	public ?string $notified_at;

	/**
	 * @param int         $id              ID de fila.
	 * @param int         $product_id      Producto suscrito.
	 * @param string      $email           Email del suscriptor.
	 * @param int|null    $user_id         Usuario WP o null (invitado).
	 * @param string      $token           Token de baja.
	 * @param string      $status          Estado del ciclo de vida.
	 * @param int         $notify_attempts Intentos fallidos.
	 * @param string      $created_at      Alta (UTC).
	 * @param string|null $notified_at     Envío exitoso (UTC) o null.
	 */
	public function __construct(
		int $id,
		int $product_id,
		string $email,
		?int $user_id,
		string $token,
		string $status,
		int $notify_attempts,
		string $created_at,
		?string $notified_at
	) {
		$this->id              = $id;
		$this->product_id      = $product_id;
		$this->email           = $email;
		$this->user_id         = $user_id;
		$this->token           = $token;
		$this->status          = $status;
		$this->notify_attempts = $notify_attempts;
		$this->created_at      = $created_at;
		$this->notified_at     = $notified_at;
	}

	/**
	 * Construye desde una fila de $wpdb (ARRAY_A). Tolerante a strings
	 * numéricos (mysqli devuelve todo como string).
	 *
	 * @param array<string, mixed> $row Fila cruda.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['product_id'] ?? 0 ),
			(string) ( $row['email'] ?? '' ),
			isset( $row['user_id'] ) && '' !== (string) $row['user_id'] && null !== $row['user_id'] ? (int) $row['user_id'] : null,
			(string) ( $row['token'] ?? '' ),
			(string) ( $row['status'] ?? self::STATUS_SUBSCRIBED ),
			(int) ( $row['notify_attempts'] ?? 0 ),
			(string) ( $row['created_at'] ?? '' ),
			isset( $row['notified_at'] ) && '' !== (string) $row['notified_at'] && null !== $row['notified_at'] ? (string) $row['notified_at'] : null
		);
	}

	/**
	 * ¿Está esperando aviso? (única condición que cuenta para count_waiting
	 * y para el lote del dispatcher).
	 *
	 * @return bool
	 */
	public function is_waiting(): bool {
		return self::STATUS_SUBSCRIBED === $this->status;
	}
}
