<?php
/**
 * Birthday — Acreditación automática de puntos por cumpleaños.
 *
 * Diseño:
 *   - El cliente registra SOLO día y mes en Mi cuenta (sin año: dato mínimo,
 *     opt-in). Se guarda como 'mm-dd'.
 *   - Cron diario por Action Scheduler (patrón memoizado del Reconciler de
 *     search: cero consultas AS por request). Acredita UNA vez por año por
 *     usuario: meta del año pagado + event_key `birthday_{user}_{year}`
 *     contra el UNIQUE del ledger — doble red.
 *   - 29 de febrero: en años no bisiestos se paga el 1 de marzo (nadie se
 *     queda sin regalo por el calendario).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class Birthday {

	public const HOOK           = 'akb_rewards_birthday_daily';
	public const META_MD        = '_akb_rewards_birthday_md';
	public const META_PAID_YEAR = '_akb_rewards_birthday_paid_year';
	private const SCHEDULED_MEMO = 'akb_rewards_birthday_scheduled';

	/**
	 * Cuelga campo de cuenta, guardado y cron.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_edit_account_form', array( $this, 'render_field' ) );
		add_action( 'woocommerce_save_account_details', array( $this, 'save_field' ), 10, 1 );
		// Registro de cuenta en WooCommerce: el cumpleaños entra como campo
		// opcional y se guarda con el mismo validador.
		add_action( 'woocommerce_register_form', array( $this, 'render_field' ) );
		add_action( 'woocommerce_created_customer', array( $this, 'save_field' ), 10, 1 );
		add_action( 'woocommerce_account_dashboard', array( $this, 'render_dashboard_reminder' ), 15 );
		add_action( self::HOOK, array( $this, 'run_daily' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 20 );
	}

	/**
	 * Agenda la corrida diaria si falta (memoizada 12 h, patrón Reconciler).
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		// Solo admin/cron/CLI: optimización de superficie para evitar
		// lecturas de transient innecesarias en el frontend.
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( 'yes' === get_transient( self::SCHEDULED_MEMO ) ) {
			return;
		}
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( false === as_next_scheduled_action( self::HOOK ) ) {
			$action_id = as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK );
			if ( ! is_int( $action_id ) || 0 >= $action_id ) {
				return;
			}
		}
		set_transient( self::SCHEDULED_MEMO, 'yes', 12 * HOUR_IN_SECONDS );
	}

	/**
	 * Campo día/mes en Mi cuenta → Detalles.
	 *
	 * @return void
	 */
	public function render_field(): void {
		if ( Options::birthday_points() <= 0 ) {
			return;
		}

		$user_id = (int) get_current_user_id();
		$md      = (string) get_user_meta( $user_id, self::META_MD, true );
		$dia     = 0;
		$mes     = 0;
		if ( preg_match( '/^(\d{2})-(\d{2})$/', $md, $m ) ) {
			$mes = (int) $m[1];
			$dia = (int) $m[2];
		}

		$meses = array( 1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre' );

		// Accesibilidad: agrupación semántica con fieldset + legend y
		// etiquetas vinculadas por ID para lectores de pantalla.
		echo '<fieldset class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide akb-rw-birthday" style="border:0;padding:0;margin:0 0 16px;min-width:0">';
		printf(
			'<legend style="padding:0;font-weight:700">Tu cumpleaños 🎂 <span style="font-weight:normal">(te regalamos %s Puntos Akibara cada año; el año no lo pedimos)</span></legend>',
			esc_html( number_format_i18n( Options::birthday_points() ) )
		);
		echo '<span style="display:flex;gap:10px;margin-top:6px">';
		echo '<label for="akb_rw_birthday_dia" class="screen-reader-text">Día de tu cumpleaños</label>';
		echo '<select name="akb_rw_birthday_dia" id="akb_rw_birthday_dia" style="min-height:44px"><option value="">Día</option>';
		for ( $d = 1; $d <= 31; $d++ ) {
			printf( '<option value="%1$d"%2$s>%1$d</option>', (int) $d, selected( $dia, $d, false ) );
		}
		echo '</select>';
		echo '<label for="akb_rw_birthday_mes" class="screen-reader-text">Mes de tu cumpleaños</label>';
		echo '<select name="akb_rw_birthday_mes" id="akb_rw_birthday_mes" style="min-height:44px"><option value="">Mes</option>';
		foreach ( $meses as $num => $nombre ) {
			printf( '<option value="%d"%s>%s</option>', (int) $num, selected( $mes, $num, false ), esc_html( $nombre ) );
		}
		echo '</select></span>';
		if ( $user_id > 0 ) {
			$anio_actual = (int) ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y' );
			if ( (int) get_user_meta( $user_id, self::META_PAID_YEAR, true ) >= $anio_actual ) {
				echo '<p class="akb-rw-birthday-note" style="margin:6px 0 0;font-size:13px;opacity:.8">Ya recibiste tu regalo de cumpleaños de este año; si cambias la fecha, aplicará desde el próximo.</p>';
			}
		}
		echo '</fieldset>';
	}

	/**
	 * Recordatorio limpio en el dashboard de Mi Cuenta para usuarios sin día de cumpleaños registrado.
	 *
	 * Permite a los usuarios registrados vía Google OAuth registrar su fecha para recibir
	 * sus 1.000 Puntos Akibara sin tener que buscar el campo en Detalles de la cuenta.
	 *
	 * @return void
	 */
	public function render_dashboard_reminder(): void {
		$points = Options::birthday_points();
		if ( $points <= 0 ) {
			return;
		}

		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$md = (string) get_user_meta( $user_id, self::META_MD, true );
		if ( '' !== $md ) {
			return;
		}

		$edit_url = function_exists( 'wc_customer_edit_account_url' )
			? wc_customer_edit_account_url()
			: ( function_exists( 'wc_get_endpoint_url' ) ? wc_get_endpoint_url( 'edit-account' ) : '#' );
		?>
		<div class="akb-dashboard__birthday-banner" style="margin:20px 0;padding:16px 20px;background:#fff8eb;border:1px solid #ffe3b3;border-radius:8px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
			<div style="display:flex;align-items:center;gap:12px">
				<span style="font-size:26px;line-height:1" aria-hidden="true">🎂</span>
				<div>
					<p style="margin:0;font-weight:600;color:#1a1a1a;font-size:15px">
						<?php
						echo esc_html(
							sprintf(
								'Recibe %s Puntos Akibara de regalo por tu cumpleaños',
								number_format_i18n( $points )
							)
						);
						?>
					</p>
					<p style="margin:4px 0 0;color:#666;font-size:13px">
						Registra tu día y mes para recibir tus 1.000 Puntos Akibara ($1.000) cada año.
					</p>
				</div>
			</div>
			<a href="<?php echo esc_url( $edit_url ); ?>" class="button" style="white-space:nowrap;font-size:13px;padding:8px 16px">
				Registrar fecha
			</a>
		</div>
		<?php
	}

	/**
	 * Guarda día/mes del campo (el año se descarta a propósito).
	 *
	 * @param int $user_id Usuario.
	 * @return void
	 */
	public function save_field( $user_id ): void {
		if ( ! isset( $_POST['akb_rw_birthday_dia'] ) && ! isset( $_POST['akb_rw_birthday_mes'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- corre dentro del guardado nativo de Woo, que ya verificó su nonce.
			return;
		}

		$day   = isset( $_POST['akb_rw_birthday_dia'] ) ? (int) $_POST['akb_rw_birthday_dia'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$month = isset( $_POST['akb_rw_birthday_mes'] ) ? (int) $_POST['akb_rw_birthday_mes'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( 0 === $day && 0 === $month ) {
			delete_user_meta( (int) $user_id, self::META_MD );
			return;
		}

		// Validación con año bisiesto de referencia: permite fechas válidas
		// como 29 de febrero y rechaza combinaciones inexistentes.
		if ( $month < 1 || $month > 12 || $day < 1 || ! checkdate( $month, $day, 2024 ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( 'El cumpleaños no se guardó: esa combinación de día y mes no existe.', 'error' );
			}
			return;
		}

		update_user_meta( (int) $user_id, self::META_MD, sprintf( '%02d-%02d', $month, $day ) );
	}

	/**
	 * Corrida diaria: paga a los cumpleañeros de HOY (hora del sitio).
	 *
	 * @return void
	 */
	/**
	 * Días hacia atrás que revisa cada corrida, además de hoy.
	 *
	 * @since 0.4.7
	 */
	private const RECOVERY_DAYS = 3;

	public function run_daily(): void {
		$points = Options::birthday_points();
		if ( $points <= 0 ) {
			return;
		}

		$now = new \DateTimeImmutable( 'now', wp_timezone() );

		// Ventana de recuperación: evalúa la fecha actual y los días anteriores
		// configurados. Protege contra retrasos en la ejecución de Action
		// Scheduler o indisponibilidad temporal del transporte SMTP. La meta
		// del año pagado y el event_key previenen duplicados.
		$dias = array();
		for ( $atras = 0; $atras <= self::RECOVERY_DAYS; $atras++ ) {
			$dia = $now->modify( sprintf( '-%d day', $atras ) );
			// Cada día lleva SU año: un 31-12 recuperado el 02-01 es el regalo
			// del año pasado, y sellarlo con el año nuevo le quitaría el de este.
			$dias[ $dia->format( 'm-d' ) ] = (int) $dia->format( 'Y' );
		}

		// 29-02 en año no bisiesto: se paga el 01-03 (o su recuperación).
		if ( isset( $dias['03-01'] ) && ! checkdate( 2, 29, $dias['03-01'] ) ) {
			$dias['02-29'] = $dias['03-01'];
		}

		foreach ( $dias as $md => $anio_del_regalo ) {
			$this->pay_birthdays( (string) $md, $anio_del_regalo, $points );
		}
	}

	/**
	 * Paga a los usuarios con cumpleaños $md que no cobraron este año.
	 *
	 * @param string $md     'mm-dd'.
	 * @param int    $year   Año en curso.
	 * @param int    $points Puntos del regalo.
	 * @return void
	 */
	private function pay_birthdays( string $md, int $year, int $points ): void {
		// Paginación por lotes para procesar la totalidad de usuarios registrados.
		$paged = 1;
		do {
			$users = get_users(
				array(
					'meta_key'   => self::META_MD, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $md, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'     => 'ids',
					'number'     => 200,
					'paged'      => $paged,
					// Mismo patrón que Expiry: por ID se sale del índice del
					// join (el default user_login ordena el conjunto COMPLETO
					// en cada página) y sin count_total se ahorra el
					// SQL_CALC_FOUND_ROWS que nadie lee. El orden es
					// irrelevante: el bucle recorre a todos.
					'orderby'     => 'ID',
					'count_total' => false,
				)
			);

			$this->pay_batch( (array) $users, $md, $year, $points );
			++$paged;
		} while ( count( (array) $users ) === 200 );
	}

	/**
	 * Paga un lote de cumpleañeros.
	 *
	 * @param array<int,int> $users  IDs.
	 * @param string         $md     'mm-dd'.
	 * @param int            $year   Año en curso.
	 * @param int            $points Puntos del regalo.
	 * @return void
	 */
	private function pay_batch( array $users, string $md, int $year, int $points ): void {
		unset( $md );

		foreach ( $users as $user_id ) {
			$user_id = (int) $user_id;

			if ( (int) get_user_meta( $user_id, self::META_PAID_YEAR, true ) >= $year ) {
				// Si los puntos ya fueron acreditados pero el correo de saludo falló
				// por indisponibilidad de transporte, se reintenta el despacho
				// durante la ventana de recuperación.
				if ( (int) get_user_meta( $user_id, Emails::META_BIRTHDAY_GREETED_YEAR, true ) < $year ) {
					/** Documented below (the same hook, re-fired for the greeting). */
					do_action( 'akb_rewards_birthday_paid', $user_id, $points, $year );
				}
				continue;
			}

			$result = Ledger::credit(
				$user_id,
				$points,
				'birthday',
				'user',
				$user_id,
				sprintf( 'Regalo de cumpleaños %d', $year ),
				true,
				sprintf( 'birthday_%d_%d', $user_id, $year )
			);

			if ( ! is_int( $result ) ) {
				continue; // Infraestructura: mañana reintenta (la meta no se sella).
			}

			update_user_meta( $user_id, self::META_PAID_YEAR, $year );

			/**
			 * Cumpleaños pagado. El correo de saludo escucha acá.
			 *
			 * @param int $user_id Usuario.
			 * @param int $points  Puntos regalados.
			 * @param int $year    Año.
			 */
			do_action( 'akb_rewards_birthday_paid', $user_id, $points, $year );
		}
	}
}
