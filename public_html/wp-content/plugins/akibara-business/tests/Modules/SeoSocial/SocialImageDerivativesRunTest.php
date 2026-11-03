<?php
/**
 * La corrida de derivadas sociales tiene tope, candado y reanudación.
 *
 * `generate_missing()` podía transcodificar el directorio ENTERO en un solo
 * tick de cron (tope 2.000 sobre 1.503 originales: ~57 s de CPU), sin candado
 * que impidiera solaparse con el tick siguiente y sin forma de retomar si
 * moría a la mitad. Y `ensure_for_slug()` hacía el decode AVIF dentro del
 * `wp_head` de un visitante.
 *
 * Se prueba con el sistema de archivos real: lo que se mide acá es el troceo, el
 * candado, el cursor y que el front ya no convierta. Las pruebas que convierten
 * de verdad se saltan si GD no trae AVIF.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeoSocial;

use Akibara\Business\Modules\SeoSocial\SocialImageDerivatives;
use Akibara\Business\Tests\Support\OptionsRowDouble;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionProperty;

final class SocialImageDerivativesRunTest extends TestCase {

	use OptionsRowDouble;

	/** Raíz temporal propia, sólo si nadie definió ya WP_CONTENT_DIR. */
	private static string $root = '';

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, array{hook: string, args: array<int, mixed>}> */
	private array $encolados = array();

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		// WP_CONTENT_DIR es una constante: si otra suite ya la fijó se reutiliza
		// su directorio; si no, esta clase monta el suyo. El directorio de
		// portadas se recrea igual: la suite hermana borra el suyo al terminar y,
		// según el orden, esta clase corre después.
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			self::$root = sys_get_temp_dir() . '/akb-social-run-' . uniqid();
			define( 'WP_CONTENT_DIR', self::$root );
		}

		$dir = WP_CONTENT_DIR . '/uploads/portadas';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
	}

	public static function tearDownAfterClass(): void {
		if ( '' !== self::$root && is_dir( self::$root ) ) {
			array_map( 'unlink', (array) glob( self::$root . '/uploads/portadas/*' ) );
			rmdir( self::$root . '/uploads/portadas' );
			rmdir( self::$root . '/uploads' );
			rmdir( self::$root );
		}
		parent::tearDownAfterClass();
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options   = array();
		// El candado del módulo se escribe con SQL atómico sobre la fila (OptionRow),
		// no con `add_option()`: sin este doble, ninguna corrida entra.
		$this->instalar_doble_de_options( $this->options );
		$this->encolados = array();
		self::reset_now_budget();

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default = false ) => $this->options[ $name ] ?? $default
		);
		Functions\when( 'add_option' )->alias(
			function ( string $name, $value ): bool {
				if ( array_key_exists( $name, $this->options ) ) {
					return false;
				}
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );

				return true;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->alias(
			function ( string $hook, array $args = array() ) {
				foreach ( $this->encolados as $encolado ) {
					if ( $encolado['hook'] === $hook && $encolado['args'] === $args ) {
						return time();
					}
				}

				return false;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( int $timestamp, string $hook, array $args = array() ): bool {
				$this->encolados[] = array(
					'hook' => $hook,
					'args' => $args,
				);

				return true;
			}
		);
	}

	protected function tearDown(): void {
		$this->retirar_doble_de_options();
		self::reset_now_budget();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * El presupuesto de conversiones en el acto es por PETICIÓN, y la suite
	 * entera es una sola: sin devolverlo a cero, el resultado dependería de
	 * cuántas portadas convirtieron las clases que corrieron antes.
	 */
	private static function reset_now_budget(): void {
		$presupuesto = new ReflectionProperty( SocialImageDerivatives::class, 'now_conversions' );
		$presupuesto->setAccessible( true );
		$presupuesto->setValue( null, 0 );
	}

	private function max_per_run(): int {
		return (int) ( new ReflectionClassConstant( SocialImageDerivatives::class, 'MAX_PER_RUN' ) )->getValue();
	}

	public function test_the_run_cap_is_small_enough_for_one_cron_tick(): void {
		// 1.503 originales en producción: el tope tiene que trocear, no cubrirlos
		// a todos. A ~38 ms por conversión, 100 son ~4 s de tick.
		self::assertLessThanOrEqual( 200, $this->max_per_run() );
		self::assertGreaterThan( 0, $this->max_per_run() );
	}

	public function test_a_second_run_bails_out_while_the_first_holds_the_lock(): void {
		if ( '' === SocialImageDerivatives::portadas_dir() || ! function_exists( 'imagecreatefromavif' ) ) {
			self::markTestSkipped( 'Sin GD-AVIF o sin portadas, la corrida sale antes de mirar el candado.' );
		}

		$modulo = new SocialImageDerivatives();

		// Formato de 1.116.10 (sólo la marca de tiempo): el que puede quedar en
		// producción durante el despliegue. Tiene que respetarse igual.
		$fresco                                       = (string) time();
		$this->options['akb_social_derivatives_lock'] = $fresco;

		self::assertSame(
			0,
			$modulo->generate_missing(),
			'Con el candado tomado y fresco, la corrida solapada no hace nada.'
		);
		self::assertSame( $fresco, $this->options['akb_social_derivatives_lock'], 'Y no lo roba ni lo suelta: sigue siendo del otro.' );
	}

	public function test_an_abandoned_lock_does_not_block_forever(): void {
		if ( '' === SocialImageDerivatives::portadas_dir() ) {
			self::markTestSkipped( 'Sin directorio de portadas en este entorno.' );
		}

		$modulo = new SocialImageDerivatives();

		// Corrida muerta hace una hora: el candado caducó.
		$this->options['akb_social_derivatives_lock'] = (string) ( time() - 3600 );
		$modulo->generate_missing();

		self::assertArrayNotHasKey(
			'akb_social_derivatives_lock',
			$this->options,
			'La corrida tiene que soltar el candado al terminar.'
		);
	}

	/**
	 * Hallazgo S-761e5164: la continuación preguntaba `wp_next_scheduled( HOOK )`,
	 * y ese hook SIEMPRE tiene agendado el evento diario (register()). El barrido
	 * troceado nunca se encadenaba: avanzaba 100 portadas por día.
	 */
	public function test_a_capped_run_chains_its_continuation_despite_the_daily_event(): void {
		if ( ! function_exists( 'imageavif' ) || ! function_exists( 'imagecreatefromavif' ) || '' === SocialImageDerivatives::portadas_dir() ) {
			self::markTestSkipped( 'Sin AVIF en GD o sin directorio de portadas.' );
		}

		$dir    = SocialImageDerivatives::portadas_dir();
		$lienzo = imagecreatetruecolor( 2, 2 );
		$avif   = $dir . '/zz-cadena-000.avif';
		imageavif( $lienzo, $avif );
		imagedestroy( $lienzo );
		$creados = array( $avif );
		// Dos topes y uno: la SEGUNDA corrida también llega al tope y tiene que
		// encontrar la continuación ya agendada.
		for ( $i = 1; $i <= 2 * $this->max_per_run(); $i++ ) {
			$copia = sprintf( '%s/zz-cadena-%03d.avif', $dir, $i );
			copy( $avif, $copia );
			$creados[] = $copia;
		}

		// El evento diario, agendado como lo deja register().
		$this->encolados[] = array(
			'hook' => SocialImageDerivatives::HOOK,
			'args' => array(),
		);

		try {
			$modulo = new SocialImageDerivatives();
			$modulo->generate_missing();
			$modulo->generate_missing(); // Otra corrida topada antes de que corra la continuación.

			$continuaciones = array_filter(
				$this->encolados,
				static fn( array $e ): bool => SocialImageDerivatives::HOOK_CONTINUE === $e['hook']
			);
			self::assertCount( 1, $continuaciones, 'Quedaban portadas: la continuación se agenda, y una sola vez.' );
		} finally {
			foreach ( $creados as $fichero ) {
				@unlink( $fichero ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( substr( $fichero, 0, -5 ) . SocialImageDerivatives::SUFFIX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/**
	 * Revisión 1.116.7: el tope contaba sólo las conversiones BUENAS. Con
	 * portadas que fallan, una corrida decodificaba el directorio entero.
	 */
	public function test_failed_conversions_count_toward_the_run_cap(): void {
		if ( ! function_exists( 'imagecreatefromavif' ) || '' === SocialImageDerivatives::portadas_dir() ) {
			self::markTestSkipped( 'Sin AVIF en GD o sin directorio de portadas.' );
		}

		$dir     = SocialImageDerivatives::portadas_dir();
		$creados = array();
		for ( $i = 0; $i <= $this->max_per_run(); $i++ ) {
			$roto = sprintf( '%s/zz-rota-%03d.avif', $dir, $i );
			file_put_contents( $roto, 'no es un avif' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$creados[] = $roto;
		}

		try {
			( new SocialImageDerivatives() )->generate_missing();

			self::assertSame( 'zz-rota-099.avif', $this->options['akb_social_derivatives_cursor'] ?? null, 'La corrida se detiene tras 100 intentos, aunque ninguno salga.' );
		} finally {
			foreach ( $creados as $fichero ) {
				@unlink( $fichero ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/**
	 * Revisión de 1.116.8: la caché negativa se escribía DESPUÉS de convertir.
	 * Un fatal dentro de `imagecreatefromavif()` (memoria, tiempo máximo) mata
	 * el proceso sin pasar por ningún `catch`, así que nunca se escribía y cada
	 * correo siguiente volvía a decodificar el mismo AVIF y a morir. No se puede
	 * provocar un fatal dentro de la suite: se prueba que la marca YA está
	 * escrita cuando la conversión todavía no produjo nada.
	 *
	 * @testdox La caché negativa se escribe antes de convertir y el éxito la retira.
	 */
	public function test_the_failure_cache_is_written_before_converting(): void {
		if ( ! function_exists( 'imageavif' ) || ! function_exists( 'imagecreatefromavif' ) || '' === SocialImageDerivatives::portadas_dir() ) {
			self::markTestSkipped( 'Sin AVIF en GD o sin directorio de portadas.' );
		}

		$dir    = SocialImageDerivatives::portadas_dir();
		$slug   = 'zz-antes-' . uniqid();
		$target = $dir . '/' . $slug . SocialImageDerivatives::SUFFIX;
		$lienzo = imagecreatetruecolor( 2, 2 );
		imageavif( $lienzo, $dir . '/' . $slug . '.avif' );
		imagedestroy( $lienzo );

		$eventos = array();
		Functions\when( 'set_transient' )->alias(
			static function ( string $clave ) use ( &$eventos, $target ): bool {
				$eventos[] = array( 'set', $clave, file_exists( $target ) );
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( string $clave ) use ( &$eventos, $target ): bool {
				$eventos[] = array( 'delete', $clave, file_exists( $target ) );
				return true;
			}
		);

		try {
			self::assertTrue( SocialImageDerivatives::ensure_now( $slug ) );
			self::assertSame(
				array(
					array( 'set', 'akb_og_fail_' . $slug, false ),
					array( 'delete', 'akb_og_fail_' . $slug, true ),
				),
				$eventos,
				'La marca va antes de que exista la JPG, y sólo la retira una conversión que terminó.'
			);
		} finally {
			@unlink( $dir . '/' . $slug . '.avif' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/** @testdox Una conversión que falla deja la caché negativa puesta y no se reintenta en la misma ventana. */
	public function test_a_failed_conversion_keeps_the_failure_cache(): void {
		if ( '' === SocialImageDerivatives::portadas_dir() ) {
			self::markTestSkipped( 'Sin directorio de portadas en este entorno.' );
		}

		$dir  = SocialImageDerivatives::portadas_dir();
		$slug = 'zz-rota-now-' . uniqid();
		file_put_contents( $dir . '/' . $slug . '.avif', 'no es un avif' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$marcas = array();
		Functions\when( 'set_transient' )->alias(
			static function ( string $clave ) use ( &$marcas ): bool {
				$marcas[ $clave ] = 1;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( string $clave ) use ( &$marcas ): bool {
				unset( $marcas[ $clave ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			static function ( string $clave ) use ( &$marcas ) {
				return $marcas[ $clave ] ?? false;
			}
		);

		try {
			self::assertFalse( SocialImageDerivatives::ensure_now( $slug ) );
			self::assertArrayHasKey( 'akb_og_fail_' . $slug, $marcas );

			self::assertFalse( SocialImageDerivatives::ensure_now( $slug ) );
			self::assertSame( 1, self::now_conversions(), 'Con la marca puesta, la segunda pregunta no vuelve a decodificar.' );
		} finally {
			@unlink( $dir . '/' . $slug . '.avif' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Revisión de 1.116.8: el resumen de «sigue tu serie» arma hasta 50
	 * destinatarios por pasada y cada portada sin hermana se convertía dentro
	 * del mismo worker, sin tope.
	 *
	 * @testdox Las conversiones en el acto tienen tope por petición; las que sobran se encolan y la URL no se toca.
	 */
	public function test_now_conversions_are_capped_per_request(): void {
		if ( '' === SocialImageDerivatives::portadas_dir() ) {
			self::markTestSkipped( 'Sin directorio de portadas en este entorno.' );
		}

		$tope = (int) ( new ReflectionClassConstant( SocialImageDerivatives::class, 'MAX_NOW_PER_REQUEST' ) )->getValue();
		self::assertGreaterThan( 0, $tope );
		self::assertLessThanOrEqual( 50, $tope, 'Un tope por encima de la tanda del resumen no acota nada.' );

		$dir     = SocialImageDerivatives::portadas_dir();
		$prefijo = 'zz-tope-' . uniqid() . '-';
		$slugs   = array();
		for ( $i = 0; $i < $tope + 5; $i++ ) {
			$slug    = $prefijo . $i;
			$slugs[] = $slug;
			file_put_contents( $dir . '/' . $slug . '.avif', 'no es un avif' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		try {
			$urls = array();
			foreach ( $slugs as $slug ) {
				$urls[] = SocialImageDerivatives::email_url( 'https://akibara.test/wp-content/uploads/portadas/' . $slug . '-300x450.avif' );
			}

			self::assertSame( $tope, self::now_conversions() );
			self::assertSame(
				array_slice( $slugs, $tope ),
				array_map(
					static fn( array $e ): string => (string) $e['args'][0],
					array_values( array_filter( $this->encolados, static fn( array $e ): bool => SocialImageDerivatives::HOOK_SLUG === $e['hook'] ) )
				),
				'Pasado el tope, cada portada queda encolada para el cron.'
			);
			foreach ( $slugs as $i => $slug ) {
				self::assertSame( 'https://akibara.test/wp-content/uploads/portadas/' . $slug . '-300x450.avif', $urls[ $i ], 'Sin JPG, la URL sale como vino.' );
			}
		} finally {
			foreach ( $slugs as $slug ) {
				@unlink( $dir . '/' . $slug . '.avif' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/**
	 * Revisión de 1.116.8: si el proceso muere entre `imagejpeg()` y `rename()`,
	 * el `*.tmp-PID` quedaba para siempre porque el barrido sólo mira `.avif`.
	 *
	 * @testdox El barrido retira los temporales huérfanos viejos y respeta los recientes.
	 */
	public function test_the_sweep_removes_orphan_temporaries(): void {
		if ( ! function_exists( 'imagecreatefromavif' ) || '' === SocialImageDerivatives::portadas_dir() ) {
			self::markTestSkipped( 'Sin AVIF en GD o sin directorio de portadas.' );
		}

		$ttl     = (int) ( new ReflectionClassConstant( SocialImageDerivatives::class, 'LOCK_TTL' ) )->getValue();
		$dir     = SocialImageDerivatives::portadas_dir();
		$viejo   = $dir . '/zz-huerfano-1' . SocialImageDerivatives::SUFFIX . '.tmp-4242';
		$reciente = $dir . '/zz-huerfano-2' . SocialImageDerivatives::SUFFIX . '.tmp-4343';
		$ajeno   = $dir . '/zz-huerfano-3.txt';
		foreach ( array( $viejo, $reciente, $ajeno ) as $fichero ) {
			file_put_contents( $fichero, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		touch( $viejo, time() - $ttl - 60 );
		touch( $ajeno, time() - $ttl - 60 );

		try {
			( new SocialImageDerivatives() )->generate_missing();

			self::assertFileDoesNotExist( $viejo, 'Un temporal de hace más de LOCK_TTL ya no lo renombra nadie.' );
			self::assertFileExists( $reciente, 'Uno reciente puede ser de una conversión en curso en otro proceso.' );
			self::assertFileExists( $ajeno, 'Sólo se tocan los temporales propios.' );
		} finally {
			foreach ( array( $viejo, $reciente, $ajeno ) as $fichero ) {
				@unlink( $fichero ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	private static function now_conversions(): int {
		$presupuesto = new ReflectionProperty( SocialImageDerivatives::class, 'now_conversions' );
		$presupuesto->setAccessible( true );

		return (int) $presupuesto->getValue();
	}

	/** La desactivación y la desinstalación retiran todos los eventos de portadas y colecciones. */
	public function test_lifecycle_clears_social_and_collection_events(): void {
		$raiz = dirname( __DIR__, 3 );
		foreach ( array( 'akibara-business.php', 'uninstall.php' ) as $fichero ) {
			$codigo = '';
			foreach ( token_get_all( (string) file_get_contents( $raiz . '/' . $fichero ) ) as $token ) {
				if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				$codigo .= is_array( $token ) ? $token[1] : $token;
			}

			// Ni los eventos de WP-Cron ni la cola de Action Scheduler se nombran ya
			// en cada superficie: las dos retiran la lista única (S-0e169ea5,
			// 2026-09-19; esta prueba fijaba las dos copias a mano de la cola, que
			// es como siguieron divergiendo). Que esa lista traiga los tres de
			// portadas y el de la colección se comprueba más abajo, contra la
			// lista misma.
			$llamada = '\Akibara\Business\Utilities\OwnCrons::unschedule_all();';
			self::assertStringContainsString( $llamada, $codigo, "{$fichero} debe llamar {$llamada}" );
		}

		foreach (
			array(
				SocialImageDerivatives::HOOK,
				SocialImageDerivatives::HOOK_CONTINUE,
				SocialImageDerivatives::HOOK_SLUG,
				'akibara_business_cleanup_collection_owner_fence',
			) as $hook
		) {
			self::assertContains( $hook, \Akibara\Business\Utilities\OwnCrons::hooks(), "La lista única no retira «{$hook}»." );
		}

		// La vía NORMAL de la limpieza de la colección es Action Scheduler; WP-Cron
		// es sólo su respaldo (revisiones de 1.116.8 y 1.116.9).
		self::assertContains(
			'akibara_business_cleanup_collection_owner_fence',
			\Akibara\Business\Utilities\OwnCrons::QUEUE['akibara-business'] ?? array(),
			'La cola única no retira la limpieza de la colección.'
		);
	}

	/**
	 * Hallazgo S-f0782d7e: los correos «ya llegó» y «sigue esta serie»
	 * incrustaban la portada en AVIF, que Gmail no pinta.
	 *
	 * @testdox En un correo, una portada AVIF sale como su hermana JPG; lo demás no se toca.
	 */
	public function test_email_url_swaps_a_cover_for_its_jpeg_sibling(): void {
		if ( '' === SocialImageDerivatives::portadas_dir() ) {
			self::markTestSkipped( 'Sin directorio de portadas en este entorno.' );
		}

		$dir = SocialImageDerivatives::portadas_dir();
		file_put_contents( $dir . '/zz-correo-1.avif', 'avif' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . '/zz-correo-1-og.jpg', 'jpg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		touch( $dir . '/zz-correo-1-og.jpg', time() + 10 );

		try {
			self::assertSame(
				'https://akibara.test/wp-content/uploads/portadas/zz-correo-1-og.jpg',
				SocialImageDerivatives::email_url( 'https://akibara.test/wp-content/uploads/portadas/zz-correo-1-350x525.avif' )
			);
			self::assertSame(
				'https://akibara.test/wp-content/uploads/2026/09/banner.avif',
				SocialImageDerivatives::email_url( 'https://akibara.test/wp-content/uploads/2026/09/banner.avif' ),
				'Fuera de /portadas/ no hay hermana JPG.'
			);
			self::assertSame(
				'https://akibara.test/wp-content/uploads/portadas/zz-no-existe-2.avif',
				SocialImageDerivatives::email_url( 'https://akibara.test/wp-content/uploads/portadas/zz-no-existe-2.avif' ),
				'Sin JPG posible, la URL no se toca: un enlace roto es peor que un AVIF.'
			);
		} finally {
			@unlink( $dir . '/zz-correo-1.avif' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $dir . '/zz-correo-1-og.jpg' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/** Los dos correos propios con portada la piden en formato de correo. */
	public function test_both_cover_emails_use_the_email_url(): void {
		$raiz = dirname( __DIR__, 3 );
		foreach ( array( 'src/Modules/BackInStock/InstockEmail.php', 'src/Modules/SeriesFollow/DigestRunner.php' ) as $fichero ) {
			$codigo = '';
			foreach ( token_get_all( (string) file_get_contents( $raiz . '/' . $fichero ) ) as $token ) {
				if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				$codigo .= is_array( $token ) ? $token[1] : $token;
			}
			self::assertStringContainsString( 'SocialImageDerivatives::email_url(', $codigo, "{$fichero} debe mandar la portada en JPG." );
		}
	}

	/** La continuación corre la misma corrida que el evento diario. */
	public function test_the_continuation_hook_runs_the_sweep(): void {
		$enganches = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$enganches ): bool {
				$enganches[ $hook ] = $callback;
				return true;
			}
		);
		Functions\when( 'add_filter' )->justReturn( true );
		$this->encolados[] = array(
			'hook' => SocialImageDerivatives::HOOK,
			'args' => array(),
		);

		$modulo = new SocialImageDerivatives();
		$modulo->register();

		self::assertSame( array( $modulo, 'generate_missing' ), $enganches[ SocialImageDerivatives::HOOK_CONTINUE ] ?? null );
	}

	public function test_a_finished_sweep_clears_the_resume_cursor(): void {
		if ( '' === SocialImageDerivatives::portadas_dir() ) {
			self::markTestSkipped( 'Sin directorio de portadas en este entorno.' );
		}

		$modulo                                         = new SocialImageDerivatives();
		$this->options['akb_social_derivatives_cursor'] = 'zzzz.avif';

		$modulo->generate_missing();

		self::assertArrayNotHasKey(
			'akb_social_derivatives_cursor',
			$this->options,
			'Al llegar al final el cursor se borra: la corrida siguiente barre entero.'
		);
	}

	/**
	 * El front NO transcodifica: encola.
	 */
	public function test_the_front_enqueues_instead_of_transcoding(): void {
		$dir = SocialImageDerivatives::portadas_dir();
		if ( '' === $dir ) {
			self::markTestSkipped( 'Sin directorio de portadas en este entorno.' );
		}

		$slug   = 'akb-test-' . uniqid();
		$source = $dir . '/' . $slug . '.avif';
		file_put_contents( $source, 'no-es-un-avif-de-verdad' );

		try {
			$usable = SocialImageDerivatives::ensure_for_slug( $slug );

			self::assertFalse( $usable, 'Sin hermana JPG todavía, el og:image se queda como estaba.' );
			self::assertFileDoesNotExist(
				$dir . '/' . $slug . SocialImageDerivatives::SUFFIX,
				'La conversión no puede ocurrir en el camino de render.'
			);
			self::assertSame(
				array(
					array(
						'hook' => SocialImageDerivatives::HOOK_SLUG,
						'args' => array( $slug ),
					),
				),
				$this->encolados,
				'La portada que falta se encola una vez, para el cron.'
			);

			// Y no se encola dos veces mientras el trabajo siga pendiente.
			SocialImageDerivatives::ensure_for_slug( $slug );
			self::assertCount( 1, $this->encolados );
		} finally {
			unlink( $source );
		}
	}

	public function test_an_existing_sibling_is_used_without_enqueueing_anything(): void {
		$dir = SocialImageDerivatives::portadas_dir();
		if ( '' === $dir ) {
			self::markTestSkipped( 'Sin directorio de portadas en este entorno.' );
		}

		$slug   = 'akb-test-' . uniqid();
		$target = $dir . '/' . $slug . SocialImageDerivatives::SUFFIX;
		file_put_contents( $target, 'jpg' );

		try {
			self::assertTrue( SocialImageDerivatives::ensure_for_slug( $slug ) );
			self::assertSame( array(), $this->encolados );
		} finally {
			unlink( $target );
		}
	}

	public function test_a_slug_that_already_failed_is_not_enqueued_again(): void {
		$dir = SocialImageDerivatives::portadas_dir();
		if ( '' === $dir ) {
			self::markTestSkipped( 'Sin directorio de portadas en este entorno.' );
		}

		Functions\when( 'get_transient' )->justReturn( 1 );

		$slug   = 'akb-test-' . uniqid();
		$source = $dir . '/' . $slug . '.avif';
		file_put_contents( $source, 'avif-corrupto' );

		try {
			self::assertFalse( SocialImageDerivatives::ensure_for_slug( $slug ) );
			self::assertSame(
				array(),
				$this->encolados,
				'La caché negativa evita reencolar un AVIF que ya se sabe roto.'
			);
		} finally {
			unlink( $source );
		}
	}

	/**
	 * Revisión independiente de 1.116.9 (MEDIA): el presupuesto era por proceso
	 * y un runner de Action Scheduler procesa su lote entero en el mismo PHP:
	 * agotado en las primeras portadas, el resto de los correos salía sin ella.
	 *
	 * REAPERTURA S-a26eb252 (2026-09-19): el cierre anterior enganchó
	 * `action_scheduler_before_execute_action`, un hook que Action Scheduler NO
	 * dispara nunca (el real es `action_scheduler_before_execute`), y esta
	 * misma prueba afirmaba la cadena equivocada — pasaba en verde con el
	 * defecto intacto porque era un espejo del error, no un candado. Ahora
	 * exige el nombre correcto Y, aparte, `test_the_registered_hook_exists_in_the_real_action_scheduler_library()`
	 * lo valida contra el código fuente de la librería instalada para que un
	 * futuro invento de nombre no vuelva a colarse sin que nadie lo note.
	 *
	 * @testdox El presupuesto de conversiones se renueva al empezar cada acción de la cola.
	 */
	public function test_the_conversion_budget_renews_per_queue_action(): void {
		$ganchos = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$ganchos ): bool {
				$ganchos[ $hook ][] = $callback;
				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( time() );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		( new SocialImageDerivatives() )->register();
		self::assertContains( array( SocialImageDerivatives::class, 'reset_now_budget' ), $ganchos['action_scheduler_before_execute'] ?? array() );

		$presupuesto = new ReflectionProperty( SocialImageDerivatives::class, 'now_conversions' );
		$presupuesto->setAccessible( true );
		$presupuesto->setValue( null, 20 );

		SocialImageDerivatives::reset_now_budget();

		self::assertSame( 0, self::now_conversions() );
	}

	/**
	 * Candado de contrato (S-a26eb252, 2026-09-19): el nombre del hook que
	 * `register()` engancha tiene que existir de verdad en la librería Action
	 * Scheduler instalada, no sólo coincidir con lo que la prueba de arriba
	 * espera a mano — eso es exactamente lo que dejó pasar el defecto
	 * original (la prueba y el código compartían la MISMA cadena inventada).
	 * Se leen las dos copias que vive el árbol (el paquete de woocommerce y la
	 * vendorizada de seo-by-rank-math) porque cuál de las dos gana el
	 * registro depende de qué plugin cargue primero.
	 *
	 * @testdox El hook registrado existe de verdad en la librería Action Scheduler instalada.
	 */
	public function test_the_registered_hook_exists_in_the_real_action_scheduler_library(): void {
		$ganchos = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$ganchos ): bool {
				$ganchos[] = $hook;
				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( time() );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		( new SocialImageDerivatives() )->register();

		$registrado = null;
		foreach ( $ganchos as $hook ) {
			if ( str_starts_with( $hook, 'action_scheduler_before_execute' ) ) {
				$registrado = $hook;
				break;
			}
		}
		self::assertNotNull( $registrado, 'register() no enganchó ningún hook "antes de ejecutar" de Action Scheduler.' );

		// __DIR__ = .../plugins/akibara-business/tests/Modules/SeoSocial; subir 4 llega a .../plugins.
		$librerias = array(
			dirname( __DIR__, 4 ) . '/woocommerce/packages/action-scheduler/classes/abstracts/ActionScheduler_Abstract_QueueRunner.php',
			dirname( __DIR__, 4 ) . '/seo-by-rank-math/vendor/woocommerce/action-scheduler/classes/abstracts/ActionScheduler_Abstract_QueueRunner.php',
		);

		$encontrada = false;
		foreach ( $librerias as $lib ) {
			if ( is_file( $lib ) && false !== strpos( (string) file_get_contents( $lib ), "do_action( '" . $registrado . "'" ) ) {
				$encontrada = true;
				break;
			}
		}

		if ( ! is_file( $librerias[0] ) && ! is_file( $librerias[1] ) ) {
			self::markTestSkipped( 'Ninguna copia de Action Scheduler está instalada en este entorno.' );
		}

		self::assertTrue(
			$encontrada,
			"Ninguna librería instalada dispara '{$registrado}'. Action Scheduler emite 'action_scheduler_before_execute', no un nombre inventado."
		);
	}

	/**
	 * `add_option()` como candado pisaba el de otra corrida con la caché desfasada, y
	 * el `delete_option()` del final borraba el candado aunque ya fuera de otra
	 * (revisión independiente, 2026-09-15).
	 *
	 * @testdox El candado se toma y se suelta por SQL atómico, y sólo se suelta el propio.
	 */
	public function test_the_lock_is_atomic_and_only_its_own_is_released(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/SeoSocial/SocialImageDerivatives.php' );
		$fuente = (string) preg_replace( '#/\*[\s\S]*?\*/|//[^\n]*#', '', $fuente );

		foreach ( array( 'add_option', 'update_option', 'delete_option', 'get_option' ) as $funcion ) {
			self::assertDoesNotMatchRegularExpression( '/' . $funcion . '\(\s*self::LOCK_OPTION/', $fuente, $funcion . '() sobre el candado.' );
		}
		self::assertStringContainsString( 'OptionRow::delete_if_equals( self::LOCK_OPTION, $this->candado )', $fuente );
	}

	/**
	 * La corrida que perdió el candado a mitad (se lo robó otra tras `LOCK_TTL`) no
	 * puede borrarlo al terminar: en 1.116.10 el `delete_option()` del `finally` era
	 * incondicional y dejaba entrar a una tercera.
	 *
	 * @testdox Una corrida que perdió su candado no borra el de la que se lo quitó.
	 */
	public function test_a_finishing_run_does_not_release_someone_elses_lock(): void {
		$modulo  = new SocialImageDerivatives();
		$tomar   = new \ReflectionMethod( SocialImageDerivatives::class, 'claim_lock' );
		$soltar  = new \ReflectionMethod( SocialImageDerivatives::class, 'release_lock' );
		$tomar->setAccessible( true );
		$soltar->setAccessible( true );

		self::assertTrue( $tomar->invoke( $modulo ) );
		$ajeno                                        = time() . '|otra-corrida';
		$this->options['akb_social_derivatives_lock'] = $ajeno;

		$soltar->invoke( $modulo );

		self::assertSame( $ajeno, $this->options['akb_social_derivatives_lock'], 'El candado de la otra corrida sigue en pie.' );
	}

	/**
	 * @testdox Un candado vencido en el formato viejo se roba por CAS y la corrida lo suelta al terminar.
	 */
	public function test_a_stale_old_format_lock_is_taken_over(): void {
		$modulo = new SocialImageDerivatives();
		$tomar  = new \ReflectionMethod( SocialImageDerivatives::class, 'claim_lock' );
		$soltar = new \ReflectionMethod( SocialImageDerivatives::class, 'release_lock' );
		$tomar->setAccessible( true );
		$soltar->setAccessible( true );

		$this->options['akb_social_derivatives_lock'] = (string) ( time() - 3600 );

		self::assertTrue( $tomar->invoke( $modulo ) );
		self::assertMatchesRegularExpression( '/^\d+\|[0-9a-f]{16}$/', (string) $this->options['akb_social_derivatives_lock'], 'Queda con el token de esta corrida.' );

		$soltar->invoke( $modulo );
		self::assertArrayNotHasKey( 'akb_social_derivatives_lock', $this->options );
	}
}
