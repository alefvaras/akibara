<?php
/**
 * Campos nativos de pa_pais para crear y editar plazos de país. Valida
 * enteros entre 1 y 365, con máximo igual o mayor al mínimo. Los valores vacíos
 * al crear no se persisten y permiten el fallback configurado.
 * Los rechazos se muestran en la propia pantalla del término mediante un
 * transient específico; no dependen del aviso de AdminPanel.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\EstimatedDelivery;

use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Term meta editor para pa_pais.
 *
 * @since 1.14.0
 */
final class PaisTermFields {

	/** Pública: el aviso de rechazo de tanda (MarcaTermFields) también se pinta aquí. */
	public const TAXONOMY = 'pa_pais';

	/**
	 * Transient por usuario donde viaja el aviso hasta el redirect del
	 * term-edit. Clave propia: la de EstimatedDelivery sólo la lee el panel de
	 * Akibara, y este flujo vuelve a `edit-tags.php`.
	 */
	public const SAVE_ERRORS_TRANSIENT_PREFIX = 'akibara_eda_pais_term_errors_';

	public function register(): void {
		add_action( self::TAXONOMY . '_add_form_fields', array( $this, 'render_add_fields' ) );
		add_action( self::TAXONOMY . '_edit_form_fields', array( $this, 'render_edit_fields' ), 10, 2 );
		add_action( 'create_' . self::TAXONOMY, array( $this, 'save_term_fields' ) );
		add_action( 'edited_' . self::TAXONOMY, array( $this, 'save_term_fields' ) );
		add_action( 'admin_notices', array( $this, 'render_save_notice' ) );
	}

	/**
	 * Campos al crear término nuevo. Renderea como `<div class="form-field">`
	 * — match del template de "Add new term" de WP.
	 *
	 * @return void
	 */
	public function render_add_fields(): void {
		?>
		<div class="form-field">
			<label for="akb_min_days">Días mínimos de tránsito</label>
			<input type="number" name="akb_min_days" id="akb_min_days" value="" min="1" max="365" step="1" />
			<p>Días hábiles mínimos desde que se genera la importación hasta que llega a Akibara.</p>
		</div>
		<div class="form-field">
			<label for="akb_max_days">Días máximos de tránsito</label>
			<input type="number" name="akb_max_days" id="akb_max_days" value="" min="1" max="365" step="1" />
			<p>Días hábiles máximos. Debe ser igual o mayor que el mínimo.</p>
		</div>
		<?php
	}

	/**
	 * Campos al editar término existente. Renderea como `<tr class="form-field">`
	 * — match del template de "Edit term" de WP (tabla form-table).
	 *
	 * @param \WP_Term $term     Término editado.
	 * @param string   $taxonomy Taxonomy slug (siempre `pa_pais` aquí).
	 * @return void
	 */
	public function render_edit_fields( $term, $taxonomy ): void {
		unset( $taxonomy );
		if ( ! $term || ! isset( $term->term_id ) ) {
			return;
		}

		$min = (int) get_term_meta( $term->term_id, Settings::TERM_META_MIN_DAYS, true );
		$max = (int) get_term_meta( $term->term_id, Settings::TERM_META_MAX_DAYS, true );
		?>
		<tr class="form-field">
			<th scope="row">
				<label for="akb_min_days">Días mínimos de tránsito</label>
			</th>
			<td>
				<input type="number" name="akb_min_days" id="akb_min_days" value="<?php echo $min > 0 ? esc_attr( (string) $min ) : ''; ?>" min="1" max="365" step="1" />
				<p class="description">Días hábiles mínimos desde que se genera la importación hasta que llega a Akibara.</p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row">
				<label for="akb_max_days">Días máximos de tránsito</label>
			</th>
			<td>
				<input type="number" name="akb_max_days" id="akb_max_days" value="<?php echo $max > 0 ? esc_attr( (string) $max ) : ''; ?>" min="1" max="365" step="1" />
				<p class="description">Días hábiles máximos. Debe ser igual o mayor que el mínimo.</p>
			</td>
		</tr>
		<?php
		$pedido_modo       = (string) get_term_meta( (int) $term->term_id, PedidoPolicy::META_MODO, true );
		$pedido_dia        = get_term_meta( (int) $term->term_id, PedidoPolicy::META_DIA, true );
		$pedido_min        = (int) get_term_meta( (int) $term->term_id, PedidoPolicy::META_MIN, true );
		$pedido_max        = (int) get_term_meta( (int) $term->term_id, PedidoPolicy::META_MAX, true );
		$pedido_frecuencia = (string) get_term_meta( (int) $term->term_id, PedidoPolicy::META_FRECUENCIA, true );
		$pedido_meses      = (string) get_term_meta( (int) $term->term_id, PedidoPolicy::META_MESES, true );
		$pedido_modo       = '' === $pedido_modo ? PedidoPolicy::MODO_HEREDADO : $pedido_modo;
		$pedido_frecuencia = '' === $pedido_frecuencia ? PedidoPolicy::FRECUENCIA_MENSUAL : $pedido_frecuencia;
		?>
		<tr class="form-field">
			<th scope="row" colspan="2" style="padding-bottom:0"><h3 style="margin:12px 0 0">Pedidos desde este país</h3>
			<p class="description" style="font-weight:normal">La fecha de CORTE del país (pedido del dueño 2026-08-30): «todos los pedidos España hasta el 5 de cada mes». Una editorial con política propia (Productos → Marcas) sigue mandando sobre esto; este corte es el default de su país. La cadena completa: ficha → editorial → país → plazo de tránsito de abajo.</p></th>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="akb_pedido_modo">Modo de pedido del país</label></th>
			<td>
				<select name="akb_pedido_modo" id="akb_pedido_modo">
					<option value="<?php echo esc_attr( PedidoPolicy::MODO_HEREDADO ); ?>"<?php selected( $pedido_modo, PedidoPolicy::MODO_HEREDADO ); ?>>Continuo (sin corte — el plazo corre desde la compra)</option>
					<option value="<?php echo esc_attr( PedidoPolicy::MODO_TANDA ); ?>"<?php selected( $pedido_modo, PedidoPolicy::MODO_TANDA ); ?>>Por tanda (con fecha de corte periódica)</option>
					<option value="<?php echo esc_attr( PedidoPolicy::MODO_PAUSADA ); ?>"<?php selected( $pedido_modo, PedidoPolicy::MODO_PAUSADA ); ?>>Pausado (sin fecha prometida)</option>
				</select>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="akb_tanda_dia">Día de corte del mes</label></th>
			<td>
				<input type="text" name="akb_tanda_dia" id="akb_tanda_dia" value="<?php echo esc_attr( (string) $pedido_dia ); ?>" placeholder="20 o 5, 20" style="max-width:140px;" />
				<p class="description">Del 1 al <?php echo esc_html( (string) PedidoPolicy::DIA_MAXIMO ); ?>, o días separados por coma para quincenal (ej: <code>5, 20</code>). Quien compra el día del corte entra en esa tanda.</p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="akb_tanda_frecuencia">Frecuencia de corte</label></th>
			<td>
				<select name="akb_tanda_frecuencia" id="akb_tanda_frecuencia" onchange="var el=document.getElementById('akb_pais_meses_wrapper'); if(el) el.style.display=(this.value==='personalizada'?'block':'none');">
					<?php foreach ( PedidoPolicy::opciones_frecuencia() as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>"<?php selected( $pedido_frecuencia, $val ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description">Define con qué frecuencia se consolida el pedido de este país (ej. mensual o quincenal para Argentina; bimestral para España).</p>
				<div id="akb_pais_meses_wrapper" style="display:<?php echo ( PedidoPolicy::FRECUENCIA_PERSONALIZADA === $pedido_frecuencia ) ? 'block' : 'none'; ?>; margin-top:6px;">
					<input type="text" name="akb_tanda_meses" id="akb_tanda_meses" value="<?php echo esc_attr( $pedido_meses ); ?>" placeholder="ej: 1, 3, 7, 11" style="max-width:180px;" />
					<p class="description" style="margin-top:2px;">Meses del año en que opera el corte (números del 1 al 12 separados por coma).</p>
				</div>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="akb_tanda_min_dias">Días desde el corte (mínimo)</label></th>
			<td><input type="number" name="akb_tanda_min_dias" id="akb_tanda_min_dias" value="<?php echo $pedido_min > 0 ? esc_attr( (string) $pedido_min ) : ''; ?>" min="1" max="365" step="1" />
			<p class="description">Días <strong>corridos</strong> entre el corte y la llegada (España ≈ un mes; Argentina ≈ tres semanas).</p></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="akb_tanda_max_dias">Días desde el corte (máximo)</label></th>
			<td><input type="number" name="akb_tanda_max_dias" id="akb_tanda_max_dias" value="<?php echo $pedido_max > 0 ? esc_attr( (string) $pedido_max ) : ''; ?>" min="1" max="365" step="1" />
			<p class="description">Igual o mayor que el mínimo. En «Continuo» los tres campos se ignoran y rige el tránsito de arriba.</p></td>
		</tr>
		<?php

	}

	/**
	 * Save handler — corre en `create_pa_pais` y `edited_pa_pais`.
	 *
	 * WP ya verificó capability + nonce antes de llegar acá (es flujo nativo
	 * de term edit). Si los inputs son inválidos, NO actualiza term-meta — el
	 * fallback de `Settings::get_country_days()` cubre el caso.
	 *
	 * @param int $term_id ID del término creado/editado.
	 * @return void
	 */
	public function save_term_fields( int $term_id ): void {
		// El bloque «Pedidos desde este país» comparte POST, validación y
		// avisos con el de las editoriales: delega en el validador probado de
		// MarcaTermFields (rechaza tandas a medias, capability propia).
		( new MarcaTermFields() )->save_term_fields( $term_id );

		// edited_{taxonomy} también puede venir de wp_update_term fuera de la UI;
		// la capacidad se comprueba en este handler antes de guardar.
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WP verifica nonce en el flujo nativo del term edit; la entrada cruda se sanea entera en self::sanitize_post() (línea 208).
		$posted_min = isset( $_POST['akb_min_days'] );
		$posted_max = isset( $_POST['akb_max_days'] );

		// `edited_pa_pais` lo dispara CUALQUIER wp_update_term(), no sólo esta
		// pantalla. Sin este guard, un update programático hecho dentro de una
		// sesión con `manage_categories` llegaba con los dos campos ausentes,
		// caía en la rama de «ambos vacíos» y BORRABA los días configurados del
		// país sin que nadie lo hubiera pedido.
		if ( ! $posted_min && ! $posted_max ) {
			return;
		}

		$min_raw = $posted_min ? wp_unslash( $_POST['akb_min_days'] ) : '';
		$max_raw = $posted_max ? wp_unslash( $_POST['akb_max_days'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// Ambos vacíos → borrar la term-meta para caer al fallback de Settings.
		if ( '' === trim( (string) $min_raw ) && '' === trim( (string) $max_raw ) ) {
			delete_term_meta( $term_id, Settings::TERM_META_MIN_DAYS );
			delete_term_meta( $term_id, Settings::TERM_META_MAX_DAYS );
			return;
		}

		$sanitized = self::sanitize_post(
			array(
				'akb_min_days' => $min_raw,
				'akb_max_days' => $max_raw,
			)
		);

		if ( null !== $sanitized['min'] && null !== $sanitized['max'] ) {
			update_term_meta( $term_id, Settings::TERM_META_MIN_DAYS, $sanitized['min'] );
			update_term_meta( $term_id, Settings::TERM_META_MAX_DAYS, $sanitized['max'] );
			return;
		}

		// Escribió algo y no valida: conservamos el valor anterior —igual que la
		// pantalla del panel— pero AVISAMOS. Callar aquí era peor que no tener
		// el campo: WordPress imprime «Elemento actualizado» igual.
		self::push_save_error(
			sprintf(
				'Días de tránsito: valores inválidos (min=%s, max=%s). Debe cumplir 1 ≤ min ≤ max ≤ 365. No se guardó.',
				is_numeric( $min_raw ) ? (string) (int) $min_raw : '—',
				is_numeric( $max_raw ) ? (string) (int) $max_raw : '—'
			)
		);
	}

	/**
	 * Guarda el aviso hasta el redirect del term-edit.
	 *
	 * Append-only y TTL de 60 s, mismo contrato que
	 * `EstimatedDelivery::push_save_error()`: sólo tiene que sobrevivir al
	 * redirect, nunca a la sesión.
	 *
	 * @param string $error Mensaje human-readable.
	 * @return void
	 */
	private static function push_save_error( string $error ): void {
		$key     = self::SAVE_ERRORS_TRANSIENT_PREFIX . get_current_user_id();
		$current = get_transient( $key );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$current[] = $error;
		set_transient( $key, $current, 60 );
	}

	/**
	 * Imprime y consume los avisos pendientes.
	 *
	 * Se borra el transient SIEMPRE que había algo, aunque el aviso se pinte en
	 * otra pantalla: dejarlo vivo lo haría reaparecer 60 s después en cualquier
	 * página del admin, que asusta más de lo que informa.
	 *
	 * @return void
	 */
	public function render_save_notice(): void {
		$key    = self::SAVE_ERRORS_TRANSIENT_PREFIX . get_current_user_id();
		$errors = get_transient( $key );
		if ( ! is_array( $errors ) || array() === $errors ) {
			return;
		}

		delete_transient( $key );

		foreach ( $errors as $error ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( (string) $error )
			);
		}
	}

	/**
	 * Sanitiza inputs raw del form. Pure function — testeable sin WP.
	 *
	 * Reglas:
	 *   - min: entero 1-365.
	 *   - max: entero >= min, <= 365.
	 *   - Cualquier valor inválido → ambos retornan null (rechaza el set entero
	 *     para mantener consistencia: o entran ambos válidos o ninguno).
	 *
	 * @param array<string, mixed> $post_data Inputs raw (típicamente subset de $_POST).
	 * @return array{min: int|null, max: int|null}
	 */
	public static function sanitize_post( array $post_data ): array {
		$min_raw = $post_data['akb_min_days'] ?? '';
		$max_raw = $post_data['akb_max_days'] ?? '';

		if ( ! is_numeric( $min_raw ) || ! is_numeric( $max_raw ) ) {
			return array( 'min' => null, 'max' => null );
		}

		$min = (int) $min_raw;
		$max = (int) $max_raw;

		if ( $min < 1 || $min > 365 || $max < $min || $max > 365 ) {
			return array( 'min' => null, 'max' => null );
		}

		return array( 'min' => $min, 'max' => $max );
	}
}
