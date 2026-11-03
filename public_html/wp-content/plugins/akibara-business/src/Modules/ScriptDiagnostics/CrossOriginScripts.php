<?php
/**
 * Permite diagnosticar errores de scripts de terceros que admiten CORS.
 *
 * crossorigin anonymous permite recibir mensaje, fichero y línea de una excepción
 * si el servidor del recurso ofrece la autorización CORS correspondiente. Sin
 * esa cabecera, añadir el atributo puede bloquear el script: no se aplica por
 * barrido a cualquier dominio ni se confunde visibilidad del error con su solución.
 *
 * La lista explícita cubre los hosts compatibles de Mercado Pago, Google Tag
 * Manager y Clarity. Un host que no autoriza CORS, como doubleclick en este
 * contrato, permanece fuera para no impedir la ejecución del proveedor.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\ScriptDiagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.108.26
 */
final class CrossOriginScripts {

	/**
	 * Hosts cuya cabecera CORS se verificó contra producción.
	 *
	 * Añadir uno sin comprobarlo antes puede dejar la tienda sin cobrar.
	 *
	 * @var array<int, string>
	 */
	private const HOSTS_VERIFICADOS = array(
		'sdk.mercadopago.com',
		'www.mercadopago.com',
		'http2.mlstatic.com',
		'www.googletagmanager.com',
		'scripts.clarity.ms',
		'www.clarity.ms',
	);

	/** Interruptor de emergencia: `no` desactiva el módulo sin desplegar. */
	public const OPTION_ENABLED = 'akb_crossorigin_scripts_enabled';

	public function register(): void {
		add_filter( 'script_loader_tag', array( $this, 'mark_third_party' ), 20, 3 );
	}

	/**
	 * @param mixed  $tag    Etiqueta <script> completa.
	 * @param mixed  $handle Handle del script.
	 * @param mixed  $src    URL del script.
	 * @return mixed
	 */
	public function mark_third_party( $tag, $handle = '', $src = '' ) {
		unset( $handle );

		if ( ! is_string( $tag ) || ! is_string( $src ) || '' === $src ) {
			return $tag;
		}

		// Interruptor de emergencia. Se consulta acá y no en `register()` para
		// que apagarlo surta efecto en la siguiente petición, sin desplegar.
		if ( 'no' === get_option( self::OPTION_ENABLED, 'yes' ) ) {
			return $tag;
		}

		// Ya lo trae (propio del plugin de terceros): no se toca.
		if ( false !== strpos( $tag, 'crossorigin' ) ) {
			return $tag;
		}

		if ( ! $this->is_verified_host( $src ) ) {
			return $tag;
		}

		// Sólo la primera aparición: un `<script>` lleva un `src` y basta.
		return (string) preg_replace(
			'/<script(\s)/',
			'<script crossorigin="anonymous"$1',
			$tag,
			1
		);
	}

	/**
	 * ¿La URL apunta a un host de la lista verificada?
	 *
	 * Se compara el host COMPLETO, no una subcadena: `strpos` daría verdadero
	 * para `sdk.mercadopago.com.atacante.cl`, que es de otro dueño.
	 */
	private function is_verified_host( string $src ): bool {
		$host = (string) wp_parse_url( $src, PHP_URL_HOST );
		if ( '' === $host ) {
			return false;
		}

		/**
		 * Permite ajustar la lista sin desplegar.
		 *
		 * @param array<int, string> $hosts Hosts verificados.
		 */
		$hosts = apply_filters( 'akb_crossorigin_verified_hosts', self::HOSTS_VERIFICADOS );

		return is_array( $hosts ) && in_array( strtolower( $host ), array_map( 'strtolower', $hosts ), true );
	}
}
