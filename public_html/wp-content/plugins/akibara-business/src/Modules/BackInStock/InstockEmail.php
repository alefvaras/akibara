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

use Akibara\Business\Modules\SeoSocial\SocialImageDerivatives;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Services\AkibaraEmail;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.53.0
 * @api
 * @psalm-suppress UnusedProperty
 */
class InstockEmail extends AkibaraEmail {

	public const EMAIL_ID = 'akb_bis_instock';

	/** @var Subscriber|null Suscripción en curso. */
	private ?Subscriber $subscriber = null;

	public function __construct() {
		$this->id             = self::EMAIL_ID;
		$this->customer_email = true;
		$this->title          = 'Akibara · Volvió a stock';
		$this->description    = 'Se envía a quien pidió aviso en la lista de espera cuando el producto agotado vuelve a estar disponible. Lo dispara el dispatcher de BackInStock con claim atómico (at-most-once por suscripción).';
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
	 * Envía el aviso a UN suscriptor. El dispatcher ya hizo el claim y el
	 * hoist de is_enabled(); acá solo se arma y despacha.
	 *
	 * @param Subscriber $subscriber Suscripción reclamada.
	 * @param WC_Product $product    Producto que volvió a habilitarse.
	 * @return bool True si el mailer aceptó el envío.
	 * @psalm-suppress PossiblyUnusedParam
	 */
	public function trigger( Subscriber $subscriber, WC_Product $product ): bool {
		if ( '' === $subscriber->email ) {
			return false;
		}

		$this->subscriber                      = $subscriber;
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

		$timeframe_copy = 'En stock en bodega · Si compras hoy, despachamos en 24 a 48 h hábiles.';
		$cta_label      = 'Comprar ahora';
		$footnote_copy  = 'El stock puede ser limitado: la mayoría de nuestros tomos tienen pocas unidades por edición.';

		$user_id       = $this->subscriber instanceof Subscriber ? $this->subscriber->user_id ?? 0 : 0;
		$is_registered = $user_id > 0;
		if ( ! $is_registered && $this->subscriber instanceof Subscriber && '' !== $this->subscriber->email && function_exists( 'get_user_by' ) ) {
			try {
				$u = get_user_by( 'email', $this->subscriber->email );
				if ( $u instanceof \WP_User && $u->ID > 0 ) {
					$is_registered = true;
				}
			} catch ( \Throwable $e ) {
				$is_registered = false;
			}
		}

		$collection_url = '';

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
			'is_registered'      => $is_registered,
			'collection_url'     => $collection_url,
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
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
