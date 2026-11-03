<?php
/**
 * Tabla de cortes por país y editorial en Pedidos y preventa. Comparte
 * term-meta y PedidoPolicy::sanitize_row() con las pantallas de términos.
 * Sólo procesa IDs enrolados por el render y verificados como términos reales.
 * Valida filas independientemente, nombra los rechazos, conserva un snapshot
 * para Deshacer y no escribe filas sin cambios.
 * Escribe term-meta directamente, evitando edited_term y el rebuild del
 * buscador. La tabla es completa, sin paginación, para el conjunto acotado
 * de países y editoriales del catálogo.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\EstimatedDelivery;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.0
 */
final class CortesPanel {

	private const ACTION_SAVE    = 'akb_cortes_save';
	private const ACTION_RESTORE = 'akb_cortes_restore';
	private const OPTION_BACKUP  = 'akb_cortes_backup';
	private const MAX_BACKUPS    = 5;
	private const TRANSIENT_NOTICE = 'akb_cortes_notice_';

	/** Taxonomías cuyo término puede llevar política de pedido. */
	private const TAXONOMIES = array( 'pa_pais', PedidoPolicy::TAXONOMY );

	/**
	 * Cuelga los receptores del form (admin-post, patrón del buscador: el
	 * save del AdminPanel no aprende nada de términos).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_RESTORE, array( $this, 'handle_restore' ) );
	}

	/**
	 * La tabla completa: avisos, filas de países y editoriales, deshacer.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->render_notice();

		$paises      = $this->terms_of( 'pa_pais' );
		$editoriales = $this->terms_of( PedidoPolicy::TAXONOMY );

		echo '<p class="description" style="max-width:720px">Todo el circuito de pedidos en una pantalla: cuándo cierra el pedido de cada país o editorial y qué plazo se promete. Lo mismo puede configurarse término por término en Productos → Atributos; esta tabla escribe exactamente los mismos datos.</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="akb-cortes-form">';
		wp_nonce_field( self::ACTION_SAVE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE ) . '">';

		echo '<table class="widefat striped akb-cortes-tabla" style="width:100%; max-width:100%"><thead><tr>'
			. '<th scope="col" style="width:6%">Ámbito</th><th scope="col" style="width:10%">Nombre</th><th scope="col" style="width:8%">Modo</th>'
			. '<th scope="col" style="width:7%">Cierre</th><th scope="col" style="width:18%">Frecuencia</th><th scope="col" style="width:6%">Mín</th><th scope="col" style="width:6%">Máx</th>'
			. '<th scope="col" style="width:39%">Lo que ve el cliente</th></tr></thead><tbody>';

		foreach ( $paises as $term ) {
			$this->render_row( 'País', $term );
		}
		foreach ( $editoriales as $term ) {
			$this->render_row( 'Editorial', $term );
		}

		echo '</tbody></table>';
		echo '<p class="description">«Por tanda» exige día de cierre (1-' . esc_html( (string) PedidoPolicy::DIA_MAXIMO ) . ') y plazo mínimo ≤ máximo (1-365, días corridos). Una tanda a medias se rechaza entera — la fila avisa y las demás se guardan igual.</p>';
		echo '<p class="submit"><button type="submit" class="button button-primary">Guardar cortes</button></p>';
		echo '</form>';

		$this->render_restore();
	}

	/**
	 * Guarda las filas enroladas: valida, hace diff, respalda y aplica.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( self::ACTION_SAVE );

		// El contrato del enroll (LiteSpeed): SOLO lo que el render pintó.
		$enrolled = isset( $_POST['akb_cortes_enroll'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['akb_cortes_enroll'] ) ) : array();
		$filas    = isset( $_POST['akb_cortes'] ) ? (array) wp_unslash( $_POST['akb_cortes'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Estructura anidada: cada fila pasa por PedidoPolicy::sanitize_row() antes de escribirse (línea 128).

		$errores  = array();
		$cambios  = 0;
		$snapshot = array();

		foreach ( $enrolled as $term_id ) {
			$term = $this->real_term( $term_id );
			if ( null === $term ) {
				continue; // Id inventado o de otra taxonomía: no escribe nada.
			}

			$raw = isset( $filas[ $term_id ] ) && is_array( $filas[ $term_id ] ) ? $filas[ $term_id ] : array();
			$res = PedidoPolicy::sanitize_row( $raw );

			if ( ! $res['ok'] ) {
				// La fila inválida no aborta a las demás, y el aviso la NOMBRA.
				$errores[] = sprintf( '«%s»: %s', $term->name, $res['error'] );
				continue;
			}

			$antes = $this->current_meta( $term_id );
			if ( ! $this->row_changes( $antes, $res ) ) {
				continue; // Sin cambios: la base no se toca.
			}

			$snapshot[ $term_id ] = $antes;
			PedidoPolicy::apply_row( $term_id, $res );
			++$cambios;
		}

		if ( array() !== $snapshot ) {
			$this->push_backup( $snapshot );
		}

		$partes = array();
		if ( $cambios > 0 ) {
			$partes[] = sprintf( '%d fila(s) guardada(s).', $cambios );
		}
		if ( array() !== $errores ) {
			$partes[] = implode( ' · ', $errores ) . ' — esas filas no se guardaron; las demás sí.';
		}
		if ( array() === $partes ) {
			$partes[] = 'Sin cambios que guardar.';
		}
		set_transient( self::TRANSIENT_NOTICE . get_current_user_id(), array(
			'tipo'  => array() === $errores ? 'success' : 'warning',
			'texto' => implode( ' ', $partes ),
		), MINUTE_IN_SECONDS );

		$this->redirect_back();
	}

	/**
	 * Deshace el último guardado: repone las metas del snapshot más reciente.
	 *
	 * @return void
	 */
	public function handle_restore(): void {
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( self::ACTION_RESTORE );

		$backups = get_option( self::OPTION_BACKUP, array() );
		$ultimo  = is_array( $backups ) && isset( $backups[0] ) && is_array( $backups[0] ) ? $backups[0] : null;

		if ( null === $ultimo || empty( $ultimo['rows'] ) ) {
			set_transient( self::TRANSIENT_NOTICE . get_current_user_id(), array(
				'tipo'  => 'warning',
				'texto' => 'No hay un guardado anterior que deshacer.',
			), MINUTE_IN_SECONDS );
			$this->redirect_back();
		}

		$repuestas = 0;
		foreach ( (array) $ultimo['rows'] as $term_id => $antes ) {
			if ( null === $this->real_term( (int) $term_id ) ) {
				continue;
			}
			$this->write_meta_verbatim( (int) $term_id, (array) $antes );
			++$repuestas;
		}

		array_shift( $backups );
		update_option( self::OPTION_BACKUP, $backups, false );

		set_transient( self::TRANSIENT_NOTICE . get_current_user_id(), array(
			'tipo'  => 'success',
			'texto' => sprintf( 'Se repuso la configuración anterior de %d fila(s).', $repuestas ),
		), MINUTE_IN_SECONDS );

		$this->redirect_back();
	}

	/**
	 * Una fila de la tabla, con enroll y la vista previa GUARDADA.
	 *
	 * @param string   $ambito «País» o «Editorial».
	 * @param \WP_Term $term   Término de la fila.
	 * @return void
	 */
	private function render_row( string $ambito, \WP_Term $term ): void {
		$id   = (int) $term->term_id;
		$meta = $this->current_meta( $id );
		$modo = '' !== $meta['modo'] ? $meta['modo'] : PedidoPolicy::MODO_HEREDADO;

		$modos = array(
			PedidoPolicy::MODO_HEREDADO => 'Hereda',
			PedidoPolicy::MODO_CONTINUO => 'Continuo',
			PedidoPolicy::MODO_TANDA    => 'Por tanda',
			PedidoPolicy::MODO_PAUSADA  => 'Pausada',
		);
		if ( ! isset( $modos[ $modo ] ) ) {
			$modo = PedidoPolicy::MODO_HEREDADO;
		}

		$vista = PedidoPolicy::vista_previa( $modo, $meta['dia'], $meta['min'], $meta['max'], $meta['frecuencia'], $meta['meses'] ?? '' );
		if ( '' === $vista ) {
			$vista = PedidoPolicy::MODO_CONTINUO === $modo
				? 'Plazo corrido desde la compra.'
				: 'Hereda: usa el plazo de ' . ( 'País' === $ambito ? 'la tienda' : 'su país' ) . '.';
		}

		echo '<tr>';
		echo '<td>' . esc_html( $ambito ) . '<input type="hidden" name="akb_cortes_enroll[]" value="' . esc_attr( (string) $id ) . '"></td>';
		echo '<th scope="row" style="text-align:left">' . esc_html( $term->name ) . '</th>';

		echo '<td><label class="screen-reader-text" for="akb-cortes-modo-' . esc_attr( (string) $id ) . '">Modo de ' . esc_attr( $term->name ) . '</label>';
		echo '<select id="akb-cortes-modo-' . esc_attr( (string) $id ) . '" name="akb_cortes[' . esc_attr( (string) $id ) . '][modo]">';
		foreach ( $modos as $valor => $etiqueta ) {
			echo '<option value="' . esc_attr( $valor ) . '"' . selected( $modo, $valor, false ) . '>' . esc_html( $etiqueta ) . '</option>';
		}
		echo '</select></td>';

		$dia_valor = is_scalar( $meta['dia'] ) && '' !== (string) $meta['dia'] && '0' !== (string) $meta['dia'] ? (string) $meta['dia'] : '';
		echo '<td><label class="screen-reader-text" for="akb-cortes-dia-' . esc_attr( (string) $id ) . '">' . esc_html( 'Día de cierre de ' . $term->name ) . '</label>';
		echo '<input type="text" id="akb-cortes-dia-' . esc_attr( (string) $id ) . '" name="akb_cortes[' . esc_attr( (string) $id ) . '][dia]" value="' . esc_attr( $dia_valor ) . '" placeholder="20 o 5, 20" style="width:4.8em"></td>';

		$frecuencia = '' !== $meta['frecuencia'] ? $meta['frecuencia'] : PedidoPolicy::FRECUENCIA_MENSUAL;
		echo '<td><label class="screen-reader-text" for="akb-cortes-frecuencia-' . esc_attr( (string) $id ) . '">' . esc_html( 'Frecuencia de ' . $term->name ) . '</label>';
		echo '<select id="akb-cortes-frecuencia-' . esc_attr( (string) $id ) . '" name="akb_cortes[' . esc_attr( (string) $id ) . '][frecuencia]" style="max-width:215px; font-size:12px;" onchange="var el=document.getElementById(\'akb-cortes-meses-' . esc_attr( (string) $id ) . '\'); if(el) el.style.display=(this.value===\'personalizada\'?\'block\':\'none\');">';
		foreach ( PedidoPolicy::opciones_frecuencia() as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( $frecuencia, $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		$show_meses = ( PedidoPolicy::FRECUENCIA_PERSONALIZADA === $frecuencia ) ? 'block' : 'none';
		echo '<div id="akb-cortes-meses-' . esc_attr( (string) $id ) . '" style="display:' . $show_meses . '; margin-top:4px;">';
		echo '<input type="text" name="akb_cortes[' . esc_attr( (string) $id ) . '][meses]" value="' . esc_attr( (string) ( $meta['meses'] ?? '' ) ) . '" placeholder="Meses ej: 1,3,7,11" title="Meses del 1 al 12 separados por coma" style="width:130px; font-size:11px; padding:2px 4px;">';
		echo '</div></td>';

		$campos_plazo = array(
			'min' => array( $meta['min'], 1, 365, 'Plazo mínimo' ),
			'max' => array( $meta['max'], 1, 365, 'Plazo máximo' ),
		);
		foreach ( $campos_plazo as $clave => list( $valor, $min_attr, $max_attr, $sr ) ) {
			echo '<td><label class="screen-reader-text" for="akb-cortes-' . esc_attr( $clave ) . '-' . esc_attr( (string) $id ) . '">' . esc_html( $sr . ' de ' . $term->name ) . '</label>';
			echo '<input type="number" id="akb-cortes-' . esc_attr( $clave ) . '-' . esc_attr( (string) $id ) . '" name="akb_cortes[' . esc_attr( (string) $id ) . '][' . esc_attr( $clave ) . ']" value="' . ( $valor > 0 ? esc_attr( (string) $valor ) : '' ) . '" min="' . esc_attr( (string) $min_attr ) . '" max="' . esc_attr( (string) $max_attr ) . '" step="1" style="width:4.2em"></td>';
		}

		echo '<td class="description" style="font-size:12px; line-height:1.4;">' . esc_html( $vista ) . '</td>';
		echo '</tr>';
	}

	/**
	 * Aviso del último guardado/deshacer, y limpieza del transient.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		$aviso = get_transient( self::TRANSIENT_NOTICE . get_current_user_id() );
		if ( ! is_array( $aviso ) || '' === (string) ( $aviso['texto'] ?? '' ) ) {
			return;
		}
		delete_transient( self::TRANSIENT_NOTICE . get_current_user_id() );

		printf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			esc_attr( 'success' === ( $aviso['tipo'] ?? '' ) ? 'success' : 'warning' ),
			esc_html( (string) $aviso['texto'] )
		);
	}

	/**
	 * Botón «Deshacer el último guardado» si hay respaldo.
	 *
	 * @return void
	 */
	private function render_restore(): void {
		$backups = get_option( self::OPTION_BACKUP, array() );
		if ( ! is_array( $backups ) || array() === $backups ) {
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px">';
		wp_nonce_field( self::ACTION_RESTORE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_RESTORE ) . '">';
		echo '<button type="submit" class="button">Deshacer el último guardado</button> ';
		echo '<span class="description">Repone la configuración anterior de las filas que cambiaron (' . esc_html( (string) count( $backups ) ) . ' respaldo(s) disponibles).</span>';
		echo '</form>';
	}

	/**
	 * @param string $taxonomy Taxonomía.
	 * @return array<int,\WP_Term>
	 */
	private function terms_of( string $taxonomy ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		return is_array( $terms ) ? array_values( array_filter( $terms, static fn( $t ): bool => $t instanceof \WP_Term ) ) : array();
	}

	/**
	 * Término real de una de las DOS taxonomías admitidas, o null.
	 *
	 * @param int $term_id Id del POST.
	 * @return \WP_Term|null
	 */
	private function real_term( int $term_id ): ?\WP_Term {
		if ( $term_id <= 0 ) {
			return null;
		}
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$term = get_term( $term_id, $taxonomy );
			if ( $term instanceof \WP_Term ) {
				return $term;
			}
		}
		return null;
	}

	/**
	 * Metas actuales de la fila, tipadas.
	 *
	 * @param int $term_id Término.
	 * @return array{modo:string,dia:mixed,min:int,max:int,frecuencia:string,meses:string}
	 */
	private function current_meta( int $term_id ): array {
		return array(
			'modo'       => (string) get_term_meta( $term_id, PedidoPolicy::META_MODO, true ),
			'dia'        => get_term_meta( $term_id, PedidoPolicy::META_DIA, true ),
			'min'        => (int) get_term_meta( $term_id, PedidoPolicy::META_MIN, true ),
			'max'        => (int) get_term_meta( $term_id, PedidoPolicy::META_MAX, true ),
			'frecuencia' => (string) get_term_meta( $term_id, PedidoPolicy::META_FRECUENCIA, true ),
			'meses'      => (string) get_term_meta( $term_id, PedidoPolicy::META_MESES, true ),
		);
	}

	/**
	 * ¿El resultado validado cambia algo respecto de lo guardado?
	 *
	 * @param array{modo:string,dia:mixed,min:int,max:int,frecuencia?:string,meses?:string} $antes Metas actuales.
	 * @param array{set:array<string,int|string>,delete:array<int,string>}  $res   Resultado de sanitize_row.
	 * @return bool
	 */
	private function row_changes( array $antes, array $res ): bool {
		$despues = $antes;
		foreach ( $res['delete'] as $meta ) {
			$slot = $this->meta_slot( $meta );
			$despues[ $slot ] = in_array( $slot, array( 'modo', 'frecuencia', 'meses' ), true ) ? '' : 0;
		}
		foreach ( $res['set'] as $meta => $valor ) {
			$slot             = $this->meta_slot( $meta );
			$despues[ $slot ] = in_array( $slot, array( 'modo', 'frecuencia', 'meses', 'dia' ), true ) ? (string) $valor : (int) $valor;
		}

		return (string) $despues['modo'] !== (string) $antes['modo']
			|| (string) $despues['dia'] !== (string) $antes['dia']
			|| (int) $despues['min'] !== (int) $antes['min']
			|| (int) $despues['max'] !== (int) $antes['max']
			|| (string) ( $despues['frecuencia'] ?? '' ) !== (string) ( $antes['frecuencia'] ?? '' )
			|| (string) ( $despues['meses'] ?? '' ) !== (string) ( $antes['meses'] ?? '' );
	}

	/**
	 * @param string $meta_key Clave real de term-meta.
	 * @return string Slot corto (modo|dia|min|max|frecuencia|meses).
	 */
	private function meta_slot( string $meta_key ): string {
		return array(
			PedidoPolicy::META_MODO       => 'modo',
			PedidoPolicy::META_DIA        => 'dia',
			PedidoPolicy::META_MIN        => 'min',
			PedidoPolicy::META_MAX        => 'max',
			PedidoPolicy::META_FRECUENCIA => 'frecuencia',
			PedidoPolicy::META_MESES      => 'meses',
		)[ $meta_key ] ?? 'modo';
	}

	/**
	 * Escribe un snapshot tal cual (para el deshacer): vacío = borrar meta.
	 *
	 * @param int                                                                        $term_id Término.
	 * @param array{modo?:string,dia?:mixed,min?:int,max?:int,frecuencia?:string,meses?:string} $antes   Snapshot previo.
	 * @return void
	 */
	private function write_meta_verbatim( int $term_id, array $antes ): void {
		$mapa = array(
			PedidoPolicy::META_MODO       => (string) ( $antes['modo'] ?? '' ),
			PedidoPolicy::META_DIA        => $antes['dia'] ?? '',
			PedidoPolicy::META_MIN        => (int) ( $antes['min'] ?? 0 ),
			PedidoPolicy::META_MAX        => (int) ( $antes['max'] ?? 0 ),
			PedidoPolicy::META_FRECUENCIA => (string) ( $antes['frecuencia'] ?? '' ),
			PedidoPolicy::META_MESES      => (string) ( $antes['meses'] ?? '' ),
		);
		foreach ( $mapa as $meta => $valor ) {
			if ( '' === (string) $valor || '0' === (string) $valor ) {
				delete_term_meta( $term_id, $meta );
			} else {
				update_term_meta( $term_id, $meta, $valor );
			}
		}
	}

	/**
	 * Empuja un snapshot al respaldo (últimos MAX_BACKUPS).
	 *
	 * @param array<int,array{modo:string,dia:int,min:int,max:int}> $rows Filas previas.
	 * @return void
	 */
	private function push_backup( array $rows ): void {
		$backups = get_option( self::OPTION_BACKUP, array() );
		$backups = is_array( $backups ) ? $backups : array();
		array_unshift( $backups, array( 't' => time(), 'rows' => $rows ) );
		update_option( self::OPTION_BACKUP, array_slice( $backups, 0, self::MAX_BACKUPS ), false );
	}

	/**
	 * PRG de vuelta al sub-tab.
	 *
	 * @return void
	 */
	private function redirect_back(): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => 'akibara-panel',
					'tab'    => \Akibara\Business\Modules\OrderPreventa\Settings::PANEL_TAB_ID,
					'subtab' => 'cortes',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
