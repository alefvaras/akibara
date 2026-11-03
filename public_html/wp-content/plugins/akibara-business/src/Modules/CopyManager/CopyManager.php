<?php
/**
 * Copy Manager — Gestor de textos transaccionales sin código.
 *
 * Permite al administrador editar textos editoriales y de EstimatedDelivery
 * desde el AdminPanel sin tocar código.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\CopyManager;

use Akibara\Business\Modules\AccountCollection\Renderer as CollectionRenderer;
use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Akibara\Business\Modules\BrandsDirectory\EditionCopy;
use Akibara\Business\Modules\DisponiblesRoute\DisponiblesRoute;
use Akibara\Business\Modules\EstimatedDelivery\BannerRenderer;
use Akibara\Business\Modules\Forms\Settings as FormsSettings;
use Akibara\Business\Modules\GoogleLoginButton\AccessWall;
use Akibara\Business\Modules\HomeHero\Renderer as HeroRenderer;
use Akibara\Business\Modules\NativeCart\RejectionCopy;
use Akibara\Business\Modules\OrderPreventa\CustomerVisibility;
use Akibara\Business\Modules\PickupCoordination\PickupCoordination;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\OrderPreventa\PreventaUX;
use Akibara\Business\Modules\OrderPreventa\ShipPromise;
use Akibara\Business\Modules\PdpEnhancer\PdpSeriesEnhancer;
use Akibara\Business\Modules\PreventasRoute\PreventasRoute;
use Akibara\Business\Modules\QuickView\QuickView;
use Akibara\Business\Modules\Recommendations\Headings;
use Akibara\Business\Modules\SeriesBundles\LandingOffer;
use Akibara\Business\Modules\SeriesBundles\SelectionCart;
use Akibara\Business\Modules\SeriesEditions\SeriesEditions;
use Akibara\Business\Modules\SeriesFacts\SeriesFacts;
use Akibara\Business\Modules\Wishlist\GuestPrompt;
use Akibara\Business\Services\CheckoutTrustSignals;
use Akibara\Business\Utilities\Text;

defined( 'ABSPATH' ) || exit;

final class CopyManager {

	/** Pestaña del panel donde viven estos ajustes. */
	private const PANEL_TAB = 'copys';

	/**
	 * Textos que pinta el TEMA en la cabecera, en la búsqueda sin resultados y
	 * en las guías del blog (UC-16, UM-8 y UC-12 = UM-17). El nombre de cada
	 * option es el contrato con el tema, que
	 * los lee con `akibara_gp_copy()` y cae al mismo literal si la option está
	 * vacía o no existe: aquí sólo se registran para que no sean options
	 * invisibles, se sanean al guardar y el panel dice qué hace cada una.
	 */
	public const OPTION_SEARCH_PLACEHOLDER = 'akb_copy_search_placeholder';

	/**
	 * Título de la fila de los más vendidos que acompaña a una búsqueda sin
	 * resultados (UM-8) y al listado vacío de WooCommerce (R-I1-6).
	 */
	public const OPTION_SEARCH_EMPTY_POPULAR = 'akb_copy_search_empty_popular';

	public const OPTION_GUIDE_CTA_TEXT = 'akb_copy_guide_cta_text';

	public const OPTION_GUIDE_CTA_SHOP = 'akb_copy_guide_cta_shop';

	public const OPTION_GUIDE_CTA_SERIES = 'akb_copy_guide_cta_series';

	public const OPTION_GUIDE_CARD_KICKER = 'akb_copy_guide_card_kicker';

	public const OPTION_GUIDE_CARD_CTA = 'akb_copy_guide_card_cta';

	/**
	 * Marcador del nombre de la serie: botón del cierre de una guía y enlace a
	 * la serie en la barra de la ficha (`PdpSeriesEnhancer`).
	 */
	public const SERIES_MARKER = '{serie}';

	/**
	 * Literales de fábrica: los MISMOS que el tema usa de respaldo
	 * (themes/akibara-gp/inc/site-copy.php).
	 *
	 * @return array<string, string>
	 */
	public static function theme_copy_defaults(): array {
		return array(
			self::OPTION_SEARCH_PLACEHOLDER   => 'Buscar manga, series o autor…',
			self::OPTION_SEARCH_EMPTY_POPULAR => 'Lo más vendido',
			self::OPTION_GUIDE_CTA_TEXT       => '¿Te inspiró? Suma estos títulos a tu colección.',
			self::OPTION_GUIDE_CTA_SHOP       => 'Explorar la tienda',
			self::OPTION_GUIDE_CTA_SERIES     => 'Ver los tomos de ' . self::SERIES_MARKER,
			self::OPTION_GUIDE_CARD_KICKER    => 'La serie en Akibara',
			self::OPTION_GUIDE_CARD_CTA       => 'Ver los tomos',
		);
	}

	private AdminPanel $admin_panel;

	/**
	 * ¿Este guardado escribió de verdad «Guía: botón a la serie»? Lo marcan
	 * los ganchos de Core, que sólo se disparan cuando el valor cambia.
	 */
	private bool $guide_series_cta_written = false;

	/**
	 * ¿Este guardado escribió «Enlace a la página de la serie» (barra de la
	 * ficha)? Mismo mecanismo que el botón de la guía.
	 */
	private bool $pdp_series_link_written = false;

	public function __construct( AdminPanel $admin_panel ) {
		$this->admin_panel = $admin_panel;
	}

	public function register(): void {
		add_action( 'init', array( $this, 'register_admin_tab' ) );
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . PreventasRoute::OPTION_INTRO,
			array( $this, 'sanitize_preventas_intro' ),
			10,
			3
		);
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . PreventasRoute::OPTION_GUIDE,
			array( $this, 'sanitize_preventas_guide' ),
			10,
			3
		);
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . DisponiblesRoute::OPTION_INTRO,
			array( $this, 'sanitize_disponibles_intro' ),
			10,
			3
		);
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . FormsSettings::OPTION_NOTIFY_EMAIL,
			array( $this, 'sanitize_contact_email' ),
			10,
			3
		);

		// Copys de UNA línea. Se sanean al guardar —etiquetas fuera, saltos de
		// línea aplanados— porque todos se imprimen dentro de `esc_html()`: un
		// `<strong>` pegado a mano no se vería en negrita, se LEERÍA.
		foreach ( array_keys( self::required_one_line_copies() ) as $option ) {
			add_filter(
				'woocommerce_admin_settings_sanitize_option_' . $option,
				array( $this, 'sanitize_required_copy' ),
				10,
				3
			);
		}
		// Límite estricto de longitud para palabras de estado (badges, chips y botones),
		// evitando que textos sobredimensionados rompan el índice de búsqueda y los contratos de UI.
		// Prioridad 20: encima del saneador general de copy.
		foreach ( self::STATE_WORD_COPIES as $option ) {
			add_filter(
				'woocommerce_admin_settings_sanitize_option_' . $option,
				array( $this, 'sanitize_state_word' ),
				20,
				3
			);
		}

		foreach ( self::OPTIONAL_ONE_LINE_COPIES as $option ) {
			add_filter(
				'woocommerce_admin_settings_sanitize_option_' . $option,
				array( $this, 'sanitize_optional_copy' ),
				10,
				3
			);
		}

		add_filter( 'akibara_panel_options_not_saved', array( $this, 'report_rejected_values' ), 10, 2 );
		add_filter( 'akibara_panel_option_not_saved_reason', array( $this, 'option_not_saved_reason' ), 10, 3 );
		add_filter( 'akibara_panel_options_saved_with_warning', array( $this, 'report_saved_with_warning' ), 10, 2 );

		// Registra cuando los textos con marcador {serie} son guardados, permitiendo
		// emitir avisos contextuales solo si la configuración ha sido modificada.
		add_action( 'add_option_' . self::OPTION_GUIDE_CTA_SERIES, array( $this, 'note_guide_series_cta_written' ), 10, 0 );
		add_action( 'update_option_' . self::OPTION_GUIDE_CTA_SERIES, array( $this, 'note_guide_series_cta_written' ), 10, 0 );
		add_action( 'add_option_' . PdpSeriesEnhancer::OPTION_SERIES_LINK, array( $this, 'note_pdp_series_link_written' ), 10, 0 );
		add_action( 'update_option_' . PdpSeriesEnhancer::OPTION_SERIES_LINK, array( $this, 'note_pdp_series_link_written' ), 10, 0 );
	}

	/**
	 * Anota que este guardado escribió «Guía: botón a la serie».
	 */
	public function note_guide_series_cta_written(): void {
		$this->guide_series_cta_written = true;
	}

	/**
	 * Anota que este guardado escribió el enlace a la serie de la ficha.
	 */
	public function note_pdp_series_link_written(): void {
		$this->pdp_series_link_written = true;
	}

	/**
	 * Dice qué campos de esta pestaña no entraron como los escribió el dueño.
	 *
	 * `sanitize_contact_email()` cambia un correo mal escrito por el de siempre y
	 * Notifica de forma granular los campos rechazados en validación, permitiendo
	 * que las demás opciones válidas del formulario se guarden con éxito.
	 *
	 * @since 1.116.9
	 *
	 * @param array<int, string> $no_guardados Options que ya no entraron.
	 * @param string             $tab          Pestaña guardada.
	 * @return array<int, string>
	 */
	public function report_rejected_values( array $no_guardados, string $tab ): array {
		if ( self::PANEL_TAB !== $tab ) {
			return $no_guardados;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- AdminPanel::handle_save() verifica el nonce antes de este filtro.
		$correo = isset( $_POST[ FormsSettings::OPTION_NOTIFY_EMAIL ] ) && is_string( $_POST[ FormsSettings::OPTION_NOTIFY_EMAIL ] )
			? trim( sanitize_text_field( wp_unslash( $_POST[ FormsSettings::OPTION_NOTIFY_EMAIL ] ) ) )
			: '';
		$guia   = isset( $_POST[ PreventasRoute::OPTION_GUIDE ] ) && is_scalar( $_POST[ PreventasRoute::OPTION_GUIDE ] )
			? absint( wp_unslash( $_POST[ PreventasRoute::OPTION_GUIDE ] ) )
			: 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Vacío no es un error: en el correo es la forma de volver al de siempre.
		if ( '' !== $correo && ! is_email( $correo ) ) {
			$no_guardados[] = FormsSettings::OPTION_NOTIFY_EMAIL;
		}
		if ( $guia > 0 && $guia !== PreventasRoute::published_post_id( $guia ) ) {
			$no_guardados[] = PreventasRoute::OPTION_GUIDE;
		}

		return $no_guardados;
	}

	/**
	 * Canal de avisos para campos guardados que requieren revisión. AdminPanel
	 * los muestra sin negar el guardado. Informa recortes por longitud y el botón
	 * de guía sin {serie}; este último sólo se revisa cuando el campo cambia.
	 *
	 * @since 1.116.41
	 *
	 * @param array<string, string> $avisos option => qué revisar.
	 * @param string                $tab    Pestaña guardada.
	 * @return array<string, string>
	 */
	public function report_saved_with_warning( array $avisos, string $tab ): array {
		if ( self::PANEL_TAB !== $tab ) {
			return $avisos;
		}

		foreach ( $this->recortados() as $option ) {
			$avisos[ $option ] = 'Quedó recortado: no cabía entero.';
		}

		if ( $this->guide_series_cta_written && self::written_without_series_marker( self::OPTION_GUIDE_CTA_SERIES ) ) {
			$avisos[ self::OPTION_GUIDE_CTA_SERIES ] = 'Sin ' . self::SERIES_MARKER . ' el botón no nombra la serie. Escribe ' . self::SERIES_MARKER . ' donde va su nombre, o déjalo vacío para volver al de siempre.';
		}

		if ( $this->pdp_series_link_written && self::written_without_series_marker( PdpSeriesEnhancer::OPTION_SERIES_LINK ) ) {
			$avisos[ PdpSeriesEnhancer::OPTION_SERIES_LINK ] = 'Sin ' . self::SERIES_MARKER . ' el enlace no nombra la serie. Escribe ' . self::SERIES_MARKER . ' donde va su nombre, o déjalo vacío para volver al de siempre.';
		}

		return $avisos;
	}

	/**
	 * Motivo específico por el cual una opción de la pestaña no pudo guardarse.
	 *
	 * Proporciona retroalimentación contextual clara en lugar de un mensaje genérico
	 * para que el usuario conozca la acción correctiva necesaria.
	 *
	 * @param string $motivo_default Motivo por defecto.
	 * @param string $option         Option que no quedó escrita.
	 * @param string $tab            Pestaña guardada.
	 */
	public function option_not_saved_reason( string $motivo_default, string $option, string $tab ): string {
		if ( self::PANEL_TAB !== $tab ) {
			return $motivo_default;
		}

		if ( FormsSettings::OPTION_NOTIFY_EMAIL === $option ) {
			return sprintf(
				'Ese correo no es válido, así que se dejó el de siempre (%s). Corrígelo y guarda de nuevo.',
				FormsSettings::DEFAULT_NOTIFY_EMAIL
			);
		}

		if ( PreventasRoute::OPTION_GUIDE === $option ) {
			return 'Esa guía no está publicada, así que quedó la anterior. Publícala y vuelve a elegirla.';
		}

		return $motivo_default;
	}

	/**
	 * Detecta campos cuyo texto fue recortado por superar el límite de longitud.
	 *
	 * Itera sobre todas las opciones registradas en `required_one_line_copies()`, aplicando
	 * `Text::ONE_LINE_MAX` de forma general y `Availability::MAX_PALABRA` para palabras
	 * de estado comercial.
	 *
	 * @return array<int, string>
	 */
	private function recortados(): array {
		$topes = array();
		foreach ( array_keys( self::required_one_line_copies() ) as $option ) {
			$topes[ $option ] = in_array( $option, self::STATE_WORD_COPIES, true )
				? Availability::MAX_PALABRA
				: Text::ONE_LINE_MAX;
		}

		$recortados = array();
		foreach ( $topes as $option => $tope ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- AdminPanel::handle_save() verifica el nonce antes de este filtro.
			$escrito = isset( $_POST[ $option ] ) && is_string( $_POST[ $option ] )
				? sanitize_textarea_field( wp_unslash( $_POST[ $option ] ) )
				: '';
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			// `Text::would_clip()` evalúa el texto entrante antes de aplicar el saneador,
			// detectando si efectivamente excedió el límite máximo configurado.
			if ( Text::would_clip( $escrito, $tope ) ) {
				$recortados[] = $option;
			}
		}

		return $recortados;
	}

	/**
	 * ¿El dueño escribió este copy sin `{serie}`? Vacío no cuenta: vuelve al de
	 * fábrica, que lo trae.
	 *
	 * @param string $option Botón de la guía o enlace de la barra de la ficha.
	 */
	private static function written_without_series_marker( string $option ): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- AdminPanel::handle_save() verifica el nonce antes de este filtro.
		$escrito = isset( $_POST[ $option ] ) && is_string( $_POST[ $option ] )
			? Text::one_line( sanitize_textarea_field( wp_unslash( $_POST[ $option ] ) ), '' )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return '' !== $escrito && false === strpos( $escrito, self::SERIES_MARKER );
	}

	/**
	 * Copys de una línea que NO pueden quedar vacíos, con el literal al que cae
	 * cada uno.
	 *
	 * Vaciar cualquiera de éstos no es una decisión editorial: dejaría un enlace
	 * sin texto, una promesa sin plazo o —en el enlace a /disponibles/— la única
	 * puerta interna hacia esa ruta convertida en un `<a>` invisible.
	 *
	 * @return array<string, string>
	 */
	private static function required_one_line_copies(): array {
		return array(
			ShipPromise::OPTION_WINDOW                  => ShipPromise::DEFAULT_WINDOW,
			CheckoutTrustSignals::OPTION_INSTALLMENTS   => CheckoutTrustSignals::DEFAULT_INSTALLMENTS,
			PreventasRoute::OPTION_GUIDE_PREFIX         => PreventasRoute::GUIDE_PREFIX,
			PreventasRoute::OPTION_GUIDE_LINK           => PreventasRoute::GUIDE_LINK_COPY,
			PreventasRoute::OPTION_SIBLING_PREFIX       => PreventasRoute::SIBLING_PREFIX,
			PreventasRoute::OPTION_SIBLING_LINK         => PreventasRoute::SIBLING_LINK_COPY,
			DisponiblesRoute::OPTION_SIBLING_PREFIX     => DisponiblesRoute::SIBLING_PREFIX,
			DisponiblesRoute::OPTION_SIBLING_LINK       => DisponiblesRoute::SIBLING_LINK_COPY,
			CustomerVisibility::OPTION_OVERDUE          => CustomerVisibility::DEFAULT_OVERDUE,

			// Textos de fecha y promesas: la sanitización asegura que campos guardados vacíos
			// adopten su valor por defecto en lugar de almacenar cadenas vacías en base de datos.
			'akb_copy_eta_cart_ready'                   => 'Tu pedido completo llega',
			'akb_copy_eta_cart_preventa'                => 'Tu pedido incluye reservas',
			'akb_copy_eta_tba'                          => 'Te avisamos en cuanto tengamos fecha',
			'akb_copy_eta_single_preventa'              => 'Preventa',
			'akb_copy_eta_single_pedido'                => 'A pedido',
			'akb_copy_eta_single_ready'                 => 'Fecha estimada de llegada',
			'akb_copy_eta_noun_preventa'                => 'preventa',
			'akb_copy_eta_noun_pedido'                  => 'productos a pedido',
			'akb_copy_eta_item_arrived'                 => 'Ya llegó a nuestra bodega',
			BannerRenderer::OPTION_BARRA                => BannerRenderer::DEFAULT_BARRA,
			'akb_copy_promise_dispatch'                 => 'Despacha en 24–48 h',
			'akb_copy_promise_preventa_transit'         => 'En camino a Akibara',
			'akb_copy_promise_pedido_transit'           => 'Lo pedimos a la editorial por ti',
			PreventaUX::OPTION_CTA_PREVENTA             => PreventaUX::DEFAULT_CTA_PREVENTA,
			PickupCoordination::OPTION_TITULO           => PickupCoordination::DEFAULT_TITULO,
			PickupCoordination::OPTION_CUERPO           => PickupCoordination::DEFAULT_CUERPO,
		) + EditionCopy::defaults() + AccessWall::defaults() + Headings::defaults()
			+ SeriesFacts::copy_defaults() + LandingOffer::copy_defaults() + GuestPrompt::defaults() + PdpSeriesEnhancer::copy_defaults()
			+ self::theme_copy_defaults() + FormsSettings::message_defaults() + RejectionCopy::defaults() + SelectionCart::defaults()
			+ array(
				QuickView::OPTION_LABEL      => QuickView::DEFAULT_LABEL,
				SeriesEditions::OPTION_LABEL => SeriesEditions::DEFAULT_LABEL,
				HeroRenderer::OPTION_LABEL   => HeroRenderer::DEFAULT_LABEL,
			);
	}

	/**
	 * Copys de una línea donde el VACÍO es la forma de apagar la línea: el pie
	 * no pinta un reloj sin horario ni un `tel:` sin número.
	 *
	 * @var array<int, string>
	 */
	/**
	 * Los dos textos que nombran un estado comercial. {@see self::sanitize_state_word()}.
	 *
	 * @var array<int, string>
	 */
	private const STATE_WORD_COPIES = array(
		'akb_copy_eta_single_pedido',
		'akb_copy_eta_single_preventa',
	);

	private const OPTIONAL_ONE_LINE_COPIES = array(
		'akb_copy_footer_support_hours',
		'akb_copy_footer_phone',
		CollectionRenderer::OPTION_DELAY_NOTICE,
	);

	/**
	 * Sanea un copy obligatorio: vacío o ilegible vuelve a su literal.
	 *
	 * @param mixed               $value     Valor preprocesado por WooCommerce.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo del formulario.
	 */
	public function sanitize_required_copy( $value, array $option = array(), $raw_value = null ): string {
		$id       = (string) ( $option['id'] ?? '' );
		$fallback = self::required_one_line_copies()[ $id ] ?? '';

		return Text::one_line( null !== $raw_value ? $raw_value : $value, $fallback );
	}

	/**
	 * Sanea un copy apagable: se limpia igual, pero el vacío se GUARDA.
	 *
	 * @param mixed               $value     Valor preprocesado por WooCommerce.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo del formulario.
	 */
	/**
	 * Acota la palabra de un estado comercial a lo que cabe en un badge.
	 *
	 * Se recorta en vez de rechazar: el resto de la pestaña se guarda igual y
	 * `report_saved_with_warning()` le dice al dueño qué campo quedó recortado.
	 *
	 * @param mixed               $value     Valor ya aplanado por el saneador de copy.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo del formulario.
	 */
	public function sanitize_state_word( $value, array $option = array(), $raw_value = null ): string {
		unset( $option, $raw_value );
		$palabra = is_string( $value ) ? $value : '';

		return Text::length( $palabra ) > Availability::MAX_PALABRA
			? rtrim( Text::substr( $palabra, 0, Availability::MAX_PALABRA ) )
			: $palabra;
	}

	public function sanitize_optional_copy( $value, array $option = array(), $raw_value = null ): string {
		unset( $option );

		return Text::one_line( null !== $raw_value ? $raw_value : $value, '' );
	}

	public function register_admin_tab(): void {
		$this->admin_panel->add_section(
			self::PANEL_TAB,
			'Textos',
			array( $this, 'get_settings' ),
			null,
			false,
			'',
			'sitio'
		);
	}

	/**
	 * Devuelve la definición declarativa de la pestaña Textos.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_settings(): array {
		return array(
			array(
				'title' => 'Textos editables',
				'type'  => 'title',
				'id'    => 'akb_copys_settings',
				'desc'  => 'Configura los mensajes visibles más importantes del sitio sin tocar código.',
			),
			array(
				'title'   => 'Banner ETA: Carrito Listo',
				'desc'    => 'Texto cuando todo el carrito está en stock.',
				'id'      => 'akb_copy_eta_cart_ready',
				'type'    => 'text',
				'default' => 'Tu pedido completo llega',
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Banner ETA: Carrito con reservas',
				'desc'    => 'Texto cuando el carrito incluye tomos a pedido o en preventa.',
				'id'      => 'akb_copy_eta_cart_preventa',
				'type'    => 'text',
				'default' => 'Tu pedido incluye reservas',
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Banner ETA: Pedido/Preventa (Sin fecha)',
				'desc'    => 'Texto secundario cuando no hay fecha exacta (TBA).',
				'id'      => 'akb_copy_eta_tba',
				'type'    => 'text',
				'default' => 'Te avisamos en cuanto tengamos fecha',
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'La palabra «Preventa»',
				'desc'    => 'La palabra con la que se nombra una PREVENTA real (un tomo que todavía no sale) en TODO el sitio: el badge de la tarjeta del catálogo, el cartel de la ficha, los chips de la landing de la serie, el carrito, la línea del pedido y los correos.',
				'id'      => 'akb_copy_eta_single_preventa',
				'type'    => 'text',
				'default' => 'Preventa',
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'La palabra «A pedido»',
				'desc'    => 'La palabra con la que se nombra un tomo A PEDIDO (ya publicado, se trae en el próximo pedido a la editorial) en TODO el sitio: badge de la tarjeta, cartel de la ficha, botón de agregar, chips de la landing, buscador, carrito, línea del pedido y correos. Es una palabra, no una frase: hasta 24 caracteres (lo que pase de ahí se recorta al guardar). Cambiarla reindexa el buscador para que el filtro diga lo mismo.',
				'id'      => 'akb_copy_eta_single_pedido',
				'type'    => 'text',
				'default' => 'A pedido',
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Retiro: título del aviso',
				'desc'    => 'Encabezado del recuadro que ve quien elige retirar, en el correo del pedido, en la página de gracias y en Mi cuenta.',
				'id'      => PickupCoordination::OPTION_TITULO,
				'type'    => 'text',
				'default' => PickupCoordination::DEFAULT_TITULO,
				'css'     => 'width: min(100%, 680px);',
			),
			array(
				'title'   => 'Retiro: cómo se coordina',
				'desc'    => 'Explica al cliente qué pasa después de comprar y dónde es la entrega. Una línea, hasta 300 caracteres: lo que pase de ahí se recorta al guardar, y este texto va en un correo que no se puede retirar. Si cambias el punto de retiro, cámbialo también en el título de arriba. El correo de contacto que aparece debajo es el del formulario de Contacto, más abajo en esta misma pestaña.',
				'id'      => PickupCoordination::OPTION_CUERPO,
				'type'    => 'text',
				'default' => PickupCoordination::DEFAULT_CUERPO,
				'css'     => 'width: min(100%, 680px);',
			),
			array(
				'title'   => 'Botón de una preventa',
				'desc'    => 'Texto del botón de agregar al carrito cuando el tomo es una preventa real. El botón de un tomo «a pedido» usa la palabra de arriba, porque nombra el estado.',
				'id'      => PreventaUX::OPTION_CTA_PREVENTA,
				'type'    => 'text',
				'default' => PreventaUX::DEFAULT_CTA_PREVENTA,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Barra de compra: llegada',
				'desc'    => 'Línea corta bajo el precio en la barra de compra fija de la ficha, sólo en los tomos a pedido o en preventa con fecha. Escribe ' . BannerRenderer::MARCADOR_FECHA . ' donde va el rango de fechas (por ejemplo «7 – 13 nov»); si lo borras, la fecha se añade al final. Vacío vuelve a «' . BannerRenderer::DEFAULT_BARRA . '».',
				'id'      => BannerRenderer::OPTION_BARRA,
				'type'    => 'text',
				'default' => BannerRenderer::DEFAULT_BARRA,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Botón de vista rápida',
				'desc'    => 'Rótulo del botón con forma de ojo sobre la portada de cada tomo del catálogo (sólo en computador). Aparece al pasar el ratón o con el teclado, y el lector de pantalla lo lee con el nombre del tomo.',
				'id'      => QuickView::OPTION_LABEL,
				'type'    => 'text',
				'default' => QuickView::DEFAULT_LABEL,
				'css'     => 'width: 300px;',
			),
			array(
				'title'   => 'Banner ETA: Fecha Estimada Singular',
				'desc'    => 'Label principal en producto individual en stock.',
				'id'      => 'akb_copy_eta_single_ready',
				'type'    => 'text',
				'default' => 'Fecha estimada de llegada',
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Cartel del pedido: sustantivo para preventa',
				'desc'    => 'Va dentro de la frase «Tu pedido incluye …» del cartel del correo y de Mi cuenta cuando alguna línea en espera es una preventa de verdad.',
				'id'      => 'akb_copy_eta_noun_preventa',
				'type'    => 'text',
				'default' => 'preventa',
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Cartel del pedido: sustantivo para a pedido',
				'desc'    => 'Va dentro de la frase «Tu pedido incluye …» cuando lo que se espera son tomos a pedido.',
				'id'      => 'akb_copy_eta_noun_pedido',
				'type'    => 'text',
				'default' => 'productos a pedido',
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Cartel del pedido: fecha estimada ya pasada',
				'desc'    => 'Sustituye a «Estimamos que el último producto llega el …» cuando esa fecha ya pasó y el tomo sigue sin llegar (la editorial se atrasó). Escribe %s una vez donde va la fecha; si lo omites, la fecha se añade al final. Vacío vuelve al texto original.',
				'id'      => 'akb_copy_eta_overdue',
				'type'    => 'text',
				'default' => CustomerVisibility::DEFAULT_OVERDUE,
				'css'     => 'width: 600px;',
			),
			array(
				'title'   => 'Línea del pedido: tomo ya llegado',
				'desc'    => 'Reemplaza la fecha estimada en la línea del pedido y del correo cuando ese tomo ya está en bodega. Se le añade el país de origen.',
				'id'      => 'akb_copy_eta_item_arrived',
				'type'    => 'text',
				'default' => 'Ya llegó a nuestra bodega',
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Ediciones: rótulo del conmutador',
				'desc'    => 'En /marca/<edición>/, encima de los botones Argentina · España. «%s» es el nombre de la familia (Panini, Ivrea).',
				'id'      => EditionCopy::OPTION_SWITCH_LABEL,
				'type'    => 'text',
				'default' => EditionCopy::DEFAULT_SWITCH_LABEL,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Ediciones: enlace a la landing de la familia',
				'desc'    => 'El enlace terciario del conmutador hacia /panini/ o /ivrea/.',
				'id'      => EditionCopy::OPTION_SWITCH_COMPARE,
				'type'    => 'text',
				'default' => EditionCopy::DEFAULT_SWITCH_COMPARE,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Ediciones: botón de cada tarjeta',
				'desc'    => 'En la landing de la familia. «%s» es el nombre de la edición (Panini Argentina).',
				'id'      => EditionCopy::OPTION_CARD_CTA,
				'type'    => 'text',
				'default' => EditionCopy::DEFAULT_CARD_CTA,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Ediciones: conteo de la tarjeta',
				'desc'    => '«%s» es el número de productos de la edición (dos o más).',
				'id'      => EditionCopy::OPTION_CARD_COUNT,
				'type'    => 'text',
				'default' => EditionCopy::DEFAULT_CARD_COUNT,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Ediciones: conteo de la tarjeta con un solo producto',
				'desc'    => 'Sin marcador: la edición tiene exactamente un producto.',
				'id'      => EditionCopy::OPTION_CARD_COUNT_ONE,
				'type'    => 'text',
				'default' => EditionCopy::DEFAULT_CARD_COUNT_ONE,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Ediciones: precio «desde»',
				'desc'    => '«%s» es el precio más bajo con stock de la edición, calculado solo.',
				'id'      => EditionCopy::OPTION_PRICE_FROM,
				'type'    => 'text',
				'default' => EditionCopy::DEFAULT_PRICE_FROM,
				'css'     => 'width: 400px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_copys_settings',
			),
			array(
				'title' => 'Promesas de la tienda',
				'type'  => 'title',
				'id'    => 'akb_copys_promises',
				'desc'  => 'Compromisos operativos y financieros visibles al cliente. Permite ajustar centralizadamente los plazos de despacho y condiciones de entrega.',
			),
			array(
				'title'   => 'Disponibilidad en producto (en stock)',
				'desc'    => 'Se muestra en la ficha de cada producto con stock. Es una promesa de despacho: mantenla realista.',
				'id'      => 'akb_copy_promise_dispatch',
				'type'    => 'text',
				'default' => 'Despacha en 24–48 h',
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Disponibilidad en producto (preventa)',
				'desc'    => 'Se muestra en la ficha de una PREVENTA real (aún no sale).',
				'id'      => 'akb_copy_promise_preventa_transit',
				'type'    => 'text',
				'default' => 'En camino a Akibara',
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Disponibilidad en producto (a pedido)',
				'desc'    => 'Se muestra en la ficha de un tomo A PEDIDO (ya publicado; se trae en el próximo pedido a la editorial).',
				'id'      => 'akb_copy_promise_pedido_transit',
				'type'    => 'text',
				'default' => 'Lo pedimos a la editorial por ti',
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Ventana de despacho de una preventa lista',
				'desc'    => 'El plazo que se promete cuando la preventa ya llegó a bodega. Se usa TAL CUAL dentro de seis frases '
					. '(«Despachamos en …», «Sale en los próximos …»), en el detalle del pedido y en los cuatro correos de preventa, '
					. 'así que escribe sólo el plazo. Vacío vuelve al de fábrica.',
				'id'      => ShipPromise::OPTION_WINDOW,
				'type'    => 'text',
				'default' => ShipPromise::DEFAULT_WINDOW,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Sello de confianza: cuotas',
				'desc'    => 'Aparece en el checkout, en los sellos de la ficha del producto, en el FAQ «¿Cómo comprar…?» y en el carrito vacío. '
					. 'Debe reflejar las condiciones reales del medio de pago.',
				'id'      => CheckoutTrustSignals::OPTION_INSTALLMENTS,
				'type'    => 'text',
				'default' => CheckoutTrustSignals::DEFAULT_INSTALLMENTS,
				'css'     => 'width: 400px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_copys_promises',
			),
			array(
				'title' => 'Carrito: cuando no se puede agregar',
				'type'  => 'title',
				'id'    => 'akb_copys_cart_rejection',
				'desc'  => 'Lo que lee el cliente cuando toca «Añadir al carrito» y el tomo no se puede agregar. Sale junto al botón, en la tarjeta del catálogo, en la barra de compra de la ficha y en el aviso de la página. {producto} se cambia por el nombre del tomo. '
					. 'Los tres de los packs salen bajo el botón de «Arma tu pack» (en la ficha) y de «Elegir tomos» (en la página de la serie); hablan de varios tomos a la vez y no llevan {producto}.',
			),
			array(
				'title'   => 'Ya tiene todas las unidades',
				'desc'    => 'Cuando el carrito ya tiene todas las unidades que quedan de ese tomo; el botón pasa a «Ver carrito». Es lo más frecuente en la tienda: muchos tomos tienen una sola unidad. Vacío vuelve a «' . RejectionCopy::DEFAULT_YA_EN_CARRITO . '».',
				'id'      => RejectionCopy::OPTION_YA_EN_CARRITO,
				'type'    => 'text',
				'default' => RejectionCopy::DEFAULT_YA_EN_CARRITO,
				'css'     => 'width: min(100%, 680px);',
			),
			array(
				'title'   => 'Tomo agotado',
				'desc'    => 'Cuando el tomo se agotó después de que el cliente abrió la página. Vacío vuelve a «' . RejectionCopy::DEFAULT_SIN_STOCK . '».',
				'id'      => RejectionCopy::OPTION_SIN_STOCK,
				'type'    => 'text',
				'default' => RejectionCopy::DEFAULT_SIN_STOCK,
				'css'     => 'width: min(100%, 680px);',
			),
			array(
				'title'   => 'Packs: ya están en el carrito',
				'desc'    => 'Cuando el cliente vuelve a elegir tomos que ya están en su carrito con todas las unidades que quedan. Los tomos se desmarcan solos: no hay nada que reintentar. Vacío vuelve a «' . SelectionCart::DEFAULT_YA_EN_CARRITO . '».',
				'id'      => SelectionCart::OPTION_YA_EN_CARRITO,
				'type'    => 'text',
				'default' => SelectionCart::DEFAULT_YA_EN_CARRITO,
				'css'     => 'width: min(100%, 680px);',
			),
			array(
				'title'   => 'Packs: tomos agotados',
				'desc'    => 'Cuando alguno de los tomos elegidos se agotó después de que el cliente abrió la página. Los agotados se desmarcan solos. Vacío vuelve a «' . SelectionCart::DEFAULT_SIN_STOCK . '».',
				'id'      => SelectionCart::OPTION_SIN_STOCK,
				'type'    => 'text',
				'default' => SelectionCart::DEFAULT_SIN_STOCK,
				'css'     => 'width: min(100%, 680px);',
			),
			array(
				'title'   => 'Packs: no se pudo agregar',
				'desc'    => 'Cuando no se sabe por qué no entraron (o se cortó la conexión): los tomos siguen marcados y reintentar puede servir. Vacío vuelve a «' . SelectionCart::DEFAULT_ERROR . '».',
				'id'      => SelectionCart::OPTION_ERROR,
				'type'    => 'text',
				'default' => SelectionCart::DEFAULT_ERROR,
				'css'     => 'width: min(100%, 680px);',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_copys_cart_rejection',
			),
			array(
				'title' => 'Ficha: barra de la serie',
				'type'  => 'title',
				'id'    => 'akb_copys_pdp_series',
				'desc'  => 'La barra bajo el título de cada tomo, con el tomo anterior, el siguiente y el enlace a la página de la serie.',
			),
			array(
				'title'   => 'Enlace a la página de la serie',
				'desc'    => 'Va en la barra bajo el título de cada tomo y lleva a la página de la serie. Escribe ' . self::SERIES_MARKER . ' donde va su nombre: es lo que Google lee para saber adónde lleva el enlace. Sin el marcador sale tal cual (el panel te avisa). Vacío vuelve a «' . PdpSeriesEnhancer::DEFAULT_SERIES_LINK . '».',
				'id'      => PdpSeriesEnhancer::OPTION_SERIES_LINK,
				'type'    => 'text',
				'default' => PdpSeriesEnhancer::DEFAULT_SERIES_LINK,
				'css'     => 'width: 400px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_copys_pdp_series',
			),
			array(
				'title' => 'Recomendaciones de la ficha',
				'type'  => 'title',
				'id'    => 'akb_copys_reco',
				'desc'  => 'Los títulos de los dos bloques de tomos que van debajo de la ficha. Qué tomos entran y en qué orden se decide en la pestaña Recomendaciones.',
			),
			array(
				'title'   => 'Título de los siguientes tomos de la serie',
				'desc'    => 'Encabezado del bloque con los siguientes tomos de la MISMA serie, en orden de lectura. Vacío vuelve a «' . Headings::DEFAULT_UPSELLS . '».',
				'id'      => Headings::OPTION_UPSELLS,
				'type'    => 'text',
				'default' => Headings::DEFAULT_UPSELLS,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Título de los tomos de otras series',
				'desc'    => 'Encabezado del bloque de más abajo: con la regla «Empezar otra serie» de Recomendaciones, cada tarjeta es el primer tomo de una serie distinta. Vacío vuelve a «' . Headings::DEFAULT_RELATED . '».',
				'id'      => Headings::OPTION_RELATED,
				'type'    => 'text',
				'default' => Headings::DEFAULT_RELATED,
				'css'     => 'width: 400px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_copys_reco',
			),
			array(
				'title' => 'Landing de serie',
				'type'  => 'title',
				'id'    => 'akb_copys_series_landing',
				'desc'  => 'Textos de las páginas /serie/…: la línea de resumen que va sobre los tomos, el atajo al último tomo y la barra «Completa tu colección».',
			),
			array(
				'title'   => 'Otras ediciones de la obra',
				'desc'    => 'Rótulo bajo el título de la serie, delante de los enlaces a las otras ediciones de la misma obra (por ejemplo, «One Piece 3 en 1»). Sólo sale en las series que tienen ediciones relacionadas. Mejor corto: va en una sola línea con los enlaces. Vacío vuelve a «' . SeriesEditions::DEFAULT_LABEL . '».',
				'id'      => SeriesEditions::OPTION_LABEL,
				'type'    => 'text',
				'default' => SeriesEditions::DEFAULT_LABEL,
				'css'     => 'width: 300px;',
			),
			array(
				'title'   => 'Enlace a la ficha de la serie',
				'desc'    => 'Va al final de la línea de resumen, sobre los tomos, y baja a la ficha completa (estado de publicación, disponibilidad, precios, autor y editorial), que está debajo de los tomos. Vacío vuelve a «' . SeriesFacts::DEFAULT_SUMMARY_LINK . '».',
				'id'      => SeriesFacts::OPTION_SUMMARY_LINK,
				'type'    => 'text',
				'default' => SeriesFacts::DEFAULT_SUMMARY_LINK,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Atajo al último tomo',
				'desc'    => 'Va junto al total de productos, sobre los tomos, y abre la ficha del tomo de número más alto de la serie. Sale en las series con dos tomos o más, en cualquier orden y página. Escribe ' . SeriesFacts::VOLUME_MARKER . ' donde quieras el número de ese tomo (va solo); si borras el marcador, el texto sale tal cual, sin número. Vacío vuelve a «' . SeriesFacts::DEFAULT_LATEST_VOLUME . '».',
				'id'      => SeriesFacts::OPTION_LATEST_VOLUME,
				'type'    => 'text',
				'default' => SeriesFacts::DEFAULT_LATEST_VOLUME,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Descuento del pack, junto al título',
				'desc'    => 'En «Completa tu colección», bajo el título. Escribe ' . LandingOffer::PCT_MARKER . ' donde quieras el descuento más alto de esa serie (el número va solo; el «%» lo pones tú). Si borras el marcador, el texto sale tal cual, sin número. Al tocarlo se abren los tramos y las condiciones. Vacío vuelve a «' . LandingOffer::DEFAULT_OFFER_HOOK . '».',
				'id'      => LandingOffer::OPTION_OFFER_HOOK,
				'type'    => 'text',
				'default' => LandingOffer::DEFAULT_OFFER_HOOK,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Enlace a las condiciones del pack',
				'desc'    => 'Va a continuación del descuento: al tocarlo se muestran los tramos («Desde 5 tomos · 8% dto.») y las condiciones (se aplica al sumarlos al carrito, cada tomo distinto cuenta una vez…). Vacío vuelve a «' . LandingOffer::DEFAULT_RULES_TOGGLE . '».',
				'id'      => LandingOffer::OPTION_RULES_TOGGLE,
				'type'    => 'text',
				'default' => LandingOffer::DEFAULT_RULES_TOGGLE,
				'css'     => 'width: 400px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_copys_series_landing',
			),
			array(
				'title' => 'Contenido de preventas',
				'type'  => 'title',
				'id'    => 'akb_preventas_content',
				'desc'  => 'Contenido editorial de la primera página de A pedido (/a-pedido/). No se repite en filtros ni paginación.',
			),
			array(
				'title'   => 'Introducción',
				'desc'    => 'Explica el modelo de reserva. Admite &lt;strong&gt; para negrita y &lt;em&gt; para cursiva; scripts, enlaces y títulos se eliminan al guardar.',
				'id'      => PreventasRoute::OPTION_INTRO,
				'type'    => 'textarea',
				'default' => PreventasRoute::DEFAULT_INTRO_CONTENT,
				'css'     => 'width: min(100%, 680px); min-height: 120px;',
			),
			array(
				'title'   => 'Guía de preventas',
				'desc'    => 'Entrada publicada que se enlaza debajo de la introducción. “Sin guía” retira el CTA.',
				'id'      => PreventasRoute::OPTION_GUIDE,
				'type'    => 'select',
				'default' => '0',
				'options' => $this->published_post_options(),
				'css'     => 'width: min(100%, 480px);',
			),
			array(
				'title'   => 'CTA de la guía: apertura',
				'desc'    => 'Frase corta que precede al enlace. Sólo se ve si arriba hay una guía elegida.',
				'id'      => PreventasRoute::OPTION_GUIDE_PREFIX,
				'type'    => 'text',
				'default' => PreventasRoute::GUIDE_PREFIX,
				'css'     => 'width: min(100%, 680px);',
			),
			array(
				'title'   => 'CTA de la guía: texto del enlace',
				'desc'    => 'Lo que se lee subrayado. Descríbelo por lo que el cliente va a encontrar, no como «haz clic aquí».',
				'id'      => PreventasRoute::OPTION_GUIDE_LINK,
				'type'    => 'text',
				'default' => PreventasRoute::GUIDE_LINK_COPY,
				'css'     => 'width: min(100%, 680px);',
			),
			array(
				'title'   => 'Enlace a Disponibles: apertura',
				'desc'    => 'Frase corta que precede al enlace hacia /disponibles/.',
				'id'      => PreventasRoute::OPTION_SIBLING_PREFIX,
				'type'    => 'text',
				'default' => PreventasRoute::SIBLING_PREFIX,
				'css'     => 'width: min(100%, 680px);',
			),
			array(
				'title'   => 'Enlace a Disponibles: texto del enlace',
				'desc'    => 'Este párrafo es la ÚNICA puerta interna hacia /disponibles/: si lo dejas en blanco vuelve al texto de fábrica en vez de desaparecer, porque sin él esa página queda huérfana para Google.',
				'id'      => PreventasRoute::OPTION_SIBLING_LINK,
				'type'    => 'text',
				'default' => PreventasRoute::SIBLING_LINK_COPY,
				'css'     => 'width: min(100%, 680px);',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_preventas_content',
			),
			array(
				'title' => 'Contenido de disponibles',
				'type'  => 'title',
				'id'    => 'akb_disponibles_content',
				'desc'  => 'Contenido editorial de la primera página de Disponibles (/disponibles/). No se repite en filtros ni paginación. El enlace a A pedido que va debajo apunta siempre a esa ruta —une las dos mitades del catálogo—, pero su texto se edita acá abajo.',
			),
			array(
				'title'   => 'Introducción',
				'desc'    => 'Explica qué significa que un tomo esté disponible y en qué se diferencia de una preventa. Admite &lt;strong&gt; para negrita y &lt;em&gt; para cursiva; scripts, enlaces y títulos se eliminan al guardar.',
				'id'      => DisponiblesRoute::OPTION_INTRO,
				'type'    => 'textarea',
				'default' => DisponiblesRoute::DEFAULT_INTRO_CONTENT,
				'css'     => 'width: min(100%, 680px); min-height: 120px;',
			),
			array(
				'title'   => 'Enlace a A pedido: apertura',
				'desc'    => 'Frase corta que precede al enlace. Sólo se ve cuando la introducción de arriba tiene texto.',
				'id'      => DisponiblesRoute::OPTION_SIBLING_PREFIX,
				'type'    => 'text',
				'default' => DisponiblesRoute::SIBLING_PREFIX,
				'css'     => 'width: min(100%, 680px);',
			),
			array(
				'title'   => 'Enlace a A pedido: texto del enlace',
				'desc'    => 'Es la salida de quien no encontró su tomo disponible. En blanco vuelve al texto de fábrica.',
				'id'      => DisponiblesRoute::OPTION_SIBLING_LINK,
				'type'    => 'text',
				'default' => DisponiblesRoute::SIBLING_LINK_COPY,
				'css'     => 'width: min(100%, 680px);',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_disponibles_content',
			),
			array(
				'title' => 'Ordenamiento del catálogo',
				'type'  => 'title',
				'id'    => 'akb_catalog_order_copys',
				'desc'  => 'Textos breves del selector de orden en catálogo, categorías y editoriales. Los criterios y su lógica siguen las reglas de WooCommerce.',
			),
			array(
				'title'   => 'Resultados por relevancia',
				'desc'    => 'Se muestra cuando hay una búsqueda activa sin orden manual.',
				'id'      => 'akb_copy_order_relevance',
				'type'    => 'text',
				'default' => 'Relevancia',
				'css'     => 'width: 300px;',
			),
			array(
				'title'   => 'Orden destacado',
				'desc'    => 'Orden editorial predeterminado del catálogo.',
				'id'      => 'akb_copy_order_featured',
				'type'    => 'text',
				'default' => 'Destacados',
				'css'     => 'width: 300px;',
			),
			array(
				'title'   => 'Orden alfabético',
				'id'      => 'akb_copy_order_title',
				'type'    => 'text',
				'default' => 'Título: A-Z',
				'css'     => 'width: 300px;',
			),
			array(
				'title'   => 'Más vendidos',
				'id'      => 'akb_copy_order_popularity',
				'type'    => 'text',
				'default' => 'Más vendidos',
				'css'     => 'width: 300px;',
			),
			array(
				'title'   => 'Más recientes',
				'id'      => 'akb_copy_order_date',
				'type'    => 'text',
				'default' => 'Más recientes',
				'css'     => 'width: 300px;',
			),
			array(
				'title'   => 'Precio ascendente',
				'id'      => 'akb_copy_order_price',
				'type'    => 'text',
				'default' => 'Menor precio',
				'css'     => 'width: 300px;',
			),
			array(
				'title'   => 'Precio descendente',
				'id'      => 'akb_copy_order_price_desc',
				'type'    => 'text',
				'default' => 'Mayor precio',
				'css'     => 'width: 300px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_catalog_order_copys',
			),
			array(
				'title' => 'Blog / Buscador',
				'type'  => 'title',
				'id'    => 'akb_blog_search_copys',
				'desc'  => 'La pista del buscador de la cabecera, el título de los más vendidos cuando una búsqueda no encuentra nada y los textos que cierran las guías del blog. Un campo vacío vuelve al texto de siempre.',
			),
			array(
				'title'   => 'Pista del buscador (escritorio)',
				'desc'    => 'Texto gris dentro del buscador de la cabecera en computador. Mejor corto: en pantallas de 1024 px se corta desde unos 35 caracteres. El buscador del celular dice «Buscar…» y no cambia.',
				'id'      => self::OPTION_SEARCH_PLACEHOLDER,
				'type'    => 'text',
				'default' => self::theme_copy_defaults()[ self::OPTION_SEARCH_PLACEHOLDER ],
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Búsqueda sin resultados: título de los más vendidos',
				'desc'    => 'Encabezado de la fila de tomos más vendidos que aparece cuando una búsqueda no encuentra nada, para que el cliente no se quede sin salida. Sale en mayúsculas. Vacío vuelve a «' . self::theme_copy_defaults()[ self::OPTION_SEARCH_EMPTY_POPULAR ] . '». La fila se muestra u oculta en Recomendaciones.',
				'id'      => self::OPTION_SEARCH_EMPTY_POPULAR,
				'type'    => 'text',
				'default' => self::theme_copy_defaults()[ self::OPTION_SEARCH_EMPTY_POPULAR ],
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Guía: frase del cierre',
				'desc'    => 'Frase al final de cada entrada del blog, sobre el botón.',
				'id'      => self::OPTION_GUIDE_CTA_TEXT,
				'type'    => 'text',
				'default' => self::theme_copy_defaults()[ self::OPTION_GUIDE_CTA_TEXT ],
				'css'     => 'width: min(100%, 680px);',
			),
			array(
				'title'   => 'Guía: botón a la tienda',
				'desc'    => 'Botón del cierre en las entradas que no están ligadas a una serie.',
				'id'      => self::OPTION_GUIDE_CTA_SHOP,
				'type'    => 'text',
				'default' => self::theme_copy_defaults()[ self::OPTION_GUIDE_CTA_SHOP ],
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Guía: botón a la serie',
				'desc'    => 'Botón del cierre en las guías ligadas a una serie. Escribe ' . self::SERIES_MARKER . ' donde va el nombre de la serie; sin el marcador el botón sale tal cual y no la nombra (el panel te avisa).',
				'id'      => self::OPTION_GUIDE_CTA_SERIES,
				'type'    => 'text',
				'default' => self::theme_copy_defaults()[ self::OPTION_GUIDE_CTA_SERIES ],
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Guía: rótulo de la tarjeta de la serie',
				'desc'    => 'Rótulo pequeño sobre el nombre de la serie, en la tarjeta que acompaña a una guía ligada.',
				'id'      => self::OPTION_GUIDE_CARD_KICKER,
				'type'    => 'text',
				'default' => self::theme_copy_defaults()[ self::OPTION_GUIDE_CARD_KICKER ],
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Guía: botón de la tarjeta de la serie',
				'desc'    => 'Corto: el nombre de la serie ya va justo encima.',
				'id'      => self::OPTION_GUIDE_CARD_CTA,
				'type'    => 'text',
				'default' => self::theme_copy_defaults()[ self::OPTION_GUIDE_CARD_CTA ],
				'css'     => 'width: 400px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_blog_search_copys',
			),
			array(
				'title' => 'Portada',
				'type'  => 'title',
				'id'    => 'akb_home_copys',
			),
			array(
				'title'   => 'Nombre del carrusel',
				'desc'    => 'No se ve en pantalla: es el nombre con el que el lector de pantalla anuncia el carrusel de imágenes de la portada (por ejemplo «' . HeroRenderer::DEFAULT_LABEL . ', carrusel»). Mejor corto y en palabras del cliente. Vacío vuelve a «' . HeroRenderer::DEFAULT_LABEL . '».',
				'id'      => HeroRenderer::OPTION_LABEL,
				'type'    => 'text',
				'default' => HeroRenderer::DEFAULT_LABEL,
				'css'     => 'width: 300px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_home_copys',
			),
			array(
				'title' => 'Pie del sitio',
				'type'  => 'title',
				'id'    => 'akb_footer_copys',
				'desc'  => 'Datos de contacto que aparecen en el pie de TODAS las páginas. Deja un campo vacío para que su línea no se pinte.',
			),
			array(
				'title'   => 'Horario de atención',
				'desc'    => 'Línea completa junto al reloj. Vacía retira la línea: mejor no prometer horario que prometer uno viejo.',
				'id'      => 'akb_copy_footer_support_hours',
				'type'    => 'text',
				'default' => 'Horario de soporte: Lun a Vie · 10:00 a 18:00 hrs',
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Teléfono público',
				'desc'    => 'Escríbelo como quieres que se lea. De este ÚNICO campo salen las tres formas: el texto visible, el enlace para llamar y el de WhatsApp (que usan sólo los dígitos). Vacío retira el teléfono y el botón de WhatsApp.',
				'id'      => 'akb_copy_footer_phone',
				'type'    => 'text',
				'default' => '+56 9 4424 2844',
				'css'     => 'width: 400px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_footer_copys',
			),
			array(
				'title' => 'Página de acceso',
				'type'  => 'title',
				'id'    => 'akb_wall_copys',
				'desc'  => 'Lo que lee quien todavía no ha entrado. Cambia según de dónde venga: si pulsó el corazón del header verá el texto de Favoritos, si venía de un tomo verá además su nombre y el enlace de vuelta.',
			),
			array(
				'title'   => 'Título general',
				'desc'    => 'Encabezado cuando el cliente entra por «Mi cuenta» sin pedir una sección concreta.',
				'id'      => AccessWall::OPTION_TITLE,
				'type'    => 'text',
				'default' => AccessWall::DEFAULT_TITLE,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Bajada general',
				'desc'    => 'Se usa cuando el cliente puede crear cuenta además de iniciar sesión.',
				'id'      => AccessWall::OPTION_SUB,
				'type'    => 'text',
				'default' => AccessWall::DEFAULT_SUB,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Bajada general sin registro',
				'desc'    => 'La que se pinta si el registro está apagado en WooCommerce: ahí no hay nada que elegir.',
				'id'      => AccessWall::OPTION_SUB_LOGIN,
				'type'    => 'text',
				'default' => AccessWall::DEFAULT_SUB_LOGIN,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Título en Favoritos',
				'desc'    => 'Lo ve quien pulsa el corazón del header sin haber entrado.',
				'id'      => AccessWall::OPTION_FAVORITOS_TITLE,
				'type'    => 'text',
				'default' => AccessWall::DEFAULT_FAVORITOS_TITLE,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Bajada en Favoritos',
				'id'      => AccessWall::OPTION_FAVORITOS_SUB,
				'type'    => 'text',
				'default' => AccessWall::DEFAULT_FAVORITOS_SUB,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Título en Colección',
				'id'      => AccessWall::OPTION_COLECCION_TITLE,
				'type'    => 'text',
				'default' => AccessWall::DEFAULT_COLECCION_TITLE,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Bajada en Colección',
				'id'      => AccessWall::OPTION_COLECCION_SUB,
				'type'    => 'text',
				'default' => AccessWall::DEFAULT_COLECCION_SUB,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Título en Pedidos',
				'id'      => AccessWall::OPTION_ORDERS_TITLE,
				'type'    => 'text',
				'default' => AccessWall::DEFAULT_ORDERS_TITLE,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Bajada en Pedidos',
				'id'      => AccessWall::OPTION_ORDERS_SUB,
				'type'    => 'text',
				'default' => AccessWall::DEFAULT_ORDERS_SUB,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Línea de vuelta al tomo',
				'desc'    => 'Aparece cuando el cliente llegó desde la ficha de un tomo. Escribe ' . AccessWall::RETURN_MARKER . ' donde quieras el nombre del tomo: ahí va su enlace de vuelta. Si borras el marcador, el enlace se añade al final.',
				'id'      => AccessWall::OPTION_RETURN,
				'type'    => 'text',
				'default' => AccessWall::DEFAULT_RETURN,
				'css'     => 'width: 400px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_wall_copys',
			),
			array(
				'title' => 'Corazón de favoritos sin sesión',
				'type'  => 'title',
				'id'    => 'akb_wl_guest_copys',
				'desc'  => 'Lo que pasa cuando alguien que no ha entrado toca el corazón de un tomo. Hoy lo lleva directo a la página de acceso, y las grabaciones muestran que casi todos vuelven a la ficha sin crear cuenta. Si enciendes el aviso, en vez de salir de la ficha ve un aviso en la misma página, con el acceso a un toque y «Seguir mirando»; si entra, el tomo queda guardado solo al volver.',
			),
			array(
				'title'   => 'Mostrar el aviso en la página',
				'desc'    => 'Encendido, el corazón abre el aviso. Apagado, lleva directo a la página de acceso, como siempre.',
				'id'      => GuestPrompt::OPTION_ENABLED,
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'   => 'Título del aviso',
				'id'      => GuestPrompt::OPTION_TITLE,
				'type'    => 'text',
				'default' => GuestPrompt::DEFAULT_TITLE,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Bajada del aviso',
				'desc'    => 'Escribe ' . GuestPrompt::PRODUCT_MARKER . ' donde quieras el nombre del tomo que tocó. Si borras el marcador, la bajada sale sin el nombre.',
				'id'      => GuestPrompt::OPTION_BODY,
				'type'    => 'text',
				'default' => GuestPrompt::DEFAULT_BODY,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Botón para entrar',
				'desc'    => 'Lleva a la página de acceso y, al entrar, de vuelta al mismo tomo.',
				'id'      => GuestPrompt::OPTION_CTA,
				'type'    => 'text',
				'default' => GuestPrompt::DEFAULT_CTA,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Botón para seguir',
				'desc'    => 'Cierra el aviso sin guardar nada.',
				'id'      => GuestPrompt::OPTION_DISMISS,
				'type'    => 'text',
				'default' => GuestPrompt::DEFAULT_DISMISS,
				'css'     => 'width: 400px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_wl_guest_copys',
			),
			array(
				'title' => 'Mi cuenta · Colección',
				'type'  => 'title',
				'id'    => 'akb_coleccion_copys',
				'desc'  => 'La colección cuenta sólo los pedidos con el pago confirmado. Los pagos por transferencia entran cuando tú confirmas el depósito, así que hasta entonces el cliente no ve el tomo acá.',
			),
			array(
				'title'   => 'Aviso de compras recientes',
				'desc'    => 'Se muestra en «Mi cuenta → Colección», tanto cuando está vacía como encima de las tarjetas. Vacío retira la línea. No prometas un plazo acá: una transferencia sin confirmar puede tardar horas o no llegar nunca.',
				'id'      => CollectionRenderer::OPTION_DELAY_NOTICE,
				'type'    => 'text',
				'default' => CollectionRenderer::DEFAULT_DELAY_NOTICE,
				'css'     => 'width: min(100%, 680px);',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_coleccion_copys',
			),
			array(
				'title' => 'Formulario de contacto',
				'type'  => 'title',
				'id'    => 'akb_contacto_settings',
				'desc'  => 'A dónde llegan los mensajes que envía el formulario de la página de Contacto, y los avisos que ve el cliente junto a un campo mal llenado.',
			),
			array(
				'title'   => 'Correo que recibe los mensajes',
				'desc'    => 'Vacío vuelve al de siempre (' . FormsSettings::DEFAULT_NOTIFY_EMAIL . '). Un correo mal escrito no se guarda —se queda el de siempre y el panel te lo dice—, porque un mensaje de un cliente no puede perderse por un tipeo. El resto de la pestaña se guarda igual.',
				'id'      => FormsSettings::OPTION_NOTIFY_EMAIL,
				'type'    => 'email',
				'default' => FormsSettings::DEFAULT_NOTIFY_EMAIL,
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Aviso: campo sin llenar',
				'desc'    => 'Aparece bajo un campo obligatorio que quedó vacío al enviar.',
				'id'      => FormsSettings::MESSAGES['valueMissing']['option'],
				'type'    => 'text',
				'default' => FormsSettings::MESSAGES['valueMissing']['default'],
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Aviso: correo mal escrito',
				'desc'    => 'Aparece bajo el correo cuando no tiene forma de correo (le falta la @ o el dominio).',
				'id'      => FormsSettings::MESSAGES['typeMismatch']['option'],
				'type'    => 'text',
				'default' => FormsSettings::MESSAGES['typeMismatch']['default'],
				'css'     => 'width: 400px;',
			),
			array(
				'title'   => 'Aviso: texto demasiado largo',
				'desc'    => 'Aparece si un campo pasa del largo máximo (el mensaje admite 3.000 caracteres).',
				'id'      => FormsSettings::MESSAGES['tooLong']['option'],
				'type'    => 'text',
				'default' => FormsSettings::MESSAGES['tooLong']['default'],
				'css'     => 'width: 400px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_contacto_settings',
			),
		);
	}

	/**
	 * Sanitiza el copy de preventas con la allowlist del renderer.
	 *
	 * @param mixed               $value     Valor preprocesado por WooCommerce.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo del formulario.
	 */
	public function sanitize_preventas_intro( $value, array $option = array(), $raw_value = null ): string {
		unset( $option );
		$candidate = null !== $raw_value ? $raw_value : $value;
		return PreventasRoute::sanitize_intro_content( $candidate );
	}

	/**
	 * Sanitiza el copy de disponibles con la allowlist de su propio renderer.
	 *
	 * Delega en `DisponiblesRoute` y no en `PreventasRoute` a propósito: cada
	 * ruta es dueña de su allowlist, así endurecer una no cambia la otra en
	 * silencio.
	 *
	 * @param mixed               $value     Valor preprocesado por WooCommerce.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo del formulario.
	 */
	public function sanitize_disponibles_intro( $value, array $option = array(), $raw_value = null ): string {
		unset( $option );
		$candidate = null !== $raw_value ? $raw_value : $value;
		return DisponiblesRoute::sanitize_intro_content( $candidate );
	}

	/**
	 * Un correo inválido cae al destinatario de siempre en vez de guardarse.
	 *
	 * El lector (`Forms\Settings::get_notify_email()`) ya sabe caer al fallback,
	 * pero dejar basura escrita en la option deja al dueño mirando una pantalla
	 * que dice una cosa y un correo que llega a otra. Se normaliza al guardar.
	 *
	 * @param mixed               $value     Valor preprocesado por WooCommerce.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo del formulario.
	 */
	public function sanitize_contact_email( $value, array $option = array(), $raw_value = null ): string {
		unset( $option );
		$candidate = null !== $raw_value ? $raw_value : $value;
		if ( ! is_scalar( $candidate ) ) {
			return FormsSettings::DEFAULT_NOTIFY_EMAIL;
		}

		$email = trim( (string) $candidate );

		return is_email( $email ) ? $email : FormsSettings::DEFAULT_NOTIFY_EMAIL;
	}

	/**
	 * Acepta solo una entrada publicada. `null` indica a WooCommerce que debe
	 * conservar el valor anterior cuando se intenta guardar un destino inválido.
	 *
	 * @param mixed               $value     Valor preprocesado por WooCommerce.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo del formulario.
	 * @return int|null
	 */
	public function sanitize_preventas_guide( $value, array $option = array(), $raw_value = null ): ?int {
		unset( $option );
		$candidate = null !== $raw_value ? $raw_value : $value;
		if ( ! is_scalar( $candidate ) ) {
			return null;
		}

		$post_id = absint( $candidate );
		if ( 0 === $post_id ) {
			return 0;
		}

		$published_id = PreventasRoute::published_post_id( $post_id );
		return $published_id > 0 ? $published_id : null;
	}

	/**
	 * Opciones del selector mantenible de guía editorial.
	 *
	 * @return array<string,string>
	 */
	private function published_post_options(): array {
		$options = array( '0' => '— Sin guía de preventas —' );
		$posts   = get_posts(
			array(
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'suppress_filters'       => false,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $posts as $post ) {
			if ( ! isset( $post->ID, $post->post_title ) ) {
				continue;
			}
			$options[ (string) $post->ID ] = (string) $post->post_title;
		}

		// Preserva la guía actualmente asignada en el selector aunque no esté publicada,
		// evitando desasignaciones accidentales al guardar otros campos de la pestaña.
		$guardada = absint( get_option( PreventasRoute::OPTION_GUIDE, 0 ) );
		if ( $guardada > 0 && ! isset( $options[ (string) $guardada ] ) ) {
			$titulo                         = get_the_title( $guardada );
			$options[ (string) $guardada ] = ( '' !== (string) $titulo ? (string) $titulo : '#' . $guardada ) . ' (no publicada)';
		}

		return $options;
	}
}
