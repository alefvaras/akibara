<?php
/**
 * AkibaraEmail — base común de los tres `WC_Email` propios del plugin.
 *
 * Existe por `init_form_fields()`: los tres correos (BackInStock\InstockEmail,
 * OrderPreventa\ReadyToShipEmail y OrderPreventa\ReminderEmail) declaraban la
 * MISMA pantalla de ajustes —activar, asunto, encabezado, contenido adicional y
 * tipo de email— tres veces, y las tres copias sólo se diferencian en el texto.
 *
 * La copia salía cara donde más duele: el campo `email_type` es obligatorio y su
 * ausencia manda el correo con `Content-Type: text/plain` con el HTML dentro
 * (cazado en el E2E de Mailpit), pero el comentario que EXPLICA eso vivía en una
 * sola de las tres copias. Con una base, la razón se escribe una vez y las tres
 * pantallas la heredan.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Services;

use WC_Email;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.1
 */
abstract class AkibaraEmail extends WC_Email {

	/**
	 * URL de baja un-clic del correo en curso ('' = correo sin baja).
	 *
	 * La escribe el trigger del hijo ANTES de enviar; con ella puesta,
	 * `get_headers()` añade el par List-Unsubscribe(-Post) de RFC 8058.
	 * Subida aquí el: vivía copiada verbatim en InstockEmail y
	 * SeriesFollowEmail (célula de barrido).
	 */
	protected string $unsubscribe_url = '';

	/**
	 * Args del template del correo, la ÚNICA pieza específica de cada hijo.
	 *
	 * @param bool $plain_text Versión plain (true) o HTML (false).
	 * @return array<string, mixed>
	 */
	abstract protected function template_args( bool $plain_text ): array;

	/**
	 * Cuerpo HTML: mismo esqueleto para todos los correos de la casa.
	 *
	 * @return string
	 */
	public function get_content_html(): string {
		return wc_get_template_html(
			$this->template_html,
			$this->template_args( false ),
			'',
			$this->template_base
		);
	}

	/**
	 * Cuerpo plain.
	 *
	 * @return string
	 */
	public function get_content_plain(): string {
		return wc_get_template_html(
			$this->template_plain,
			$this->template_args( true ),
			'',
			$this->template_base
		);
	}

	/*
	 * `from_mailer()` y `mailer_ready()` VIVÍAN AQUÍ y se fueron a
	 * {@see MailerBoot} el. No es orden: es que un guardia que
	 * comprueba si `WC_Email` se puede cargar no puede vivir en una clase que
	 * empieza por `extends WC_Email`. Llamarlo obligaba a autocargar este
	 * fichero, y el `extends` reventaba antes de ejecutar una línea del método.
	 * Dos corridas muertas en producción con `Class "WC_Email" not found`.
	 */

	/**
	 * Cabeceras + List-Unsubscribe un-clic cuando el correo declara baja.
	 *
	 * @return string
	 */
	public function get_headers(): string {
		$headers = parent::get_headers();

		if ( '' !== $this->unsubscribe_url ) {
			$headers .= 'List-Unsubscribe: <' . $this->unsubscribe_url . ">\r\n";
			$headers .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
		}

		return $headers;
	}

	/**
	 * Textos propios de la pantalla de ajustes de ESTE correo.
	 *
	 * Cada clave reemplaza la del esqueleto; lo que no se declara usa el default
	 * de abajo. `subject`/`heading` aceptan además `placeholder`, que es donde
	 * cada correo muestra su copy por defecto.
	 *
	 * @return array{
	 *     enabled?: array<string, mixed>,
	 *     subject?: array<string, mixed>,
	 *     heading?: array<string, mixed>,
	 *     additional_content?: array<string, mixed>
	 * }
	 */
	abstract protected function form_field_copy(): array;

	/**
	 * Pantalla de ajustes en `WooCommerce → Ajustes → Emails`.
	 *
	 * @return void
	 */
	public function init_form_fields(): void {
		$copy   = $this->form_field_copy();
		$extras = $this->extra_form_fields();

		$base = array(
			'enabled'            => array(
				'title'   => 'Activar/desactivar',
				'type'    => 'checkbox',
				'label'   => 'Activar este email',
				'default' => 'yes',
			),
			'subject'            => array(
				'title'       => 'Asunto',
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => 'Asunto del email.',
				'placeholder' => '',
				'default'     => '',
			),
			'heading'            => array(
				'title'       => 'Encabezado',
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => 'Encabezado principal mostrado en el header del email.',
				'placeholder' => '',
				'default'     => '',
			),
			'additional_content' => array(
				'title'       => 'Contenido adicional',
				'type'        => 'textarea',
				'description' => 'Texto opcional al final del email.',
				'css'         => 'width:400px; height: 75px;',
				'default'     => '',
			),
		);

		$fields = array();
		foreach ( $base as $key => $definicion ) {
			$fields[ $key ] = array_merge( $definicion, $copy[ $key ] ?? array() );

			foreach ( $extras as $clave => $extra ) {
				if ( $key !== ( $extra['akb_after'] ?? '' ) ) {
					continue;
				}
				unset( $extra['akb_after'] );
				$fields[ $clave ] = $extra;
			}
		}

		// Los que no anclan a ninguna clave del esqueleto van al final del bloque
		// de texto. `email_type` se añade DESPUÉS y sigue siendo el último.
		foreach ( $extras as $clave => $extra ) {
			if ( isset( $fields[ $clave ] ) ) {
				continue;
			}
			unset( $extra['akb_after'] );
			$fields[ $clave ] = $extra;
		}

		// email_type debe existir para que el contenido HTML use el tipo correcto.
		// El valor predeterminado multipart incorpora las variantes HTML y text/plain;
		// cada correo sigue siendo editable desde los ajustes de WooCommerce.

		$fields['email_type'] = array(
			'title'       => 'Tipo de email',
			'type'        => 'select',
			'default'     => 'multipart',
			'class'       => 'email_type wc-enhanced-select',
			'description' => 'Formato del email a enviar.',
			'options'     => $this->get_email_type_options(),
			'desc_tip'    => true,
		);

		$this->form_fields = $fields;
	}

	/**
	 * Campos propios del correo hijo, insertados en el ORDEN de lectura.
	 *
	 * POR QUÉ EXISTE: `init_form_fields()` arma la pantalla iterando un `$base`
	 * FIJO de cuatro claves, así que una clave extra devuelta por
	 * `form_field_copy()` se DESCARTA EN SILENCIO — se vería en el código y no en
	 * la pantalla, que es exactamente la «option invisible» que las reglas de la
	 * casa prohíben. La alternativa era que cada hijo sobrescribiera
	 * `init_form_fields()` con un baile de `unset`/re-append para dejar
	 * `email_type` al final, y eso reabre la duplicación que esta clase base
	 * existe para curar: se copiaría al quinto correo. Unas líneas aquí lo
	 * resuelven para los cuatro.
	 *
	 * `akb_after` es una clave PRIVADA de esta base: dice tras qué campo del
	 * esqueleto va el campo nuevo, y se quita antes de que el array llegue a
	 * WooCommerce.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function extra_form_fields(): array {
		return array();
	}
}
