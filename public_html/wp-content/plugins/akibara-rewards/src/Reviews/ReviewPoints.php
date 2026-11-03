<?php
/**
 * ReviewPoints — Puntos por reseña con foto tras moderación y aprobación administrativa.
 *
 * Disparadores: la aprobación puede llegar por transición de estado o por inserción directa.
 * Se escuchan ambos eventos garantizando idempotencia mediante metadatos y event_key.
 *
 * Compuertas de validación:
 *   - Compra verificada obligatoria en WooCommerce.
 *   - Un pago por cliente-producto en toda la historia (índice UNIQUE en el ledger).
 *   - Exclusión de reseñas previas con incentivos de terceros.
 *   - Producto publicado, fotografía real y exclusión de reseñas de administración.
 *   - Usuarios invitados: retención con sello pendiente hasta validación de cuenta.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class ReviewPoints {

	public const META_POINTS_PAID   = '_akb_rewards_review_points';
	/** 1 = solicitó premio; 0 = reseña sin premio. Ausente conserva el trato anterior. */
	public const META_POINTS_REQUESTED = '_akb_rewards_review_points_requested';
	public const SEAL_PENDING_USER  = 'pending_account';

	/**
	 * Sello: pagada y luego REVERTIDA (rechazo, papelera, spam o borrado tras
	 * haber cobrado). REVERSIBLE: si se re-aprueba después, `maybe_pay()`
	 * la vuelve a evaluar desde cero.
	 */
	public const SEAL_REVERSED = 'reversed';

	/**
	 * Posición de la reseña en la cadencia «premiar cada N» (1, 2, 3…). Una
	 * reseña con avance y sin puntos igual cuenta para un premio.
	 */
	public const META_COUNTED       = '_akb_rewards_review_counted';

	/** Avance en la cadencia «premiar cada N» («2/3»), visible en moderación. */
	public const META_PROGRESS      = '_akb_rewards_review_progress';

	/**
	 * Cuelga los disparadores.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'transition_comment_status', array( $this, 'on_status_transition' ), 10, 3 );
		add_action( 'comment_post', array( $this, 'on_comment_born' ), 20, 2 );
		add_action( 'wp_insert_comment', array( $this, 'on_comment_inserted' ), 20, 2 );
		// El vínculo invitado→cuenta exige verificar el correo antes de pagar.
		add_action( 'woocommerce_customer_email_verified', array( $this, 'on_email_verified' ), 20, 1 );
		// 'delete_comment' se ejecuta antes de modificar la base de datos, asegurando
		// que META_POINTS_PAID aún exista y pueda efectuarse la reversa de puntos
		// antes de que wp_delete_comment() elimine los metadatos asociados.
		add_action( 'delete_comment', array( $this, 'on_comment_deleting' ), 10, 2 );
	}

	/**
	 * Transición de estado de comentario: pagar al APROBAR, revertir en
	 * cualquier otro destino (rechazo, papelera, spam, o el pseudo-estado
	 * 'delete' de un borrado en firme —cubierto también, sin daño, por
	 * `on_comment_deleting()`, que corre primero con la meta intacta).
	 *
	 * @param string      $new_status Estado nuevo ('approved', 'unapproved'…).
	 * @param string      $old_status Estado anterior.
	 * @param \WP_Comment $comment    Comentario.
	 * @return void
	 */
	public function on_status_transition( $new_status, $old_status, $comment ): void {
		unset( $old_status );

		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}

		if ( 'approved' === (string) $new_status ) {
			$this->maybe_pay( $comment );
			return;
		}

		$this->maybe_reverse( $comment );
	}

	/**
	 * `delete_comment`: el punto MÁS TEMPRANO de `wp_delete_comment()`, con
	 * la commentmeta todavía intacta. Ver el comentario de `register()`.
	 *
	 * @param string           $comment_id ID del comentario (string en el hook nativo).
	 * @param \WP_Comment|null $comment    Comentario a borrar.
	 * @return void
	 */
	public function on_comment_deleting( $comment_id, $comment ): void {
		unset( $comment_id );

		if ( $comment instanceof \WP_Comment ) {
			$this->maybe_reverse( $comment );
		}
	}

	/**
	 * Nacimiento vía wp_new_comment (dispara comment_post con el veredicto).
	 *
	 * @param int        $comment_id ID del comentario.
	 * @param int|string $approved   1/0/'spam'.
	 * @return void
	 */
	public function on_comment_born( $comment_id, $approved ): void {
		if ( 1 !== (int) $approved ) {
			return;
		}

		$comment = get_comment( (int) $comment_id );
		if ( $comment instanceof \WP_Comment ) {
			$this->maybe_pay( $comment );
		}
	}

	/**
	 * Nacimiento vía wp_insert_comment directo (CusRev, imports): el hook
	 * comment_post no corre ahí; este sí, siempre.
	 *
	 * @param int         $comment_id ID.
	 * @param \WP_Comment $comment    Comentario recién insertado.
	 * @return void
	 */
	public function on_comment_inserted( $comment_id, $comment ): void {
		unset( $comment_id );

		if ( $comment instanceof \WP_Comment && '1' === (string) $comment->comment_approved ) {
			$this->maybe_pay( $comment );
		}
	}

	/**
	 * El gancho invitado→cuenta, tras VERIFICAR el email: sus reseñas
	 * aprobadas con sello pendiente cobran (el event_key impide dobles).
	 *
	 * El sello NO se borra antes: maybe_pay acepta `pending_account` y lo
	 * resuelve; si una compuerta posterior corta, la huella sobrevive.
	 *
	 * @param int $user_id Usuario con email verificado.
	 * @return void
	 */
	public function on_email_verified( $user_id ): void {
		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user instanceof \WP_User || '' === (string) $user->user_email ) {
			return;
		}

		$comments = get_comments(
			array(
				'type'         => 'review',
				'status'       => 'approve',
				'author_email' => (string) $user->user_email,
				'meta_key'     => self::META_POINTS_PAID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => self::SEAL_PENDING_USER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$pagadas = 0;
		foreach ( (array) $comments as $comment ) {
			if ( $comment instanceof \WP_Comment ) {
				$this->maybe_pay( $comment );
				// Comprueba si la reseña califica como incentivada para invalidar cachés.
				if ( '' !== Disclosure::notice_for( (int) $comment->comment_ID ) ) {
					++$pagadas;
				}
			}
		}

		// Si hubo reseñas que pasaron a incentivadas, purgar el transient del carrusel
		// y la caché de página para reflejar los avisos actualizados.
		if ( $pagadas > 0 ) {
			delete_transient( Carousel::TRANSIENT ); // La tarjeta del home también cambia de criterio.
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LiteSpeed (tercero).
		}
	}

	/**
	 * Paga los puntos de una reseña aprobada si cumple todas las compuertas.
	 *
	 * @param \WP_Comment $comment Reseña aprobada.
	 * @return void
	 */
	public function maybe_pay( \WP_Comment $comment ): void {
		$points = Options::review_points();
		if ( $points <= 0 ) {
			return; // Programa apagado.
		}

		if ( 'review' !== (string) $comment->comment_type ) {
			return;
		}

		if ( (int) $comment->comment_parent > 0 ) {
			return; // Una RESPUESTA jamás cobra ni quema el event_key (2a).
		}

		$comment_id = (int) $comment->comment_ID;
		$product_id = (int) $comment->comment_post_ID;
		$requested = (string) get_comment_meta( $comment_id, self::META_POINTS_REQUESTED, true );
		if ( '' !== $requested && '1' !== $requested ) {
			return; // Publicar una reseña no inscribe al cliente en los premios.
		}

		if ( 'product' !== get_post_type( $product_id ) || 'publish' !== get_post_status( $product_id ) ) {
			return;
		}

		$seal = (string) get_comment_meta( $comment_id, self::META_POINTS_PAID, true );
		if ( '' !== $seal && self::SEAL_PENDING_USER !== $seal && self::SEAL_REVERSED !== $seal ) {
			return; // Ya resuelta (pagada o descartada con huella).
		}

		if ( '' !== (string) get_comment_meta( $comment_id, AdminReviews::META_ADMIN_CREATED, true ) ) {
			return; // Las reseñas escritas por la tienda no se premian a sí mismas.
		}

		if ( '' !== (string) get_comment_meta( $comment_id, 'cr_coupon_code', true ) ) {
			// Ya cobró el cupón del tercero: una recompensa por acción.
			update_comment_meta( $comment_id, self::META_POINTS_PAID, 0 );
			return;
		}

		if ( ! Media::has_real_photo( $comment_id ) ) {
			return; // Sin foto real no hay premio (video y texto no cuentan).
		}

		$user_id = self::resolve_user( $comment );
		if ( $user_id <= 0 ) {
			// Sin cuenta verificada todavía: el hook de verificación re-evalúa.
			update_comment_meta( $comment_id, self::META_POINTS_PAID, self::SEAL_PENDING_USER );
			return;
		}

		if ( ! self::verified_purchase( $comment, $user_id, $product_id ) ) {
			return; // Sin compra verificada no hay premio (interruptor).
		}

		// Una retirada puede haber encontrado ocupado el candado. Completar
		// esa limpieza antes de volver a contar la misma reseña restaurada.
		if ( self::SEAL_REVERSED === $seal && ! self::release_cadence_slot( $user_id, $comment_id ) ) {
			do_action( 'akb_rewards_review_pay_failed', $comment, $user_id, $points );
			return;
		}

		// Política de frecuencia: tope histórico por cliente y cadencia «cada N reseñas».
		// Ambas se validan contra el ledger. El tope cuenta productos con crédito
		// vigente (neto de reversas) para asegurar que reseñas revertidas liberen el cupo.
		$max = Options::review_max_per_user();
		if ( $max > 0 && Ledger::count_live_credits( $user_id, 'review', 'review', 'review_reversal' ) >= $max ) {
			update_comment_meta( $comment_id, self::META_POINTS_PAID, 0 );
			return; // El cliente ya alcanzó su tope histórico de premios.
		}

		$every = Options::review_every_n();
		if ( $every > 1 ) {
			$locked = Ledger::with_user_lock(
				$user_id,
				static function () use ( $user_id, $comment_id ): int {
					// Idempotencia por reseña: asegura que llamadas concurrentes
					// no consuman posiciones duplicadas en la cadencia.
					$pinned = (int) get_comment_meta( $comment_id, self::META_COUNTED, true );
					if ( $pinned > 0 ) {
						return $pinned;
					}

					$n = Ledger::raw_user_meta_int( $user_id, '_akb_rewards_review_qualifying' ) + 1;
					update_user_meta( $user_id, '_akb_rewards_review_qualifying', $n );
					update_comment_meta( $comment_id, self::META_COUNTED, $n );
					return $n;
				}
			);

			if ( null === $locked ) {
				// Candado ocupado: sin sellar y sin contar para reintentar en el próximo evento.
				do_action( 'akb_rewards_review_pay_failed', $comment, $user_id, $points );
				return;
			}

			$qualifying = (int) $locked;
			// Retirar una intermedia puede devolver el contador al mismo múltiplo
			// que ya pagó. Los créditos aún vigentes consumen esos ciclos: no
			// basta con volver a alcanzar N para pagar el mismo grupo dos veces.
			if ( 0 === $qualifying % $every && Ledger::count_live_credits( $user_id, 'review', 'review', 'review_reversal' ) >= intdiv( $qualifying, $every ) ) {
				update_comment_meta( $comment_id, self::META_POINTS_PAID, 0 );
				delete_comment_meta( $comment_id, self::META_PROGRESS );
				return;
			}
			if ( 0 !== $qualifying % $every ) {
				// Calificada pero aún no toca premio: sellada con 0 y registro de progreso.
				update_comment_meta( $comment_id, self::META_POINTS_PAID, 0 );
				update_comment_meta( $comment_id, self::META_PROGRESS, sprintf( '%d/%d', $qualifying % $every, $every ) );
				return;
			}
		}

		// Clave de evento por ocurrencia: si el cliente ya tuvo una reversa para este producto,
		// la clave incorpora el índice de reversa para garantizar unicidad e idempotencia en el ledger.
		$reversals = Ledger::count_movements_for_user( $user_id, 'review', $product_id, 'review_reversal' );
		$event_key = $reversals > 0
			? sprintf( 'review_u%d_p%d_r%d', $user_id, $product_id, $reversals )
			: sprintf( 'review_u%d_p%d', $user_id, $product_id );

		$moved_before = Ledger::count_movements_for_user( $user_id, 'review', $product_id, 'review' );

		$result = Ledger::credit(
			$user_id,
			$points,
			'review',
			'review',
			$product_id,
			sprintf(
				'Reseña con foto aprobada (#%d)%s',
				$comment_id,
				get_current_user_id() > 0 ? sprintf( ' por %s', wp_get_current_user()->user_login ) : ''
			),
			true,
			$event_key
		);

		if ( ! is_int( $result ) ) {
			// Infraestructura: huella + alarma; la próxima aprobación (o el
			// nacimiento re-procesado) reintenta.
			do_action( 'akb_rewards_review_pay_failed', $comment, $user_id, $points );
			return;
		}

		// El duplicado por cliente-producto vuelve como éxito sin movimiento
		// (saldo intacto): se sella con 0 y huella — re-reseñar no re-paga.
		// Y el hook de abajo solo se dispara si ESTE llamado movió saldo: un
		// sello perdido no puede duplicar el correo de aviso (2a).
		$moved_now = Ledger::count_movements_for_user( $user_id, 'review', $product_id, 'review' ) > $moved_before;
		update_comment_meta( $comment_id, self::META_POINTS_PAID, $moved_now ? $points : 0 );

		if ( ! $moved_now ) {
			return;
		}

		/**
		 * Reseña premiada con puntos. El correo de aviso escucha acá.
		 *
		 * @param \WP_Comment $comment Reseña.
		 * @param int         $user_id Cliente premiado.
		 * @param int         $points  Puntos acreditados.
		 */
		do_action( 'akb_rewards_review_points_paid', $comment, $user_id, $points );
	}

	/**
	 * Revierte los puntos de una reseña que YA COBRÓ y deja de estar aprobada
	 * (rechazo, papelera, spam) o se borra en firme. Mismo patrón que las
	 * demás reversas del programa —
	 * `Accrual::deduct_all_locked()` para pedidos anulados y
	 * `Referrals::maybe_reverse_referrer()` para el premio del padrino—:
	 * débito con clave de evento PROPIA e idempotente, topado al saldo
	 * disponible (`Ledger::debit()` con `$require_sufficient = false` nunca
	 * deja el saldo negativo), y si no se recuperó todo, la misma alarma que
	 * usan esas otras reversas.
	 *
	 * Sólo debita si el sello guarda puntos. Las reseñas intermedias de una
	 * cadencia también liberan su posición, aunque aún no hayan cobrado.
	 * Un segundo disparo no repite ni el débito ni el decremento del avance.
	 *
	 * La reversa descuenta del saldo sin enviar correo transaccional automático.
	 *
	 * @param \WP_Comment $comment Reseña que deja de estar aprobada, o se borra.
	 * @return void
	 */
	private function maybe_reverse( \WP_Comment $comment ): void {
		if ( 'review' !== (string) $comment->comment_type || (int) $comment->comment_parent > 0 ) {
			return;
		}

		$comment_id = (int) $comment->comment_ID;
		$points     = (int) get_comment_meta( $comment_id, self::META_POINTS_PAID, true );
		if ( $points <= 0 ) {
			// Una reseña intermedia no pagó, pero sí consumió una posición.
			// Su retirada también debe liberar ese avance. El sello permite
			// reintentar si el candado está ocupado, incluso al reaprobársela.
			if ( (int) get_comment_meta( $comment_id, self::META_COUNTED, true ) > 0 ) {
				$user_id = self::resolve_user( $comment );
				if ( $user_id > 0 ) {
					update_comment_meta( $comment_id, self::META_POINTS_PAID, self::SEAL_REVERSED );
					self::release_cadence_slot( $user_id, $comment_id );
				}
			}
			return;
		}

		$user_id = self::resolve_user( $comment );
		if ( $user_id <= 0 ) {
			return;
		}

		$product_id = (int) $comment->comment_post_ID;

		// Clave por OCURRENCIA, igual que Referrals::maybe_reverse_referrer:
		// si esta reseña ya se había revertido, se re-aprobó y volvió a
		// pagar, la SEGUNDA reversa necesita una clave distinta o el UNIQUE
		// la trataría como «ya aplicada» y no descontaría nada la segunda vez.
		$reversals = Ledger::count_movements_for_user( $user_id, 'review', $product_id, 'review_reversal' );

		$deducted = Ledger::debit(
			$user_id,
			$points,
			'review_reversal',
			'review',
			$product_id,
			'Reseña retirada',
			false,
			sprintf( 'review_reversal_u%d_p%d_r%d', $user_id, $product_id, $reversals )
		);

		if ( ! is_int( $deducted ) ) {
			// Candado ocupado: sin sellar, el próximo disparo reintenta con la
			// MISMA clave (no se insertó nada; `$reversals` no cambió).
			return;
		}

		// Sello REVERSIBLE: si el dueño la re-aprueba después, maybe_pay() la
		// vuelve a evaluar desde cero (mismo trato que pending_account).
		update_comment_meta( $comment_id, self::META_POINTS_PAID, self::SEAL_REVERSED );

		if ( $deducted < $points ) {
			// El cliente ya se había gastado los puntos: no hay saldo
			// negativo que cobrarle, y el dueño necesita verlo (mismo patrón
			// que Accrual::deduct_all_locked() y
			// Referrals::maybe_reverse_referrer()).
			do_action( 'akb_rewards_reversal_failed', $comment, $points - $deducted );
		}

		// Cadencia «cada N reseñas»: liberar la posición consumida bajo el candado
		// del usuario para que la siguiente reseña calificada pueda utilizarla.
		// Se eliminan META_COUNTED y META_PROGRESS para que, en caso de una posterior
		// re-aprobación, se calcule una posición limpia a partir del estado actual del contador.
		self::release_cadence_slot( $user_id, $comment_id );
	}

	/**
	 * Libera una posición una sola vez, conservando las marcas si el candado falla.
	 *
	 * @param int $user_id Cliente.
	 * @param int $comment_id Reseña retirada.
	 * @return bool Si la limpieza terminó o no quedaba posición que liberar.
	 */
	private static function release_cadence_slot( int $user_id, int $comment_id ): bool {
		if ( (int) get_comment_meta( $comment_id, self::META_COUNTED, true ) <= 0 ) {
			return true;
		}
		return true === Ledger::with_user_lock(
			$user_id,
			static function () use ( $user_id, $comment_id ): bool {
				if ( (int) get_comment_meta( $comment_id, self::META_COUNTED, true ) <= 0 ) {
					return true;
				}
				$current = Ledger::raw_user_meta_int( $user_id, '_akb_rewards_review_qualifying' );
				update_user_meta( $user_id, '_akb_rewards_review_qualifying', max( 0, $current - 1 ) );
				delete_comment_meta( $comment_id, self::META_COUNTED );
				delete_comment_meta( $comment_id, self::META_PROGRESS );
				return true;
			}
		);
	}

	/**
	 * Compra verificada, con el cálculo perezoso clonado de CusRev: si la
	 * meta `verified` falta, se computa con wc_customer_bought_product y se
	 * PERSISTE (mismo efecto de escritura que el tercero).
	 *
	 * @param \WP_Comment $comment    Reseña.
	 * @param int         $user_id    Cliente resuelto.
	 * @param int         $product_id Producto.
	 * @return bool
	 */
	private static function verified_purchase( \WP_Comment $comment, int $user_id, int $product_id ): bool {
		if ( 'yes' !== (string) get_option( 'akb_rewards_review_require_verified', 'yes' ) ) {
			return true;
		}

		// El formulario conoce el pedido exacto. Su prueba incluye el estado
		// propio Listo para enviar y se revalida aunque Woo dejara verified=0,
		// o aunque un verified=1 anterior preceda a una anulación/devolución.
		$linked = VerifiedPurchase::for_review( $comment, $user_id );
		if ( null !== $linked ) {
			update_comment_meta( (int) $comment->comment_ID, 'verified', (int) $linked );
			return $linked;
		}

		$verified = (string) get_comment_meta( (int) $comment->comment_ID, 'verified', true );

		if ( '' === $verified && function_exists( 'wc_customer_bought_product' ) ) {
			$bought = wc_customer_bought_product( (string) $comment->comment_author_email, $user_id, $product_id );
			add_comment_meta( (int) $comment->comment_ID, 'verified', (int) $bought, true );
			// Usa el valor persistido, incluso si otro proceso ganó la inserción única.
			$verified = (string) get_comment_meta( (int) $comment->comment_ID, 'verified', true );
		}

		return '1' === $verified;
	}

	/**
	 * Resuelve el usuario dueño de la reseña: user_id directo, o el email del
	 * autor contra la tabla de usuarios (los invitados de CusRev quedan con
	 * user_id 0 — ingeniería inversa). Resolver por email exige que Woo
	 * confirme su verificación: crear una cuenta ajena no prueba identidad.
	 *
	 * @param \WP_Comment $comment Reseña.
	 * @return int 0 si no hay cuenta.
	 */
	private static function resolve_user( \WP_Comment $comment ): int {
		if ( (int) $comment->user_id > 0 ) {
			return (int) $comment->user_id;
		}

		$email = (string) $comment->comment_author_email;
		if ( '' === $email ) {
			return 0;
		}

		$user = get_user_by( 'email', $email );

		if ( ! $user instanceof \WP_User || strtolower( (string) $user->user_email ) !== strtolower( $email ) || ! function_exists( 'wc_get_container' ) ) {
			return 0;
		}
		$verification = wc_get_container()->get( \Automattic\WooCommerce\Internal\CustomerEmailVerification\EmailVerificationService::class );
		return $verification->is_verified( (int) $user->ID ) ? (int) $user->ID : 0;
	}
}
