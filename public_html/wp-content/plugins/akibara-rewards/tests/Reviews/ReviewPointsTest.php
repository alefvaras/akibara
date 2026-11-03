<?php
/**
 * Contrato de los puntos por reseña.
 *
 * Decisión del dueño 2026-08-30: la reseña con FOTO da puntos SOLO tras su
 * aprobación. Acá se fijan las compuertas: foto real obligatoria, cuenta
 * obligatoria, idempotencia (aprobar-desaprobar-aprobar paga UNA vez), y que
 * las reseñas escritas por la tienda jamás se premian a sí mismas.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Reviews\AdminReviews;
use Akibara\Rewards\Reviews\Disclosure;
use Akibara\Rewards\Reviews\Media;
use Akibara\Rewards\Reviews\ReviewPoints;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.2.0
 */
final class ReviewPointsTest extends RewardsTestCase {

	private ReviewPoints $points;

	protected function setUp(): void {
		parent::setUp();

		$this->points = new ReviewPoints();

		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'wp_attachment_is' )->alias(
			static fn( string $type, int $id ): bool => 'image' === $type && $id > 0
		);
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'wc_get_container' )->justReturn( new class {
			public function get( string $class ): object { return new class { public function is_verified( int $id ): bool { return true; } }; }
		} );
	}

	/**
	 * Reseña con foto local de un cliente con cuenta.
	 */
	private function review_with_photo( int $id = 10, int $user_id = 7, int $product = 777 ): \WP_Comment {
		$this->wpdb->comment_meta[ $id ][ Media::META_LOCAL_IMAGE ] = array( 901 );
		$this->wpdb->comment_meta[ $id ]['verified']                = array( '1' );

		return new \WP_Comment(
			array(
				'comment_ID'      => $id,
				'comment_post_ID' => $product,
				'user_id'         => $user_id,
				'comment_type'    => 'review',
			)
		);
	}

	public function test_an_approved_photo_review_pays_the_points_once(): void {
		$review = $this->review_with_photo();

		\Brain\Monkey\Actions\expectDone( 'akb_rewards_review_points_paid' )
			->once()
			->with( $review, 7, 500 );

		$this->points->on_status_transition( 'approved', 'unapproved', $review );
		// El dueño la des-aprueba y re-aprueba: NO paga dos veces.
		$this->points->on_status_transition( 'approved', 'unapproved', $review );

		$this->assertSame( 500, Ledger::balance( 7 ) );
		$this->assertSame( array( 500 ), $this->wpdb->comment_meta[10][ ReviewPoints::META_POINTS_PAID ] );
	}

	/**
	 * Reapertura R4-144b3d33 (2026-09-19): esta prueba afirmaba justo el
	 * defecto («quitar la reseña no castiga el saldo»). El dueño SÍ puede
	 * retirar el premio: el patrón es el mismo que la reversa de pedidos
	 * (Accrual) y de referidos (Referrals) — débito con clave propia, topado
	 * al saldo, con un motivo legible en el ledger.
	 *
	 * @testdox Rechazar (o mandar a spam) una reseña que YA pagó revierte sus puntos, con un motivo legible en el ledger.
	 */
	public function test_unapproving_a_paid_review_reverses_its_points(): void {
		$review = $this->review_with_photo();
		$this->points->on_status_transition( 'approved', 'unapproved', $review );
		$this->assertSame( 500, Ledger::balance( 7 ) );

		$this->points->on_status_transition( 'unapproved', 'approved', $review );

		$this->assertSame( 0, Ledger::balance( 7 ), 'El dueño retiró la reseña: el premio no sobrevive.' );
		$nota = end( $this->wpdb->inserts )[1]['note'] ?? '';
		$this->assertSame( 'Reseña retirada', $nota, 'Motivo legible para el cliente en su historial.' );
		$this->assertSame(
			array( ReviewPoints::SEAL_REVERSED ),
			$this->wpdb->comment_meta[10][ ReviewPoints::META_POINTS_PAID ],
			'Sello REVERSIBLE: no la deja pagada, pero tampoco cierra la puerta a una re-aprobación.'
		);

		// Dos disparos del mismo rechazo (spam sobre un comentario ya
		// revertido) no debitan una segunda vez.
		$this->points->on_status_transition( 'spam', 'unapproved', $review );
		$this->assertSame( 0, Ledger::balance( 7 ) );
		$reversas = array_filter( $this->wpdb->inserts, static fn( array $x ): bool => 'review_reversal' === $x[1]['action'] );
		$this->assertCount( 1, $reversas, 'Sin doble descuento.' );
	}

	/**
	 * @testdox Enviar a la papelera una reseña que pagó también revierte sus puntos.
	 */
	public function test_trashing_a_paid_review_reverses_its_points(): void {
		$review = $this->review_with_photo();
		$this->points->on_status_transition( 'approved', 'unapproved', $review );
		$this->assertSame( 500, Ledger::balance( 7 ) );

		$this->points->on_status_transition( 'trash', 'approved', $review );

		$this->assertSame( 0, Ledger::balance( 7 ) );
	}

	/**
	 * `wp_delete_comment()` borra la commentmeta ANTES de disparar
	 * `deleted_comment`; `delete_comment` es el único punto donde el sello
	 * todavía se puede leer (verificado contra wp-includes/comment.php).
	 *
	 * @testdox Borrar en firme una reseña que pagó (delete_comment, ANTES de que WordPress borre su meta) también revierte sus puntos.
	 */
	public function test_hard_deleting_a_paid_review_reverses_its_points(): void {
		$review = $this->review_with_photo();
		$this->points->on_status_transition( 'approved', 'unapproved', $review );
		$this->assertSame( 500, Ledger::balance( 7 ) );

		$this->points->on_comment_deleting( (string) $review->comment_ID, $review );

		$this->assertSame( 0, Ledger::balance( 7 ) );
	}

	/**
	 * @testdox Una reseña que NUNCA pagó (sellada en 0, pendiente, o virgen) no tiene nada que revertir.
	 */
	public function test_unpaid_reviews_have_nothing_to_reverse(): void {
		// Sellada con 0 (cupón del tercero): trashearla no debe tocar el ledger.
		$conCupon = $this->review_with_photo( 10, 7, 777 );
		$this->wpdb->comment_meta[10]['cr_coupon_code'] = array( 'FOTO10-ABC' );
		$this->points->on_status_transition( 'approved', 'unapproved', $conCupon );
		$this->assertSame( array( 0 ), $this->wpdb->comment_meta[10][ ReviewPoints::META_POINTS_PAID ] );

		$this->points->on_status_transition( 'trash', 'approved', $conCupon );

		$this->assertCount( 0, $this->wpdb->inserts, 'Nada que pagó nunca es nada que revertir.' );

		// Virgen (jamás evaluada): borrarla tampoco dispara nada.
		$virgen = $this->review_with_photo( 11, 7, 778 );
		$this->points->on_comment_deleting( '11', $virgen );
		$this->assertCount( 0, $this->wpdb->inserts );
	}

	/**
	 * Segundo defecto de R4-144b3d33: si la reseña se RE-APRUEBA tras la
	 * reversa, vuelve a pagar UNA vez — la clave de evento original
	 * (`review_u{user}_p{product}`) ya vive en el ledger de la reseña
	 * retirada, y sin una clave por ocurrencia `Ledger::credit()` la trataría
	 * como «ya aplicada» para siempre.
	 *
	 * @testdox Re-aprobar la MISMA reseña tras una reversa vuelve a pagar (una vez, no dos).
	 */
	public function test_restoring_the_same_review_after_a_reversal_pays_again_once(): void {
		$review = $this->review_with_photo();
		$this->points->on_status_transition( 'approved', 'unapproved', $review );
		$this->points->on_status_transition( 'unapproved', 'approved', $review ); // Reversa.
		$this->assertSame( 0, Ledger::balance( 7 ) );

		$this->points->on_status_transition( 'approved', 'unapproved', $review ); // Re-aprobación.

		$this->assertSame( 500, Ledger::balance( 7 ), 'Vuelve a pagar: la reversa no cierra la puerta para siempre.' );
		$this->assertSame( array( 500 ), $this->wpdb->comment_meta[10][ ReviewPoints::META_POINTS_PAID ] );

		// Y esta segunda aprobación no se re-dispara sola: idempotente.
		$this->points->on_status_transition( 'approved', 'unapproved', $review );
		$this->assertSame( 500, Ledger::balance( 7 ), 'Sin doble cobro en el segundo pago.' );

		$claves = array_column( array_column( $this->wpdb->inserts, 1 ), 'event_key' );
		$this->assertContains( 'review_u7_p777', $claves );
		$this->assertContains( 'review_u7_p777_r1', $claves, 'La segunda acreditación lleva una clave DISTINTA de la primera.' );
	}

	/**
	 * El ciclo completo que myCred resuelve sin claves de idempotencia y nosotros
	 * con ellas: cada reversa y cada re-pago llevan clave PROPIA, o la segunda
	 * reversa se daría por «ya aplicada» y los 500 quedarían en la cuenta
	 * (revisión del tech lead, 2026-09-19).
	 *
	 * @testdox Aprobar → papelera → restaurar → papelera otra vez termina en cero, con cada paso anotado.
	 */
	public function test_a_double_trash_cycle_ends_at_zero_with_every_step_recorded(): void {
		$review = $this->review_with_photo();
		$this->points->on_status_transition( 'approved', 'unapproved', $review );
		$this->points->on_status_transition( 'trash', 'approved', $review );
		$this->points->on_status_transition( 'approved', 'trash', $review );
		$this->assertSame( 500, Ledger::balance( 7 ), 'Restaurada: vuelve a tener sus puntos.' );

		$this->points->on_status_transition( 'trash', 'approved', $review );

		$this->assertSame( 0, Ledger::balance( 7 ), 'La segunda retirada también descuenta.' );
		$this->assertSame( ReviewPoints::SEAL_REVERSED, $this->wpdb->comment_meta[10][ ReviewPoints::META_POINTS_PAID ][0] ?? null );

		$claves = array_column( array_column( $this->wpdb->inserts, 1 ), 'event_key' );
		foreach ( array( 'review_u7_p777', 'review_reversal_u7_p777_r0', 'review_u7_p777_r1', 'review_reversal_u7_p777_r1' ) as $clave ) {
			$this->assertContains( $clave, $claves, "Falta el movimiento «{$clave}»." );
		}
		$this->assertCount( 4, $claves, 'Cuatro movimientos: pago, reversa, re-pago, segunda reversa. Ni uno más.' );
	}

	/**
	 * Segundo defecto de R4-144b3d33: una reseña NUEVA y legítima del mismo
	 * tomo, tras la reversa de una reseña ANTERIOR (comentario distinto),
	 * también debe poder pagar.
	 *
	 * @testdox Tras revertir una reseña, una reseña NUEVA y distinta del mismo tomo sí puede pagar.
	 */
	public function test_a_new_review_of_the_same_product_pays_after_a_prior_one_was_reversed(): void {
		$primera = $this->review_with_photo( 10, 7, 777 );
		$this->points->on_status_transition( 'approved', 'unapproved', $primera );
		$this->points->on_status_transition( 'trash', 'approved', $primera ); // La retira el dueño.
		$this->assertSame( 0, Ledger::balance( 7 ) );

		$nueva = $this->review_with_photo( 12, 7, 777 );
		$this->points->on_status_transition( 'approved', 'unapproved', $nueva );

		$this->assertSame( 500, Ledger::balance( 7 ), 'La reseña legítima de reemplazo sí cobra.' );
		$this->assertSame( array( 500 ), $this->wpdb->comment_meta[12][ ReviewPoints::META_POINTS_PAID ] );
	}

	/**
	 * @testdox Si el cliente ya se gastó los puntos, la reversa cobra sólo lo que queda y avisa al dueño.
	 */
	public function test_a_reversal_clamps_to_the_available_balance_and_alerts_the_owner(): void {
		$review = $this->review_with_photo();
		$this->points->on_status_transition( 'approved', 'unapproved', $review );
		Ledger::debit( 7, 450, 'redemption', 'order', 999, 'Canjeados en un pedido' );
		$this->assertSame( 50, Ledger::balance( 7 ) );

		\Brain\Monkey\Actions\expectDone( 'akb_rewards_reversal_failed' )
			->once()
			->with( $review, 450 );

		$this->points->on_status_transition( 'unapproved', 'approved', $review );

		$this->assertSame( 0, Ledger::balance( 7 ), 'Nunca un saldo negativo: se clampa en 0.' );
	}

	/**
	 * @testdox La reversa de una reseña que pagó en su posición «N/N» libera el cupo para que la siguiente calificada pague.
	 */
	public function test_a_reversal_frees_the_cadence_slot_for_the_next_qualifying_review(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_review_every_n' === $option ? 3 : $default_value
		);

		foreach ( array( 10 => 771, 11 => 772, 12 => 773 ) as $cid => $pid ) {
			$review = $this->review_with_photo( $cid, 7, $pid );
			$this->points->on_status_transition( 'approved', 'unapproved', $review );
		}
		$this->assertSame( 500, Ledger::balance( 7 ), 'La 3ª (posición 3/3) pagó.' );
		$this->assertSame( 3, (int) $this->wpdb->user_meta[7]['_akb_rewards_review_qualifying'] );

		// El dueño retira la reseña que pagó: su posición se libera.
		$pagadora = $this->review_with_photo( 12, 7, 773 );
		$this->points->on_status_transition( 'trash', 'approved', $pagadora );

		$this->assertSame( 0, Ledger::balance( 7 ) );
		$this->assertSame(
			2,
			(int) $this->wpdb->user_meta[7]['_akb_rewards_review_qualifying'],
			'El contador de reseñas premiadas queda coherente: vuelve a "2 de 3".'
		);

		// La siguiente reseña calificada (producto nuevo) ocupa la 3ª posición y paga.
		$cuarta = $this->review_with_photo( 13, 7, 774 );
		$this->points->on_status_transition( 'approved', 'unapproved', $cuarta );

		$this->assertSame( 500, Ledger::balance( 7 ), 'La posición liberada la ocupa la siguiente calificada.' );
	}

	/**
	 * Defecto hallado en la revisión independiente del lote R4-144b3d33
	 * (2026-09-19): `maybe_reverse()` decrementaba el contador compartido
	 * pero NO borraba el `META_COUNTED` de la propia reseña. Si esa MISMA
	 * reseña (no una nueva) se re-aprobaba después, `maybe_pay()` volvía a
	 * leer el pin viejo y devolvía la misma posición SIN avanzar el
	 * contador, que quedaba congelado un paso atrás. La siguiente reseña
	 * calificada de un producto DISTINTO caía entonces en esa misma
	 * posición por aritmética y pagaba sin haber completado su propio
	 * ciclo — un pago no ganado.
	 *
	 * @testdox Restaurar la MISMA reseña de la posición N/N dentro de la cadencia deja el contador coherente: la siguiente reseña calificada NO paga antes de completar su propio ciclo.
	 */
	public function test_restoring_the_same_cadence_paying_review_does_not_desync_the_counter(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_review_every_n' === $option ? 3 : $default_value
		);

		// A, B, C: la 3ª (posición 3/3) paga.
		foreach ( array( 10 => 771, 11 => 772, 12 => 773 ) as $cid => $pid ) {
			$review = $this->review_with_photo( $cid, 7, $pid );
			$this->points->on_status_transition( 'approved', 'unapproved', $review );
		}
		$this->assertSame( 500, Ledger::balance( 7 ) );
		$this->assertSame( 3, (int) $this->wpdb->user_meta[7]['_akb_rewards_review_qualifying'] );

		// El dueño manda C a la papelera: reversa, y el cupo se libera.
		$tercera = $this->review_with_photo( 12, 7, 773 );
		$this->points->on_status_transition( 'trash', 'approved', $tercera );
		$this->assertSame( 0, Ledger::balance( 7 ) );
		$this->assertSame( 2, (int) $this->wpdb->user_meta[7]['_akb_rewards_review_qualifying'] );
		// Efecto secundario del mismo defecto: Disclosure ya no debe anunciar
		// «sumó para un premio» sobre un crédito que quedó revertido.
		$this->assertSame( '', Disclosure::notice_for( 12 ), 'El crédito se revirtió: sin aviso de incentivo.' );

		// El dueño restaura la MISMA reseña C (Papelera → Aprobado): vuelve a
		// pagar, y el contador debe quedar en 3 (no congelado en 2).
		$this->points->on_status_transition( 'approved', 'unapproved', $tercera );
		$this->assertSame( 500, Ledger::balance( 7 ), 'La restauración vuelve a pagar la misma reseña.' );
		$this->assertSame(
			3,
			(int) $this->wpdb->user_meta[7]['_akb_rewards_review_qualifying'],
			'El contador avanza con la restauración: no se queda congelado un paso atrás.'
		);
		$this->assertSame(
			3,
			(int) $this->wpdb->comment_meta[12][ ReviewPoints::META_COUNTED ][0],
			'La restauración recalcula la posición FRESCA (3) a partir del contador ya corregido, no repite el pin viejo sin más.'
		);

		// Una reseña NUEVA calificada, de OTRO producto: le toca la 1ª
		// posición de un ciclo nuevo y NO debe pagar todavía.
		$cuarta = $this->review_with_photo( 13, 7, 774 );
		$this->points->on_status_transition( 'approved', 'unapproved', $cuarta );

		$this->assertSame(
			500,
			Ledger::balance( 7 ),
			'La cuarta reseña calificada es la 1ª de un ciclo nuevo: no le toca pagar todavía.'
		);
		$this->assertSame( array( '1/3' ), $this->wpdb->comment_meta[13][ ReviewPoints::META_PROGRESS ] );
	}

	/**
	 * Efecto secundario del mismo defecto R4-144b3d33: `Disclosure::notice_for()`
	 * lee `META_COUNTED` para anunciar «sumó para un premio», y la moderación
	 * lee `META_PROGRESS` para mostrar el avance («2 de 3»). La reversa debe
	 * limpiar CUALQUIER resto de esas dos metas, no sólo la que decrementa el
	 * contador — de lo contrario una reseña retirada podía seguir mostrando
	 * un avance de cadencia que ya no es cierto.
	 *
	 * @testdox La reversa borra también un META_PROGRESS que hubiera quedado colgando en la reseña.
	 */
	public function test_reversal_also_clears_a_stale_progress_meta(): void {
		$review = $this->review_with_photo();
		$this->points->on_status_transition( 'approved', 'unapproved', $review );
		$this->assertSame( 500, Ledger::balance( 7 ) );

		// Resto de avance colgando (defensivo: el propio maybe_pay() nunca deja
		// las dos metas a la vez, pero la reversa debe limpiarla igual si estuviera).
		// META_COUNTED > 0 es lo que hace entrar a la rama de limpieza de cadencia.
		$this->wpdb->comment_meta[10][ ReviewPoints::META_COUNTED ]  = array( 1 );
		$this->wpdb->comment_meta[10][ ReviewPoints::META_PROGRESS ] = array( '2/3' );

		$this->points->on_status_transition( 'trash', 'approved', $review );

		$this->assertArrayNotHasKey(
			ReviewPoints::META_PROGRESS,
			$this->wpdb->comment_meta[10],
			'La reversa no deja avances de cadencia a medio limpiar.'
		);
	}

	/**
	 * @testdox El tope histórico por cliente cuenta créditos VIGENTES: una reseña revertida deja de ocupar cupo.
	 */
	public function test_the_per_customer_cap_forgets_a_reversed_review(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_review_max_per_user' === $option ? 1 : $default_value
		);

		$primera = $this->review_with_photo( 10, 7, 771 );
		$this->points->on_status_transition( 'approved', 'unapproved', $primera );
		$this->assertSame( 500, Ledger::balance( 7 ) );

		// El dueño la retira: el cupo del tope se libera.
		$this->points->on_status_transition( 'trash', 'approved', $primera );
		$this->assertSame( 0, Ledger::balance( 7 ) );

		// Otro producto sí paga: el tope ya no cuenta la reseña revertida.
		$segunda = $this->review_with_photo( 11, 7, 772 );
		$this->points->on_status_transition( 'approved', 'unapproved', $segunda );

		$this->assertSame( 500, Ledger::balance( 7 ), 'El tope histórico está coherente tras la reversa.' );
	}

	public function test_no_photo_no_points(): void {
		$review = new \WP_Comment(
			array(
				'comment_ID'      => 10,
				'comment_post_ID' => 777,
				'user_id'         => 7,
				'comment_type'    => 'review',
			)
		);

		$this->points->on_status_transition( 'approved', 'unapproved', $review );

		$this->assertSame( 0, Ledger::balance( 7 ) );
		$this->assertArrayNotHasKey( ReviewPoints::META_POINTS_PAID, $this->wpdb->comment_meta[10] ?? array() );
	}

	public function test_a_video_only_review_earns_nothing(): void {
		// La fila de media existe, pero el adjunto es un VIDEO: no vale para
		// el premio (decisión del dueño, heredada del gate del cupón).
		Functions\when( 'wp_attachment_is' )->alias(
			static fn( string $type, int $id ): bool => 'image' === $type && 903 !== $id && $id > 0
		);
		$this->wpdb->comment_meta[10][ Media::META_LOCAL_IMAGE ] = array( 903 );
		$review = new \WP_Comment(
			array(
				'comment_ID'      => 10,
				'comment_post_ID' => 777,
				'user_id'         => 7,
				'comment_type'    => 'review',
			)
		);

		$this->points->on_status_transition( 'approved', 'unapproved', $review );

		$this->assertSame( 0, Ledger::balance( 7 ) );
	}

	public function test_guests_without_account_get_a_reversible_seal(): void {
		$review = $this->review_with_photo( 10, 0 );
		$review->comment_author_email = 'invitado@example.com';

		$this->points->on_status_transition( 'approved', 'unapproved', $review );

		$this->assertCount( 0, $this->wpdb->inserts );
		// Sello REVERSIBLE: el gancho invitado→cuenta puede cobrarlo después.
		$this->assertSame( array( ReviewPoints::SEAL_PENDING_USER ), $this->wpdb->comment_meta[10][ ReviewPoints::META_POINTS_PAID ] );
	}

	public function test_a_reply_with_photo_never_earns_nor_burns_the_key(): void {
		// Una RESPUESTA en el hilo (confirmador 2a): ni cobra ni quema el
		// event_key del cliente para ese producto.
		$reply                 = $this->review_with_photo( 20, 7, 777 );
		$reply->comment_parent = 5;
		$this->points->on_status_transition( 'approved', 'unapproved', $reply );
		$this->assertSame( 0, Ledger::balance( 7 ) );

		// La reseña REAL posterior sí cobra: la clave no se quemó.
		$real = $this->review_with_photo( 21, 7, 777 );
		$this->points->on_status_transition( 'approved', 'unapproved', $real );
		$this->assertSame( 500, Ledger::balance( 7 ) );
	}

	public function test_a_verified_email_pays_the_pending_review(): void {
		// La reseña del invitado quedó pendiente…
		$review = $this->review_with_photo( 10, 0 );
		$review->comment_author_email = 'nueva@example.com';
		$this->points->on_status_transition( 'approved', 'unapproved', $review );
		$this->assertCount( 0, $this->wpdb->inserts );

		// …y al crear la cuenta con ESE email, cobra.
		Functions\when( 'get_user_by' )->alias(
			static function ( string $by, $value ) {
				if ( 'id' === $by ) {
					$u             = new \WP_User( 42 );
					$u->user_email = 'nueva@example.com';
					return $u;
				}
				if ( 'nueva@example.com' !== $value ) { return false; }
				$user = new \WP_User( 42 );
				$user->user_email = 'nueva@example.com';
				return $user;
			}
		);
		Functions\when( 'get_comments' )->justReturn( array( $review ) );

		// Con el aviso de incentivo encendido, la reseña ya se veía sin él: sin
		// cambio de estado del comentario, sólo esta purga retira la copia de
		// invitado de la ficha.
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_disclosure_enabled' === $option ? 'yes' : $default_value
		);
		Functions\when( 'delete_transient' )->justReturn( true );
		\Brain\Monkey\Actions\expectDone( 'litespeed_purge_all' )->once();

		$this->points->on_email_verified( 42 );

		$this->assertSame( 500, Ledger::balance( 42 ), 'El gancho invitado→cuenta paga tras VERIFICAR el email (cosechar registrando el email ajeno ya no basta).' );
		// Y el sello pendiente quedó resuelto a pagado.
		$this->assertSame( array( 500 ), $this->wpdb->comment_meta[10][ ReviewPoints::META_POINTS_PAID ] );
	}

	/**
	 * Reapertura S-0212815e (2026-09-19): la única prueba de este gancho
	 * dejaba el aviso de divulgación ENCENDIDO, que es justo la configuración
	 * donde `Disclosure::notice_for()` (el criterio crudo que usa
	 * `on_email_verified()`) y `visible_notice_for()` (el que respeta el
	 * interruptor) devuelven lo mismo — así que la prueba pasaba igual con el
	 * código viejo y el nuevo. La tienda tiene el aviso APAGADO (fábrica y
	 * decisión del dueño 2026-09-13): con esa configuración real, el pago
	 * tardío tiene que seguir purgando, porque el criterio de «fue
	 * incentivada» rige la exclusión de Google y la purga del pago tardío
	 * pase lo que pase con el AVISO.
	 *
	 * @testdox Con el aviso de incentivo APAGADO —como está la tienda— el pago tardío igual purga: el criterio es «fue incentivada», no «se muestra el aviso».
	 */
	public function test_the_late_payment_purges_with_the_notice_off(): void {
		$review = $this->review_with_photo( 10, 0 );
		$review->comment_author_email = 'nueva@example.com';
		$this->points->on_status_transition( 'approved', 'unapproved', $review );
		$this->assertCount( 0, $this->wpdb->inserts );

		Functions\when( 'get_user_by' )->alias(
			static function ( string $by, $value ) {
				if ( 'id' === $by ) {
					$u             = new \WP_User( 42 );
					$u->user_email = 'nueva@example.com';
					return $u;
				}
				if ( 'nueva@example.com' !== $value ) { return false; }
				$user = new \WP_User( 42 );
				$user->user_email = 'nueva@example.com';
				return $user;
			}
		);
		Functions\when( 'get_comments' )->justReturn( array( $review ) );

		// Aviso APAGADO: valor de fábrica y configuración real de la tienda
		// (get_option cae al default 'no' de Options::disclosure_on()).
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => $default_value
		);
		Functions\when( 'delete_transient' )->justReturn( true );
		\Brain\Monkey\Actions\expectDone( 'litespeed_purge_all' )->once();

		$this->points->on_email_verified( 42 );

		$this->assertSame( 500, Ledger::balance( 42 ) );
		$this->assertSame( array( 500 ), $this->wpdb->comment_meta[10][ ReviewPoints::META_POINTS_PAID ] );
	}

	/**
	 * Caso espejo, para que la purga total no se vuelva incondicional: si la
	 * verificación de correo NO paga ninguna reseña (no hay pendiente de esa
	 * cuenta), no hay ficha que haya cambiado y no hace falta purgar el
	 * sitio entero.
	 *
	 * @testdox Verificar el correo de una reseña pendiente que NO pasa a premiada no purga el sitio entero.
	 */
	public function test_a_verified_email_without_payment_never_purges(): void {
		Functions\when( 'get_user_by' )->alias(
			static function ( string $by, $value ) {
				if ( 'id' === $by ) {
					$u             = new \WP_User( 42 );
					$u->user_email = 'nueva@example.com';
					return $u;
				}
				if ( 'nueva@example.com' !== $value ) { return false; }
				$user = new \WP_User( 42 );
				$user->user_email = 'nueva@example.com';
				return $user;
			}
		);
		Functions\when( 'get_comments' )->justReturn( array() );
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => $default_value
		);

		\Brain\Monkey\Actions\expectDone( 'litespeed_purge_all' )->never();

		$this->points->on_email_verified( 42 );

		$this->assertSame( 0, Ledger::balance( 42 ) );
	}

	public function test_one_payment_per_customer_and_product_forever(): void {
		// Dos reseñas del MISMO cliente sobre el MISMO tomo: una paga.
		$primera = $this->review_with_photo( 10, 7, 777 );
		$segunda = $this->review_with_photo( 11, 7, 777 );

		$this->points->on_status_transition( 'approved', 'unapproved', $primera );
		$this->points->on_status_transition( 'approved', 'unapproved', $segunda );

		$this->assertSame( 500, Ledger::balance( 7 ), 'Re-reseñar el mismo producto no re-paga.' );
		$this->assertSame( array( 0 ), $this->wpdb->comment_meta[11][ ReviewPoints::META_POINTS_PAID ], 'La segunda queda sellada sin pago.' );

		// Otro PRODUCTO sí paga (compró otro tomo y lo reseñó).
		$otra = $this->review_with_photo( 12, 7, 888 );
		$this->points->on_status_transition( 'approved', 'unapproved', $otra );
		$this->assertSame( 1000, Ledger::balance( 7 ) );
	}

	public function test_a_born_approved_review_pays_via_the_insert_hooks(): void {
		// En prod comment_previously_approved=1 auto-aprueba: la transición
		// jamás corre. Los hooks de nacimiento cubren ese camino (ALTA 2a).
		$review                   = $this->review_with_photo();
		$review->comment_approved = '1';

		$this->points->on_comment_inserted( 10, $review );

		$this->assertSame( 500, Ledger::balance( 7 ) );

		// Y el mismo nacimiento re-procesado por comment_post no duplica.
		Functions\when( 'get_comment' )->justReturn( $review );
		$this->points->on_comment_born( 10, 1 );
		$this->assertSame( 500, Ledger::balance( 7 ) );
	}

	public function test_unverified_purchases_earn_nothing_by_default(): void {
		$review = $this->review_with_photo();
		$this->wpdb->comment_meta[10]['verified'] = array( '0' );

		$this->points->on_status_transition( 'approved', 'unapproved', $review );

		$this->assertSame( 0, Ledger::balance( 7 ), 'Sin compra verificada no hay premio.' );
	}

	public function test_the_verified_meta_is_computed_lazily_like_the_third_party(): void {
		$review = $this->review_with_photo();
		unset( $this->wpdb->comment_meta[10]['verified'] );
		$review->comment_author_email = 'cliente@example.com';

		Functions\when( 'wc_customer_bought_product' )->justReturn( true );

		$this->points->on_status_transition( 'approved', 'unapproved', $review );

		$this->assertSame( 500, Ledger::balance( 7 ) );
		$this->assertSame( array( 1 ), $this->wpdb->comment_meta[10]['verified'], 'El cálculo perezoso PERSISTE la meta, como CusRev.' );
	}

	public function test_a_coupon_rewarded_review_never_earns_points_too(): void {
		$review = $this->review_with_photo();
		$this->wpdb->comment_meta[10]['cr_coupon_code'] = array( 'FOTO10-ABC' );

		$this->points->on_status_transition( 'approved', 'unapproved', $review );

		$this->assertSame( 0, Ledger::balance( 7 ), 'Una recompensa por acción: cupón O puntos, jamás ambos.' );
	}

	public function test_reviews_on_unpublished_products_earn_nothing(): void {
		Functions\when( 'get_post_status' )->justReturn( 'draft' );
		$review = $this->review_with_photo();

		$this->points->on_status_transition( 'approved', 'unapproved', $review );

		$this->assertSame( 0, Ledger::balance( 7 ) );
	}

	public function test_the_author_email_resolves_the_account_for_guests(): void {
		Functions\when( 'get_user_by' )->alias(
			static function ( string $by, $email ) {
				$user = new \WP_User( 42 );
				$user->user_email = 'cliente@example.com';
				return 'cliente@example.com' === $email ? $user : false;
			}
		);

		$review = $this->review_with_photo( 10, 0 );
		$review->comment_author_email = 'cliente@example.com';

		$this->points->on_status_transition( 'approved', 'unapproved', $review );

		$this->assertSame( 500, Ledger::balance( 42 ) );
	}

	public function test_admin_created_reviews_never_pay_the_store_itself(): void {
		$review = $this->review_with_photo();
		$this->wpdb->comment_meta[10][ AdminReviews::META_ADMIN_CREATED ] = array( 1 );

		$this->points->on_status_transition( 'approved', 'unapproved', $review );

		$this->assertSame( 0, Ledger::balance( 7 ) );
	}

	public function test_only_reviews_on_products_qualify(): void {
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		$review = $this->review_with_photo();

		$this->points->on_status_transition( 'approved', 'unapproved', $review );

		$this->assertSame( 0, Ledger::balance( 7 ) );
	}

	public function test_other_comment_types_and_statuses_are_ignored(): void {
		$qna               = $this->review_with_photo();
		$qna->comment_type = 'cr_qna';
		$this->points->on_status_transition( 'approved', 'unapproved', $qna );

		$review = $this->review_with_photo();
		$this->points->on_status_transition( 'unapproved', 'approved', $review );
		$this->points->on_status_transition( 'trash', 'approved', $review );

		$this->assertSame( 0, Ledger::balance( 7 ) );
	}

	public function test_every_n_pays_only_the_nth_qualifying_review(): void {
		// «500 cada tres reseñas» (directiva del dueño): la 1ª y la 2ª marcan
		// avance, la 3ª paga. Tres productos distintos, mismo cliente.
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_review_every_n' === $option ? 3 : $default_value
		);

		foreach ( array( 10 => 771, 11 => 772, 12 => 773 ) as $cid => $pid ) {
			$review = $this->review_with_photo( $cid, 7, $pid );
			$this->points->on_status_transition( 'approved', 'unapproved', $review );
		}

		$this->assertSame( 500, Ledger::balance( 7 ), 'Solo la tercera calificada paga.' );
		$this->assertSame( array( '1/3' ), $this->wpdb->comment_meta[10]['_akb_rewards_review_progress'], 'La primera muestra su avance.' );
		$this->assertSame( array( '2/3' ), $this->wpdb->comment_meta[11]['_akb_rewards_review_progress'] );
		$this->assertSame( array( 500 ), $this->wpdb->comment_meta[12][ ReviewPoints::META_POINTS_PAID ] );
	}

	public function test_withdrawing_an_unpaid_cadence_review_removes_its_progress(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_review_every_n' === $option ? 3 : $default_value
		);
		$first = $this->review_with_photo( 10, 7, 771 );
		$this->points->on_status_transition( 'approved', 'unapproved', $first );
		$this->points->on_status_transition( 'unapproved', 'approved', $first );
		$this->points->on_status_transition( 'trash', 'unapproved', $first );
		$this->assertSame( 0, (int) $this->wpdb->user_meta[7]['_akb_rewards_review_qualifying'] );
		$this->assertArrayNotHasKey( ReviewPoints::META_COUNTED, $this->wpdb->comment_meta[10] );
		$this->assertArrayNotHasKey( ReviewPoints::META_PROGRESS, $this->wpdb->comment_meta[10] );
		foreach ( array( 11 => 772, 12 => 773 ) as $cid => $pid ) {
			$this->points->on_status_transition( 'approved', 'unapproved', $this->review_with_photo( $cid, 7, $pid ) );
		}
		$this->assertSame( 0, Ledger::balance( 7 ), 'Dos reseñas aprobadas no completan tres.' );
		$this->points->on_status_transition( 'approved', 'unapproved', $this->review_with_photo( 13, 7, 774 ) );
		$this->assertSame( 500, Ledger::balance( 7 ) );
		$this->points->on_status_transition( 'approved', 'trash', $first );
		$this->points->on_status_transition( 'approved', 'unapproved', $first );
		$this->assertSame( 4, (int) $this->wpdb->user_meta[7]['_akb_rewards_review_qualifying'] );
		$this->assertSame( 500, Ledger::balance( 7 ), 'Restaurar la primera no duplica el premio.' );
	}

	/** Una baja intermedia no vuelve a pagar un ciclo cuyo premio sigue vigente. */
	public function test_withdrawing_an_intermediate_after_payment_does_not_pay_the_cycle_twice(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_review_every_n' === $option ? 3 : $default_value
		);
		$first = $this->review_with_photo( 10, 7, 770 );
		$this->points->on_status_transition( 'approved', 'unapproved', $first );
		foreach ( array( 11, 12 ) as $id ) {
			$this->points->on_status_transition( 'approved', 'unapproved', $this->review_with_photo( $id, 7, 760 + $id ) );
		}
		$this->assertSame( 500, Ledger::balance( 7 ) );
		$this->points->on_status_transition( 'unapproved', 'approved', $first );
		foreach ( array( 13, 14, 15 ) as $id ) {
			$this->points->on_status_transition( 'approved', 'unapproved', $this->review_with_photo( $id, 7, 760 + $id ) );
			$this->assertSame( 500, Ledger::balance( 7 ), 'Reponer una intermedia no crea otro ciclo pagable.' );
		}
		$this->points->on_status_transition( 'approved', 'unapproved', $this->review_with_photo( 16, 7, 776 ) );
		$this->assertSame( 1000, Ledger::balance( 7 ), 'Seis reseñas vigentes permiten dos premios.' );
	}

	public function test_a_busy_lock_preserves_cadence_cleanup_for_reapproval(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_review_every_n' === $option ? 3 : $default_value
		);
		$review = $this->review_with_photo();
		$this->points->on_status_transition( 'approved', 'unapproved', $review );
		$this->wpdb->lock_result = '0';
		$this->points->on_status_transition( 'unapproved', 'approved', $review );
		$this->assertSame( 1, (int) $this->wpdb->user_meta[7]['_akb_rewards_review_qualifying'] );
		$this->assertArrayHasKey( ReviewPoints::META_COUNTED, $this->wpdb->comment_meta[10] );
		$this->assertSame( array( ReviewPoints::SEAL_REVERSED ), $this->wpdb->comment_meta[10][ ReviewPoints::META_POINTS_PAID ] );
		$this->wpdb->lock_result = '1';
		$this->points->on_status_transition( 'approved', 'unapproved', $review );
		$this->assertSame( 1, (int) $this->wpdb->user_meta[7]['_akb_rewards_review_qualifying'] );
		$this->assertSame( array( '1/3' ), $this->wpdb->comment_meta[10][ ReviewPoints::META_PROGRESS ] );
		$this->assertSame( 0, Ledger::balance( 7 ) );
		$this->points->on_status_transition( 'unapproved', 'approved', $review );
		$this->assertSame( 0, (int) $this->wpdb->user_meta[7]['_akb_rewards_review_qualifying'] );
	}

	public function test_the_per_customer_cap_stops_further_rewards(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_review_max_per_user' === $option ? 1 : $default_value
		);

		$primera = $this->review_with_photo( 10, 7, 771 );
		$this->points->on_status_transition( 'approved', 'unapproved', $primera );
		$this->assertSame( 500, Ledger::balance( 7 ) );

		// Otro producto, cliente en su tope: sellada sin pago.
		$segunda = $this->review_with_photo( 11, 7, 772 );
		$this->points->on_status_transition( 'approved', 'unapproved', $segunda );

		$this->assertSame( 500, Ledger::balance( 7 ), 'El tope histórico por cliente manda.' );
		$this->assertSame( array( 0 ), $this->wpdb->comment_meta[11][ ReviewPoints::META_POINTS_PAID ] );
	}

	public function test_a_zero_configuration_turns_the_program_off(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_review_points' === $option ? 0 : $default_value
		);

		$review = $this->review_with_photo();
		$this->points->on_status_transition( 'approved', 'unapproved', $review );

		$this->assertSame( 0, Ledger::balance( 7 ) );
	}

	public function test_the_birth_double_fire_consumes_one_cadence_slot_and_retries_the_pay(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_review_every_n' === $option ? 3 : $default_value
		);
		$this->wpdb->user_meta[9]['_akb_rewards_review_qualifying'] = 2;
		$comment = $this->review_with_photo( 501, 9, 77 );

		// Doble disparo del nacimiento (wp_insert_comment + comment_post):
		// la reseña queda FIJADA en la posición 3 y paga UNA vez.
		$this->points->on_status_transition( 'approved', 'unapproved', $comment );
		$this->points->on_status_transition( 'approved', 'unapproved', $comment );

		$this->assertSame( 3, (int) $this->wpdb->user_meta[9]['_akb_rewards_review_qualifying'], 'Una reseña = una posición.' );
		$this->assertSame( 3, (int) ( ( (array) ( $this->wpdb->comment_meta[501]['_akb_rewards_review_counted'] ?? array() ) )[0] ?? 0 ) );
		$this->assertSame( 500, Ledger::balance( 9 ), 'La tercera reseña paga, sin doble cobro.' );
	}

	public function test_a_busy_lock_leaves_the_cadence_review_retryable(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_review_every_n' === $option ? 3 : $default_value
		);
		$this->wpdb->user_meta[9]['_akb_rewards_review_qualifying'] = 2;
		$comment = $this->review_with_photo( 502, 9, 78 );

		$this->wpdb->lock_result = '0'; // Candado ocupado (captura concurrente).
		$this->points->on_status_transition( 'approved', 'unapproved', $comment );

		$this->assertArrayNotHasKey( '_akb_rewards_points_paid', $this->wpdb->comment_meta[502] ?? array(), 'Sin sello: reintenta.' );
		$this->assertSame( 2, (int) $this->wpdb->user_meta[9]['_akb_rewards_review_qualifying'], 'Sin contar: la posición no se quema.' );

		$this->wpdb->lock_result = '1'; // El candado se libera: el re-disparo cobra.
		$this->points->on_status_transition( 'approved', 'unapproved', $comment );
		$this->assertSame( 500, Ledger::balance( 9 ) );
	}
	/** @testdox Una reseña normal con foto no cobra al aprobarla, reintentar ni verificar la cuenta. */
	public function test_explicit_no_request_never_pays_or_creates_pending_reward(): void {
		$review = $this->review_with_photo( 10, 0 );
		$review->comment_author_email = 'cliente@example.com';
		$this->wpdb->comment_meta[10][ ReviewPoints::META_POINTS_REQUESTED ] = array( 0 );
		\Brain\Monkey\Actions\expectDone( 'akb_rewards_review_points_paid' )->never();
		$this->points->on_status_transition( 'approved', 'unapproved', $review );
		$this->assertSame( '', get_comment_meta( 10, ReviewPoints::META_POINTS_PAID, true ) );
		$user = new \WP_User( 42 );
		$user->user_email = 'cliente@example.com';
		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'get_comments' )->justReturn( array( $review ) );
		$this->points->on_email_verified( 42 );
		$this->points->maybe_pay( $review );
		$this->assertSame( 0, Ledger::balance( 42 ) );
		$this->assertSame( '', get_comment_meta( 10, ReviewPoints::META_POINTS_PAID, true ) );
	}

	/** @testdox Optar al premio exige verificar la cuenta de invitado aunque el administrador reintente. */
	public function test_requested_guest_waits_for_real_email_verification_then_pays_once(): void {
		$review = $this->review_with_photo( 10, 0 );
		$review->comment_author_email = 'cliente@example.com';
		$this->wpdb->comment_meta[10][ ReviewPoints::META_POINTS_REQUESTED ] = array( 1 );
		$this->points->on_status_transition( 'approved', 'unapproved', $review );
		$this->assertSame( ReviewPoints::SEAL_PENDING_USER, get_comment_meta( 10, ReviewPoints::META_POINTS_PAID, true ) );
		$user = new \WP_User( 42 );
		$user->user_email = 'cliente@example.com';
		Functions\when( 'get_user_by' )->justReturn( $user );
		$service = new class { public bool $verified = false; public function is_verified( int $id ): bool { return $this->verified; } };
		$container = new class( $service ) { private object $service; public function __construct( object $service ) { $this->service = $service; } public function get( string $class ): object { return $this->service; } };
		Functions\when( 'wc_get_container' )->justReturn( $container );
		$this->points->maybe_pay( $review );
		$this->points->on_status_transition( 'approved', 'unapproved', $review );
		$this->assertSame( 0, Ledger::balance( 42 ) );
		$this->assertSame( ReviewPoints::SEAL_PENDING_USER, get_comment_meta( 10, ReviewPoints::META_POINTS_PAID, true ) );
		$service->verified = true;
		Functions\when( 'get_comments' )->justReturn( array( $review ) );
		Functions\when( 'delete_transient' )->justReturn( true );
		\Brain\Monkey\Actions\expectDone( 'akb_rewards_review_points_paid' )->once();
		$this->points->on_email_verified( 42 );
		$this->points->maybe_pay( $review );
		$this->assertSame( 500, Ledger::balance( 42 ) );
	}

	/** @testdox Las reseñas históricas sin la nueva meta conservan la política ofrecida. */
	public function test_legacy_review_keeps_reward_without_new_opt_in_meta(): void {
		$review = $this->review_with_photo();
		$this->assertSame( '', get_comment_meta( 10, ReviewPoints::META_POINTS_REQUESTED, true ) );
		$this->points->maybe_pay( $review );
		$this->points->maybe_pay( $review );
		$this->assertSame( 500, Ledger::balance( 7 ) );
	}

}
