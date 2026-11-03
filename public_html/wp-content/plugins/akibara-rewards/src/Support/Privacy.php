<?php
/**
 * Privacy — qué pasa con los puntos cuando una CUENTA se borra o pide sus
 * datos.
 *
 * Preservación de trazabilidad contable en el ledger al eliminar usuarios:
 * congela la identidad en la fila del asiento para mantener la inteligibilidad contable.
 * Integra con el sistema estándar de privacidad de WordPress (Herramientas -> Exportar/Borrar datos personales).
 *
 * El exportador entrega saldo, historial, cumpleaños, código de padrino,
 * vínculo de invitación y fotos adjuntas a las reseñas de la persona.
 *
 * El borrador elimina lo que sólo describe a la persona —el
 * día de su cumpleaños, su código de padrino y quién la invitó— y RETIENE,
 * diciéndolo, dos cosas distintas: el historial de puntos, que es registro
 * contable (respaldo de descuentos ya cobrados), y los sellos de «ya se pagó»
 * del bono, del cumpleaños y del premio de referido, que no llevan identidad y
 * sin los cuales el mismo premio se volvería a pagar.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

use Akibara\Rewards\Points\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.0
 */
final class Privacy {

	/**
	 * Cuelga los hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'delete_user', array( $this, 'stamp_identity' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Congela la identidad del cliente en sus filas del ledger ANTES de que
	 * WordPress lo borre (delete_user corre antes del borrado real).
	 *
	 * @param int $user_id Usuario a borrar.
	 * @return void
	 */
	public function stamp_identity( $user_id ): void {
		$user = get_userdata( (int) $user_id );
		if ( false === $user || '' === (string) $user->user_email ) {
			return;
		}

		// Si el usuario solicitó supresión formal de datos, no se estampa el correo en el historial.
		$marca = '' !== (string) get_user_meta( (int) $user_id, self::META_SUPRESION, true )
			? ' [cuenta borrada a petición de su titular]'
			: ' [cuenta borrada: ' . (string) $user->user_email . ']';

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabla propia; corre una vez, al borrar la cuenta.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Schema::ledger_table() . ' SET note = SUBSTRING(CONCAT(note, %s), 1, 255) WHERE user_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla propia.
				$marca,
				(int) $user_id
			)
		);
	}

	/**
	 * Registra el exportador del flujo de privacidad del core.
	 *
	 * @param array<string,array<string,mixed>> $exporters Exportadores.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_exporter( $exporters ): array {
		$exporters['akibara-rewards'] = array(
			'exporter_friendly_name' => 'Puntos Akibara',
			'callback'               => array( $this, 'export' ),
		);
		return (array) $exporters;
	}

	/**
	 * Registra el borrador del flujo de privacidad del core.
	 *
	 * @param array<string,array<string,mixed>> $erasers Borradores.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_eraser( $erasers ): array {
		$erasers['akibara-rewards'] = array(
			'eraser_friendly_name' => 'Puntos Akibara',
			'callback'             => array( $this, 'erase' ),
		);
		return (array) $erasers;
	}

	/**
	 * Movimientos por página del exportador. El core vuelve a llamar con
	 * $page+1 mientras `done` sea false.
	 */
	private const PER_PAGE = 200;

	public const CUMPLEANOS = '_akb_rewards_birthday_md';

	/**
	 * Sello de «este titular pidió la supresión de sus datos» (fecha Unix).
	 *
	 * No describe a la persona: existe para que `stamp_identity()` cumpla lo
	 * que el informe de borrado prometió si después se elimina la cuenta.
	 */
	public const META_SUPRESION = '_akb_rewards_privacy_erased';

	/**
	 * Lo que el programa guarda SOBRE la persona, dicho como lo diría ella.
	 *
	 * Lista común al exportador y al borrador para mantener la cobertura de
	 * datos personales en ambos flujos.
	 *
	 * @var array<string,string>
	 */
	private const CAMPOS_PERSONALES = array(
		self::CUMPLEANOS             => 'Día de cumpleaños registrado',
		'_akb_rewards_ref_code'      => 'Tu código para invitar amigos',
	);

	/**
	 * Sellos de «ya se pagó»: se RETIENEN y se declaran.
	 *
	 * No llevan identidad —son un año, un id de pedido o un sí— y son lo único
	 * que impide que el mismo bono, el mismo regalo de cumpleaños o el mismo
	 * premio de referido se vuelvan a pagar. Borrarlos con la solicitud de
	 * borrado convertiría el derecho del cliente en una palanca de fraude.
	 *
	 * @var array<int,string>
	 */
	private const SELLOS = array(
		'_akb_rewards_welcome_paid',
		'_akb_rewards_birthday_paid_year',
		'_akb_rewards_referral_order',
	);

	/**
	 * Exporta saldo e historial de puntos del correo solicitado.
	 *
	 * PAGINADO de verdad: cortar en 200 y declarar `done = true` entregaba un
	 * historial incompleto en silencio, que es justo lo que un derecho de
	 * acceso no admite. El saldo va sólo en la primera página.
	 *
	 * @param string $email Correo de la solicitud.
	 * @param int    $page  Página (1-based) que pide el core.
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export( $email, $page = 1 ): array {
		$page = max( 1, (int) $page );
		$user = get_user_by( 'email', (string) $email );
		if ( false === $user ) {
			// Compradores invitados pueden tener fotos asociadas a sus reseñas aunque no tengan cuenta activa.
			return array(
				'data' => 1 === $page ? self::items_de_fotos( (string) $email, 0 ) : array(),
				'done' => true,
			);
		}

		global $wpdb;

		$offset = ( $page - 1 ) * self::PER_PAGE;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT created_at, delta, note FROM ' . Schema::ledger_table() . ' WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla propia.
				(int) $user->ID,
				self::PER_PAGE,
				$offset
			),
			ARRAY_A
		);

		$data = array();

		if ( 1 === $page ) {
			$perfil = array(
				array(
					'name'  => 'Saldo de puntos',
					'value' => (string) Ledger::balance( (int) $user->ID ),
				),
			);

			// El resto de lo que el programa guarda SOBRE la persona. Se
			// nombra en lenguaje de persona, no con la meta_key: quien pide
			// sus datos no sabe qué es `_akb_rewards_ref_code`.
			foreach ( self::CAMPOS_PERSONALES as $meta => $etiqueta ) {
				$valor = (string) get_user_meta( (int) $user->ID, $meta, true );
				if ( '' === $valor ) {
					continue;
				}

				$perfil[] = array(
					'name'  => $etiqueta,
					'value' => self::CUMPLEANOS === $meta ? self::cumpleanos_legible( $valor ) : $valor,
				);
			}

			// A quién invitó esta persona no se lista: son terceros, y su
			// identidad no es dato de quien pregunta. Sí va el vínculo
			// inverso —quién la invitó a ella—, que sí la describe.
			$padrino = (int) get_user_meta( (int) $user->ID, '_akb_rewards_referred_by', true );
			if ( $padrino > 0 ) {
				$perfil[] = array(
					'name'  => 'Llegó invitado por',
					'value' => self::nombre_de( $padrino ),
				);
			}

			$data[] = array(
				'group_id'    => 'akibara-rewards',
				'group_label' => 'Puntos Akibara',
				'item_id'     => 'akb-rewards-balance',
				'data'        => $perfil,
			);

			$data = array_merge( $data, self::items_de_fotos( (string) $email, (int) $user->ID ) );
		}

		foreach ( $rows as $i => $row ) {
			$data[] = array(
				'group_id'    => 'akibara-rewards',
				'group_label' => 'Puntos Akibara',
				'item_id'     => 'akb-rewards-mov-' . ( $offset + (int) $i ),
				'data'        => array(
					array(
						'name'  => 'Movimiento',
						'value' => sprintf( '%s: %+d — %s', (string) $row['created_at'], (int) $row['delta'], (string) $row['note'] ),
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Borra lo que sólo describe a la persona y declara lo que se retiene.
	 *
	 * @param string $email Correo de la solicitud.
	 * @param int    $page  Página.
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );

		$user = get_user_by( 'email', (string) $email );

		// Las fotos de las reseñas se declaran SIEMPRE, con cuenta o sin ella:
		// el núcleo anonimiza el comentario pero no toca sus adjuntos, y una
		// respuesta que calla lo que se queda es la que este fichero vino a
		// retirar. Si se borran o no es una decisión del dueño (la foto es
		// requisito de la reseña publicada); mientras tanto se dice la verdad
		// y se da el camino.
		$fotos = count( self::fotos_de( (string) $email, false === $user ? 0 : (int) $user->ID ) );
		$aviso_fotos = $fotos > 0
			? sprintf(
				1 === $fotos
					? 'Se conserva %d foto publicada junto a una reseña tuya: si quieres que la retiremos, escríbenos a contacto@akibara.cl.'
					: 'Se conservan %d fotos publicadas junto a tus reseñas: si quieres que las retiremos, escríbenos a contacto@akibara.cl.',
				$fotos
			)
			: '';

		if ( false === $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => '' !== $aviso_fotos,
				'messages'       => '' !== $aviso_fotos ? array( $aviso_fotos ) : array(),
				'done'           => true,
			);
		}

		// El sello va ANTES de borrar nada: si después se elimina la cuenta,
		// `stamp_identity()` lo lee para no estampar el correo en el historial.
		update_user_meta( (int) $user->ID, self::META_SUPRESION, time() );

		$borrados = array();
		foreach ( self::CAMPOS_PERSONALES as $meta => $etiqueta ) {
			if ( '' !== (string) get_user_meta( (int) $user->ID, $meta, true ) ) {
				delete_user_meta( (int) $user->ID, $meta );
				$borrados[] = $etiqueta;
			}
		}

		// El vínculo con quien la invitó describe una relación entre dos
		// personas. Se va; el SELLO del premio (`_akb_rewards_referral_order`)
		// se queda, y con él la imposibilidad de volver a cobrarlo.
		if ( '' !== (string) get_user_meta( (int) $user->ID, '_akb_rewards_referred_by', true ) ) {
			delete_user_meta( (int) $user->ID, '_akb_rewards_referred_by' );
			$borrados[] = 'quién la invitó';
		}

		$mensajes = array();

		if ( array() !== $borrados ) {
			$mensajes[] = 'Se borraron de Puntos Akibara: ' . implode( ', ', $borrados ) . '.';
		}

		$retenido = array();

		if ( array() !== $this->has_rows( (int) $user->ID ) ) {
			$retenido[] = 'el historial de puntos, que es registro contable (respaldo de descuentos ya aplicados) y queda sin tu correo: si la cuenta se elimina, tus movimientos no llevan ningún dato que te identifique';
		}

		foreach ( self::SELLOS as $meta ) {
			if ( '' !== (string) get_user_meta( (int) $user->ID, $meta, true ) ) {
				$retenido[] = 'la marca de que ya se pagó un premio del programa (sin ningún dato de identidad), para que no se pague dos veces';
				break;
			}
		}

		if ( array() !== $retenido ) {
			$mensajes[] = 'Se conservan ' . implode( ', y ', $retenido ) . '.';
		}

		if ( '' !== $aviso_fotos ) {
			$mensajes[] = $aviso_fotos;
		}

		return array(
			'items_removed'  => array() !== $borrados,
			'items_retained' => array() !== $retenido || '' !== $aviso_fotos,
			'messages'       => $mensajes,
			'done'           => true,
		);
	}

	/**
	 * «03-14» → «14 de marzo». El formato interno no le dice nada a nadie.
	 *
	 * @param string $md Mes-día guardado.
	 * @return string
	 */
	private static function cumpleanos_legible( string $md ): string {
		if ( ! preg_match( '/^(\d{2})-(\d{2})$/', $md, $m ) ) {
			return $md;
		}

		$meses = array( 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );
		$mes   = (int) $m[1];

		return isset( $meses[ $mes - 1 ] ) ? sprintf( '%d de %s', (int) $m[2], $meses[ $mes - 1 ] ) : $md;
	}

	/**
	 * Nombre público de la persona que invitó, sin filtrar su correo.
	 *
	 * @param int $user_id Padrino.
	 * @return string
	 */
	private static function nombre_de( int $user_id ): string {
		$padrino = get_userdata( $user_id );

		return false === $padrino ? 'otro cliente' : (string) $padrino->display_name;
	}

	/**
	 * Las fotos de reseña, ya con la forma que pide el exportador del núcleo.
	 *
	 * @param string $email   Correo de la solicitud.
	 * @param int    $user_id Cuenta, si la hay (0 = comprador invitado).
	 * @return array<int,array<string,mixed>>
	 */
	private static function items_de_fotos( string $email, int $user_id ): array {
		$items = array();

		foreach ( self::fotos_de( $email, $user_id ) as $i => $url ) {
			$items[] = array(
				'group_id'    => 'akibara-rewards',
				'group_label' => 'Puntos Akibara',
				'item_id'     => 'akb-rewards-foto-' . $i,
				'data'        => array(
					array(
						'name'  => 'Foto que subiste con una reseña',
						'value' => $url,
					),
				),
			);
		}

		return $items;
	}

	/**
	 * URLs de las fotos que esta persona subió con sus reseñas.
	 *
	 * Complementa la exportación de comentarios del core con las fotos de las
	 * reseñas asociadas al correo solicitado.
	 *
	 * @param string $email   Correo de la solicitud.
	 * @param int    $user_id Cuenta, si la hay.
	 * @return array<int,string>
	 */
	private static function fotos_de( string $email, int $user_id ): array {
		$comentarios = get_comments(
			array(
				'author_email' => $email,
				// 'status' => 'any' incluye cualquier estado (aprobado, pendiente, spam, papelera).
				'status'       => 'any',
				'number'       => 200,
				'fields'       => 'ids',
			)
		);

		if ( $user_id > 0 ) {
			$comentarios = array_unique(
				array_merge(
					(array) $comentarios,
					(array) get_comments(
						array(
							'user_id' => $user_id,
							'status'  => 'any',
							'number'  => 200,
							'fields'  => 'ids',
						)
					)
				)
			);
		}

		$urls = array();
		foreach ( (array) $comentarios as $comment_id ) {
			foreach ( (array) get_comment_meta( (int) $comment_id, \Akibara\Rewards\Reviews\Media::META_LOCAL_IMAGE ) as $attachment_id ) {
				$url = wp_get_attachment_url( (int) $attachment_id );
				if ( is_string( $url ) && '' !== $url ) {
					$urls[] = $url;
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * ¿El usuario tiene filas en el ledger?
	 *
	 * @param int $user_id Usuario.
	 * @return array<int,string> Ids encontrados (vacío = ninguno).
	 */
	private function has_rows( int $user_id ): array {
		global $wpdb;

		return (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . Schema::ledger_table() . ' WHERE user_id = %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla propia.
				$user_id
			)
		);
	}
}
