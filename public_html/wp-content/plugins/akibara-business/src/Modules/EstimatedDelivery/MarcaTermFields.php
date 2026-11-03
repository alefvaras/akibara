<?php
/**
 * Campos de política de preventa en la pantalla de edición de una editorial.
 *
 * Vive en `product_brand` y no en `pa_pais` porque la decisión es por editorial:
 * bajo «Argentina» conviven Ivrea Argentina —350 de las 420 preventas de la
 * tienda— y Panini Argentina, con 372 productos y UNA preventa viva. Ver
 * {@see PedidoPolicy} para el razonamiento y las mediciones.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\EstimatedDelivery;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.108.22
 */
final class MarcaTermFields {

	private const TAXONOMY = PedidoPolicy::TAXONOMY;

	/** Prefijo del transient del aviso; se le concatena el ID del usuario. */
	private const TRANSIENT_AVISO = 'akb_pedido_aviso_';

	public function register(): void {
		add_action( self::TAXONOMY . '_edit_form_fields', array( $this, 'render_edit_fields' ), 10, 2 );
		add_action( 'edited_' . self::TAXONOMY, array( $this, 'save_term_fields' ) );
		add_action( 'create_' . self::TAXONOMY, array( $this, 'save_term_fields' ) );
		add_action( 'admin_notices', array( $this, 'render_rejection_notice' ) );
		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', array( $this, 'add_policy_column' ) );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', array( $this, 'render_policy_column' ), 10, 3 );
	}

	/**
	 * Columna «Pedidos» en el listado de editoriales (modelo 2026: pedidos y preventa).
	 *
	 * Sin ella la política sólo se ve entrando a editar cada término, uno por uno.
	 * Con ocho editoriales eso ya es incómodo, y sobre todo hace invisible el error
	 * que importa: una editorial que quedó en tanda cuando no debía.
	 *
	 * @param array<string,string> $columns Columnas de la tabla.
	 * @return array<string,string>
	 */
	public function add_policy_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$columns['akb_pedido'] = 'Pedidos';

		return $columns;
	}

	/**
	 * @param mixed  $content Contenido acumulado (WP lo pasa por filtro, no por echo).
	 * @param string $column  Columna que se está pintando.
	 * @param int    $term_id Término de la fila.
	 * @return mixed
	 */
	public function render_policy_column( $content, $column = '', $term_id = 0 ) {
		if ( 'akb_pedido' !== $column ) {
			return $content;
		}

		$term_id = (int) $term_id;
		$modo    = (string) get_term_meta( $term_id, PedidoPolicy::META_MODO, true );

		if ( PedidoPolicy::MODO_TANDA === $modo ) {
			$dia        = get_term_meta( $term_id, PedidoPolicy::META_DIA, true );
			$min        = (int) get_term_meta( $term_id, PedidoPolicy::META_MIN, true );
			$max        = (int) get_term_meta( $term_id, PedidoPolicy::META_MAX, true );
			$frecuencia = (string) get_term_meta( $term_id, PedidoPolicy::META_FRECUENCIA, true );
			$frec_label = '';
			if ( PedidoPolicy::FRECUENCIA_QUINCENAL === $frecuencia ) {
				$frec_label = ' · Quincenal';
			} elseif ( PedidoPolicy::FRECUENCIA_PERSONALIZADA === $frecuencia ) {
				$frec_label = ' · Personalizada';
			} elseif ( 0 === strpos( $frecuencia, 'bimestral' ) ) {
				$frec_label = ' · Bimestral';
			} elseif ( 0 === strpos( $frecuencia, 'trimestral' ) ) {
				$frec_label = ' · Trimestral';
			}

			return esc_html(
				sprintf(
					'Tanda · cierra el %1$s%4$s · %2$d-%3$d días',
					(string) $dia,
					$min,
					$max,
					$frec_label
				)
			);
		}

		if ( PedidoPolicy::MODO_PAUSADA === $modo ) {
			return esc_html( 'Pausada · sin fecha' );
		}

		if ( PedidoPolicy::MODO_CONTINUO === $modo ) {
			return esc_html( 'Continuo' );
		}

		return '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html( 'Sin política propia: usa el plazo del país' ) . '</span>';
	}

	/**
	 * Aviso de por qué NO se guardó la tanda.
	 *
	 * Sin esto el rechazo es mudo: el dueño escribe «cierra el 31», vuelve a ver
	 * el selector en «heredado» y no tiene forma de saber si el campo no sirve, si
	 * el día es inválido o si el guardado se perdió. La pantalla de términos de
	 * WordPress redirige, así que el mensaje tiene que sobrevivir al redirect.
	 */
	public function render_rejection_notice(): void {
		// El aviso sólo se consume en edición de editorial o país, las dos
		// pantallas que comparten el validador de tandas. Fuera de ellas se conserva
		// y no se consulta para evitar lecturas y avisos fuera de contexto.
		$screen = get_current_screen();
		if ( ! is_object( $screen ) || ! isset( $screen->taxonomy )
			|| ! in_array( (string) $screen->taxonomy, array( self::TAXONOMY, PaisTermFields::TAXONOMY ), true ) ) {
			return;
		}

		$aviso = get_transient( self::TRANSIENT_AVISO . get_current_user_id() );
		if ( ! is_string( $aviso ) || '' === $aviso ) {
			return;
		}

		delete_transient( self::TRANSIENT_AVISO . get_current_user_id() );

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html( $aviso )
		);
	}

	/**
	 * @param mixed  $term     Término editado.
	 * @param string $taxonomy Taxonomía recibida por WordPress.
	 */
	public function render_edit_fields( $term, $taxonomy = '' ): void {
		unset( $taxonomy );
		if ( ! is_object( $term ) || ! isset( $term->term_id ) ) {
			return;
		}

		$id            = (int) $term->term_id;
		$modo          = (string) get_term_meta( $id, PedidoPolicy::META_MODO, true );
		$dia           = get_term_meta( $id, PedidoPolicy::META_DIA, true );
		$min           = (int) get_term_meta( $id, PedidoPolicy::META_MIN, true );
		$max           = (int) get_term_meta( $id, PedidoPolicy::META_MAX, true );
		$frecuencia    = (string) get_term_meta( $id, PedidoPolicy::META_FRECUENCIA, true );
		$frecuencia    = '' === $frecuencia ? PedidoPolicy::FRECUENCIA_MENSUAL : $frecuencia;
		$meses         = (string) get_term_meta( $id, PedidoPolicy::META_MESES, true );

		$modos = array(
			PedidoPolicy::MODO_HEREDADO => 'Como hasta ahora — el plazo del país corre desde la compra',
			PedidoPolicy::MODO_CONTINUO => 'Continuo — igual que el anterior, pero fijado para esta editorial',
			PedidoPolicy::MODO_TANDA    => 'Por tanda — el plazo corre desde el cierre del pedido',
			PedidoPolicy::MODO_PAUSADA  => 'Pausada — no prometer fecha para esta editorial',
		);
		if ( '' === $modo || ! isset( $modos[ $modo ] ) ) {
			$modo = PedidoPolicy::MODO_HEREDADO;
		}
		?>
		<tr class="form-field">
			<th scope="row"><label for="akb_pedido_modo">Pedidos a esta editorial</label></th>
			<td>
				<select name="akb_pedido_modo" id="akb_pedido_modo">
					<?php foreach ( $modos as $valor => $etiqueta ) : ?>
						<option value="<?php echo esc_attr( $valor ); ?>"<?php selected( $modo, $valor ); ?>><?php echo esc_html( $etiqueta ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description">
					<strong>Por tanda</strong> sólo conviene donde el viaje es largo: junta los pedidos hasta una fecha de cierre
					y promete a partir de ahí, que es lo que puedes sostener. Para una editorial que publica cada semana
					—Ivrea Argentina saca novedades todos los jueves— la tanda <em>añade</em> hasta cuatro semanas de espera
					a mercadería que hoy empieza a viajar el mismo día que se compra.
				</p>
				<p class="description">
					<strong>Pausada</strong> deja de prometer fecha sin quitar los productos: la ficha dice «sin fecha
					confirmada» en vez de dar una que la distribuidora no puede sostener.
				</p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="akb_tanda_dia">Día de cierre de la tanda</label></th>
			<td>
				<input type="text" name="akb_tanda_dia" id="akb_tanda_dia" value="<?php echo esc_attr( (string) $dia ); ?>" placeholder="20 o 5, 20" style="max-width:140px;" />
				<p class="description">
					Día del mes en que cierra el pedido (del 1 al <?php echo esc_html( (string) PedidoPolicy::DIA_MAXIMO ); ?>), o múltiples días separados por coma para quincenal (ej: <code>5, 20</code>).
					Quien compra <em>el propio día del cierre</em> entra en esa tanda.
				</p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="akb_tanda_frecuencia">Frecuencia de corte</label></th>
			<td>
				<select name="akb_tanda_frecuencia" id="akb_tanda_frecuencia" onchange="var el=document.getElementById('akb_tanda_meses_wrapper'); if(el) el.style.display=(this.value==='personalizada'?'block':'none');">
					<?php foreach ( PedidoPolicy::opciones_frecuencia() as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>"<?php selected( $frecuencia, $val ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description">Frecuencia con que se envía el pedido consolidado a esta editorial.</p>
				<div id="akb_tanda_meses_wrapper" style="display:<?php echo ( PedidoPolicy::FRECUENCIA_PERSONALIZADA === $frecuencia ) ? 'block' : 'none'; ?>; margin-top:6px;">
					<input type="text" name="akb_tanda_meses" id="akb_tanda_meses" value="<?php echo esc_attr( $meses ); ?>" placeholder="ej: 1, 3, 7, 11" style="max-width:180px;" />
					<p class="description" style="margin-top:2px;">Meses del año en que opera el corte (números del 1 al 12 separados por coma).</p>
				</div>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="akb_tanda_min_dias">Días desde el cierre (mínimo)</label></th>
			<td>
				<input type="number" name="akb_tanda_min_dias" id="akb_tanda_min_dias" value="<?php echo $min > 0 ? esc_attr( (string) $min ) : ''; ?>" min="1" max="365" step="1" />
				<p class="description">Días <strong>corridos</strong> entre el cierre y la llegada, no hábiles: es una espera de barco, no trabajo de bodega.</p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="akb_tanda_max_dias">Días desde el cierre (máximo)</label></th>
			<td>
				<input type="number" name="akb_tanda_max_dias" id="akb_tanda_max_dias" value="<?php echo $max > 0 ? esc_attr( (string) $max ) : ''; ?>" min="1" max="365" step="1" />
				<p class="description">Igual o mayor que el mínimo. Si dejas los tres campos vacíos, la editorial vuelve al plazo de su país.</p>
			</td>
		</tr>
		<?php $vista = $this->vista_previa_guardada( $modo, $dia, $min, $max, $frecuencia, $meses ); ?>
		<?php if ( '' !== $vista ) : ?>
			<tr class="form-field">
				<th scope="row">Lo que ve el cliente</th>
				<td>
					<p><strong><?php echo esc_html( $vista ); ?></strong></p>
					<p class="description">Calculado con la configuración GUARDADA y la fecha de hoy. Si cambias los campos, guarda y vuelve a mirar.</p>
				</td>
			</tr>
		<?php endif; ?>
		<?php
	}

	/**
	 * La promesa que un cliente vería HOY con la política guardada.
	 *
	 * Configurar a ciegas es lo que el dueño pidió evitar: esta fila traduce
	 * modo+día+plazos a la frase y el rango exactos de la ficha. Server-side y
	 * sobre lo GUARDADO a propósito — la vista en vivo exigiría duplicar el
	 * cálculo en JS y las dos copias divergirían.
	 *
	 * @param string $modo       Modo guardado.
	 * @param mixed  $dia        Día de cierre guardado.
	 * @param int    $min        Días mínimos guardados.
	 * @param int    $max        Días máximos guardados.
	 * @param string $frecuencia Frecuencia guardada.
	 * @param string $meses      Meses guardados para personalizada.
	 */
	private function vista_previa_guardada( string $modo, $dia, int $min, int $max, string $frecuencia = PedidoPolicy::FRECUENCIA_MENSUAL, string $meses = '' ): string {
		return PedidoPolicy::vista_previa( $modo, $dia, $min, $max, $frecuencia, $meses );
	}

	/**
	 * Guarda la política.
	 *
	 * MISMA TRAMPA que `PaisTermFields`, y por eso el mismo guard: `edited_{taxonomy}`
	 * lo dispara CUALQUIER `wp_update_term()`, no sólo esta pantalla. Sin comprobar
	 * que los campos vinieron en el POST, un update programático hecho dentro de una
	 * sesión con permisos llegaba con todo ausente y BORRABA la configuración de la
	 * editorial sin que nadie lo hubiera pedido.
	 */
	public function save_term_fields( int $term_id ): void {
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WP verifica el nonce en el flujo nativo de edición de términos; la entrada cruda se sanea entera en PedidoPolicy::sanitize_row() (línea 260).
		if ( ! isset( $_POST['akb_pedido_modo'] ) ) {
			return;
		}

		$raw = array(
			'modo'       => wp_unslash( $_POST['akb_pedido_modo'] ),
			'dia'        => isset( $_POST['akb_tanda_dia'] ) ? wp_unslash( $_POST['akb_tanda_dia'] ) : 0,
			'min'        => isset( $_POST['akb_tanda_min_dias'] ) ? wp_unslash( $_POST['akb_tanda_min_dias'] ) : 0,
			'max'        => isset( $_POST['akb_tanda_max_dias'] ) ? wp_unslash( $_POST['akb_tanda_max_dias'] ) : 0,
			'frecuencia' => isset( $_POST['akb_tanda_frecuencia'] ) ? wp_unslash( $_POST['akb_tanda_frecuencia'] ) : '',
			'meses'      => isset( $_POST['akb_tanda_meses'] ) ? wp_unslash( $_POST['akb_tanda_meses'] ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// VALIDADOR ÚNICO (blueprint de paneles): esta pantalla y la tabla de
		// cortes del panel llaman a la misma función — dos pantallas, un
		// validador. Reglas y mensajes idénticos a los históricos.
		$res = PedidoPolicy::sanitize_row( $raw );

		if ( ! $res['ok'] ) {
			$this->avisar( $res['error'] );
			return;
		}

		PedidoPolicy::apply_row( $term_id, $res );
	}

	/**
	 * Deja el aviso para el siguiente pintado del admin de este usuario.
	 *
	 * @param string $mensaje Texto ya traducido.
	 */
	private function avisar( string $mensaje ): void {
		set_transient( self::TRANSIENT_AVISO . get_current_user_id(), $mensaje, MINUTE_IN_SECONDS );
	}
}
