<?php
/**
 * Tests del catálogo de accesos a proveedores.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\MuPlugins;

use Akibara\Business\Tests\Support\CatalogoDeAccesos;
use Akibara\ProviderAccess\Access;
use Akibara\ProviderAccess\Registry;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ProviderAccessTest extends TestCase {

	/** @testdox Welcome Turnstile is a server secret and must never fall back to the database. */
	public function test_welcome_turnstile_secret_is_private_configuration_only(): void {
		$entry = Registry::entry( 'welcome_turnstile_secret' );
		self::assertSame( Registry::SCOPE_SERVER, $entry['scope'] );
		self::assertSame( '', $entry['option'] );
		self::assertSame( 'AKB_WELCOME_TURNSTILE_SECRET', $entry['const'] );
		self::assertSame( '', Access::resolve( 'welcome_turnstile_secret', null, 'test-only-database-value-must-be-ignored' ) );
		self::assertSame( 'test-only-private-value-123456', Access::resolve( 'welcome_turnstile_secret', 'test-only-private-value-123456', null ) );
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		CatalogoDeAccesos::cargar();

		Access::flush();
	}

	protected function tearDown(): void {
		Access::flush();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox No catalog entry may ever carry a value field.
	 */
	public function test_ninguna_entrada_puede_llevar_un_valor(): void {
		// Ésta es la defensa más importante del archivo: el catálogo vive en el
		// repositorio, y el repositorio se despliega entero por rsync. Un campo
		// `value` sería una credencial versionada.
		foreach ( Registry::all() as $id => $entry ) {
			self::assertSame(
				Registry::ENTRY_FIELDS,
				array_keys( $entry ),
				sprintf( 'la entrada «%s» no tiene la forma declarada', $id )
			);
		}
	}

	/**
	 * @testdox A server-scope secret must have no database option, ever.
	 */
	public function test_la_regla_de_una_linea_es_una_asercion(): void {
		foreach ( Registry::all() as $id => $entry ) {
			if ( Registry::SCOPE_SERVER === $entry['scope'] ) {
				self::assertSame( '', $entry['option'], sprintf( '«%s» es de servidor y declara una option', $id ) );
				continue;
			}

			self::assertSame( Registry::SCOPE_PUBLIC, $entry['scope'] );
			self::assertNotSame( '', $entry['option'], sprintf( '«%s» es pública y no declara dónde se edita', $id ) );
			self::assertStringStartsWith( 'akibara_', (string) $entry['option'] );
		}
	}

	/**
	 * @testdox Only server-scope keys may declare an external consumer file.
	 */
	public function test_solo_una_clave_de_servidor_puede_tener_consumidor_externo(): void {
		foreach ( Registry::all() as $id => $entry ) {
			if ( '' === $entry['owner_file'] ) {
				continue;
			}
			self::assertSame( Registry::SCOPE_SERVER, $entry['scope'], sprintf( '«%s»', $id ) );
		}
	}

	/**
	 * @testdox Names and patterns must be well formed and unique.
	 */
	public function test_nombres_unicos_y_patrones_que_compilan(): void {
		$constantes = array();
		$options    = array();

		foreach ( Registry::all() as $id => $entry ) {
			self::assertMatchesRegularExpression( '/^[a-z0-9_]+$/', $id );
			self::assertMatchesRegularExpression( '/^(AKB|AKIBARA)_[A-Z0-9_]+$/', (string) $entry['const'] );
			self::assertNotSame( '', (string) $entry['label'] );
			self::assertNotSame( '', (string) $entry['owner'] );

			// Un patrón mal escrito apagaría una clave válida en silencio: es el
			// modo de fallo que introduce revalidar el formato en lectura.
			self::assertNotFalse(
				@preg_match( (string) $entry['pattern'], 'cualquier-cosa' ),
				sprintf( 'el patrón de «%s» no compila', $id )
			);

			$constantes[] = (string) $entry['const'];
			if ( '' !== $entry['option'] ) {
				$options[] = (string) $entry['option'];
			}
		}

		self::assertSame( $constantes, array_unique( $constantes ) );
		self::assertSame( $options, array_unique( $options ) );
	}

	/**
	 * @testdox The constant always wins over the database option.
	 */
	/**
	 * Hallazgo S-c15599d0: el gate validaba la FORMA de cada constante, no el
	 * perfil que el transporte exige. Con `ventas@akibara.cl`, con el host en
	 * `mail.akibara.cl` o con el puerto en 2525, `wp akibara access-check` decía
	 * «todo configurado» mientras `pre_wp_mail` cortaba el 100 % de los envíos.
	 *
	 * @testdox Los patrones del correo son los del perfil que el transporte acepta.
	 */
	public function test_los_patrones_del_correo_son_el_perfil_del_transporte(): void {
		$casos = array(
			'hostinger_smtp_username'   => array( 'bueno' => 'contacto@akibara.cl', 'malo' => 'ventas@akibara.cl' ),
			'hostinger_smtp_host'       => array( 'bueno' => 'smtp.hostinger.com', 'malo' => 'mail.akibara.cl' ),
			'hostinger_smtp_port'       => array( 'bueno' => '465', 'malo' => '2525' ),
			'hostinger_smtp_encryption' => array( 'bueno' => 'ssl', 'malo' => 'starttls' ),
		);

		foreach ( $casos as $id => $caso ) {
			$patron = (string) Registry::entry( $id )['pattern'];
			self::assertSame( 1, preg_match( $patron, $caso['bueno'] ), "«{$id}» tiene que aceptar el valor que el transporte usa." );
			self::assertSame( 0, preg_match( $patron, $caso['malo'] ), "«{$id}» daba verde con «{$caso['malo']}» y el correo caído." );
		}
	}

	/**
	 * Revisión de 1.2.0: el transporte compara con `strtolower( trim() )` y los
	 * patrones distinguían mayúsculas. `Contacto@akibara.cl` o
	 * `SMTP.hostinger.com` daban rojo en el gate con el correo saliendo, y un
	 * rojo en el gate frena el despliegue. Los espacios ya los recortaba
	 * `Access::resolve()` antes del patrón: se fija aquí para que siga así.
	 *
	 * @testdox Los patrones del correo aceptan lo mismo que el transporte: sin distinguir mayúsculas y con espacios alrededor.
	 */
	public function test_los_patrones_del_correo_no_distinguen_mayusculas_ni_espacios(): void {
		$casos = array(
			'hostinger_smtp_username'   => ' Contacto@Akibara.CL ',
			'hostinger_smtp_host'       => 'SMTP.hostinger.com ',
			'hostinger_smtp_encryption' => ' SSL',
			'hostinger_smtp_port'       => ' 587 ',
		);

		foreach ( $casos as $id => $valor ) {
			self::assertSame( trim( $valor ), Access::resolve( $id, $valor, null ), "«{$id}» tiene que aceptar «{$valor}», como el transporte." );
		}

		self::assertSame( '', Access::resolve( 'hostinger_smtp_username', 'VENTAS@akibara.cl', null ), 'Sin distinguir mayúsculas, pero sigue exigiendo el buzón correcto.' );
	}

	/** @testdox El chequeo del despliegue le pregunta al transporte por el perfil completo. */
	public function test_el_chequeo_pregunta_por_el_perfil_completo(): void {
		$codigo = '';
		foreach ( token_get_all( (string) file_get_contents( dirname( __DIR__, 4 ) . '/mu-plugins/akibara-provider-access.php' ) ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$codigo .= is_array( $token ) ? $token[1] : $token;
		}

		self::assertStringContainsString( 'Transport::profile_is_valid()', $codigo, 'El puerto de un perfil con el cifrado del otro pasa los dos patrones por separado.' );
	}

	public function test_la_constante_gana_sobre_la_option(): void {
		$de_constante = 'AIza' . str_repeat( 'a', 35 );
		$de_option    = 'AIza' . str_repeat( 'b', 35 );

		self::assertSame( $de_constante, Access::resolve( 'google_maps', $de_constante, $de_option ) );
		self::assertSame( $de_option, Access::resolve( 'google_maps', '', $de_option ) );
		self::assertSame( $de_option, Access::resolve( 'google_maps', null, $de_option ) );
		self::assertSame( $de_constante, Access::resolve( 'google_maps', '  ' . $de_constante . ' ', null ) );
		self::assertSame( '', Access::resolve( 'google_maps', null, null ) );
	}

	/**
	 * @testdox A server secret has no path from the database, whatever is in there.
	 */
	public function test_un_secreto_de_servidor_no_tiene_camino_desde_la_base(): void {
		// Aunque alguien haga `wp option update` a mano, la rama no existe.
		self::assertSame(
			'',
			Access::resolve( 'hostinger_smtp_password', null, 'contrasena-metida-en-la-base' )
		);
		self::assertSame(
			'secreto-de-verdad',
			Access::resolve( 'hostinger_smtp_password', 'secreto-de-verdad', null )
		);
	}

	/**
	 * @testdox A malformed value reads as absent, on both paths.
	 */
	public function test_un_valor_con_formato_invalido_se_lee_como_ausente(): void {
		// 38 caracteres en vez de 39: exactamente lo que pasa al pegar una clave
		// truncada, que es el fallo silencioso número uno de este flujo.
		$truncada = 'AIza' . str_repeat( 'x', 34 );

		self::assertSame( '', Access::resolve( 'google_maps', $truncada, null ) );
		self::assertSame( '', Access::resolve( 'google_maps', null, $truncada ) );
		self::assertSame( '', Access::resolve( 'hostinger_smtp_username', 'no-es-un-correo', null ) );
	}

	/**
	 * @testdox A key that expects a boolean must reject its text lookalike.
	 */
	public function test_una_clave_que_espera_booleano_rechaza_su_sombra_de_texto(): void {
		// El interruptor del correo es el único caso donde el catálogo y su
		// consumidor tienen que coincidir en el TIPO, no sólo en el texto: el
		// transporte exige `true ===`. Como `raw_constant()` rinde el booleano
		// como el texto `true`, la CADENA 'true' era indistinguible del booleano
		// para el patrón, y `define( ..., 'true' )` —un tecleo con comillas en el
		// wp-config— daba verde en el gate del despliegue con `pre_wp_mail`
		// cortando el 100 % de los envíos. Es el modo de fallo del incidente del
		// 18-21 ago 2026.
		$entrada = Registry::entry( 'hostinger_mail_enabled' );
		self::assertTrue( $entrada['expect_bool'], 'el interruptor del correo dejó de exigir un booleano' );

		// Y la exigencia se juzga sobre el TIPO, antes de aplanar: por eso el
		// candado vive en `raw_constant()` y no en el patrón. Aquí se fija que
		// ninguna otra entrada se apunte al mismo criterio sin quererlo.
		foreach ( Registry::all() as $id => $entry ) {
			self::assertIsBool( $entry['expect_bool'], sprintf( '«%s»', $id ) );
			if ( 'hostinger_mail_enabled' === $id ) {
				continue;
			}
			self::assertFalse( $entry['expect_bool'], sprintf( '«%s» exige booleano sin que su consumidor lo pida', $id ) );
		}
	}

	/**
	 * @testdox A key written but rejected must read as malformed, never as missing.
	 */
	public function test_una_clave_escrita_pero_rechazada_no_se_informa_como_ausente(): void {
		// «Falta» y «está puesta pero no vale» mandan al dueño a sitios
		// distintos, y la primera lo manda a escribir lo que ya escribió. La
		// bandera existe para eso, así que aquí se mide la SALIDA de
		// `inventory()`, no la declaración del catálogo: el 2026-09-12 el
		// rechazo por tipo se coló como «ausente» porque nadie miraba esta
		// columna.
		Functions\when( 'get_option' )->justReturn( 'AIzaTRUNCADA' );

		$fila = null;
		foreach ( Access::inventory() as $candidata ) {
			if ( 'google_maps' === $candidata['id'] ) {
				$fila = $candidata;
			}
		}

		self::assertIsArray( $fila );
		self::assertFalse( $fila['configured'], 'una clave truncada no puede darse por configurada' );
		self::assertTrue( $fila['malformed'], 'una clave escrita y rechazada se informa como ausente' );
	}

	/**
	 * @testdox An undeclared key is a programming error, not an empty string.
	 */
	public function test_una_clave_no_declarada_revienta(): void {
		$this->expectException( \InvalidArgumentException::class );

		Access::resolve( 'proveedor_inventado', 'x', null );
	}

	/**
	 * @testdox The registry inventories the mail credentials but refuses to hand them over.
	 */
	public function test_el_registro_no_entrega_las_credenciales_del_correo(): void {
		// Esta excepción NO es un obstáculo que rodear. Las credenciales SMTP las
		// lee el mu-plugin del transporte y nadie más; si alguien la «arregla»
		// para mostrar el estado del correo, el inventario se convierte en una
		// puerta a las credenciales.
		$this->expectException( \DomainException::class );

		Access::get( 'hostinger_smtp_password' );
	}

	/**
	 * @testdox Knowing whether a secret is set must not require reading it.
	 */
	public function test_se_puede_saber_si_esta_puesta_sin_leerla(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		// No lanza: es lo que permite inventariar sin cruzar la frontera.
		self::assertFalse( Access::is_configured( 'hostinger_smtp_password' ) );
	}

	/**
	 * @testdox Resolving a server secret must never touch the options table.
	 */
	public function test_un_secreto_de_servidor_no_consulta_la_base_de_datos(): void {
		Functions\expect( 'get_option' )->never();

		self::assertFalse( Access::is_configured( 'hostinger_smtp_username' ) );
		self::assertFalse( Access::is_configured( 'hostinger_smtp_password' ) );
	}

	/**
	 * @testdox Una clave ausente devuelve cadena vacía: el consumidor degrada, no revienta.
	 *
	 * Aquí se probaba `Access::must()`, retirada el 2026-09-18 por decisión del
	 * dueño con la regla de cero código muerto: no tenía un solo llamador y su
	 * único consumidor posible —el autocompletar de direcciones— no puede
	 * lanzar, porque un throw dentro de `wp_enqueue_scripts` tumba el front.
	 * Lo que SÍ hay que fijar es que la ausencia se note sin romper nada.
	 */
	public function test_una_clave_ausente_degrada_en_vez_de_lanzar(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		self::assertSame( '', Access::get( 'google_maps' ) );
		self::assertFalse( Access::is_configured( 'google_maps' ) );
	}

	/** @testdox El catálogo ya no expone una vía que lance: no queda quien la llame. */
	public function test_no_vuelve_must(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 4 ) . '/mu-plugins/akibara-provider-access.php' );

		self::assertStringNotContainsString( 'public static function must(', $fuente );
		self::assertStringNotContainsString( 'class MissingProviderAccess', $fuente );
	}

	/**
	 * @testdox The inventory must never expose a value.
	 */
	public function test_el_inventario_no_expone_ningun_valor(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$filas = Access::inventory();
		self::assertNotSame( array(), $filas );

		$declaradas = array(
			'id',
			'label',
			'owner',
			'scope',
			'const',
			'required',
			'configured',
			'malformed',
			'source',
			'length',
			'owner_file',
			'owner_file_ok',
			'owner_live',
			'console_url',
		);

		foreach ( $filas as $fila ) {
			// Añadir una columna obliga a pasar por acá, que es donde se piensa
			// si esa columna filtra algo.
			self::assertSame( $declaradas, array_keys( $fila ) );
			self::assertArrayNotHasKey( 'value', $fila );
			self::assertIsBool( $fila['configured'] );
			self::assertIsInt( $fila['length'] );
		}
	}

	/**
	 * @testdox The orphan scanner must not see constants written inside comments.
	 */
	public function test_el_escaner_ignora_los_comentarios(): void {
		// La primera pasada de `access-scan` en producción se quejó de una
		// constante de ejemplo que sólo vivía dentro de un PHPDoc. Un inventario
		// que grita por comentarios enseña a ignorar sus avisos.
		$codigo = <<<'PHP'
		<?php
		/** Ejemplo: defined( 'AKB_SOLO_EN_UN_COMENTARIO' ) */
		// otro comentario: constant( 'AKB_TAMPOCO' )
		$x = defined( 'AKB_ESTA_SI_SE_LEE' );
		PHP;

		$limpio = \Akibara\ProviderAccess\Cli::code_only( $codigo );

		self::assertStringNotContainsString( 'AKB_SOLO_EN_UN_COMENTARIO', $limpio );
		self::assertStringNotContainsString( 'AKB_TAMPOCO', $limpio );
		self::assertStringContainsString( 'AKB_ESTA_SI_SE_LEE', $limpio );
	}

	/**
	 * @testdox Every constant the transport reads must be declared in the catalog.
	 */
	public function test_el_catalogo_declara_todo_lo_que_lee_el_transporte(): void {
		// El inventario sólo dice la verdad si nadie lee claves por su cuenta.
		$fuente = \Akibara\ProviderAccess\Cli::code_only(
			(string) file_get_contents( dirname( __DIR__, 4 ) . '/mu-plugins/akibara-correo-smtp.php' )
		);
		preg_match_all( '/(?:defined|constant)\(\s*[\'"]((?:AKB|AKIBARA)_[A-Z0-9_]+)[\'"]/', $fuente, $m );

		$leidas     = array_unique( $m[1] );
		$declaradas = Registry::declared_constants();

		self::assertNotSame( array(), $leidas, 'el transporte debería leer al menos una constante' );
		foreach ( $leidas as $constante ) {
			self::assertContains( $constante, $declaradas, sprintf( '%s se lee pero no está declarada', $constante ) );
		}
	}

	/**
	 * El gate que nunca puede salir verde se acaba ignorando, y con él la señal
	 * del día en que aparezca una clave de proveedor de verdad leída a mano
	 * (S-8034c7e6). Esta prueba es ese gate, sin WP-CLI.
	 *
	 * @testdox The orphan scan must be able to end green.
	 */
	public function test_el_barrido_de_constantes_puede_dar_verde(): void {
		$raiz       = dirname( __DIR__, 4 );
		$declaradas = Registry::declared_constants();
		$permitidas = Registry::NOT_A_PROVIDER_KEY;
		$huerfanas  = array();

		// La MISMA lista que usa el comando, no una copia: si alguien añade una
		// carpeta al barrido, esta prueba la cubre sola.
		foreach ( \Akibara\ProviderAccess\Cli::SCAN_DIRS as $sub ) {
			if ( ! is_dir( $raiz . $sub ) ) {
				continue;
			}
			$archivos = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $raiz . $sub, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $archivos as $archivo ) {
				$ruta = (string) $archivo->getPathname();
				if ( ! $archivo->isFile() || 'php' !== strtolower( (string) $archivo->getExtension() ) ) {
					continue;
				}
				// Las pruebas escriben constantes de mentira a propósito.
				if ( false !== strpos( $ruta, '/tests/' ) ) {
					continue;
				}
				$codigo = \Akibara\ProviderAccess\Cli::code_only( (string) file_get_contents( $ruta ) );
				if ( ! preg_match_all( '/(?:defined|constant)\(\s*[\'"]((?:AKB|AKIBARA)_[A-Z0-9_]+)[\'"]/', $codigo, $m ) ) {
					continue;
				}
				foreach ( array_unique( $m[1] ) as $nombre ) {
					if ( in_array( $nombre, $declaradas, true ) || in_array( $nombre, $permitidas, true ) ) {
						continue;
					}
					$huerfanas[ $nombre ] = str_replace( $raiz . '/', '', $ruta );
				}
			}
		}

		self::assertSame(
			array(),
			$huerfanas,
			'o la constante es una clave de proveedor y va al catálogo, o no lo es y va a NOT_A_PROVIDER_KEY'
		);
	}

	/**
	 * El hueco que encontró la revisión independiente antes de desplegar: la
	 * pantalla ya distinguía «está» de «cargó», pero el GATE DEL DESPLIEGUE
	 * seguía mirando sólo si el fichero existe. Con un transporte presente pero
	 * inerte decía «Todos los accesos obligatorios están configurados» y el
	 * despliegue seguía adelante con el correo caído.
	 *
	 * Y se prueba EJECUTANDO el cálculo, no con `grep` al fuente: esa falta de
	 * cobertura es lo que dejó pasar el hueco.
	 *
	 * @testdox The deploy gate must fail when the transport is there but did not load.
	 */
	public function test_el_gate_falla_con_el_transporte_presente_pero_inerte(): void {
		$fila = static fn( string $id ): array => array(
			'id'            => $id,
			'const'         => 'AKIBARA_HOSTINGER_' . strtoupper( $id ),
			'owner_file'    => 'mu-plugins/akibara-correo-smtp.php',
			'owner_file_ok' => true,
			'owner_live'    => false,
			'required'      => true,
			'configured'    => true,
			'malformed'     => false,
		);
		$filas = array( $fila( 'mail_enabled' ), $fila( 'smtp_username' ) );

		$problemas = \Akibara\ProviderAccess\Cli::problemas( $filas, true, '', true );

		self::assertCount( 1, $problemas, 'seis entradas comparten consumidor: se nombra una vez, no seis' );
		self::assertStringContainsString( 'NO se cargó', $problemas[0] );

		// También fuera de producción: un transporte que no carga es correo que
		// no sale, aquí y en el espejo. Lo que sí depende del entorno es la
		// ausencia de las claves, no esto.
		self::assertCount( 1, \Akibara\ProviderAccess\Cli::problemas( $filas, false, '', null ) );
	}

	/**
	 * @testdox With everything in place the gate returns nothing, and a rejected profile is a problem.
	 */
	public function test_el_gate_da_verde_cuando_todo_esta(): void {
		$sana = array(
			'id'            => 'google_maps',
			'const'         => 'AKB_GOOGLE_MAPS_API_KEY',
			'owner_file'    => '',
			'owner_file_ok' => true,
			'owner_live'    => null,
			'required'      => false,
			'configured'    => true,
			'malformed'     => false,
		);

		self::assertSame( array(), \Akibara\ProviderAccess\Cli::problemas( array( $sana ), true, '', true ) );

		$rechazado = \Akibara\ProviderAccess\Cli::problemas( array( $sana ), true, '', false );
		self::assertCount( 1, $rechazado );
		self::assertStringContainsString( 'perfil_smtp', $rechazado[0], 'el puerto de un perfil con el cifrado del otro pasa los dos patrones' );
	}

	/**
	 * @testdox A constant that is not a provider key must be declared as such, never invented.
	 */
	public function test_la_lista_de_no_claves_no_pisa_al_catalogo(): void {
		foreach ( Registry::NOT_A_PROVIDER_KEY as $nombre ) {
			self::assertMatchesRegularExpression( '/^(AKB|AKIBARA)_[A-Z0-9_]+$/', $nombre );
			self::assertNotContains(
				$nombre,
				Registry::declared_constants(),
				sprintf( '«%s» no puede estar a la vez en el catálogo y en la lista de no-claves', $nombre )
			);
		}
	}

	/**
	 * @testdox The console must not shout FALTA for a key the other two surfaces call production-only.
	 */
	public function test_la_consola_no_grita_falta_fuera_de_produccion(): void {
		$fila = array(
			'id'            => 'hostinger_smtp_username',
			'configured'    => false,
			'malformed'     => false,
			'required'      => true,
			'owner_file_ok' => true,
			'owner_live'    => true,
		);

		self::assertSame( 'FALTA', \Akibara\ProviderAccess\Cli::estado_de( $fila, true ) );
		self::assertSame( 'SÓLO EN PROD', \Akibara\ProviderAccess\Cli::estado_de( $fila, false ) );
	}

	/**
	 * @testdox A present but inert transport must never read green.
	 */
	public function test_un_transporte_presente_pero_inerte_no_da_verde(): void {
		$fila = array(
			'id'            => 'hostinger_mail_enabled',
			'configured'    => true,
			'malformed'     => false,
			'required'      => true,
			'owner_file_ok' => true,
			'owner_live'    => false,
		);

		self::assertSame(
			'NO CARGADO',
			\Akibara\ProviderAccess\Cli::estado_de( $fila, true ),
			'el inventario daba «ok» mientras el centinela gritaba, y el verde era el que mentía'
		);
	}

	/**
	 * @testdox Every catalog entry must be reachable through the registry.
	 */
	public function test_el_inventario_cubre_el_catalogo_entero(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		self::assertSame(
			array_keys( Registry::all() ),
			array_column( Access::inventory(), 'id' )
		);
		self::assertSame(
			array_column( Registry::all(), 'const' ),
			Registry::declared_constants()
		);
	}
}
