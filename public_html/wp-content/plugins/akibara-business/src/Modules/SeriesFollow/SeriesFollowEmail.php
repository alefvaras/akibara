<?php
/**
 * Comunica novedades de las series que el cliente colecciona.
 *
 * No promete el siguiente tomo: un digest puede mostrar la novedad más reciente
 * y una fila pendiente puede corresponder a un tomo anterior al ya avisado.
 * AkibaraEmail aporta los campos comunes y email_type para servir HTML con el
 * tipo correcto. El asunto y cuerpo se editan sólo en el WC_Email; el panel de
 * Akibara enlaza a esos ajustes. template_args() entrega groups porque el digest
 * contiene varios productos y no un único objeto.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFollow;

use Akibara\Business\Services\AkibaraEmail;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.2
 */
class SeriesFollowEmail extends AkibaraEmail {

	public const EMAIL_ID = 'akb_sf_series';

	/** @var array<int, array<string, mixed>> Bloques del digest actual. */
	protected array $groups = array();

	/** @var int Cliente del envío ACTUAL (para los enlaces de baja). */
	protected int $user_id = 0;

	/** @var string URL de baja GLOBAL del destinatario actual (header). */
	protected string $unsubscribe_url = '';

	public function __construct() {
		$this->id             = self::EMAIL_ID;
		$this->customer_email = true;
		$this->title          = 'Akibara · Novedades de tu serie';
		$this->description    = 'Se envía a quien ya compró tomos anteriores de una serie cuando publicas uno posterior que aún no tiene. Lo dispara el digest agrupado de «Sigue esta serie», con claim atómico: un tomo no se avisa dos veces al mismo cliente.';
		$this->template_html  = 'emails/customer-series-follow.php';
		$this->template_plain = 'emails/plain/customer-series-follow.php';
		$this->template_base  = trailingslashit( AKIBARA_BUSINESS_DIR ) . 'templates/';
		$this->placeholders   = array(
			'{serie}'      => '',
			'{y_mas}'      => '',
			'{tomo}'       => '',
			'{n_tomos}'    => '',
			'{tomos}'      => '',
			'{site_title}' => $this->get_blogname(),
		);

		parent::__construct();
	}

	/**
	 * @return string
	 */
	public function get_default_subject(): string {
		return 'Salió el tomo {tomo} de {serie}{y_mas}';
	}

	/**
	 * @return string
	 */
	public function get_default_heading(): string {
		// Cierto con un tomo, con cuatro, con tres series y con una fila
		// rezagada. Por eso NO hay un segundo campo de encabezado: el problema
		// no era el plural, era la palabra «siguiente».
		return 'Novedades de tu colección';
	}

	/**
	 * Asunto cuando la serie que ABRE el correo trae dos tomos o más.
	 *
	 * CONSTANTE A PROPÓSITO, como su hermano. `WC_Settings_API::get_option()`
	 * ESCRIBE este default dentro de `$this->settings` la primera vez y ya no
	 * vuelve a preguntar; y `DigestRunner::run()` reutiliza UNA instancia para
	 * los 50 clientes del lote. Un default que dependiera del envío congelaría
	 * el del PRIMER cliente para los otros 49. Lo que varía viaja en
	 * placeholders, no aquí.
	 *
	 * @return string
	 */
	public function get_default_subject_tanda(): string {
		return 'Salieron {n_tomos} tomos de {serie}{y_mas}';
	}

	/**
	 * Asunto cuando el bloque que abre el correo cierra un hueco intermedio de
	 * la colección del cliente (un solo tomo menor que su máximo comprado).
	 *
	 * @return string
	 */
	public function get_default_subject_hueco(): string {
		return 'Cierra el hueco de tu colección: tomo {tomo} de {serie}{y_mas}';
	}

	/**
	 * Asunto: el de tanda cuando la serie que abre trae dos tomos o más.
	 *
	 * NO reimplementa `WC_Email::get_subject()`: cambia el VALOR y delega. Así
	 * sobreviven las tres cosas que una copia perdería —la vista previa del
	 * dueño, el filtro `woocommerce_email_subject_{id}` y la rama del editor de
	 * correos por bloques—, y el día que WooCommerce cambie esa cadena, esto la
	 * sigue.
	 *
	 * El `init_settings()` no es ceremonia: `get_option()` sólo carga los ajustes
	 * guardados `if ( empty( $this->settings))`. Escribir en `$this->settings`
	 * sin cargarlos antes dejaría el array no-vacío y los ajustes GUARDADOS del
	 * dueño se perderían en silencio.
	 *
	 * Con `$this->groups` vacío —la vista previa de WooCommerce— esto es false y
	 * se va por `parent::`, así que la previsualización nunca ve el asunto de
	 * tanda. Es una limitación declarada, y va escrita en la ayuda del campo.
	 *
	 * @return string
	 */
	public function get_subject(): string {
		if ( $this->apertura_es_tanda() ) {
			$this->init_settings();

			$previo                    = $this->settings['subject'] ?? '';
			$this->settings['subject'] = (string) $this->get_option( 'subject_tanda', $this->get_default_subject_tanda() );

			try {
				return (string) parent::get_subject();
			} finally {
				$this->settings['subject'] = $previo;
			}
		}

		if ( $this->apertura_es_hueco() ) {
			$this->init_settings();

			$previo = (string) ( $this->settings['subject'] ?? '' );
			if ( '' === $previo || $this->get_default_subject() === $previo ) {
				$this->settings['subject'] = $this->get_default_subject_hueco();
			}

			try {
				return (string) parent::get_subject();
			} finally {
				$this->settings['subject'] = $previo;
			}
		}

		return (string) parent::get_subject();
	}

	/**
	 * ¿El bloque que abre el correo cubre más de un tomo?
	 *
	 * @return bool
	 */
	private function apertura_es_tanda(): bool {
		return max( 1, (int) ( $this->groups[0]['volumes_count'] ?? 1 ) ) > 1;
	}

	/**
	 * ¿El bloque que abre el correo cierra un hueco intermedio de la colección?
	 *
	 * @return bool
	 */
	private function apertura_es_hueco(): bool {
		return ! empty( $this->groups[0]['is_gap'] );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	protected function extra_form_fields(): array {
		return array(
			'subject_tanda' => array(
				'akb_after'   => 'subject',
				'title'       => 'Asunto cuando salieron varios tomos',
				'type'        => 'text',
				'desc_tip'    => true,
				'default'     => '',
				'placeholder' => $this->get_default_subject_tanda(),
				'description' => 'Se usa SOLO cuando la serie que abre el correo trae dos tomos o más; con uno solo manda el asunto de arriba. Marcadores: {serie}, {y_mas} («y 2 series más»), {n_tomos} (cuántos son), {tomos} («71 al 74», o «71, 73 y 74» si falta alguno del medio), {tomo} (el más nuevo), {site_title}. {n_tomos} son los tomos de esa serie que van en ESTE correo para ESE cliente: si ya compró alguno, a él le saldrá un número menor. Este asunto no se puede previsualizar (la vista previa no trae varios tomos). Vacío vuelve al texto de ejemplo.',
			),
		);
	}

	/**
	 * Envía UN correo con todos los bloques del cliente. El runner ya hizo el
	 * claim atómico y el hoist de `is_enabled()`.
	 *
	 * @param int                              $user_id Cliente.
	 * @param array<int, array<string, mixed>> $groups  Bloques, la serie más nueva primero.
	 * @return bool True si el mailer aceptó el envío.
	 */
	public function trigger( int $user_id, array $groups ): bool {
		if ( $user_id < 1 || array() === $groups ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! is_object( $user ) || ! isset( $user->user_email ) || '' === (string) $user->user_email ) {
			return false;
		}

		$this->user_id   = $user_id;
		$this->groups    = array_values( $groups );
		$this->recipient = (string) $user->user_email;

		$newest = $this->groups[0];
		$extra  = count( $this->groups ) - 1;

		// El sufijo de «varias series» sale FUERA de {serie}. Pegado dentro,
		// «Novedades de {serie}» daba «Novedades de One Piece (+2 series más)» y
		// el dueño no podía ni verlo ni moverlo: una option invisible escondida
		// dentro de una que sí lo es. Un digest de varias series sigue diciendo
		// cuántas viajan dentro —sin eso el cliente cree que el correo habla de
		// una sola y no abre los otros bloques—, pero ahora en su propio
		// marcador. Concatenación y no sprintf: la regla vale también para los
		// literales de este fichero.
		$this->placeholders['{serie}'] = (string) ( $newest['series_name'] ?? '' );
		$this->placeholders['{y_mas}'] = $extra > 0
			? ' y ' . $extra . ( 1 === $extra ? ' serie más' : ' series más' )
			: '';
		$this->placeholders['{tomo}']  = (string) ( $newest['volume'] ?? '' );
		// SIEMPRE del bloque que ABRE, en los dos campos de asunto: un mismo
		// marcador con dos significados según dónde se escriba es la trampa que
		// produce «Salieron 1 tomos» en cuanto el dueño mueve una frase de un
		// campo al otro.
		$this->placeholders['{n_tomos}'] = (string) max( 1, (int) ( $newest['volumes_count'] ?? 1 ) );
		$this->placeholders['{tomos}']   = '' !== (string) ( $newest['volumes_phrase'] ?? '' )
			? (string) $newest['volumes_phrase']
			: (string) ( $newest['volume'] ?? '' );
		$this->unsubscribe_url = UnfollowHandler::url_for_all( $user_id );

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
	 * UN ENLACE DE BAJA POR SERIE: dar de baja de una no puede callar las otras
	 * dos.
	 *
	 * @param bool $plain_text Versión plain.
	 * @return array<string, mixed>
	 */
	protected function template_args( bool $plain_text ): array {
		$groups = array();
		foreach ( $this->groups as $group ) {
			$group['unfollow_url'] = UnfollowHandler::url_for( $this->user_id, (int) ( $group['term_id'] ?? 0 ) );
			$groups[]              = $group;
		}

		return array(
			'groups'             => $groups,
			'collection_url'     => \Akibara\Business\Modules\AccountCollection\Endpoint::url(),
			'unsubscribe_url'    => $this->unsubscribe_url,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
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
				'description' => 'Se usa cuando la serie que abre el correo trae UN tomo. Marcadores: {serie}, {y_mas} («y 2 series más»), {tomo}, {n_tomos}, {tomos}, {site_title}. El corte y el enlace de baja solo funcionan en el cuerpo.',
				'placeholder' => $this->get_default_subject(),
			),
			'heading'            => array(
				'description' => 'Sale arriba del todo, sea cual sea el correo. Marcadores: {serie}, {y_mas}, {tomo}, {n_tomos}, {tomos}, {site_title}.',
				'placeholder' => $this->get_default_heading(),
			),
			'additional_content' => array(
				'description' => 'Texto opcional debajo del contenido principal.',
				'placeholder' => '',
			),
		);
	}
}
