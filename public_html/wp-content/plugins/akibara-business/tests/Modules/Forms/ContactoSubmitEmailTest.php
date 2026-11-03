<?php
/**
 * Quien teclea mal su correo tiene que poder saber QUÉ arreglar.
 *
 * EL DEFECTO: `sanitize_email()` VACÍA un correo mal formado, así que
 * «juan@correo» llegaba como cadena vacía y se lo comía la comprobación de
 * «faltan campos» — con los tres campos llenos. El cliente leía «Nombre, email y
 * mensaje son requeridos», miraba el formulario, no encontraba qué faltaba y se
 * iba. Y el `invalid_email` que existía para decírselo era código INALCANZABLE.
 *
 * Del otro lado del mostrador se ve como «no llegó ningún mensaje».
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Forms;

use Akibara\Business\Modules\Forms\ContactoSubmit;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

final class ContactoSubmitEmailTest extends TestCase {

	/** El `$wpdb` global que había antes: se devuelve al terminar. */
	private $wpdb_previo = null;

	private bool $habia_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->habia_wpdb  = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_previo = $GLOBALS['wpdb'] ?? null;

		// `sanitize_email()` de verdad: vaciar lo que no es un correo es
		// justamente el comportamiento que causaba el defecto, así que doblarlo
		// «amable» escondería lo que se está probando.
		Functions\when( 'sanitize_email' )->alias(
			static function ( string $valor ): string {
				$limpio = filter_var( trim( $valor ), FILTER_SANITIZE_EMAIL );

				return is_string( $limpio ) && false !== filter_var( $limpio, FILTER_VALIDATE_EMAIL ) ? $limpio : '';
			}
		);
		Functions\when( 'is_email' )->alias(
			static fn( string $valor ) => false !== filter_var( $valor, FILTER_VALIDATE_EMAIL ) ? $valor : false
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $v ): string => trim( $v ) );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( string $v ): string => trim( $v ) );

		// Lo que hay ANTES de la validación y no es lo que se mide: el guard de
		// intentos, el honeypot y el origen. Se dejan pasar a propósito para que
		// la prueba llegue al punto donde se decide el mensaje de error.
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://akibara.cl' );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $u, int $c = -1 ) => parse_url( $u, $c ) );
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_cache_add' )->justReturn( true );
		Functions\when( 'wp_cache_incr' )->justReturn( 1 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $valor ) => $valor );
		Functions\when( 'absint' )->alias( static fn( $v ): int => abs( (int) $v ) );
		Functions\when( 'sanitize_key' )->alias( static fn( string $v ): string => strtolower( $v ) );

		// Lo que hay DESPUÉS de la validación. Las pruebas del dueño del fichero
		// nunca llegaban hasta aquí —todas sus direcciones se caían en el filtro—,
		// pero el contrapeso de direcciones válidas sí tiene que atravesar la
		// bitácora y el aviso para demostrar que una dirección legítima llega a
		// buen puerto. Se doblan al mínimo: lo que se mide sigue siendo el código
		// de error, no la entrega.
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'maybe_serialize' )->alias( static fn( $v ) => is_scalar( $v ) ? (string) $v : serialize( $v ) );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'get_option' )->alias( static fn( string $c, $defecto = false ) => $defecto );
		Functions\when( 'wp_trim_words' )->alias(
			static function ( string $texto, int $palabras = 55, ?string $mas = null ): string {
				$trozos = preg_split( '/\s+/', trim( $texto ) ) ?: array();
				return count( $trozos ) <= $palabras
					? implode( ' ', $trozos )
					: implode( ' ', array_slice( $trozos, 0, $palabras ) ) . (string) $mas;
			}
		);
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'prueba-0000-0000-0000-000000000000' );
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'get_bloginfo' )->justReturn( 'Akibara' );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $v ): string => strip_tags( $v ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'current_time' )->alias( static fn( string $t = 'mysql' ) => 'mysql' === $t ? '2026-09-11 12:00:00' : time() );

		// El limitador cae a la base cuando no hay caché de objetos. Aquí sólo
		// tiene que dejar pasar: lo que se mide es el mensaje de error, y el
		// limitador ya tiene sus propias pruebas.
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';

			public string $options = 'wp_options';

			public string $last_error = '';

			/** @param mixed ...$args Ignorados. @return string */
			public function prepare( string $consulta, ...$args ): string {
				unset( $args );

				return $consulta;
			}

			/**
			 * La bitácora crea la fila de cada mensaje con `INSERT IGNORE` (OptionRow):
			 * con el registro vacío esa inserción entra. El resto (el limitador) sigue
			 * viendo 0 filas, como antes.
			 *
			 * @return int
			 */
			public function query( string $consulta ) {
				return 0 === strpos( $consulta, 'INSERT IGNORE INTO wp_options' ) ? 1 : 0;
			}

			/** @return string|null */
			public function get_var( string $consulta ) {
				unset( $consulta );

				return '0';
			}

			/**
			 * La bitácora lee las entradas existentes para podar las viejas. Con el
			 * registro vacío no hay nada que podar, que es el estado en el que
			 * interesa medir: el mensaje entra limpio.
			 *
			 * @return array<int, string>
			 */
			public function get_col( string $consulta ) {
				unset( $consulta );

				return array();
			}

			/** @return string */
			public function esc_like( string $texto ) {
				return addcslashes( $texto, '_%\\' );
			}
		};
	}

	protected function tearDown(): void {
		// El doble de `$wpdb` de esta prueba no tiene `get_results()`: dejarlo puesto
		// hacía fallar a la prueba que corriera después y lo leyera (según el orden).
		if ( $this->habia_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_previo;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Corre `handle()` hasta el punto de validación y devuelve el código de error.
	 *
	 * @param array<string, string> $campos Lo que envía el formulario.
	 * @return string Código de WP_Error, o '' si pasó la validación.
	 */
	private function codigo_de_error( array $campos ): string {
		$peticion = new WP_REST_Request();
		foreach ( $campos as $clave => $valor ) {
			$peticion->set_param( $clave, $valor );
		}

		$respuesta = ( new ContactoSubmit() )->handle( $peticion );

		return $respuesta instanceof WP_Error ? (string) $respuesta->get_error_code() : '';
	}

	/**
	 * @testdox Un correo mal escrito dice que el correo está mal, no que falten campos.
	 *
	 * El caso exacto que se midió: los tres campos llenos y «juan@correo» como
	 * dirección.
	 */
	public function test_un_correo_mal_escrito_no_se_reporta_como_campo_vacio(): void {
		$codigo = $this->codigo_de_error(
			array(
				'nombre'  => 'Juan',
				'email'   => 'juan@correo',
				'mensaje' => '¿Tienen el tomo 5?',
			)
		);

		self::assertNotSame( 'missing_fields', $codigo, 'El campo NO está vacío: está mal escrito.' );
		self::assertSame( 'invalid_email', $codigo );
	}

	/**
	 * @testdox Y el campo vacío de verdad sigue diciendo que falta.
	 *
	 * La otra mitad: distinguir «no lo escribiste» de «lo escribiste mal» sólo
	 * sirve si las dos respuestas siguen siendo ciertas.
	 *
	 * @dataProvider campos_que_faltan
	 *
	 * @param array<string, string> $campos Envío incompleto.
	 */
	public function test_un_campo_vacio_sigue_diciendo_que_falta( array $campos ): void {
		self::assertSame( 'missing_fields', $this->codigo_de_error( $campos ) );
	}

	/** @return array<string, array{0: array<string, string>}> */
	public static function campos_que_faltan(): array {
		return array(
			'sin correo'  => array( array( 'nombre' => 'Juan', 'email' => '', 'mensaje' => 'Hola' ) ),
			'sólo espacios en el correo' => array( array( 'nombre' => 'Juan', 'email' => '   ', 'mensaje' => 'Hola' ) ),
			'sin nombre'  => array( array( 'nombre' => '', 'email' => 'juan@akibara.cl', 'mensaje' => 'Hola' ) ),
			'sin mensaje' => array( array( 'nombre' => 'Juan', 'email' => 'juan@akibara.cl', 'mensaje' => '' ) ),
		);
	}

	/**
	 * @testdox El valor crudo se usa SÓLO para saber si venía vacío.
	 *
	 * Candado de fuente: lo que se envía, se guarda o se muestra tiene que ser el
	 * saneado. Un crudo que se escape a `notify()` sería peor que el defecto que
	 * esto cierra.
	 */
	public function test_el_crudo_no_sale_de_la_validacion(): void {
		$fuente = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/Forms/ContactoSubmit.php'
		);
		$fuente = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $fuente );

		self::assertSame(
			4,
			substr_count( $fuente, '$email_crudo' ),
			'El crudo aparece cuatro veces y sólo cuatro: donde se lee, donde se sanea, donde'
				. ' se comprueba si venía vacío y donde se compara contra el saneado para no'
				. ' suplantar la dirección. Una quinta sería el crudo escapándose.'
		);
		self::assertStringNotContainsString( 'notify( $nombre, $email_crudo', $fuente );

		// El conteo de arriba se pone rojo con cualquier cambio, correcto o no.
		// Esto otro mide lo que de verdad importa: que ninguna línea que GUARDA o
		// ENVÍA toque el crudo. Sobrevive a que alguien mueva la validación de sitio.
		foreach ( preg_split( '/\R/', $fuente ) ?: array() as $numero => $linea ) {
			if ( ! str_contains( $linea, '$email_crudo' ) ) {
				continue;
			}
			foreach ( array( 'ContactoLog::record', 'notify(', 'wp_mail(', 'esc_html', 'echo ' ) as $salida ) {
				self::assertStringNotContainsString(
					$salida,
					$linea,
					sprintf(
						'Línea %d: el crudo llegó a «%s». Lo que se guarda, se envía o se muestra'
							. ' tiene que ser el saneado.',
						$numero + 1,
						$salida
					)
				);
			}
		}
	}

	/**
	 * @testdox Un espacio en medio del correo se rechaza, no se borra en silencio.
	 *
	 * EL DEFECTO QUE CIERRA: `sanitize_email()` no sólo vacía lo mal formado;
	 * también REESCRIBE lo que cree poder arreglar. «juan perez@gmail.com» salía
	 * como «juanperez@gmail.com» —otra dirección, igual de válida, probablemente
	 * de otra persona— y el cliente leía «Mensaje recibido». La respuesta del
	 * dueño se iba a un buzón ajeno, y esa persona leía una consulta que no es
	 * suya. El formulario lleva `novalidate`: el navegador tampoco lo avisa.
	 */
	public function test_un_espacio_en_medio_no_reescribe_la_direccion(): void {
		$codigo = $this->codigo_de_error(
			array(
				'nombre'  => 'Juan',
				'email'   => 'juan perez@gmail.com',
				'mensaje' => '¿Tienen el tomo 5?',
			)
		);

		self::assertSame(
			'invalid_email',
			$codigo,
			'Con el espacio dentro, la dirección que se guardaría es la de otra persona.'
		);
	}

	/**
	 * @testdox Los acentos y la ñ no se borran en silencio.
	 *
	 * Medio país se apellida Muñoz, Peña o Ibáñez. Sanear le quita la tilde y
	 * devuelve una dirección distinta y válida, así que el defecto no avisa nunca.
	 *
	 * @dataProvider apellidos_chilenos
	 *
	 * @param string $correo Dirección tal cual la teclea el cliente.
	 */
	public function test_los_acentos_no_se_borran_en_silencio( string $correo ): void {
		self::assertSame(
			'invalid_email',
			$this->codigo_de_error(
				array(
					'nombre'  => 'Cliente',
					'email'   => $correo,
					'mensaje' => 'Consulta',
				)
			),
			sprintf( 'A «%s» se le cae la tilde y se guarda como otra dirección.', $correo )
		);
	}

	/** @return array<string, array{0: string}> */
	public static function apellidos_chilenos(): array {
		return array(
			'con ñ'           => array( 'muñoz@gmail.com' ),
			'con tilde'       => array( 'maria.peña@gmail.com' ),
			'con las dos'     => array( 'ibáñez.jose@gmail.com' ),
		);
	}

	/**
	 * @testdox Y las direcciones raras pero válidas siguen entrando.
	 *
	 * EL CONTRAPESO. Rechazar «lo que cambió al sanear» es una red que puede
	 * cerrarse de más: si `sanitize_email()` normalizara algo legítimo —minúsculas,
	 * espacios de los bordes, un `+`— dejaríamos fuera a clientes reales, que es
	 * peor que el defecto que esto cierra. Estas seis tienen que pasar siempre.
	 *
	 * @dataProvider validos_que_no_se_pueden_romper
	 *
	 * @param string $correo Dirección legítima.
	 */
	public function test_lo_valido_sigue_pasando( string $correo ): void {
		self::assertSame(
			'',
			$this->codigo_de_error(
				array(
					'nombre'  => 'Cliente',
					'email'   => $correo,
					'mensaje' => 'Consulta',
				)
			),
			sprintf( '«%s» es una dirección legítima: si esto se pone rojo, la red cierra de más.', $correo )
		);
	}

	/** @return array<string, array{0: string}> */
	public static function validos_que_no_se_pueden_romper(): array {
		return array(
			'con espacios en los bordes' => array( '  juan@gmail.com  ' ),
			'con etiqueta'               => array( 'juan+etiqueta@gmail.com' ),
			'con guión bajo y subdominio' => array( 'juan_perez@sub.dominio.cl' ),
			'con guión y dominio doble'  => array( 'juan-perez@dominio.co.uk' ),
			'en mayúsculas'              => array( 'JUAN@GMAIL.COM' ),
			'lo más corto posible'       => array( 'j@d.cl' ),
		);
	}
}
