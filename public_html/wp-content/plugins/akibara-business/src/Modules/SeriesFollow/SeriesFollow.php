<?php
/**
 * SeriesFollow — «Salió el tomo N de tu serie».
 *
 * Avisa a quien ya compró tomos anteriores de una `pa_serie` cuando se publica
 * uno posterior que aún no tiene.
 *
 * La suscripción NO se almacena
 * -----------------------------
 * Es un hecho DERIVADO: un cliente sigue la serie S si (a) compró al menos un
 * producto con `pa_serie` = S en un pedido pagado y (b) no hizo opt-out de S.
 * (a) usa la MISMA autoridad que define «lo tenés» en toda la casa —
 * `SeriesBundles\CustomerCollection::purchase_statuses()`, filtrable por
 * `akibara_business_series_purchase_statuses`. La ventaja de que sea derivado y
 * no almacenado es concreta: un pedido que cae de `processing` a `cancelled`
 * deja de producir seguimiento en la lectura siguiente, sin evento de baja ni
 * fila que limpiar.
 *
 * Relación con el programa de puntos, dicha bien: el estado que acredita puntos
 * está DENTRO de ese conjunto, pero el conjunto es más ancho — el seguimiento
 * empieza antes, al pagar, y los puntos llegan después. Es contención, no
 * igualdad, y se fija con un test, no con un `require`: akibara-rewards viaja en
 * otro riel con contratos sellados propios.
 *
 * No hay backfill ni evento de alta. El día que se enciende el interruptor, los
 * clientes con compras pagadas ya siguen sus series; no se envía nada
 * retroactivo porque el disparador es la publicación de un tomo nuevo.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFollow;

use Akibara\Business\Contracts\EtaProvider;
use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Akibara\Business\Modules\SeriesBundles\CustomerCollection;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.2
 */
final class SeriesFollow {

	public const OPTION_ENABLED         = 'akb_sf_enabled';
	public const OPTION_TRIGGER_RESTOCK = 'akb_sf_trigger_on_restock';
	public const OPTION_VOLUME_SCOPE    = 'akb_sf_volume_scope';

	public const SCOPE_NEW_AND_GAPS = 'new_and_gaps';
	public const SCOPE_ONLY_NEW     = 'only_new';
	public const SCOPE_ALL_MISSING  = 'all_missing';

	/** Clave del correo en `woocommerce_email_classes`; su minúscula es la `section` del panel de Woo. */
	public const EMAIL_CLASS_KEY = 'AkibaraSeriesFollowEmail';
	public const OPTION_CAP_DAYS    = 'akb_sf_weekly_cap_days';
	public const OPTION_DELAY_HOURS = 'akb_sf_digest_delay_hours';
	public const OPTION_MAX_SERIES  = 'akb_sf_max_series_per_email';

	/** Grupo de Action Scheduler del módulo. */
	public const AS_GROUP = 'akibara-series-follow';

	/** Escaneo de un tomo recién publicado. */
	public const HOOK_SCAN = 'akb_sf_scan_product';

	/** Corrida del digest. */
	public const HOOK_DIGEST = 'akb_sf_digest_run';

	private const DEFAULT_CAP_DAYS    = 7;
	private const DEFAULT_DELAY_HOURS = 6;
	private const DEFAULT_MAX_SERIES  = 5;

	/** @var CustomerCollection */
	private CustomerCollection $collection;

	/** @var EtaProvider */
	private EtaProvider $eta;

	/** @var PedidoPolicy */
	private PedidoPolicy $policy;

	/** @var DigestQueue|null */
	private ?DigestQueue $queue = null;

	/**
	 * @param CustomerCollection $collection Autoridad de compras netas.
	 * @param EtaProvider        $eta        Calculador de fechas.
	 * @param PedidoPolicy       $policy     Política de cortes.
	 */
	public function __construct( CustomerCollection $collection, EtaProvider $eta, PedidoPolicy $policy ) {
		$this->collection = $collection;
		$this->eta        = $eta;
		$this->policy     = $policy;
	}

	/**
	 * Cola del módulo (la construye perezosamente sobre el `$wpdb` global).
	 *
	 * @return DigestQueue
	 */
	public function queue(): DigestQueue {
		if ( null === $this->queue ) {
			global $wpdb;
			$this->queue = new DigestQueue( $wpdb, Install::table_name() );
		}

		return $this->queue;
	}

	/**
	 * Cablea los submódulos. Llamado por Plugin::register_modules().
	 *
	 * @return void
	 */
	public function register(): void {
		Install::maybe_install();

		( new PublishListener() )->register();

		( new DigestRunner(
			$this->queue(),
			new FollowerFinder( $this->collection ),
			$this->collection,
			new CutoffPhrase( $this->eta, $this->policy )
		) )->register();

		// La baja, el interruptor por serie y el interruptor global de «Mi
		// colección» están SIEMPRE vivos, como la baja one-click de la lista de
		// espera: un enlace de baja muerto en un correo ya enviado es
		// incumplimiento, y el flag es del ENVÍO.
		( new UnfollowHandler() )->register();
		( new CollectionToggle() )->register();
		( new GlobalToggle() )->register();

		add_filter( 'woocommerce_email_classes', array( $this, 'register_email' ) );
		add_action( 'woocommerce_admin_field_akb_sf_pending', array( $this, 'render_pending_field' ) );
	}

	/**
	 * @param array<string, mixed> $emails Emails registrados en el mailer.
	 * @return array<string, mixed>
	 */
	public function register_email( array $emails ): array {
		$emails[ self::EMAIL_CLASS_KEY ] = new SeriesFollowEmail();
		return $emails;
	}

	/**
	 * Ajustes No-Code del módulo para el form estándar del AdminPanel.
	 *
	 * El asunto y el cuerpo NO están aquí: viven en los settings del `WC_Email`
	 * y el encabezado del tab enlaza directo a esa pantalla. Duplicarlos crearía
	 * dos fuentes de verdad para el mismo texto.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return array(
			array(
				'title' => 'Avisos de tomo nuevo',
				'type'  => 'title',
				'id'    => 'akb_sf_settings',
				'desc'  => 'Cuando publicas un tomo de una serie, avisamos a quien ya compró tomos anteriores y aún no tiene ése. Nada de esto borra datos: apagar solo detiene los envíos.',
			),
			array(
				'title'    => 'Enviar avisos de tomo nuevo',
				'id'       => self::OPTION_ENABLED,
				'type'     => 'checkbox',
				'desc'     => 'Avisar a quien sigue la serie',
				'desc_tip' => 'Es el interruptor maestro. Apagado no se detecta ni se envía nada, y no hay envío retroactivo: al encenderlo se avisan los tomos que publiques desde ese momento.',
				'default'  => 'no',
			),
			array(
				'title'    => 'Despertar al habilitar stock o pedidos',
				'id'       => self::OPTION_TRIGGER_RESTOCK,
				'type'     => 'checkbox',
				'desc'     => 'Avisar también cuando un tomo agotado pase a «Disponible», «A pedido» o «Preventa»',
				'desc_tip' => 'Recomendado: si publicas un tomo inicialmente agotado o vuelves a habilitar un tomo agotado como «Disponible», «A pedido» o «Preventa», se evalúa y avisa a los coleccionistas que correspondan.',
				'default'  => 'yes',
			),
			array(
				'title'    => 'Qué tomos avisar al coleccionista',
				'id'       => self::OPTION_VOLUME_SCOPE,
				'type'     => 'select',
				'default'  => self::SCOPE_NEW_AND_GAPS,
				'options'  => array(
					self::SCOPE_NEW_AND_GAPS => 'Tomos nuevos + huecos intermedios de su colección (recomendado)',
					self::SCOPE_ONLY_NEW     => 'Solo tomos posteriores al último que compró',
					self::SCOPE_ALL_MISSING  => 'Cualquier tomo de la serie que aún no haya comprado',
				),
				'desc_tip' => 'Con la opción recomendada, si un coleccionista compró en Akibara los tomos 1, 2, 4 y 10 y se habilita el 3, recibe el aviso para cerrar su hueco; pero si empezó a comprar desde el 8, no recibe avisos por los tomos 1 al 7.',
			),
			array(
				'title'             => 'Días mínimos entre correos a un mismo cliente',
				'id'                => self::OPTION_CAP_DAYS,
				'type'              => 'number',
				'default'           => (string) self::DEFAULT_CAP_DAYS,
				'custom_attributes' => array(
					'min'  => 1,
					'max'  => 30,
					'step' => 1,
				),
				'desc_tip'          => 'El tope. Con 7, nadie recibe más de un correo por semana por más tomos que publiques ese día.',
			),
			array(
				'title'             => 'Esperar antes de enviar (horas)',
				'id'                => self::OPTION_DELAY_HOURS,
				'type'              => 'number',
				'default'           => (string) self::DEFAULT_DELAY_HOURS,
				'custom_attributes' => array(
					'min'  => 0,
					'max'  => 72,
					'step' => 1,
				),
				'desc_tip'          => 'Tiempo de acumulación: todo lo que publiques dentro de esta ventana viaja en un solo correo. Súbelo el día que cargues muchos tomos de golpe.',
			),
			array(
				'title'             => 'Máximo de series por correo',
				'id'                => self::OPTION_MAX_SERIES,
				'type'              => 'number',
				'default'           => (string) self::DEFAULT_MAX_SERIES,
				'custom_attributes' => array(
					'min'  => 1,
					'max'  => 20,
					'step' => 1,
				),
				'desc_tip'          => 'Lo que no entra queda en cola y sale en el siguiente correo del cliente.',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_sf_settings',
			),
			array(
				'title' => 'Qué hay en cola',
				'type'  => 'title',
				'id'    => 'akb_sf_queue_section',
				'desc'  => 'Informativo. No requiere ninguna acción.',
			),
			array(
				'type'      => 'akb_sf_pending',
				'id'        => 'akb_sf_pending',
				'is_option' => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_sf_queue_section',
			),
		);
	}

	/**
	 * Encabezado contextual del tab, antes del formulario.
	 *
	 * @return void
	 */
	public function render_admin_section(): void {
		$pendientes = 0;
		foreach ( $this->queue()->pending_summary( 200 ) as $fila ) {
			$pendientes += (int) ( $fila['waiting'] ?? 0 );
		}

		echo '<div class="wrap" style="max-width:900px;">';
		printf(
			'<p>Estado actual: avisos <strong>%s</strong> · <strong>%d</strong> avisos en cola · próxima corrida: %s.</p>',
			self::enabled() ? 'activos' : 'apagados',
			(int) $pendientes,
			esc_html( self::next_run_label() )
		);
		// section corresponde a la clave de woocommerce_email_classes en minúsculas,
		// no al id interno del WC_Email.

		printf(
			'<p><a href="%s">Editar el texto del correo →</a></p>',
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=email&section=' . strtolower( self::EMAIL_CLASS_KEY ) ) )
		);
		echo self::wc_email_mismatch_notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya viene escapado dentro de la propia función.
		echo '</div>';
	}

	/**
	 * Informa cuando los avisos del módulo están activos pero su WC_Email no.
	 *
	 * MailerBoot::wc_email_enabled() consulta el interruptor de WooCommerce, separado
	 * del panel de Akibara. email_factory permite inyectar el correo en pruebas sin
	 * redefinir WC() globalmente ni contaminar el resto del proceso.
	 *
	 * @param callable|null $email_factory Factory del email (pruebas); null en producción.
	 * @return string HTML del aviso, o cadena vacía si no hace falta.
	 */
	private static function wc_email_mismatch_notice_html( ?callable $email_factory = null ): string {
		if ( ! self::enabled() ) {
			return '';
		}

		if ( false !== \Akibara\Business\Services\MailerBoot::wc_email_enabled( SeriesFollowEmail::class, $email_factory ) ) {
			return '';
		}

		return sprintf(
			'<p style="color:#996800;">⚠ Este panel dice avisos <strong>activos</strong>, pero el correo está <strong>desactivado</strong> en WooCommerce → Ajustes → Emails. Mientras siga así no sale ni un aviso. <a href="%s">Revisar ahí →</a></p>',
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=email&section=' . strtolower( self::EMAIL_CLASS_KEY ) ) )
		);
	}

	/**
	 * Cuándo corre el digest, en la zona de la tienda.
	 *
	 * @return string
	 */
	private static function next_run_label(): string {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return 'sin programar';
		}

		$next = as_next_scheduled_action( self::HOOK_DIGEST, null, self::AS_GROUP );
		if ( ! is_int( $next ) || $next < 1 ) {
			return 'sin programar';
		}

		$fecha = wp_date( 'd/m/Y H:i', $next );

		return is_string( $fecha ) && '' !== $fecha ? $fecha : 'sin programar';
	}

	/**
	 * Campo custom de WooCommerce con el resumen de la cola. No contiene inputs
	 * ni modifica datos.
	 *
	 * @param array<string, mixed> $field Definición WC del campo.
	 * @return void
	 */
	public function render_pending_field( array $field ): void {
		unset( $field );
		$rows = $this->queue()->pending_summary( 50 );

		echo '<tr valign="top"><td colspan="2" class="akb-sf-pending-field">';
		if ( array() === $rows ) {
			echo '<p>Todavía no hay avisos en cola. Aparecerán aquí cuando publiques un tomo de una serie que alguien ya está comprando.</p>';
			echo '</td></tr>';
			return;
		}

		// Un solo prime para todos los get_the_title del loop.
		_prime_post_caches( array_column( $rows, 'product_id' ), false, false );

		// Envoltura scrollable: en móvil la tabla no debe empujar la página; bajo
		// 960px (el breakpoint del form-table del panel, cuya regla más
		// específica manda aquí dentro) la hoja del panel la reapila en
		// tarjetas usando los data-label.
		echo '<div class="akb-sf-pending">';
		echo '<table class="widefat striped akb-sf-pending__table"><thead><tr>';
		echo '<th>Serie</th><th style="width:80px;">Tomo</th><th style="width:130px;">Clientes en cola</th><th style="width:110px;">Enviados</th><th style="width:170px;">Nombrados en otro aviso</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $fila ) {
			$product_id = (int) ( $fila['product_id'] ?? 0 );
			$term       = get_term( (int) ( $fila['term_id'] ?? 0 ) );
			$serie      = is_object( $term ) && isset( $term->name ) ? (string) $term->name : '';
			if ( '' === $serie ) {
				$titulo = (string) get_the_title( $product_id );
				$serie  = '' !== $titulo ? $titulo : ( 'Producto #' . $product_id );
			}
			$edit = get_edit_post_link( $product_id );

			echo '<tr>';
			echo '<td data-label="Serie">' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $serie ) . '</a>' : esc_html( $serie ) ) . '</td>';
			echo '<td data-label="Tomo">' . (int) ( $fila['volume'] ?? 0 ) . '</td>';
			echo '<td data-label="Clientes en cola"><strong>' . (int) ( $fila['waiting'] ?? 0 ) . '</strong></td>';
			echo '<td data-label="Enviados">' . (int) ( $fila['notified'] ?? 0 ) . '</td>';
			echo '<td data-label="Nombrados en otro aviso">' . (int) ( $fila['absorbed'] ?? 0 ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
		echo '<p style="color:#666;font-size:12px;">Los enviados se conservan a propósito: son la marca que impide avisar dos veces del mismo tomo al mismo cliente. La última columna son los clientes a los que este tomo se les nombró dentro del aviso de un tomo posterior de la misma serie —«tomos 71 al 74»—: no están sin avisar, simplemente no tuvieron ficha propia.</p>';
		echo '</td></tr>';
	}

	/**
	 * ¿Avisos habilitados? Option persistente detrás de filter (pruebas/QA).
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		/**
		 * Habilita la detección y el envío de avisos de tomo nuevo.
		 *
		 * @since 1.110.2
		 *
		 * @param bool $enabled Default: option akb_sf_enabled (false).
		 */
		return (bool) apply_filters( 'akb_sf_enabled', self::option_is_enabled( self::OPTION_ENABLED ) );
	}

	/**
	 * ¿Despertar el escaneo cuando un tomo pasa a estado comprable (`instock` / `onbackorder`)?
	 *
	 * @since 1.118.1
	 *
	 * @return bool
	 */
	public static function trigger_on_restock_enabled(): bool {
		$value   = get_option( self::OPTION_TRIGGER_RESTOCK, 'yes' );
		$enabled = ! in_array( $value, array( false, 0, '0', 'no', 'off' ), true );

		/**
		 * Habilita el escaneo de seguidores cuando un tomo pasa a estado
		 * comprable (`instock` u `onbackorder`).
		 *
		 * @since 1.118.1
		 *
		 * @param bool $enabled Default: option akb_sf_trigger_on_restock (true).
		 */
		return (bool) apply_filters( 'akb_sf_trigger_on_restock', $enabled );
	}

	/**
	 * Alcance de tomos a notificar: nuevos + huecos intermedios (default), solo
	 * nuevos (> max_owned) o cualquier faltante.
	 *
	 * @since 1.118.1
	 *
	 * @return string Uno de SCOPE_NEW_AND_GAPS|SCOPE_ONLY_NEW|SCOPE_ALL_MISSING.
	 */
	public static function volume_scope(): string {
		$raw     = (string) get_option( self::OPTION_VOLUME_SCOPE, self::SCOPE_NEW_AND_GAPS );
		$allowed = array( self::SCOPE_NEW_AND_GAPS, self::SCOPE_ONLY_NEW, self::SCOPE_ALL_MISSING );
		$scope   = in_array( $raw, $allowed, true ) ? $raw : self::SCOPE_NEW_AND_GAPS;

		/**
		 * Filtra el alcance de tomos que dispara el aviso de seguimiento de serie.
		 *
		 * @since 1.118.1
		 *
		 * @param string $scope Default: 'new_and_gaps'.
		 */
		$filtered = (string) apply_filters( 'akb_sf_volume_scope', $scope );

		return in_array( $filtered, $allowed, true ) ? $filtered : self::SCOPE_NEW_AND_GAPS;
	}

	/**
	 * Días mínimos entre correos a un mismo cliente.
	 *
	 * @return int
	 */
	public static function weekly_cap_days(): int {
		$value = (int) get_option( self::OPTION_CAP_DAYS, self::DEFAULT_CAP_DAYS );

		/**
		 * Tope de frecuencia por cliente, en días.
		 *
		 * @since 1.110.2
		 *
		 * @param int $days Default 7.
		 */
		return max( 1, (int) apply_filters( 'akb_sf_weekly_cap_days', $value > 0 ? $value : self::DEFAULT_CAP_DAYS ) );
	}

	/**
	 * Ventana de acumulación del digest, en segundos.
	 *
	 * @return int
	 */
	public static function digest_delay_seconds(): int {
		$raw   = get_option( self::OPTION_DELAY_HOURS, self::DEFAULT_DELAY_HOURS );
		$hours = is_scalar( $raw ) && '' !== (string) $raw ? (int) $raw : self::DEFAULT_DELAY_HOURS;

		/**
		 * Segundos de acumulación antes de enviar el digest.
		 *
		 * @since 1.110.2
		 *
		 * @param int $seconds Default 6 horas.
		 */
		return max( 0, (int) apply_filters( 'akb_sf_digest_delay_seconds', max( 0, $hours ) * HOUR_IN_SECONDS ) );
	}

	/**
	 * Bloques máximos por correo.
	 *
	 * @return int
	 */
	public static function max_series_per_email(): int {
		$value = (int) get_option( self::OPTION_MAX_SERIES, self::DEFAULT_MAX_SERIES );

		/**
		 * Máximo de series por correo.
		 *
		 * @since 1.110.2
		 *
		 * @param int $max Default 5.
		 */
		return max( 1, (int) apply_filters( 'akb_sf_max_series_per_email', $value > 0 ? $value : self::DEFAULT_MAX_SERIES ) );
	}

	/**
	 * Lee tanto el formato histórico (1/on) como el formato WC (yes/no).
	 *
	 * @param string $option Nombre de la option.
	 * @return bool
	 */
	private static function option_is_enabled( string $option ): bool {
		$value = get_option( $option, false );
		return in_array( $value, array( true, 1, '1', 'yes', 'on' ), true );
	}
}
