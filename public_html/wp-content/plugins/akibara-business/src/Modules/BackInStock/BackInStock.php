<?php
/**
 * Orquesta la lista de espera de productos agotados: captura, detección
 * de reposición y envío con claim atómico por suscriptor. onbackorder queda fuera.
 * Captura y despacho tienen interruptores independientes; detección, aviso
 * de alta y baja permanecen activos. Con despacho apagado se conserva
 * la observabilidad mediante akb_bis_subscribers_ready.
 * El formulario requiere JavaScript. RateLimit protege por IP compartida
 * también a clientes autenticados y sólo exime a staff; su tope de 12/hora lo define
 * RateLimit::MAX_POR_TIPO['bis']. Las audiencias son distintas de SeriesFollow
 * (compradores de serie) y OrderPreventa (pedidos reservables).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BackInStock;

use Akibara\Business\Modules\Stock\StockStatus;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.53.0
 */
final class BackInStock {

	public const OPTION_CAPTURE          = 'akb_bis_capture_enabled';
	public const OPTION_DISPATCH         = 'akb_bis_dispatch_enabled';
	public const OPTION_NOTIFY_BACKORDER = 'akb_bis_notify_on_backorder';

	/** @var SubscriberRepository */
	private SubscriberRepository $repository;

	public function __construct() {
		global $wpdb;
		$this->repository = new SubscriberRepository( $wpdb, Install::table_name() );
	}

	/**
	 * @return SubscriberRepository
	 */
	public function repository(): SubscriberRepository {
		return $this->repository;
	}

	/**
	 * Conecta todos los submódulos. Llamado por Plugin::register_modules().
	 *
	 * @return void
	 */
	public function register(): void {
		Install::maybe_install();

		( new CaptureRestController( $this->repository ) )->register();
		( new CaptureForm() )->register();
		// Aviso de alta: SIEMPRE vivo, como la baja one-click. No cuelga de
		// ningún flag — es el consentimiento del titular del correo, no una
		// etapa operable del módulo.
		( new SubscriptionNotice() )->register();
		( new StockWatcher( $this->repository ) )->register();
		( new NotificationDispatcher( $this->repository ) )->register();
		( new UnsubscribeHandler( $this->repository ) )->register();
		( new PrivacyHandler( $this->repository ) )->register();
		( new Retention( $this->repository ) )->register();

		add_filter( 'woocommerce_email_classes', array( $this, 'register_email' ) );

		// Los CTAs "Avísame cuando vuelva" del loop (LoopEnhancer) y del
		// builder (SeriesBundles) gatean por estos filters (default false
		// post-cutover): este módulo es quien los enciende con la captura.
		add_filter( 'akibara_loop_waitlist_active', array( self::class, 'filter_waitlist_active' ) );
		add_filter( 'akb_sb_waitlist_active', array( self::class, 'filter_waitlist_active' ) );

		// Las primeras versiones del módulo guardaban los flags como "1"/"0".
		// WC_Admin_Settings usa "yes"/"no" para checkboxes; normalizamos una sola
		// vez al entrar al admin para que la UI refleje el estado real sin alterar
		// el comportamiento del frontend durante el despliegue.
		add_action( 'admin_init', array( self::class, 'normalize_legacy_flag_options' ), 5 );

		// Fila custom del form WC: conserva el ranking de demanda debajo de los
		// interruptores operables, en vez de empujarlos después de una tabla larga.
		add_action( 'woocommerce_admin_field_akb_bis_demand', array( $this, 'render_demand_field' ) );

		// UCSS: las clases que el JS togglea post-submit (.is-ok/.is-error/
		// .is-loading) no están en el HTML al generar el UCSS → LiteSpeed las
		// podaría y el feedback saldría sin estilos (M6; patrón Wishlist).
		add_filter( 'litespeed_ucss_whitelist', array( $this, 'allowlist_ucss_selectors' ), 20 );
	}

	/**
	 * Ajustes No-Code del módulo para el form estándar del AdminPanel.
	 *
	 * Desactivar captura no borra suscriptores existentes. Desactivar envíos
	 * funciona como corte operativo: el watcher mantiene la observabilidad y las
	 * filas quedan pendientes hasta volver a encender el despacho.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return array(
			array(
				'title' => 'Configuración de la lista de espera',
				'type'  => 'title',
				'id'    => 'akb_bis_settings',
				'desc'  => 'Controla por separado la captura de emails y el envío de avisos. Ninguno de estos interruptores borra las suscripciones guardadas.',
			),
			array(
				'title'    => 'Aceptar nuevas suscripciones',
				'id'       => self::OPTION_CAPTURE,
				'type'     => 'checkbox',
				'desc'     => 'Mostrar “Avísame cuando vuelva” en productos agotados',
				'desc_tip' => 'Al desactivarlo se ocultan el formulario y los CTAs asociados, pero se conserva la demanda existente.',
				'default'  => 'no',
			),
			array(
				'title'    => 'Enviar avisos de reposición',
				'id'       => self::OPTION_DISPATCH,
				'type'     => 'checkbox',
				'desc'     => 'Enviar el email cuando el producto vuelva a stock',
				'desc_tip' => 'Al desactivarlo se siguen detectando reposiciones, pero no se envían correos. Úsalo como corte seguro durante mantenimiento.',
				'default'  => 'no',
			),
			array(
				'title'    => 'Avisar también en «A pedido» y «Preventa»',
				'id'       => self::OPTION_NOTIFY_BACKORDER,
				'type'     => 'checkbox',
				'desc'     => 'Enviar el correo cuando un producto agotado se habilite para pedir o reservar',
				'desc_tip' => 'Recomendado: si un tomo agotado se vuelve a habilitar como «A pedido» o «Preventa», se avisa de inmediato indicando el estado comercial y el plazo estimado. Si lo desactivas, solo se avisará cuando entre a stock físico inmediato.',
				'default'  => 'yes',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_bis_settings',
			),
			array(
				'title' => 'Demanda por producto',
				'type'  => 'title',
				'id'    => 'akb_bis_demand_section',
				'desc'  => 'La columna “Esperando” ayuda a priorizar reposiciones. El ranking es informativo y no requiere operación manual.',
			),
			array(
				'type'      => 'akb_bis_demand',
				'id'        => 'akb_bis_demand',
				'is_option' => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_bis_demand_section',
			),
		);
	}

	/**
	 * Encabezado contextual del tab, antes del formulario de ajustes.
	 *
	 * @return void
	 */
	public function render_admin_section(): void {
		echo '<div class="wrap" style="max-width:900px;">';
		echo '<p>Activa o pausa cada etapa sin tocar código. ';
		echo '<strong>Captura</strong> controla nuevas altas; <strong>envío</strong> controla los correos cuando vuelve el stock. ';
		printf(
			'<span class="akb-bis-status">Estado actual: captura <strong>%s</strong> · envío <strong>%s</strong>.</span></p>',
			self::capture_enabled() ? 'activa' : 'apagada',
			self::dispatch_enabled() ? 'activo' : 'apagado'
		);
		echo self::wc_email_mismatch_notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya viene escapado dentro de la propia función.
		echo '</div>';
	}

	/**
	 * Advierte cuando el despacho propio está activo pero el WC_Email está
	 * apagado. MailerBoot::wc_email_enabled() comparte esa decisión con SeriesFollow.
	 * Un mailer todavía no resoluble devuelve null y no se trata como apagado.
	 * La factory permite probar el desacuerdo sin redefinir globalmente WC(),
	 * cuya presencia puede contaminar otros tests del mismo proceso.
	 *
	 * @param callable|null $email_factory Factory del email (pruebas); null en producción.
	 * @return string HTML del aviso, o cadena vacía si no hace falta.
	 */
	private static function wc_email_mismatch_notice_html( ?callable $email_factory = null ): string {
		if ( ! self::dispatch_enabled() ) {
			return '';
		}

		if ( false !== \Akibara\Business\Services\MailerBoot::wc_email_enabled( InstockEmail::class, $email_factory ) ) {
			return '';
		}

		return sprintf(
			'<p style="color:#996800;">⚠ Este panel dice envío <strong>activo</strong>, pero el correo está <strong>desactivado</strong> en WooCommerce → Ajustes → Emails. Mientras siga así no sale ni un aviso. <a href="%s">Revisar ahí →</a></p>',
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=email&section=' . strtolower( InstockEmail::EMAIL_ID ) ) )
		);
	}

	/**
	 * Campo custom de WooCommerce que mantiene el ranking dentro del mismo form,
	 * después de los interruptores. No contiene inputs ni modifica datos.
	 *
	 * @param array<string, mixed> $field Definición WC del campo.
	 * @return void
	 */
	public function render_demand_field( array $field ): void {
		unset( $field );
		$rows = $this->repository->demand_summary( 50 );

		echo '<tr valign="top"><td colspan="2" class="akb-bis-demand-field">';
		if ( array() === $rows ) {
			echo '<p>Nadie está esperando productos por ahora. Las suscripciones aparecerán aquí cuando alguien use “Avísame cuando vuelva” en un producto agotado.</p>';
			echo '</td></tr>';
			return;
		}

		// Un solo prime para todos los get_the_title/permalink del loop
		// (doctrina woocommerce-performance: sin N+1 de posts/meta).
		_prime_post_caches( array_column( $rows, 'product_id' ), false, false );

		// Los totales se cuentan sobre la tabla ENTERA, no sobre las filas que se
		// pintan. Dos motivos, y los dos se midieron: `$rows` incluye productos
		// con `waiting = 0` —los ya avisados del todo—, que inflaban el conteo de
		// productos (en prod decía 12 siendo 11); y `$rows` viene con LIMIT 50,
		// así que a partir del producto 51 el número de personas se quedaba corto
		// sin avisar.
		$totales   = $this->repository->waiting_totals();
		$personas  = (int) $totales['personas'];
		$productos = (int) $totales['productos'];
		printf(
			'<p><strong>%d</strong> %s esperando en <strong>%d</strong> %s.</p>',
			(int) $personas,
			1 === $personas ? 'persona' : 'personas',
			(int) $productos,
			1 === $productos ? 'producto' : 'productos'
		);

		// El corte de la tabla, dicho en voz alta: una lista truncada en
		// silencio se lee como «esto es todo lo que hay».
		$pintados = count( array_filter( $rows, static fn( array $r ): bool => $r['waiting'] > 0 ) );
		if ( $productos > $pintados ) {
			printf(
				'<p style="color:#996800;">Mostrando los %d productos con más demanda de <strong>%d</strong> en total.</p>',
				(int) $pintados,
				(int) $productos
			);
		}

		// La envoltura limita el desplazamiento horizontal a la tabla. Bajo 782px
		// el panel usa data-label para presentar las filas como tarjetas.
		echo '<div class="akb-bis-demand">';
		echo '<table class="widefat striped akb-bis-demand__table"><thead><tr>';
		echo '<th>Producto</th><th style="width:120px;">Estado</th><th style="width:110px;">Esperando</th><th style="width:110px;">Notificados</th><th style="width:110px;">Fallidos</th><th style="width:200px;">Última alta</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$title = get_the_title( $r['product_id'] );
			$title = '' !== $title ? $title : ( 'Producto #' . $r['product_id'] );
			$edit  = get_edit_post_link( $r['product_id'] );

			list( $estado, $color ) = self::product_state( (int) $r['product_id'] );

			echo '<tr>';
			echo '<td data-label="Producto">' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '</td>';
			echo '<td data-label="Estado"><span style="color:' . esc_attr( $color ) . ';">' . esc_html( $estado ) . '</span></td>';
			echo '<td data-label="Esperando"><strong>' . (int) $r['waiting'] . '</strong></td>';
			echo '<td data-label="Notificados">' . (int) $r['notified'] . '</td>';
			echo '<td data-label="Fallidos">' . ( $r['failed'] > 0 ? '<span style="color:#b32d2e;">' . (int) $r['failed'] . '</span>' : '0' ) . '</td>';
			echo '<td data-label="Última alta">' . esc_html( self::format_signup( (string) $r['last_signup'] ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
		echo '<p style="color:#666;font-size:12px;">Los fallidos agotaron 3 intentos de envío (detalle en WooCommerce → Estado → Registros, source <code>akibara-bis</code>). El texto del email se edita en WooCommerce → Ajustes → Emails → «Akibara · Volvió a stock».</p>';
		echo '<p style="color:#666;font-size:12px;">Esta lista <strong>no crece sin control</strong>: los avisos ya enviados y las bajas se borran solos a los 180 días. Lo único que se conserva es quién sigue esperando, porque esa espera es la señal de qué conviene reponer.</p>';
		echo '</td></tr>';
	}

	/**
	 * Estado del producto en lenguaje de tienda, para que la columna «Esperando»
	 * se pueda interpretar: 3 personas esperando un tomo agotado es lo normal;
	 * 3 esperando uno que ya está disponible es un aviso que no salió.
	 *
	 * @since 1.114.1
	 *
	 * @param int $product_id Producto.
	 * @return array{0: string, 1: string} Etiqueta y color.
	 */
	private static function product_state( int $product_id ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array( 'Eliminado', '#b32d2e' );
		}

		$post_status = get_post_status( $product_id );
		if ( 'publish' !== $post_status ) {
			return array( 'Despublicado', '#b32d2e' );
		}

		if ( StockStatus::ON_BACKORDER === StockStatus::of( $product ) ) {
			return array( StockStatus::palabra( StockStatus::ON_BACKORDER ), '#996800' );
		}

		return StockStatus::is_instock( $product )
			? array( 'Disponible', '#006505' )
			: array( 'Agotado', '#666' );
	}

	/**
	 * Convierte la fecha UTC del repositorio a la zona del sitio y añade
	 * una indicación relativa.
	 *
	 * @param string $utc Fecha `Y-m-d H:i:s` en UTC.
	 * @return string Texto ya listo para escapar.
	 */
	private static function format_signup( string $utc ): string {
		$utc = trim( $utc );
		if ( '' === $utc || 0 === strpos( $utc, '0000-00-00' ) ) {
			return '—';
		}

		$timestamp = strtotime( $utc . ' +00:00' );
		if ( false === $timestamp ) {
			return $utc;
		}

		$fecha = wp_date( 'd/m/Y H:i', $timestamp );
		if ( ! is_string( $fecha ) || '' === $fecha ) {
			return $utc;
		}

		return sprintf( '%s (hace %s)', $fecha, human_time_diff( $timestamp ) );
	}

	/**
	 * Protege del tree-shaking del UCSS los selectores del form que solo
	 * aparecen vía JS.
	 *
	 * @param array<int, string> $selectors Whitelist acumulada.
	 * @return array<int, string>
	 */
	public function allowlist_ucss_selectors( array $selectors ): array {
		$selectors[] = '.akb-bis';
		$selectors[] = '.akb-bis__msg.is-ok';
		$selectors[] = '.akb-bis__msg.is-error';
		$selectors[] = '.akb-bis__submit.is-loading';

		return array_values( array_unique( $selectors ) );
	}

	/**
	 * @param array<string, mixed> $emails Emails registrados en el mailer.
	 * @return array<string, mixed>
	 */
	public function register_email( array $emails ): array {
		$emails['AkibaraBisInstockEmail'] = new InstockEmail();
		return $emails;
	}

	/**
	 * OR con el valor entrante del filter (extensible por terceros): activo
	 * si la captura propia está encendida.
	 *
	 * @param bool $active Valor entrante del filter.
	 * @return bool
	 */
	public static function filter_waitlist_active( $active ): bool {
		return (bool) $active || self::capture_enabled();
	}

	/**
	 * ¿Captura habilitada? Option persistente detrás de filter (tests/QA).
	 *
	 * @return bool
	 */
	public static function capture_enabled(): bool {
		/**
		 * Habilita la captura de suscriptores (form PDP + REST + CTAs).
		 *
		 * @since 1.53.0
		 *
		 * @param bool $enabled Default: option akb_bis_capture_enabled (false).
		 */
		return (bool) apply_filters( 'akb_bis_capture_enabled', self::option_is_enabled( self::OPTION_CAPTURE ) );
	}

	/**
	 * ¿Envío de emails habilitado?
	 *
	 * @return bool
	 */
	public static function dispatch_enabled(): bool {
		/**
		 * Habilita el envío real de avisos (el dispatcher observa igual).
		 *
		 * @since 1.53.0
		 *
		 * @param bool $enabled Default: option akb_bis_dispatch_enabled (false).
		 */
		return (bool) apply_filters( 'akb_bis_dispatch_enabled', self::option_is_enabled( self::OPTION_DISPATCH ) );
	}

	/**
	 * ¿Avisar también cuando el producto agotado pase a «A pedido» o «Preventa»?
	 *
	 * @since 1.118.1
	 *
	 * @return bool
	 */
	public static function notify_on_backorder_enabled(): bool {
		$value   = get_option( self::OPTION_NOTIFY_BACKORDER, 'yes' );
		$enabled = ! in_array( $value, array( false, 0, '0', 'no', 'off' ), true );

		/**
		 * Habilita el aviso de lista de espera cuando un producto agotado pasa
		 * a `onbackorder` («A pedido» o «Preventa»), además de `instock`.
		 *
		 * @since 1.118.1
		 *
		 * @param bool $enabled Default: option akb_bis_notify_on_backorder (true).
		 */
		return (bool) apply_filters( 'akb_bis_notify_on_backorder', $enabled );
	}

	/**
	 * ¿Este estado de stock crudo dispara el aviso de lista de espera?
	 *
	 * @since 1.118.1
	 *
	 * @param string $status Estado crudo ('instock'|'onbackorder'|'outofstock').
	 * @return bool
	 */
	public static function is_notifiable_status( string $status ): bool {
		if ( StockStatus::IN_STOCK === $status ) {
			return true;
		}

		if ( StockStatus::ON_BACKORDER === $status ) {
			return self::notify_on_backorder_enabled();
		}

		return false;
	}

	/**
	 * Migra el formato legacy de flags al contrato yes/no de WC_Admin_Settings.
	 *
	 * @return void
	 */
	public static function normalize_legacy_flag_options(): void {
		foreach ( array( self::OPTION_CAPTURE, self::OPTION_DISPATCH, self::OPTION_NOTIFY_BACKORDER ) as $option ) {
			$value = get_option( $option, null );
			if ( '1' === $value || 1 === $value || true === $value || 'on' === $value ) {
				update_option( $option, 'yes', false );
			} elseif ( '0' === $value || 0 === $value ) {
				update_option( $option, 'no', false );
			}
		}
	}

	/**
	 * Lee tanto el formato histórico (1/on) como el formato WC (yes/no).
	 */
	private static function option_is_enabled( string $option ): bool {
		$value = get_option( $option, false );
		return in_array( $value, array( true, 1, '1', 'yes', 'on' ), true );
	}
}
