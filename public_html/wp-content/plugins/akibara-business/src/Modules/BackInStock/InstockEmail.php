<?php
/**
 * Correo transaccional de reposición, registrado como WC_Email para heredar
 * asunto, encabezado y contenido adicional. La clase admite extensión y dobles
 * de prueba. NotificationDispatcher lo dispara directamente: no se registra
 * en woocommerce_email_actions, pues el repositorio ya controla el claim.
 * Conserva asunto sin emoji y cabeceras List-Unsubscribe/List-Unsubscribe-Post
 * para la baja de un clic mediante RFC 8058.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BackInStock;

use Akibara\Business\Modules\EstimatedDelivery\DateCalculator;
use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Akibara\Business\Modules\EstimatedDelivery\Settings;
use Akibara\Business\Modules\SeoSocial\SocialImageDerivatives;
use Akibara\Business\Modules\SeriesFollow\CutoffPhrase;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Services\AkibaraEmail;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.53.0
 */
class InstockEmail extends AkibaraEmail {

	public const EMAIL_ID = 'akb_bis_instock';

	/** @var string URL de baja del destinatario ACTUAL (para headers/template). */
	public function __construct() {
		$this->id             = self::EMAIL_ID;
		$this->customer_email = true;
		$this->title          = 'Akibara · Volvió a stock';
		$this->description    = 'Se envía a quien pidió aviso en la lista de espera cuando el producto agotado vuelve a estar disponible o se habilita para encargar (A pedido / Preventa). Lo dispara el dispatcher de BackInStock con claim atómico (at-most-once por suscripción).';
		$this->template_html  = 'emails/customer-bis-instock.php';
		$this->template_plain = 'emails/plain/customer-bis-instock.php';
		$this->template_base  = trailingslashit( AKIBARA_BUSINESS_DIR ) . 'templates/';
		$this->placeholders   = array(
			'{product_name}' => '',
			'{availability}' => '',
			'{site_title}'   => $this->get_blogname(),
		);

		parent::__construct();
	}

	/**
	 * @return string
	 */
	public function get_default_subject(): string {
		return '{product_name} está de vuelta en Akibara';
	}

	/**
	 * @return string
	 */
	public function get_default_heading(): string {
		return 'Volvió a stock';
	}

	/**
	 * Ajusta el asunto por defecto cuando el tomo se habilita como «A pedido» o
	 * «Preventa», respetando cualquier asunto personalizado por el dueño.
	 *
	 * @return string
	 */
	public function get_subject(): string {
		if ( ! ( $this->object instanceof WC_Product ) || ! StockStatus::is_reservable( $this->object ) ) {
			return (string) parent::get_subject();
		}

		$this->init_settings();
		$custom = (string) ( $this->settings['subject'] ?? '' );
		if ( '' !== $custom && $this->get_default_subject() !== $custom ) {
			return (string) parent::get_subject();
		}

		$state                     = Availability::of( $this->object );
		$previo                    = $this->settings['subject'] ?? '';
		$this->settings['subject'] = Availability::PREVENTA === $state
			? '{product_name} ya se puede reservar en preventa en Akibara'
			: '{product_name} ya se puede pedir en Akibara';

		try {
			return (string) parent::get_subject();
		} finally {
			$this->settings['subject'] = $previo;
		}
	}

	/**
	 * Ajusta el encabezado por defecto cuando el tomo se habilita como «A pedido»
	 * o «Preventa», respetando cualquier encabezado personalizado por el dueño.
	 *
	 * @return string
	 */
	public function get_heading(): string {
		if ( ! ( $this->object instanceof WC_Product ) || ! StockStatus::is_reservable( $this->object ) ) {
			return (string) parent::get_heading();
		}

		$this->init_settings();
		$custom = (string) ( $this->settings['heading'] ?? '' );
		if ( '' !== $custom && $this->get_default_heading() !== $custom ) {
			return (string) parent::get_heading();
		}

		$state                     = Availability::of( $this->object );
		$previo                    = $this->settings['heading'] ?? '';
		$this->settings['heading'] = Availability::PREVENTA === $state
			? 'Abierta la preventa'
			: 'Ya se puede pedir';

		try {
			return (string) parent::get_heading();
		} finally {
			$this->settings['heading'] = $previo;
		}
	}

	/**
	 * Envía el aviso a UN suscriptor. El dispatcher ya hizo el claim y el
	 * hoist de is_enabled(); acá solo se arma y despacha.
	 *
	 * @param Subscriber $subscriber Suscripción reclamada.
	 * @param WC_Product $product    Producto que volvió a habilitarse.
	 * @return bool True si el mailer aceptó el envío.
	 */
	public function trigger( Subscriber $subscriber, WC_Product $product ): bool {
		if ( '' === $subscriber->email ) {
			return false;
		}

		$this->object                          = $product;
		$this->recipient                       = $subscriber->email;
		$this->placeholders['{product_name}']  = $product->get_name();
		$this->placeholders['{availability}']  = Availability::label_of( $product );
		$this->unsubscribe_url                 = UnsubscribeHandler::url_for( $subscriber->token );

		$this->setup_locale();
		$sent = $this->send(
			$this->get_recipient(),
			$this->get_subject(),
			$this->get_content(),
			$this->get_headers(),
			$this->get_attachments()
		);
		$this->restore_locale();

		return (bool) $sent;
	}

	/**
	 * @param bool $plain_text Versión plain.
	 * @return array<string, mixed>
	 */
	protected function template_args( bool $plain_text ): array {
		$product            = $this->object instanceof WC_Product ? $this->object : null;
		$availability_state = $product ? Availability::of( $product ) : Availability::DISPONIBLE;
		$availability_label = $product ? Availability::label_of( $product ) : Availability::palabra( Availability::DISPONIBLE );
		$cutoff_phrase      = '';

		if ( $product && StockStatus::is_reservable( $product ) ) {
			$cutoff_phrase = $this->resolve_cutoff_phrase( $product );
		}

		if ( Availability::PREVENTA === $availability_state ) {
			$timeframe_copy = '' !== $cutoff_phrase
				? $cutoff_phrase
				: 'Reserva anticipada · Si reservas hoy, se despacha apenas llegue el lanzamiento a bodega.';
			$cta_label      = 'Reservar preventa';
			$footnote_copy  = 'Al reservarlo hoy aseguras tu ejemplar desde la preventa y te avisaremos por correo en cuanto llegue a nuestra bodega.';
		} elseif ( Availability::A_PEDIDO === $availability_state ) {
			$timeframe_copy = '' !== $cutoff_phrase
				? $cutoff_phrase
				: 'A pedido a editorial · Si lo solicitas hoy, entra en el próximo corte de importación.';
			$cta_label      = 'Pedir ahora';
			$footnote_copy  = 'Al pedirlo hoy aseguras tu ejemplar en la tanda actual y te avisaremos por correo en cuanto llegue a nuestra bodega.';
		} else {
			$timeframe_copy = 'En stock en bodega · Si compras hoy, despachamos en 24 a 48 h hábiles.';
			$cta_label      = 'Comprar ahora';
			$footnote_copy  = 'El stock puede ser limitado: la mayoría de nuestros tomos tienen pocas unidades por edición.';
		}

		return array(
			'product'            => $product,
			'product_name'       => $product ? $product->get_name() : '',
			'product_url'        => $product ? (string) get_permalink( $product->get_id() ) : '',
			'product_price_html' => $product ? $product->get_price_html() : '',
			// Usa JPG como formato compatible de portada para el correo.
			'product_image_url'  => $product ? SocialImageDerivatives::email_url( (string) wp_get_attachment_image_url( (int) $product->get_image_id(), 'woocommerce_thumbnail' ) ) : '',
			'availability_state' => $availability_state,
			'availability_label' => $availability_label,
			'cutoff_phrase'      => $cutoff_phrase,
			'timeframe_copy'     => $timeframe_copy,
			'cta_label'          => $cta_label,
			'footnote_copy'      => $footnote_copy,
			'unsubscribe_url'    => $this->unsubscribe_url,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}

	/**
	 * Resuelve la frase de corte y plazo de viaje de la editorial para un
	 * producto reservable (`onbackorder`).
	 *
	 * @param WC_Product $product Producto reservable.
	 * @return string Frase de corte o '' si no aplica.
	 */
	private function resolve_cutoff_phrase( WC_Product $product ): string {
		$cutoff = new CutoffPhrase(
			new DateCalculator( new Settings() ),
			new PedidoPolicy()
		);

		return $cutoff->for_product( $product );
	}

	/**
	 * Copy propio de la pantalla de ajustes. El esqueleto vive en AkibaraEmail.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function form_field_copy(): array {
		return array(
			'enabled'            => array(
				'title' => 'Habilitar/deshabilitar',
				'label' => 'Habilitar este email',
			),
			'subject'            => array(
				'description' => 'Placeholders: {product_name}, {availability}, {site_title}.',
				'placeholder' => $this->get_default_subject(),
			),
			'heading'            => array(
				'description' => 'Placeholders: {product_name}, {availability}, {site_title}.',
				'placeholder' => $this->get_default_heading(),
			),
			'additional_content' => array(
				'description' => 'Texto opcional debajo del contenido principal.',
				'placeholder' => '',
			),
		);
	}
}
