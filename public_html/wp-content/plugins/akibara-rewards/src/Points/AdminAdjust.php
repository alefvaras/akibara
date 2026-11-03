<?php
/**
 * AdminAdjust — otorgar o quitar puntos a mano desde el panel administrativo:
 * compensaciones y correcciones con trazabilidad contable en el ledger.
 *
 * Controles de seguridad y auditoría:
 *  - Permisos restringidos a `manage_woocommerce` con verificación de nonce.
 *  - Motivo descriptivo obligatorio.
 *  - Identificación del administrador que ejecutó el ajuste.
 *  - Acotación estricta de débitos al saldo disponible del cliente.
 *  - Notificación opcional por correo transaccional.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.0
 */
final class AdminAdjust {

	public const ACTION = 'akb_rewards_admin_adjust';

	/**
	 * Nombre único para el nonce del formulario para evitar colisiones en el DOM.
	 */
	private const NONCE_NAME = 'akb_adj_nonce';
	private const TRANSIENT_NOTICE = 'akb_rw_adjust_notice_';

	/**
	 * Tipo de referencia de un ajuste ligado a un pedido. NO es `order`: ése lo
	 * concilia Accrual contra lo acreditado por la compra, y un premio manual no
	 * puede entrar en esa cuenta.
	 */
	public const REF_PEDIDO = 'order_adjust';

	/** Confirmación pendiente: qué avisos y sobre qué valores. De un solo uso. */
	private const TRANSIENT_CONFIRMA = 'akb_rw_adjust_confirma_';

	/** Avisos que se pueden confirmar, uno por causa. */
	public const AVISO_AJENO    = 'ajeno';
	public const AVISO_REPETIDO = 'repetido';

	/**
	 * Huella de lo que el dueño está confirmando. Si cambia cualquiera de los
	 * cuatro valores, la confirmación deja de valer y los avisos se re-evalúan.
	 *
	 * @param string $email  Correo del cliente.
	 * @param int    $pedido Pedido.
	 * @param string $signo  mas|menos.
	 * @param int    $points Puntos.
	 * @return string
	 */
	public static function huella( string $email, int $pedido, string $signo, int $points ): string {
		return md5( strtolower( trim( $email ) ) . '|' . $pedido . '|' . $signo . '|' . $points );
	}

	/**
	 * Une avisos confirmados sin repetir y en orden estable.
	 *
	 * @param string ...$listas Listas separadas por coma.
	 * @return string
	 */
	public static function avisos_unidos( string ...$listas ): string {
		$avisos = array();
		foreach ( $listas as $lista ) {
			foreach ( explode( ',', $lista ) as $aviso ) {
				$aviso = sanitize_key( trim( $aviso ) );
				if ( in_array( $aviso, array( self::AVISO_AJENO, self::AVISO_REPETIDO ), true ) && ! in_array( $aviso, $avisos, true ) ) {
					$avisos[] = $aviso;
				}
			}
		}
		sort( $avisos, SORT_STRING );

		return implode( ',', $avisos );
	}

	/**
	 * Qué avisos quedan confirmados con esta petición, si es que alguno.
	 *
	 * Exige las tres cosas: que el POST traiga exactamente los avisos que esta
	 * pantalla dejó pendientes, que la huella del POST sea la que se guardó, y
	 * que esa huella siga describiendo los valores que se están enviando.
	 *
	 * @param string $de_post        Avisos que dice confirmar el POST.
	 * @param string $huella_post    Huella que trae el POST.
	 * @param string $avisos_guardados Avisos que el servidor dejó pendientes.
	 * @param string $huella_guardada  Huella que el servidor dejó pendiente.
	 * @param string $huella_actual    Huella de los valores enviados ahora.
	 * @return string Avisos confirmados, o '' si algo no cuadra.
	 */
	public static function confirmacion_valida( string $de_post, string $huella_post, string $avisos_guardados, string $huella_guardada, string $huella_actual ): string {
		$pedidos = self::avisos_unidos( $de_post );
		if ( '' === $pedidos || $pedidos !== self::avisos_unidos( $avisos_guardados ) ) {
			return '';
		}
		if ( '' === $huella_guardada || ! hash_equals( $huella_guardada, $huella_post ) || ! hash_equals( $huella_actual, $huella_post ) ) {
			return '';
		}

		return $pedidos;
	}

	/**
	 * Cuelga el receptor y el bloque en la pantalla de ajustes.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		// WordPress supplies an empty string to argument-less actions unless
		// accepted_args is zero. The optional account ID is for direct calls.
		add_action( 'akb_rewards_settings_after', array( $this, 'render_box' ), 10, 0 );
	}

	/**
	 * El formulario: cliente por correo, puntos ±, motivo y aviso opcional.
	 *
	 * @return void
	 */
	public function render_box( int $user_id = 0 ): void {
		$aviso  = get_transient( self::TRANSIENT_NOTICE . get_current_user_id() );
		$campos = array();
		if ( is_array( $aviso ) && '' !== (string) ( $aviso['texto'] ?? '' ) ) {
			delete_transient( self::TRANSIENT_NOTICE . get_current_user_id() );
			$tipos = array(
				'success' => 'success',
				'warning' => 'warning',
			);
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $tipos[ (string) ( $aviso['tipo'] ?? '' ) ] ?? 'error' ),
				esc_html( (string) $aviso['texto'] )
			);
			// Cuando falta una confirmación, el formulario vuelve RELLENO: el dueño
			// sólo marca la casilla, no reescribe correo, puntos, motivo y pedido.
			$campos = is_array( $aviso['campos'] ?? null ) ? $aviso['campos'] : array();
		}
		$selected_user = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;
		if ( $user_id > 0 && ! $selected_user instanceof \WP_User ) {
			return;
		}
		if ( $selected_user instanceof \WP_User ) {
			$campos['email'] = (string) $selected_user->user_email;
		}
		$pide_confirmacion = ! empty( $campos['pide_confirmacion'] );
		$signo             = 'menos' === ( $campos['signo'] ?? '' ) ? 'menos' : 'mas';
		?>
		<hr style="margin:32px 0 24px">
		<h2>Otorgar o quitar puntos a mano</h2>
		<p class="description" style="max-width:640px">Para compensaciones o correcciones fuera del circuito automático —por ejemplo, un problema con un pedido—. El movimiento queda en el historial del cliente con tu nombre y el motivo. Si indicas el pedido, te avisamos cuando ya recibió puntos a mano, para no premiarlo dos veces.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:640px">
			<?php wp_nonce_field( self::ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php if ( $selected_user instanceof \WP_User ) : ?>
				<input type="hidden" name="akb_adj_user_id" value="<?php echo esc_attr( (string) $user_id ); ?>">
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="akb_adj_email">Correo del cliente</label></th>
					<td><input name="akb_adj_email" id="akb_adj_email" type="email" required class="regular-text" placeholder="cliente@correo.cl" value="<?php echo esc_attr( (string) ( $campos['email'] ?? '' ) ); ?>" <?php echo $selected_user instanceof \WP_User ? 'readonly' : ''; ?>></td>
				</tr>
				<tr>
					<th scope="row"><label for="akb_adj_points">Puntos</label></th>
					<td>
						<select name="akb_adj_signo" id="akb_adj_signo" style="vertical-align:baseline" aria-label="Sentido del movimiento: otorgar o quitar">
							<option value="mas" <?php selected( $signo, 'mas' ); ?>>Otorgar +</option>
							<option value="menos" <?php selected( $signo, 'menos' ); ?>>Quitar −</option>
						</select>
						<input name="akb_adj_points" id="akb_adj_points" type="number" min="1" step="1" required class="small-text" placeholder="400" value="<?php echo esc_attr( empty( $campos['points'] ) ? '' : (string) (int) $campos['points'] ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="akb_adj_motivo">Motivo (obligatorio)</label></th>
					<td><input name="akb_adj_motivo" id="akb_adj_motivo" type="text" required maxlength="180" class="large-text" placeholder="Compensación por demora del pedido" value="<?php echo esc_attr( (string) ( $campos['motivo'] ?? '' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="akb_adj_pedido">Pedido (opcional)</label></th>
					<td>
						<input name="akb_adj_pedido" id="akb_adj_pedido" type="text" inputmode="numeric" class="small-text" placeholder="1234" value="<?php echo esc_attr( empty( $campos['pedido'] ) ? '' : (string) (int) $campos['pedido'] ); ?>">
						<p class="description">El número del pedido al que corresponde el premio. Evita premiar dos veces el mismo pedido sin darte cuenta.</p>
					</td>
				</tr>
				<?php if ( $pide_confirmacion ) : ?>
				<tr>
					<th scope="row">Confirmación</th>
					<td>
						<label><input name="akb_adj_confirmar" type="checkbox" value="1"> Confirmo: lo revisé y quiero aplicarlo igual</label>
						<input type="hidden" name="akb_adj_confirmar_de" value="<?php echo esc_attr( (string) ( $campos['aviso'] ?? '' ) ); ?>">
						<input type="hidden" name="akb_adj_huella" value="<?php echo esc_attr( (string) ( $campos['huella'] ?? '' ) ); ?>">
					</td>
				</tr>
				<?php endif; ?>
				<tr>
					<th scope="row">Avisar al cliente</th>
					<td><label><input name="akb_adj_avisar" type="checkbox" value="1" <?php checked( ! array_key_exists( 'avisar', $campos ) || ! empty( $campos['avisar'] ) ); ?>> Enviarle un correo con el movimiento y su saldo</label></td>
				</tr>
			</table>
			<p class="submit" style="padding-top:0"><button type="submit" class="button button-primary">Aplicar movimiento</button></p>
		</form>
		<?php
	}

	/**
	 * Aplica el ajuste: valida, asienta con auditoría y avisa.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( self::ACTION, self::NONCE_NAME );

		$email  = isset( $_POST['akb_adj_email'] ) ? sanitize_email( wp_unslash( $_POST['akb_adj_email'] ) ) : '';
		$points = isset( $_POST['akb_adj_points'] ) ? absint( $_POST['akb_adj_points'] ) : 0;
		$signo  = isset( $_POST['akb_adj_signo'] ) && 'menos' === $_POST['akb_adj_signo'] ? 'menos' : 'mas';
		$motivo = isset( $_POST['akb_adj_motivo'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['akb_adj_motivo'] ) ) ) : '';
		$avisar = ! empty( $_POST['akb_adj_avisar'] );
		$selected_id = isset( $_POST['akb_adj_user_id'] ) ? absint( $_POST['akb_adj_user_id'] ) : 0;
		$selected_user = $selected_id > 0 ? get_user_by( 'id', $selected_id ) : false;
		if ( isset( $_POST['akb_adj_user_id'] ) ) {
			// Resolve the identity from the selected account, never from an editable
			// email or an Analytics customer ID supplied by the browser.
			$email = $selected_user instanceof \WP_User ? (string) $selected_user->user_email : '';
		}
		// «#1234», «1234» o «pedido 1234 (2026)»: el PRIMER número, no todos los
		// dígitos pegados —«pedido 1234 (2026)» daba 12342026, que puede existir—.
		// Los puntos de millar se quitan antes: «1.234» es 1234.
		$crudo  = isset( $_POST['akb_adj_pedido'] ) ? sanitize_text_field( wp_unslash( $_POST['akb_adj_pedido'] ) ) : '';
		$pedido = preg_match( '/\d+/', str_replace( array( '.', ' ' ), '', $crudo ), $digitos ) ? absint( $digitos[0] ) : 0;

		/*
		 * La confirmación valida el conjunto de avisos reconocidos, los valores auditados
		 * y se almacena en un transient de un solo uso para prevenir reenvíos desde el historial.
		 */
		$confirmado = '';
		$pendiente  = get_transient( self::TRANSIENT_CONFIRMA . get_current_user_id() );
		if ( ! empty( $_POST['akb_adj_confirmar'] ) && is_array( $pendiente ) ) {
			delete_transient( self::TRANSIENT_CONFIRMA . get_current_user_id() );
			$de     = isset( $_POST['akb_adj_confirmar_de'] ) ? sanitize_text_field( wp_unslash( $_POST['akb_adj_confirmar_de'] ) ) : '';
			$huella = isset( $_POST['akb_adj_huella'] ) ? sanitize_text_field( wp_unslash( $_POST['akb_adj_huella'] ) ) : '';
			$confirmado = self::confirmacion_valida(
				$de,
				$huella,
				(string) ( $pendiente['avisos'] ?? '' ),
				(string) ( $pendiente['huella'] ?? '' ),
				self::huella( $email, $pedido, $signo, $points )
			);
		}

		$user = isset( $_POST['akb_adj_user_id'] ) ? $selected_user : ( '' !== $email ? get_user_by( 'email', $email ) : false );

		$resultado                     = $this->apply( $user instanceof \WP_User ? (int) $user->ID : 0, $points, 'menos' === $signo ? -1 : 1, $motivo, $pedido, $confirmado );
		list( $ok, $texto, $aplicado ) = $resultado;
		$aviso_pendiente               = is_string( $resultado[3] ?? '' ) ? (string) ( $resultado[3] ?? '' ) : '';
		$pide_confirmacion             = '' !== $aviso_pendiente;
		// Lo ya confirmado MÁS lo que falta: así el segundo aviso no borra el primero.
		$avisos_a_confirmar            = $pide_confirmacion ? self::avisos_unidos( $confirmado, $aviso_pendiente ) : '';
		if ( $pide_confirmacion ) {
			set_transient(
				self::TRANSIENT_CONFIRMA . get_current_user_id(),
				array(
					'avisos' => $avisos_a_confirmar,
					'huella' => self::huella( $email, $pedido, $signo, $points ),
				),
				5 * MINUTE_IN_SECONDS
			);
		}

		// Notifica el delta asentado por el ledger. Si es cero no hubo movimiento.
		if ( $ok && $avisar && 0 !== $aplicado && $user instanceof \WP_User ) {
			$this->notify( $user, $aplicado, $motivo );
		}

		set_transient(
			self::TRANSIENT_NOTICE . get_current_user_id(),
			array(
				'tipo'   => $ok ? 'success' : ( $pide_confirmacion ? 'warning' : 'error' ),
				'texto'  => $texto,
				'campos' => $pide_confirmacion
					? array(
						'email'             => $email,
						'points'            => $points,
						'signo'             => $signo,
						'motivo'            => $motivo,
						'pedido'            => $pedido,
						'avisar'            => $avisar,
						'pide_confirmacion' => true,
						'aviso'             => $avisos_a_confirmar,
						'huella'            => self::huella( $email, $pedido, $signo, $points ),
					)
					: array(),
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( $selected_id > 0 ? CustomerPointsPage::url( $selected_id ) : admin_url( 'admin.php?page=' . \Akibara\Rewards\Support\SettingsPage::SLUG ) );
		exit;
	}

	/**
	 * Núcleo testeable del ajuste.
	 *
	 * @param int    $user_id Cliente (0 = no encontrado).
	 * @param int    $points  Puntos (positivos).
	 * @param int    $sign    1 otorgar, -1 quitar.
	 * @param string $motivo  Motivo obligatorio.
	 * Retorna el delta asentado real (acotado al saldo en débitos) y valida si requiere
	 * confirmación explícita cuando el pedido ya fue premiado o pertenece a otro cliente.
	 *
	 * @param int    $order_id   Pedido al que corresponde (0 = sin pedido).
	 * @param string $confirmado Avisos ya confirmados, separados por coma: '', `ajeno`, `repetido` o ambos.
	 * @return array{0:bool,1:string,2:int,3?:string} [ok, mensaje, delta asentado, aviso por confirmar].
	 */
	public function apply( int $user_id, int $points, int $sign, string $motivo, int $order_id = 0, string $confirmado = '' ) {
		if ( $user_id <= 0 ) {
			return array( false, 'No existe un cliente con ese correo.', 0 );
		}
		if ( $points <= 0 ) {
			return array( false, 'Los puntos deben ser un número mayor que cero.', 0 );
		}
		if ( '' === $motivo ) {
			return array( false, 'El motivo es obligatorio: es lo que audita el movimiento.', 0 );
		}

		$admin = wp_get_current_user();
		$login = $admin instanceof \WP_User && '' !== (string) $admin->user_login ? (string) $admin->user_login : 'admin';
		$nota  = self::nota( $login, $motivo, $order_id );

		// Clave determinista por contenido y minuto para prevenir reenvíos accidentales concurrentes.
		$key = sprintf(
			'adjust_%d_%d_%s',
			$user_id,
			$sign * $points,
			substr( md5( $login . '|' . $motivo . '|' . $order_id . '|' . gmdate( 'YmdHi' ) ), 0, 24 )
		);

		// Notifica al administrador si el evento exacto ya fue registrado previamente.
		if ( Ledger::event_applied( $key ) ) {
			return array( false, 'Este mismo ajuste acaba de asentarse (doble envío ignorado): revisa el saldo antes de repetirlo.', 0 );
		}

		$ref_type = 'user';
		$ref_id   = $user_id;
		if ( $order_id > 0 ) {
			$pedido = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
			if ( ! is_object( $pedido ) || ! method_exists( $pedido, 'get_id' ) ) {
				return array( false, sprintf( 'No existe el pedido #%d. Revisa el número.', $order_id ), 0 );
			}
			// Valida que el pedido sea una orden de compra estándar (shop_order) y no un reembolso o borrador.
			$es_compra = method_exists( $pedido, 'get_customer_id' ) && method_exists( $pedido, 'get_billing_email' )
				&& ( ! method_exists( $pedido, 'get_type' ) || 'shop_order' === $pedido->get_type() );
			if ( ! $es_compra ) {
				return array( false, sprintf( 'El #%d no es un pedido de compra (puede ser un reembolso o un borrador). Usa el número del pedido del cliente.', $order_id ), 0 );
			}
			$estado = method_exists( $pedido, 'get_status' ) ? (string) $pedido->get_status() : '';
			if ( 'trash' === $estado || 'checkout-draft' === $estado ) {
				return array(
					false,
					sprintf(
						'trash' === $estado
							? 'El pedido #%d está en la papelera. Restáuralo o usa otro número.'
							: 'El #%d es un borrador de caja, no un pedido. Usa el número del pedido del cliente.',
						$order_id
					),
					0,
				);
			}
			$confirmados = explode( ',', self::avisos_unidos( $confirmado ) );
			if ( ! in_array( self::AVISO_AJENO, $confirmados, true ) && ! $this->order_belongs_to( $pedido, $user_id ) ) {
				return array( false, sprintf( 'El pedido #%d no es de este cliente. Revisa el número; si igual quieres asociarlo, marca «Confirmo» y vuelve a aplicar.', $order_id ), 0, self::AVISO_AJENO );
			}
			if ( $sign > 0 && ! in_array( self::AVISO_REPETIDO, $confirmados, true ) ) {
				$previo = Ledger::last_credit( self::REF_PEDIDO, $order_id, 'admin_adjust' );
				if ( null !== $previo ) {
					// Motivo y fecha van como ARGUMENTOS del formato, nunca dentro: un
					// «%» escrito por el dueño en un formato es un fatal (ya pasó).
					return array(
						false,
						sprintf(
							'El pedido #%1$d ya recibió %2$s puntos a mano el %3$s (%4$s). Si es otro premio, marca «Confirmo» y vuelve a aplicar.',
							$order_id,
							number_format_i18n( $previo['delta'] ),
							'' !== $previo['created_at'] ? substr( $previo['created_at'], 0, 16 ) : 'antes',
							'' !== $previo['note'] ? $previo['note'] : 'sin nota'
						),
						0,
						self::AVISO_REPETIDO,
					);
				}
			}
			$ref_type = self::REF_PEDIDO;
			$ref_id   = $order_id;
		}

		if ( $sign >= 0 ) {
			/*
			 * `counts_as_earned = false`: los ajustes manuales del administrador no
			 * incrementan el histórico `total_earned` para evitar asimetrías contables.
			 */
			$res = Ledger::credit( $user_id, $points, 'admin_adjust', $ref_type, $ref_id, $nota, false, $key );
		} else {
			$res = Ledger::debit( $user_id, $points, 'admin_adjust', $ref_type, $ref_id, $nota, false, $key );
		}

		if ( ! is_int( $res ) ) {
			return array( false, 'El movimiento no se pudo asentar (candado ocupado o infraestructura): inténtalo de nuevo.', 0 );
		}

		// Lo asentado, no lo pedido. Al otorgar coinciden —no hay acotación al
		// alta—; al quitar, el ledger devuelve lo que de verdad pudo descontar.
		// Es el mismo criterio con el que ya se redacta el aviso del admin.
		$aplicado = $sign >= 0 ? $points : -$res;

		// Una baja sin saldo no genera movimiento ni correo al cliente.
		if ( $sign < 0 && 0 === $res ) {
			return array(
				true,
				'No se quitó nada: el cliente ya estaba en 0 puntos. No se le envió ningún aviso.',
				0,
			);
		}

		return array(
			true,
			sprintf(
				'%s %s puntos %s. Saldo actual: %s.',
				$sign >= 0 ? 'Otorgados' : 'Quitados',
				number_format_i18n( $sign >= 0 ? $points : $res ),
				$sign >= 0 ? 'al cliente' : 'al cliente (clampado a su saldo)',
				number_format_i18n( Ledger::balance( $user_id ) )
			),
			$aplicado,
		);
	}

	/**
	 * Nota de auditoría, recortada respetando límites de bytes y secuencias multibyte válidas.
	 *
	 * La columna en base de datos admite hasta 255 bytes; asegura que el sufijo de pedido
	 * se preserve y previene truncamientos a mitad de carácter UTF-8.
	 *
	 * @param string $login    Quién lo hace.
	 * @param string $motivo   Motivo del dueño.
	 * @param int    $order_id Pedido, o 0.
	 * @return string
	 */
	private static function nota( string $login, string $motivo, int $order_id ): string {
		$prefijo = sprintf( 'Ajuste manual por %s: ', $login );
		$sufijo  = $order_id > 0 ? sprintf( ' (pedido #%d)', $order_id ) : '';
		$sitio   = 255 - strlen( $prefijo ) - strlen( $sufijo );
		if ( strlen( $motivo ) > $sitio ) {
			$motivo = function_exists( 'mb_strcut' )
				? mb_strcut( $motivo, 0, max( 0, $sitio ), 'UTF-8' )
				// Sin mbstring: cortar a bytes y retirar la secuencia UTF-8 que
				// quedó a medias, o `wpdb::insert()` rechaza la fila entera.
				: (string) preg_replace( '/[\xC0-\xFF][\x80-\xBF]*$/', '', substr( $motivo, 0, max( 0, $sitio ) ) );
		}

		return $prefijo . $motivo . $sufijo;
	}

	/**
	 * ¿El pedido es de este cliente? Por cuenta, o por el correo de facturación
	 * cuando se compró como invitado.
	 *
	 * @param object $pedido  Pedido de WooCommerce.
	 * @param int    $user_id Cliente.
	 * @return bool
	 */
	private function order_belongs_to( object $pedido, int $user_id ): bool {
		if ( method_exists( $pedido, 'get_customer_id' ) && (int) $pedido->get_customer_id() === $user_id ) {
			return true;
		}

		$user   = get_userdata( $user_id );
		$correo = method_exists( $pedido, 'get_billing_email' ) ? strtolower( trim( (string) $pedido->get_billing_email() ) ) : '';

		return is_object( $user ) && '' !== $correo && strtolower( trim( (string) ( $user->user_email ?? '' ) ) ) === $correo;
	}

	/**
	 * Correo brandeado del ajuste (misma disciplina: es un aviso, no sella nada).
	 *
	 * @param \WP_User $user   Cliente.
	 * @param int      $points Delta con signo.
	 * @param string   $motivo Motivo visible.
	 * @return void
	 */
	private function notify( \WP_User $user, int $points, string $motivo ): void {
		if ( '' === (string) $user->user_email || ! function_exists( 'WC' ) ) {
			return;
		}

		$body = sprintf(
			'<p style="margin:0 0 12px">%s <strong>%s Puntos Akibara</strong> en tu cuenta: %s.</p>
			<p style="margin:0 0 12px">Tu saldo actual es de <strong>%s puntos</strong> — cada punto vale %s en tu próxima compra.</p>%s',
			$points >= 0 ? 'Te regalamos' : 'Se descontaron',
			esc_html( number_format_i18n( abs( $points ) ) ),
			esc_html( $motivo ),
			esc_html( number_format_i18n( Ledger::balance( (int) $user->ID ) ) ),
			esc_html( Options::redeem_label() ),
			Emails::cta( 'Ver mis puntos', Emails::rewards_url() )
		);

		Emails::send_branded(
			(string) $user->user_email,
			$points >= 0
				? sprintf( '[Akibara] 🎁 Sumaste %s Puntos Akibara', number_format_i18n( abs( $points ) ) )
				: '[Akibara] Movimiento en tus Puntos Akibara',
			$points >= 0 ? '¡Sumaste Puntos Akibara!' : 'Movimiento en tus Puntos Akibara',
			$body,
			Emails::context( 'admin_adjust', (int) $user->ID )
		);
	}
}
