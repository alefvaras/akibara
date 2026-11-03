<?php
/**
 * Contrato de privacidad del ledger (RX fiabilidad 2026-08-31).
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Support;

use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Support\Privacy;
use Brain\Monkey\Functions;

/**
 * @since 0.3.0
 */
final class PrivacyTest extends RewardsTestCase {

	public function test_deleting_a_user_freezes_their_identity_in_the_ledger(): void {
		Functions\when( 'get_userdata' )->alias( static function ( int $id ) {
			$u             = new \WP_User( $id );
			$u->user_email = 'clienta@correo.cl';
			return $u;
		} );

		( new Privacy() )->stamp_identity( 9 );

		$sql = implode( ' | ', $this->wpdb->queries );
		$this->assertStringContainsString( 'UPDATE wp_akb_rewards_ledger', $sql );
		$this->assertStringContainsString( 'cuenta borrada: clienta@correo.cl', $sql, 'La identidad queda congelada en las filas ANTES del borrado.' );
	}

	public function test_the_exporter_keeps_going_until_the_history_is_complete(): void {
		Functions\when( 'get_user_by' )->alias( static function ( string $field, string $email ) {
			unset( $field, $email );
			$u             = new \WP_User( 9 );
			$u->user_email = 'clienta@correo.cl';
			return $u;
		} );

		$fila = static fn( int $i ): array => array(
			'created_at' => '2026-08-30 12:00:00',
			'delta'      => $i,
			'note'       => 'Movimiento ' . $i,
		);

		// 205 movimientos: una página llena y una corta.
		$this->wpdb->results_answers['OFFSET 0']   = array_map( $fila, range( 1, 200 ) );
		$this->wpdb->results_answers['OFFSET 200'] = array_map( $fila, range( 201, 205 ) );

		$privacy = new Privacy();

		$p1 = $privacy->export( 'clienta@correo.cl', 1 );
		$this->assertFalse( $p1['done'], 'Una página llena NO cierra la exportación: quedaba historial afuera.' );
		$this->assertCount( 201, $p1['data'], 'Saldo + 200 movimientos.' );
		$this->assertSame( 'akb-rewards-balance', $p1['data'][0]['item_id'] );

		$p2 = $privacy->export( 'clienta@correo.cl', 2 );
		$this->assertTrue( $p2['done'], 'La página corta cierra.' );
		$this->assertCount( 5, $p2['data'], 'El saldo va sólo en la primera página.' );
		$this->assertSame( 'akb-rewards-mov-200', $p2['data'][0]['item_id'], 'Los ids siguen la numeración global, no reinician.' );
	}

	public function test_the_eraser_declares_the_ledger_retained_never_silent(): void {
		Functions\when( 'get_user_by' )->alias( static function ( string $field, string $email ) {
			unset( $field, $email );
			$u             = new \WP_User( 9 );
			$u->user_email = 'clienta@correo.cl';
			return $u;
		} );
		Ledger::credit( 9, 100, 'order', 'order', 1 );

		$res = ( new Privacy() )->erase( 'clienta@correo.cl' );

		$this->assertFalse( $res['items_removed'], 'El registro contable no se borra.' );
		$this->assertTrue( $res['items_retained'] );
		$this->assertStringContainsString( 'registro contable', (string) $res['messages'][0] );
	}

	/**
	 * Un derecho de acceso que devuelve la mitad de lo guardado no es un
	 * derecho de acceso: el cumpleaños, el código de padrino, quién lo invitó
	 * y las fotos de sus reseñas se guardaban y no se entregaban (hallazgos
	 * S-1c3746bb y S-4365d38c, 2026-09-18).
	 *
	 * @testdox El exportador entrega todo lo que el programa guarda sobre la persona.
	 */
	public function test_the_exporter_hands_over_everything_it_keeps(): void {
		$this->wpdb->users[9] = array( 'user_email' => 'clienta@correo.cl' );
		$this->wpdb->user_meta[9]['_akb_rewards_birthday_md']  = '03-14';
		$this->wpdb->user_meta[9]['_akb_rewards_ref_code']     = 'AKB-CLIENTA';
		$this->wpdb->user_meta[9]['_akb_rewards_referred_by']  = 77;

		Functions\when( 'get_userdata' )->alias( static function ( int $id ) {
			$u               = new \WP_User( $id );
			$u->display_name = 'Rodrigo';
			return $u;
		} );
		Functions\when( 'get_comments' )->justReturn( array( 501 ) );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://akibara.test/foto-de-la-resena.jpg' );
		$this->wpdb->comment_meta[501]['ivole_review_image2'] = array( 8801 );

		$campos = ( new Privacy() )->export( 'clienta@correo.cl', 1 );

		$perfil = array();
		foreach ( $campos['data'][0]['data'] as $par ) {
			$perfil[ $par['name'] ] = $par['value'];
		}

		self::assertArrayHasKey( 'Saldo de puntos', $perfil );
		self::assertSame( '14 de marzo', $perfil['Día de cumpleaños registrado'], 'El formato interno no le dice nada a nadie.' );
		self::assertSame( 'AKB-CLIENTA', $perfil['Tu código para invitar amigos'] );
		self::assertSame( 'Rodrigo', $perfil['Llegó invitado por'], 'El nombre público del padrino, nunca su correo.' );

		$fotos = array_filter( $campos['data'], static fn( array $i ): bool => str_starts_with( (string) $i['item_id'], 'akb-rewards-foto-' ) );
		self::assertCount( 1, $fotos, 'La foto es lo más personal de la reseña y era justo lo que no se entregaba.' );
		self::assertSame( 'https://akibara.test/foto-de-la-resena.jpg', array_values( $fotos )[0]['data'][0]['value'] );
	}

	/** @testdox A quién invitó esta persona NO se exporta: son terceros. */
	public function test_the_exporter_never_lists_third_parties(): void {
		$this->wpdb->users[9] = array( 'user_email' => 'clienta@correo.cl' );
		$this->wpdb->user_meta[9]['_akb_rewards_ref_code'] = 'AKB-CLIENTA';

		$campos = ( new Privacy() )->export( 'clienta@correo.cl', 1 );
		$texto  = wp_json_encode( $campos );

		self::assertStringNotContainsString( 'invitó a', (string) $texto );
		self::assertStringNotContainsString( 'ahijado', (string) $texto );
	}

	/**
	 * El borrador conservaba el cumpleaños y el vínculo de referido sin
	 * decirlo, y su único mensaje hablaba del historial contable — de modo que
	 * lo retenido de verdad quedaba fuera de la respuesta (S-24de6093).
	 *
	 * @testdox El borrador borra lo que describe a la persona y dice qué retiene.
	 */
	public function test_the_eraser_removes_the_personal_and_declares_the_rest(): void {
		$this->wpdb->users[9] = array( 'user_email' => 'clienta@correo.cl' );
		$this->wpdb->user_meta[9]['_akb_rewards_birthday_md']       = '03-14';
		$this->wpdb->user_meta[9]['_akb_rewards_ref_code']          = 'AKB-CLIENTA';
		$this->wpdb->user_meta[9]['_akb_rewards_referred_by']       = 77;
		$this->wpdb->user_meta[9]['_akb_rewards_welcome_paid']      = 1;
		$this->wpdb->user_meta[9]['_akb_rewards_birthday_paid_year'] = 2026;

		$res = ( new Privacy() )->erase( 'clienta@correo.cl' );

		self::assertTrue( $res['items_removed'] );
		self::assertArrayNotHasKey( '_akb_rewards_birthday_md', $this->wpdb->user_meta[9], 'El día de cumpleaños es dato personal puro.' );
		self::assertArrayNotHasKey( '_akb_rewards_ref_code', $this->wpdb->user_meta[9] );
		self::assertArrayNotHasKey( '_akb_rewards_referred_by', $this->wpdb->user_meta[9], 'El vínculo describe una relación entre dos personas.' );

		// Los sellos antifraude se quedan: sin ellos el mismo premio se paga
		// dos veces, y el derecho del cliente se vuelve una palanca de fraude.
		self::assertSame( 1, $this->wpdb->user_meta[9]['_akb_rewards_welcome_paid'] );
		self::assertSame( 2026, $this->wpdb->user_meta[9]['_akb_rewards_birthday_paid_year'] );

		$dicho = implode( ' ', $res['messages'] );
		self::assertStringContainsString( 'cumpleaños', $dicho, 'Lo borrado se nombra.' );
		self::assertStringContainsString( 'no se pague dos veces', $dicho, 'Y lo retenido se justifica.' );
		self::assertTrue( $res['items_retained'] );
	}

	/** @testdox Una solicitud de un correo que no es cliente no inventa nada. */
	public function test_an_unknown_email_returns_an_empty_answer(): void {
		$res = ( new Privacy() )->erase( 'nadie@correo.cl' );

		self::assertFalse( $res['items_removed'] );
		self::assertFalse( $res['items_retained'] );
		self::assertSame( array(), $res['messages'] );
		self::assertTrue( $res['done'] );
	}

	/**
	 * La purga consciente (`AKIBARA_REWARDS_ALLOW_WIPE`) enumeraba tres
	 * usermeta de catorce y olvidaba una tabla entera (S-64da5c8d).
	 *
	 * @testdox La purga del desinstalador no deja atrás tablas ni metas.
	 */
	public function test_the_uninstall_wipe_leaves_nothing_behind(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );

		self::assertStringContainsString( 'DROP TABLE IF EXISTS {$wpdb->prefix}akb_rewards_ledger', $fuente );
		self::assertStringContainsString( 'DROP TABLE IF EXISTS {$wpdb->prefix}akb_rewards_review_forms', $fuente, 'La tabla de enlaces de reseña es tan propia como el ledger.' );
		self::assertMatchesRegularExpression(
			'/DELETE FROM \{\$wpdb->usermeta\} WHERE meta_key LIKE/',
			$fuente,
			'Por prefijo: una lista de claves deja fuera las que nazcan después.'
		);
		self::assertStringNotContainsString(
			"meta_key IN (",
			$fuente,
			'La enumeración es justo lo que dejó ocho metas vivas.'
		);
	}

	/**
	 * El informe oficial de borrado le dice al titular que el historial se
	 * conserva SIN su identidad. `stamp_identity()`, colgado de `delete_user`,
	 * estampaba su correo en claro en cada fila en cuanto el dueño eliminaba
	 * la cuenta: la promesa y el código decían lo contrario (revisión
	 * independiente previa al despliegue, 2026-09-19).
	 *
	 * @testdox Tras una solicitud de supresión, borrar la cuenta NO estampa el correo en el historial.
	 */
	public function test_an_erasure_request_keeps_the_email_out_of_the_ledger(): void {
		$this->wpdb->users[9] = array( 'user_email' => 'clienta@correo.cl' );
		Functions\when( 'get_userdata' )->alias( static function ( int $id ) {
			$u             = new \WP_User( $id );
			$u->user_email = 'clienta@correo.cl';
			return $u;
		} );

		$privacy = new Privacy();
		$privacy->erase( 'clienta@correo.cl' );

		self::assertNotSame( '', (string) ( $this->wpdb->user_meta[9][ Privacy::META_SUPRESION ] ?? '' ), 'La supresión deja su sello.' );

		$privacy->stamp_identity( 9 );

		$sql = implode( ' | ', $this->wpdb->queries );
		self::assertStringContainsString( 'UPDATE wp_akb_rewards_ledger', $sql );
		self::assertStringNotContainsString( 'clienta@correo.cl', $sql, 'Lo que el informe prometió, el código lo cumple.' );
		self::assertStringContainsString( 'a petición de su titular', $sql );
	}

	/** @testdox El mensaje del borrador ya no promete una desvinculación que dependa de otro paso. */
	public function test_the_eraser_sentence_is_true_on_its_own(): void {
		$this->wpdb->users[9] = array( 'user_email' => 'clienta@correo.cl' );
		Ledger::credit( 9, 100, 'order', 'order', 1 );

		$dicho = implode( ' ', ( new Privacy() )->erase( 'clienta@correo.cl' )['messages'] );

		self::assertStringContainsString( 'queda sin tu correo', $dicho );
		self::assertStringNotContainsString( 'la identidad queda desvinculada', $dicho );
	}

	/**
	 * El formulario de reseña por enlace acepta compradores invitados, que son
	 * buena parte de los pedidos. El exportador respondía vacío en cuanto no
	 * había cuenta, y con eso negaba la foto —el dato más personal de la
	 * reseña— a quien más probablemente la subió sin registrarse.
	 *
	 * @testdox Un comprador invitado también recibe las fotos de sus reseñas.
	 */
	public function test_a_guest_gets_their_review_photos(): void {
		Functions\when( 'get_comments' )->justReturn( array( 501 ) );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://akibara.test/foto-invitada.jpg' );
		$this->wpdb->comment_meta[501]['ivole_review_image2'] = array( 8801 );

		$res = ( new Privacy() )->export( 'invitada@correo.cl', 1 );

		self::assertTrue( $res['done'] );
		self::assertCount( 1, $res['data'] );
		self::assertSame( 'https://akibara.test/foto-invitada.jpg', $res['data'][0]['data'][0]['value'] );

		// Y el borrador no calla lo que se queda.
		$borrado = ( new Privacy() )->erase( 'invitada@correo.cl' );
		self::assertTrue( $borrado['items_retained'] );
		self::assertStringContainsString( 'foto publicada', implode( ' ', $borrado['messages'] ) );
	}

	/** @testdox La purga consentida conserva la lista de quienes pidieron no recibir invitaciones. */
	public function test_the_wipe_keeps_the_opt_out_list(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );

		self::assertStringContainsString(
			"option_name <> 'akb_rewards_invite_optout'",
			$fuente,
			'Son huellas sin identidad y lo único que impide que una reinstalación le escriba a quien pidió que no.'
		);
	}
}
