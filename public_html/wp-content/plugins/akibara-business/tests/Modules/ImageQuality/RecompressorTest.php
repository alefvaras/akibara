<?php
/**
 * La recompresión nunca deja una portada más pesada, nunca toca el original, no
 * se queda muerta si PHP muere, trabaja de a poco y no vacía el borde en hora punta.
 *
 * Se prueba con archivos reales en un directorio temporal y un codificador falso
 * que escribe los bytes que la prueba decide: lo que se mide es la decisión, no
 * ImageMagick (su calidad real se midió en producción el 2026-09-14).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\ImageQuality;

use Akibara\Business\Modules\ImageQuality\Recompressor;
use Akibara\Business\Tests\Support\OptionsRowDouble;
use Akibara\Business\Tests\Support\OptionsRowWpdb;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class RecompressorTest extends TestCase {

	use OptionsRowDouble;

	private string $dir = '';

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, array<string, mixed>> */
	private array $post_meta = array();

	/** @var array<int, array<string, mixed>> */
	private array $metas = array();

	/** @var array<int, string> */
	private array $archivos = array();

	/** @var array<int, array{hook:string, ts:int, group:string, status:string}> */
	private array $acciones = array();

	/** @var array<int, array<int, mixed>> */
	private array $llamadas = array();

	/** Bytes que escribe el codificador falso, por ancho de caja (-1 = error, -2 = excepción). @var array<int, int> */
	private array $bytes = array();

	/** Medidas que devuelve el codificador falso, por ancho de caja. @var array<int, array{0:int,1:int}> */
	private array $medidas = array();

	/** @var array<string, array<string, mixed>> */
	private array $registrados = array();

	/** @var array{0:int,1:int}|false */
	private $medidas_fuente = array( 800, 1174 );

	private float $ahora = 0.0;

	/** Callback que corre el codificador falso tras escribir (para mover el reloj). @var callable|null */
	private $tras_codificar = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->dir = sys_get_temp_dir() . '/akb-recomprimir-' . uniqid();
		mkdir( $this->dir, 0777, true );

		Functions\when( 'get_option' )->alias( fn( string $n, $d = false ) => $this->options[ $n ] ?? $d );
		Functions\when( 'add_option' )->alias(
			function ( string $n, $v ): bool {
				if ( array_key_exists( $n, $this->options ) ) {
					return false;
				}
				$this->options[ $n ] = $v;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $n, $v ): bool {
				$this->options[ $n ] = $v;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $n ): bool {
				unset( $this->options[ $n ] );
				return true;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( int $id, string $k ) {
				if ( '_wp_attachment_metadata' === $k ) {
					return $this->metas[ $id ] ?? '';
				}
				return $this->post_meta[ $id ][ $k ] ?? '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( int $id, string $k, $v ): bool {
				if ( '_wp_attachment_metadata' === $k ) {
					$this->metas[ $id ] = $v;
					return true;
				}
				$this->post_meta[ $id ][ $k ] = $v;
				return true;
			}
		);
		Functions\when( 'wp_get_attachment_metadata' )->alias( fn( int $id ) => $this->metas[ $id ] ?? false );
		Functions\when( 'get_attached_file' )->alias( fn( int $id ) => $this->archivos[ $id ] ?? '' );
		Functions\when( 'wp_get_original_image_path' )->alias( fn( int $id ) => $this->archivos[ $id ] ?? '' );
		Functions\when( 'wp_getimagesize' )->alias( fn() => $this->medidas_fuente );
		Functions\when( 'wp_get_registered_image_subsizes' )->alias( fn() => $this->registrados );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'America/Santiago' ) );
		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $args ): array {
				$ids = array();
				foreach ( $this->acciones as $i => $a ) {
					if ( $a['hook'] === ( $args['hook'] ?? $a['hook'] ) && $a['status'] === ( $args['status'] ?? $a['status'] ) ) {
						$ids[] = $i;
					}
				}
				return $ids;
			}
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $ts, string $hook, array $args = array(), string $group = '', bool $unique = false, int $priority = 10 ): int {
				$this->acciones[] = array( 'hook' => $hook, 'ts' => $ts, 'group' => $group, 'status' => 'pending', 'priority' => $priority );
				return count( $this->acciones );
			}
		);
		// Como Action Scheduler 4.0.0: la marca de tiempo de la pendiente, `true` si hay
		// una EN CURSO (que por eso no sirve para decidir «no hay ninguna») y false si no.
		Functions\when( 'as_next_scheduled_action' )->alias(
			function ( string $hook ) {
				foreach ( $this->acciones as $a ) {
					if ( $a['hook'] === $hook && 'in-progress' === $a['status'] ) {
						return true;
					}
				}
				foreach ( $this->acciones as $a ) {
					if ( $a['hook'] === $hook && 'pending' === $a['status'] ) {
						return (int) $a['ts'];
					}
				}
				return false;
			}
		);
		Functions\when( 'as_unschedule_all_actions' )->alias(
			function ( string $hook = '', array $args = array(), string $group = '' ): void {
				foreach ( $this->acciones as $i => $a ) {
					if ( 'pending' === $a['status'] && ( '' === $hook || $a['hook'] === $hook ) ) {
						unset( $this->acciones[ $i ] );
					}
				}
			}
		);


		// La fila de options la modela el doble compartido (misma semántica de MariaDB
		// en todas las pruebas); éste añade sólo las dos consultas de portadas.
		$this->instalar_doble_de_options(
			$this->options,
			new class() extends OptionsRowWpdb {
				/** @var array<int, int> */
				public array $ids = array();
				/** Consultas de portadas (las del candado no se cuentan). @var array<int, string> */
				public array $portadas = array();
				public function get_col( string $sql ): array {
					$this->portadas[] = $sql;
					preg_match( '/p\.ID > (\d+) ORDER BY p\.ID ASC LIMIT (\d+)/', $sql, $m );
					$ids = array_values( array_filter( $this->ids, static fn( int $id ): bool => $id > (int) $m[1] ) );
					sort( $ids );
					return array_slice( $ids, 0, (int) $m[2] );
				}
				protected function get_var_propio( string $sql ) {
					$this->portadas[] = $sql;
					return count( $this->ids );
				}
			}
		);
	}

	protected function tearDown(): void {
		$this->retirar_doble_de_options();
		foreach ( array( '*', '.*' ) as $patron ) {
			foreach ( (array) glob( $this->dir . '/' . $patron ) as $f ) {
				if ( is_file( $f ) ) {
					unlink( $f );
				}
			}
		}
		rmdir( $this->dir );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Una portada 800×1174 con tamaños AVIF de los bytes pedidos.
	 *
	 * @param int                                     $id      Adjunto.
	 * @param array<string, array{0:int,1:int,2:int}> $tamanos nombre => [ancho, alto, bytes].
	 */
	private function portada( int $id, array $tamanos ): void {
		$original = $this->dir . "/tomo-{$id}.avif";
		file_put_contents( $original, str_repeat( 'o', 5000 ) );
		$this->archivos[ $id ] = $original;
		$meta                  = array(
			'width'  => 800,
			'height' => 1174,
			'file'   => "portadas/tomo-{$id}.avif",
			'sizes'  => array(),
		);
		foreach ( $tamanos as $nombre => [ $w, $h, $b ] ) {
			$archivo = "tomo-{$id}-{$w}x{$h}.avif";
			if ( ! is_file( $this->dir . '/' . $archivo ) ) {
				file_put_contents( $this->dir . '/' . $archivo, str_repeat( 'a', $b ) );
			}
			$meta['sizes'][ $nombre ] = array(
				'file'      => $archivo,
				'width'     => $w,
				'height'    => $h,
				'mime-type' => 'image/avif',
				'filesize'  => $b,
			);
			$this->medidas[ $w ] = $this->medidas[ $w ] ?? array( $w, $h );
		}
		$this->metas[ $id ]     = $meta;
		$GLOBALS['wpdb']->ids[] = $id;
	}

	private function recompressor(): Recompressor {
		$encode = function ( string $fuente, int $bw, int $bh, $crop, int $q, string $destino ) {
			$this->llamadas[] = array( $fuente, $bw, $bh, $crop, $q, $destino );
			$b                = $this->bytes[ $bw ] ?? 100;
			if ( -1 === $b ) {
				return new \WP_Error( 'encode', 'sin memoria' );
			}
			if ( -2 === $b ) {
				throw new \RuntimeException( 'PHP murió a mitad de la portada' );
			}
			if ( -3 === $b ) {
				return new \WP_Error( 'error_getting_dimensions', 'La caja no cabe en la fuente' );
			}
			file_put_contents( $destino, str_repeat( 'n', $b ) );
			if ( null !== $this->tras_codificar ) {
				( $this->tras_codificar )();
			}
			[ $w, $h ] = $this->medidas[ $bw ] ?? array( $bw, $bh );
			return array( 'width' => $w, 'height' => $h, 'path' => $destino, 'mime' => 'image/avif' );
		};

		return new Recompressor( $encode, fn(): float => $this->ahora );
	}

	/** @return array<string, int|string> */
	private function empezado( int $calidad = 60 ): array {
		return array_merge( Recompressor::state(), array( 'quality' => $calidad, 'started' => 1000 ) );
	}

	/** @return array<int, string> */
	private function temporales(): array {
		return (array) glob( $this->dir . '/' . Recompressor::TMP_PREFIX . '*' );
	}

	/** @return array<int, array<string, mixed>> */
	private function pendientes( string $hook ): array {
		return array_values( array_filter( $this->acciones, static fn( array $a ): bool => $a['hook'] === $hook && 'pending' === $a['status'] ) );
	}

	/**
	 * @testdox Un tamaño que baja al 90 % o menos se reemplaza y su peso nuevo se anota en crudo.
	 */
	public function test_lighter_file_replaces_the_derivative(): void {
		$this->portada( 7, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->bytes[450] = 900;
		$estado           = $this->empezado();
		Functions\expect( 'wp_update_attachment_metadata' )->never();

		$this->recompressor()->process_attachment( 7, 60, $estado );

		self::assertSame( 900, filesize( $this->dir . '/tomo-7-450x660.avif' ) );
		self::assertSame( 900, $this->metas[7]['sizes']['akb-card-2x']['filesize'] );
		self::assertSame( 1, $estado['reemplazados'] );
		self::assertSame( 1000, $estado['bytes_antes'] );
		self::assertSame( 900, $estado['bytes_despues'] );
		self::assertSame( 60, $this->post_meta[7][ Recompressor::META_DONE ] );
		self::assertSame( array(), $this->temporales() );
		self::assertSame( 5000, filesize( $this->dir . '/tomo-7.avif' ), 'El original nunca se toca.' );
	}

	/**
	 * Las portadas antiguas ya van más comprimidas que un 60: rehacerlas las
	 * haría más pesadas (medido en prod: 71 KB contra ~95 KB).
	 *
	 * @testdox Un archivo nuevo que no baja del 90 % se descarta y el existente queda intacto.
	 */
	public function test_not_lighter_enough_keeps_the_derivative(): void {
		$this->portada( 8, array( 'large' => array( 698, 1024, 1000 ) ) );
		$this->registrados['large'] = array( 'width' => 1024, 'height' => 1024, 'crop' => false );
		$this->medidas[1024]        = array( 698, 1024 );
		$this->bytes[1024]          = 901;
		$estado                     = $this->empezado();

		$this->recompressor()->process_attachment( 8, 60, $estado );

		self::assertSame( 1000, filesize( $this->dir . '/tomo-8-698x1024.avif' ) );
		self::assertSame( 0, $estado['reemplazados'] );
		self::assertSame( 1, $estado['conservados'] );
		self::assertSame( array(), $this->temporales() );
	}

	/**
	 * @testdox Si el resultado no mide lo mismo, no reemplaza aunque pese menos.
	 */
	public function test_different_dimensions_never_replace(): void {
		$this->portada( 9, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->bytes[450]   = 100;
		$this->medidas[450] = array( 449, 660 );
		$estado             = $this->empezado();

		$this->recompressor()->process_attachment( 9, 60, $estado );

		self::assertSame( 1000, filesize( $this->dir . '/tomo-9-450x660.avif' ) );
		self::assertSame( 1, $estado['conservados'] );
		self::assertSame( array(), $this->temporales() );
	}

	/**
	 * @testdox Un fallo pasajero del codificador deja la portada sin marca para reintentarla, y sin temporales.
	 */
	public function test_transient_encoder_failure_leaves_the_cover_for_the_next_pass(): void {
		$this->portada( 10, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->bytes[450] = -1;
		$estado           = $this->empezado();

		$this->recompressor()->process_attachment( 10, 60, $estado );

		self::assertSame( 1, $estado['errores'] );
		self::assertStringContainsString( 'sin memoria', (string) $estado['ultimo_error'] );
		self::assertArrayNotHasKey( Recompressor::META_DONE, $this->post_meta[10] ?? array() );
		self::assertSame( 1000, filesize( $this->dir . '/tomo-10-450x660.avif' ) );
		self::assertSame( array(), $this->temporales() );
	}

	/**
	 * Core devuelve `error_getting_dimensions` cuando la caja no cabe en la fuente:
	 * no cambia nunca, así que reintentarlo en cada pasada sería prometer en falso.
	 *
	 * @testdox Una caja que no cabe en la fuente se conserva y la portada queda marcada.
	 */
	public function test_box_that_does_not_fit_is_kept_and_marked(): void {
		$this->portada( 11, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->bytes[450] = -3;
		$estado           = $this->empezado();

		$this->recompressor()->process_attachment( 11, 60, $estado );

		self::assertSame( 0, $estado['errores'] );
		self::assertSame( 1, $estado['conservados'] );
		self::assertSame( 60, $this->post_meta[11][ Recompressor::META_DONE ] );
	}

	/**
	 * Si algún día un filtro mapea AVIF a otro formato, el editor escribiría otro
	 * archivo: nunca se renombra algo que no sea el AVIF del temporal.
	 *
	 * @testdox Un resultado fuera del temporal o que no es AVIF no reemplaza nada.
	 */
	public function test_result_that_is_not_the_avif_temporary_is_never_renamed(): void {
		$this->portada( 12, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$estado = $this->empezado();
		$encode = function ( string $f, int $bw, int $bh, $c, int $q, string $destino ) {
			$otro = str_replace( '.avif', '.jpg', $destino );
			file_put_contents( $otro, 'j' );
			return array( 'width' => 450, 'height' => 660, 'path' => $otro, 'mime' => 'image/jpeg' );
		};

		( new Recompressor( $encode, fn(): float => 0.0 ) )->process_attachment( 12, 60, $estado );

		self::assertSame( 1000, filesize( $this->dir . '/tomo-12-450x660.avif' ) );
		self::assertSame( 1, $estado['errores'] );
		self::assertSame( array(), $this->temporales() );
	}

	/**
	 * @testdox Sólo se recodifican tamaños AVIF que existen en disco.
	 */
	public function test_only_existing_avif_sizes_are_encoded(): void {
		$this->portada( 13, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->metas[13]['sizes']['og']      = array( 'file' => 'tomo-13-og.jpg', 'width' => 1200, 'height' => 630, 'mime-type' => 'image/jpeg' );
		$this->metas[13]['sizes']['perdido'] = array( 'file' => 'tomo-13-300x440.avif', 'width' => 300, 'height' => 440, 'mime-type' => 'image/avif' );
		$estado                              = $this->empezado();

		$this->recompressor()->process_attachment( 13, 60, $estado );

		self::assertCount( 1, $this->llamadas );
		self::assertSame( 450, $this->llamadas[0][1] );
	}

	/**
	 * @testdox Una portada ya revisada con esta calidad se salta; con otra calidad se revisa.
	 */
	public function test_marker_skips_only_the_same_quality(): void {
		$this->portada( 14, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->post_meta[14][ Recompressor::META_DONE ] = 60;
		$estado                                          = $this->empezado();

		$this->recompressor()->process_attachment( 14, 60, $estado );
		self::assertCount( 0, $this->llamadas );

		$this->recompressor()->process_attachment( 14, 50, $estado );
		self::assertCount( 1, $this->llamadas );
	}

	/**
	 * @testdox Los tamaños registrados usan su caja y su recorte; los que ya no existen, su ancho.
	 */
	public function test_boxes_come_from_the_registered_sizes(): void {
		$this->portada( 15, array( 'thumbnail' => array( 150, 150, 1000 ), 'portada_500' => array( 500, 734, 1000 ) ) );
		$this->registrados['thumbnail'] = array( 'width' => 150, 'height' => 150, 'crop' => true );
		$estado                         = $this->empezado();

		$this->recompressor()->process_attachment( 15, 60, $estado );

		self::assertSame( array( 150, 150, true ), array_slice( $this->llamadas[0], 1, 3 ) );
		self::assertSame( array( 500, 0, false ), array_slice( $this->llamadas[1], 1, 3 ) );
	}

	/**
	 * @testdox Un archivo que figura con dos nombres de tamaño se recodifica una vez y anota el peso en los dos.
	 */
	public function test_a_file_shared_by_two_sizes_is_encoded_once(): void {
		$this->portada( 16, array( 'medium_large' => array( 768, 1127, 1000 ), 'woocommerce_single' => array( 768, 1127, 1000 ) ) );
		$this->bytes[768] = 700;
		$estado           = $this->empezado();

		$this->recompressor()->process_attachment( 16, 60, $estado );

		self::assertCount( 1, $this->llamadas );
		self::assertSame( 1, $estado['reemplazados'] );
		self::assertSame( 700, $this->metas[16]['sizes']['medium_large']['filesize'] );
		self::assertSame( 700, $this->metas[16]['sizes']['woocommerce_single']['filesize'] );
	}

	/**
	 * La fuente enorme es permanente (se marca); que falte el original puede ser un
	 * disco o un permiso pasajero, así que esa NO se marca y la próxima pasada la
	 * reintenta (revisión independiente, 2026-09-14).
	 *
	 * @testdox Una fuente enorme se deja como está y queda marcada; una ausente se deja sin marca.
	 */
	public function test_huge_or_missing_source_is_left_as_is(): void {
		$this->portada( 17, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->medidas_fuente = array( 5596, 8394 );
		$estado               = $this->empezado();

		$this->recompressor()->process_attachment( 17, 60, $estado );
		self::assertCount( 0, $this->llamadas );
		self::assertSame( 1, $estado['omitidas'] );
		self::assertStringContainsString( '5596 × 8394', (string) $estado['ultimo_error'] );
		self::assertSame( 60, $this->post_meta[17][ Recompressor::META_DONE ] );

		$this->portada( 18, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->medidas_fuente = array( 800, 1174 );
		unlink( $this->archivos[18] );
		$this->recompressor()->process_attachment( 18, 60, $estado );
		self::assertCount( 0, $this->llamadas );
		self::assertSame( 2, $estado['omitidas'] );
		self::assertArrayNotHasKey( Recompressor::META_DONE, $this->post_meta[18] ?? array() );
	}

	/**
	 * @testdox La fuente es la de core: el original, o la adjunta si no hay original legible.
	 */
	public function test_source_is_what_core_uses(): void {
		$original = $this->dir . '/serie-1.avif';
		file_put_contents( $original, 'x' );
		$this->archivos[61] = $original;
		self::assertSame( $original, Recompressor::source_for( 61 ) );

		unlink( $original );
		self::assertSame( '', Recompressor::source_for( 61 ) );
	}

	/**
	 * @testdox Una corrida usa la calidad de su pasada aunque el panel guarde otra mientras tanto.
	 */
	public function test_run_uses_the_pass_quality(): void {
		$this->portada( 21, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->options['akb_image_avif_quality']    = '82';
		$this->options[ Recompressor::OPTION_STATE ] = $this->empezado( 55 );

		$this->recompressor()->run();

		self::assertSame( 55, $this->llamadas[0][4] );
	}

	/**
	 * Si PHP muere (memoria, tiempo, libheif), `finally` no alcanza a agendar nada:
	 * por eso la corrida siguiente se agenda ANTES de trabajar.
	 *
	 * @testdox La corrida siguiente queda agendada antes de trabajar y la portada en curso queda anotada.
	 */
	public function test_next_run_is_scheduled_before_working(): void {
		$this->portada( 22, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->bytes[450]                            = -2;
		$this->options[ Recompressor::OPTION_STATE ] = $this->empezado();

		try {
			$this->recompressor()->run();
			self::fail( 'La excepción simula que PHP murió.' );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		self::assertCount( 1, $this->pendientes( Recompressor::HOOK ) );
		self::assertSame( 20, $this->pendientes( Recompressor::HOOK )[0]['priority'], 'Detrás del buscador y la preventa.' );
		self::assertSame( 22, Recompressor::state()['en_proceso'] );
	}

	/**
	 * Cuando PHP muere de verdad no hay `finally`: el candado se queda tomado y
	 * fresco. Hasta que vence, ninguna corrida entra (pero deja continuación).
	 *
	 * @testdox Tras un fatal, el candado fresco frena a las demás sin perder la cadena.
	 */
	public function test_a_fresh_lock_from_a_fatal_blocks_without_losing_the_chain(): void {
		$this->portada( 23, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->options[ Recompressor::OPTION_STATE ] = array_merge( $this->empezado(), array( 'en_proceso' => 23 ) );
		$this->options[ Recompressor::LOCK_OPTION ]  = (string) time();

		$this->recompressor()->run();

		self::assertCount( 0, $this->llamadas, 'Con el candado fresco no entra nadie.' );
		self::assertCount( 1, $this->pendientes( Recompressor::HOOK ), 'Pero la pasada sigue teniendo quién la mueva.' );
		self::assertSame( 23, Recompressor::state()['en_proceso'] );
	}

	/**
	 * Una corrida se corta también por cosas que no son culpa de la portada (tope de
	 * tiempo, reciclado del proceso, memoria de otra cuenta del hosting). Marcarla a
	 * la primera la dejaba fuera para siempre y sin manera de recuperarla
	 * (revisión independiente, 2026-09-15).
	 *
	 * @testdox La portada que quedó en vuelo se reintenta una vez; si vuelve a cortar, se marca y el cursor la pasa.
	 */
	public function test_an_interrupted_cover_gets_one_retry(): void {
		$this->portada( 23, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->portada( 24, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->options[ Recompressor::OPTION_STATE ] = array_merge( $this->empezado(), array( 'en_proceso' => 23 ) );
		$this->options[ Recompressor::LOCK_OPTION ]  = (string) ( time() - 901 );

		// Primer corte: se reintenta y sale bien; nada se marca ni se omite.
		$this->recompressor()->run();

		$estado = Recompressor::state();
		self::assertCount( 2, $this->llamadas, 'La 23 se reintenta y la 24 sigue.' );
		self::assertStringContainsString( 'tomo-23', (string) $this->llamadas[0][0] );
		self::assertSame( 0, $estado['omitidas'] );
		self::assertSame( 0, $estado['reintentada'], 'El reintento salió bien: la portada deja de estar señalada.' );
		self::assertSame( 60, $this->post_meta[23][ Recompressor::META_DONE ] );
		self::assertSame( 2, $estado['revisadas'] );

		// Segundo corte en la MISMA portada (la que de verdad tumba PHP): se marca.
		$this->portada( 33, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->llamadas                              = array();
		$this->options[ Recompressor::OPTION_STATE ] = array_merge(
			$this->empezado(),
			array( 'en_proceso' => 33, 'reintentada' => 33, 'cursor' => 24, 'revisadas' => 2, 'finished' => 0 )
		);
		$this->options[ Recompressor::LOCK_OPTION ] = (string) ( time() - 901 );

		$this->recompressor()->run();

		$estado = Recompressor::state();
		self::assertSame( 1, $estado['omitidas'] );
		self::assertSame( 0, $estado['errores'], 'No es un error de tamaño: es una portada dejada como estaba.' );
		self::assertStringContainsString( 'Portada 33', (string) $estado['ultimo_error'] );
		self::assertStringContainsString( 'otra calidad', (string) $estado['ultimo_error'], 'El aviso dice cómo reintentarla.' );
		self::assertSame( 60, $this->post_meta[33][ Recompressor::META_DONE ] );
		self::assertSame( 0, $estado['en_proceso'] );
		self::assertSame( 0, $estado['reintentada'] );
		self::assertCount( 0, $this->llamadas, 'No se vuelve a codificar.' );
		self::assertSame( 2, $estado['revisadas'], 'La omitida no se cuenta también como revisada.' );
	}

	/**
	 * Cortar a mitad de portada hacía que la corrida siguiente la empezara de cero:
	 * con una portada más lenta que el presupuesto, la pasada no terminaba NUNCA y
	 * gastaba CPU cada minuto (revisión independiente, 2026-09-14).
	 *
	 * @testdox Una portada más lenta que el presupuesto se termina entera y la pasada avanza.
	 */
	public function test_a_cover_slower_than_the_budget_still_finishes(): void {
		$this->portada( 25, array( 'woocommerce_thumbnail' => array( 350, 514, 1000 ), 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->portada( 26, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->options[ Recompressor::OPTION_STATE ] = $this->empezado();
		$this->tras_codificar                        = function (): void {
			$this->ahora += 25.0;
		};

		$this->recompressor()->run();

		$estado = Recompressor::state();
		self::assertCount( 2, $this->llamadas, 'Los dos tamaños de la 25, aunque el presupuesto ya estaba agotado.' );
		self::assertSame( 25, $estado['cursor'], 'La 25 queda revisada.' );
		self::assertSame( 1, $estado['revisadas'] );
		self::assertSame( 0, $estado['en_proceso'] );
		self::assertSame( 0, $estado['finished'] );
		self::assertSame( 60, $this->post_meta[25][ Recompressor::META_DONE ] );
		self::assertCount( 1, $this->pendientes( Recompressor::HOOK ) );
		self::assertArrayNotHasKey( Recompressor::LOCK_OPTION, $this->options );

		// La corrida siguiente NO vuelve a la 25: sigue con la 26.
		$this->llamadas = array();
		$this->recompressor()->run();
		self::assertCount( 1, $this->llamadas );
		self::assertStringContainsString( 'tomo-26', (string) $this->llamadas[0][0] );
	}

	/**
	 * El candado lleva el token de quien lo tomó. Una corrida a la que se lo robaron
	 * (tardó más que `LOCK_TTL` en un archivo) no reemplaza nada más, no escribe el
	 * estado de la pasada viva y no borra el candado ajeno.
	 *
	 * @testdox Una corrida que perdió el candado deja de reemplazar, no pisa el estado ni borra el candado del otro.
	 */
	public function test_a_run_that_lost_the_lock_stops_writing(): void {
		$this->portada( 27, array( 'woocommerce_thumbnail' => array( 350, 514, 1000 ), 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->bytes[350]                            = 500;
		$this->bytes[450]                            = 500;
		$this->options[ Recompressor::OPTION_STATE ] = $this->empezado();
		$ajeno                                       = time() . '|otra-corrida|1';
		$this->tras_codificar                        = function () use ( $ajeno ): void {
			if ( count( $this->llamadas ) >= 2 ) {
				$this->options[ Recompressor::LOCK_OPTION ] = $ajeno;
			}
		};

		$this->recompressor()->run();

		$estado = Recompressor::state();
		self::assertSame( 500, filesize( $this->dir . '/tomo-27-350x514.avif' ), 'Lo que alcanzó a reemplazar antes del robo queda.' );
		self::assertSame( 1000, filesize( $this->dir . '/tomo-27-450x660.avif' ), 'Después del robo no reemplaza nada.' );
		self::assertSame( 500, $this->metas[27]['sizes']['woocommerce_thumbnail']['filesize'], 'El peso nuevo sí se anota: es del adjunto, no de la pasada.' );
		self::assertSame( 0, $estado['reemplazados'], 'El estado de la pasada lo lleva quien tiene el candado.' );
		self::assertSame( 27, $estado['en_proceso'] );
		self::assertSame( $ajeno, $this->options[ Recompressor::LOCK_OPTION ], 'El candado ajeno sigue en pie.' );
		self::assertCount( 1, $this->pendientes( Recompressor::HOOK_PURGE ), 'Lo reemplazado se purga igual.' );
		self::assertSame( array(), $this->temporales() );
	}

	/**
	 * `litespeed_purge_all` también tira CSS/JS optimizado, la caché de objetos y todo
	 * el borde; al terminar desde el cron en hora punta enfriaría la tienda entera.
	 *
	 * @testdox Al terminar con cambios, retira la continuación y agenda UNA purga a las 04:30 de Chile.
	 */
	public function test_finish_schedules_one_quiet_purge(): void {
		$this->portada( 31, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->bytes[450]                            = 500;
		$this->options[ Recompressor::OPTION_STATE ] = $this->empezado();
		Actions\expectDone( 'litespeed_purge_all' )->never();

		$this->recompressor()->run();

		$estado = Recompressor::state();
		self::assertGreaterThan( 0, $estado['finished'] );
		self::assertSame( array(), $this->pendientes( Recompressor::HOOK ) );
		$purgas = $this->pendientes( Recompressor::HOOK_PURGE );
		self::assertCount( 1, $purgas );
		self::assertSame( $estado['purga_agendada'], $purgas[0]['ts'] );
		$local = ( new \DateTimeImmutable( '@' . $purgas[0]['ts'] ) )->setTimezone( new \DateTimeZone( 'America/Santiago' ) );
		self::assertSame( '04:30', $local->format( 'H:i' ) );
	}

	/**
	 * @testdox Sin nada más liviano, termina sin agendar purga.
	 */
	public function test_finish_without_changes_schedules_no_purge(): void {
		$this->portada( 32, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->bytes[450]                            = 1000;
		$this->options[ Recompressor::OPTION_STATE ] = $this->empezado();

		$this->recompressor()->run();

		self::assertGreaterThan( 0, Recompressor::state()['finished'] );
		self::assertSame( array(), $this->pendientes( Recompressor::HOOK_PURGE ) );
	}

	/**
	 * @testdox La purga agendada vacía el borde una vez, lo anota y salda la deuda.
	 */
	public function test_purge_edge_purges_once_and_records_it(): void {
		$this->options[ Recompressor::OPTION_STATE ] = array_merge( $this->empezado(), array( 'finished' => 2000, 'purga_agendada' => 3000, 'purga_pendiente' => 1 ) );
		Actions\expectDone( 'litespeed_purge_all' )->once();

		$this->recompressor()->purge_edge();

		$estado = Recompressor::state();
		self::assertSame( 0, $estado['purga_agendada'] );
		self::assertSame( 0, $estado['purga_pendiente'] );
		self::assertGreaterThan( 0, $estado['purgada'] );
		self::assertArrayNotHasKey( Recompressor::LOCK_OPTION, $this->options, 'La purga suelta el candado.' );
	}

	/**
	 * La purga también escribe el estado, así que va con candado: si una corrida lo
	 * tiene, no purga a medias ni pisa el progreso; se reintenta, y sigue siendo de
	 * madrugada (revisión independiente, 2026-09-14).
	 *
	 * @testdox Con una corrida trabajando, la purga no se hace: se reintenta a los diez minutos.
	 */
	public function test_purge_edge_waits_for_a_working_run(): void {
		$this->options[ Recompressor::OPTION_STATE ] = array_merge( $this->empezado(), array( 'finished' => 2000, 'purga_agendada' => 3000, 'purga_pendiente' => 1 ) );
		$this->options[ Recompressor::LOCK_OPTION ]  = (string) time();
		Actions\expectDone( 'litespeed_purge_all' )->never();

		$this->recompressor()->purge_edge();

		self::assertSame( 1, Recompressor::state()['purga_pendiente'], 'La deuda sigue viva.' );
		$purgas = $this->pendientes( Recompressor::HOOK_PURGE );
		self::assertCount( 1, $purgas );
		// Diez minutos después, o la próxima madrugada si esos diez minutos ya caen de día
		// (eso lo fija `test_the_purge_retry_stays_in_the_quiet_window`).
		self::assertGreaterThan( time(), $purgas[0]['ts'] );
	}

	/**
	 * @testdox La hora de purga es la próxima 04:30 de la tienda, también cerca de medianoche y de madrugada.
	 */
	public function test_quiet_timestamp_is_the_next_0430_store_time(): void {
		$zona = new \DateTimeZone( 'America/Santiago' );

		$noche = ( new \DateTimeImmutable( '2026-09-14 22:00:00', $zona ) )->getTimestamp();
		self::assertSame( ( new \DateTimeImmutable( '2026-09-15 04:30:00', $zona ) )->getTimestamp(), Recompressor::next_quiet_timestamp( $noche ) );

		$madrugada = ( new \DateTimeImmutable( '2026-09-15 03:00:00', $zona ) )->getTimestamp();
		self::assertSame( ( new \DateTimeImmutable( '2026-09-15 04:30:00', $zona ) )->getTimestamp(), Recompressor::next_quiet_timestamp( $madrugada ) );
	}

	/**
	 * @testdox Sin pasada empezada, o con otra corrida viva, no hace nada.
	 */
	public function test_run_is_a_noop_without_a_pass_or_with_a_live_lock(): void {
		$this->portada( 41, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );

		$this->recompressor()->run();
		self::assertCount( 0, $this->llamadas );

		$this->options[ Recompressor::OPTION_STATE ] = $this->empezado();
		$this->options[ Recompressor::LOCK_OPTION ]  = (string) time();
		$this->recompressor()->run();
		self::assertCount( 0, $this->llamadas );
	}

	/**
	 * Medido en el espejo el 2026-09-14: una corrida de la cola y otra por CLI a la vez
	 * dejaron la pasada detenida en 424 de 1.589, sin nada pendiente.
	 *
	 * @testdox Una corrida que encuentra el candado ocupado deja una continuación antes de irse.
	 */
	public function test_a_run_blocked_by_the_lock_leaves_a_continuation(): void {
		$this->portada( 43, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->options[ Recompressor::OPTION_STATE ] = $this->empezado();
		$this->options[ Recompressor::LOCK_OPTION ]  = (string) time();

		$this->recompressor()->run();

		self::assertCount( 0, $this->llamadas );
		self::assertCount( 1, $this->pendientes( Recompressor::HOOK ) );

		$this->recompressor()->run();
		self::assertCount( 1, $this->pendientes( Recompressor::HOOK ), 'Con una pendiente no agenda otra.' );
	}

	/**
	 * @testdox Si otra corrida consumió la continuación mientras ésta trabajaba, deja otra al salir.
	 */
	public function test_a_consumed_continuation_is_replaced_on_exit(): void {
		$this->portada( 44, array( 'woocommerce_thumbnail' => array( 350, 514, 1000 ), 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->options[ Recompressor::OPTION_STATE ] = $this->empezado();
		$this->tras_codificar                        = function (): void {
			// Otra corrida sin candado se llevó la continuación agendada al empezar.
			foreach ( $this->acciones as $i => $a ) {
				if ( Recompressor::HOOK === $a['hook'] ) {
					$this->acciones[ $i ]['status'] = 'complete';
				}
			}
			$this->ahora = 25.0;
		};

		$this->recompressor()->run();

		self::assertSame( 0, Recompressor::state()['finished'] );
		self::assertCount( 1, $this->pendientes( Recompressor::HOOK ) );
	}

	/**
	 * @testdox Un candado vencido se toma: la corrida que lo dejó ya no existe.
	 */
	public function test_a_stale_lock_is_taken_over(): void {
		$this->portada( 42, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->options[ Recompressor::OPTION_STATE ] = $this->empezado();
		$this->options[ Recompressor::LOCK_OPTION ]  = (string) ( time() - 3600 );

		$this->recompressor()->run();

		self::assertCount( 1, $this->llamadas );
	}

	/**
	 * @testdox Empezar encola una sola pasada; con una corrida pendiente o en curso no la duplica.
	 */
	public function test_start_enqueues_once_and_respects_a_running_action(): void {
		$this->portada( 51, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$r = $this->recompressor();

		self::assertSame( 'encolada', $r->start( 60 ) );
		self::assertSame( 1, Recompressor::state()['total'] );
		self::assertSame( 60, Recompressor::state()['quality'] );
		self::assertCount( 1, $this->pendientes( Recompressor::HOOK ) );

		self::assertSame( 'ya-en-curso', $r->start( 60 ) );
		self::assertCount( 1, $this->pendientes( Recompressor::HOOK ) );

		foreach ( $this->acciones as $i => $a ) {
			$this->acciones[ $i ]['status'] = 'in-progress';
		}
		self::assertSame( 'ya-en-curso', $r->start( 60 ), 'La corrida en curso no cuenta como pendiente, pero la pasada vive.' );
	}

	/**
	 * @testdox Una pasada detenida se retoma con la misma calidad donde quedó, y marca la portada que la cortó.
	 */
	public function test_a_stalled_pass_is_resumed_where_it_stopped(): void {
		$this->options[ Recompressor::OPTION_STATE ] = array_merge(
			$this->empezado(),
			array( 'revisadas' => 300, 'cursor' => 900, 'reemplazados' => 40, 'en_proceso' => 901 )
		);
		$this->options[ Recompressor::LOCK_OPTION ] = (string) ( time() - 3600 );

		self::assertTrue( Recompressor::is_stalled( Recompressor::state() ) );
		self::assertSame( 'encolada', $this->recompressor()->start( 60 ) );

		$estado = Recompressor::state();
		self::assertSame( 300, $estado['revisadas'], 'No rehace lo que ya revisó.' );
		self::assertSame( 900, $estado['cursor'] );
		self::assertSame( 40, $estado['reemplazados'] );
		self::assertSame( 0, $estado['omitidas'], 'Empezar no juzga a nadie: eso lo decide la corrida.' );
		self::assertSame( 901, $estado['en_proceso'], 'La portada en vuelo la resuelve la corrida, con su reintento.' );
		self::assertArrayNotHasKey( Recompressor::META_DONE, $this->post_meta[901] ?? array() );
		self::assertFalse( Recompressor::is_stalled( Recompressor::state() ) );
		self::assertArrayNotHasKey( Recompressor::LOCK_OPTION, $this->options );
	}

	/**
	 * Con otra calidad la pasada empieza de cero, pero la deuda de purga NO se
	 * pierde: lo reemplazado antes sigue servido pesado desde Cloudflare hasta que
	 * alguien vacía el borde (revisión independiente, 2026-09-14).
	 *
	 * @testdox Empezar con otra calidad reinicia las cuentas y conserva la purga pendiente.
	 */
	public function test_restarting_with_another_quality_keeps_the_pending_purge(): void {
		$this->portada( 53, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->options[ Recompressor::OPTION_STATE ] = array_merge(
			$this->empezado( 55 ),
			array( 'revisadas' => 300, 'cursor' => 900, 'reemplazados' => 40, 'purga_pendiente' => 1 )
		);
		$this->options[ Recompressor::LOCK_OPTION ] = (string) ( time() - 3600 );

		self::assertSame( 'encolada', $this->recompressor()->start( 60 ) );

		$estado = Recompressor::state();
		self::assertSame( 60, $estado['quality'] );
		self::assertSame( 0, $estado['revisadas'] );
		self::assertSame( 0, $estado['cursor'] );
		self::assertSame( 1, $estado['purga_pendiente'] );

		// Y al terminar, aunque esta pasada no reemplace nada, la purga se agenda.
		$this->bytes[450] = 1000;
		$this->recompressor()->run();
		self::assertGreaterThan( 0, Recompressor::state()['finished'] );
		self::assertSame( 0, Recompressor::state()['reemplazados'] );
		self::assertCount( 1, $this->pendientes( Recompressor::HOOK_PURGE ) );
	}

	/**
	 * @testdox Los temporales huérfanos viejos se barren; los recientes, no.
	 */
	public function test_old_orphan_temporaries_are_swept(): void {
		$this->portada( 71, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$viejo = $this->dir . '/' . Recompressor::TMP_PREFIX . '1-viejo.avif';
		$joven = $this->dir . '/' . Recompressor::TMP_PREFIX . '2-joven.avif';
		file_put_contents( $viejo, 'x' );
		file_put_contents( $joven, 'x' );
		touch( $viejo, time() - 3600 );
		// Reciente pero en el PASADO: con la hora del instante, el reloj del disco puede ir
		// unos milisegundos por delante del de PHP y la resta sale negativa, así que la
		// prueba no distinguía «reciente» de «barrer todo» (una mutación sobrevivió así).
		touch( $joven, time() - 60 );
		$estado = $this->empezado();

		$this->recompressor()->process_attachment( 71, 60, $estado );

		self::assertFileDoesNotExist( $viejo );
		self::assertFileExists( $joven );
	}

	/**
	 * El logo, el favicon y los banners del slider también son AVIF: no se miden ni
	 * se recomprimen. (La exclusión real sobre la base la mide el QA del espejo.)
	 *
	 * @testdox La pasada sólo cuenta y recorre adjuntos de «portadas/», sin duplicados.
	 */
	public function test_pass_is_limited_to_covers(): void {
		$this->portada( 52, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );

		$this->recompressor()->start( 60 );
		$this->recompressor()->run();

		self::assertNotEmpty( $GLOBALS['wpdb']->portadas );
		foreach ( $GLOBALS['wpdb']->portadas as $sql ) {
			self::assertStringContainsString( "meta_key = '_wp_attached_file'", $sql );
			self::assertStringContainsString( "meta_value LIKE 'portadas/%'", $sql );
			self::assertStringContainsString( 'DISTINCT', $sql );
		}
	}

	/**
	 * Las demás pruebas del candado siembran el valor a mano. Ésta hace pelear a dos
	 * objetos por el camino real: `INSERT IGNORE` → leer → CAS.
	 *
	 * @testdox Dos corridas vivas: sólo una entra, el candado del ganador no se toca y la perdedora no lo borra.
	 */
	public function test_two_live_runs_fight_for_the_lock_through_sql(): void {
		$this->portada( 61, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->options[ Recompressor::OPTION_STATE ] = $this->empezado();

		$primera = $this->recompressor();
		$segunda = $this->recompressor();

		// La primera toma el candado por el camino real y, dentro de una portada, entra la otra.
		$this->tras_codificar = function () use ( $segunda ): void {
			$candado_del_ganador = $this->options[ Recompressor::LOCK_OPTION ];

			$segunda->run();

			self::assertSame( $candado_del_ganador, $this->options[ Recompressor::LOCK_OPTION ], 'La perdedora no pisa ni borra el candado ajeno.' );
			self::assertCount( 1, $this->llamadas, 'La perdedora no codifica nada.' );
			self::assertCount( 1, $this->pendientes( Recompressor::HOOK ), 'Y deja la cadena viva, sin duplicarla.' );
		};

		$primera->run();

		self::assertArrayNotHasKey( Recompressor::LOCK_OPTION, $this->options, 'La ganadora sí lo suelta al terminar.' );
		self::assertGreaterThan( 0, Recompressor::state()['finished'] );
	}

	/**
	 * @testdox Un candado vencido sólo lo roba una corrida: la otra se va sin trabajar.
	 */
	public function test_only_one_run_steals_an_expired_lock(): void {
		$this->portada( 62, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->options[ Recompressor::OPTION_STATE ] = $this->empezado();
		$this->options[ Recompressor::LOCK_OPTION ]  = (string) ( time() - 901 );

		$ladrona = $this->recompressor();
		$tardia  = $this->recompressor();
		// Mientras la ladrona trabaja, otra llega con el mismo candado vencido en la
		// mano: su CAS ya no coincide con la fila y se queda fuera.
		$this->tras_codificar = function () use ( $tardia ): void {
			$tardia->run();
			self::assertCount( 1, $this->llamadas );
		};

		$ladrona->run();

		self::assertCount( 1, $this->llamadas );
		self::assertGreaterThan( 0, Recompressor::state()['finished'] );
	}

	/**
	 * @testdox Sin calidad no se da por revisada ninguna portada.
	 */
	public function test_a_zero_quality_never_marks_anything(): void {
		$this->portada( 63, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->options[ Recompressor::OPTION_STATE ] = $this->empezado( 0 );

		$this->recompressor()->run();

		self::assertCount( 0, $this->llamadas );
		self::assertSame( 0, Recompressor::state()['revisadas'], '`(int) "" === 0` daría por revisada cada portada sin marca.' );
		self::assertSame( 0, Recompressor::state()['finished'] );
		self::assertArrayNotHasKey( Recompressor::META_DONE, $this->post_meta[63] ?? array() );
	}

	/**
	 * @testdox La purga ya agendada manda: terminar otra pasada no la empuja a mañana.
	 */
	public function test_finishing_respects_a_purge_already_scheduled(): void {
		$this->portada( 64, array( 'akb-card-2x' => array( 450, 660, 1000 ) ) );
		$this->bytes[450]                            = 500;
		$this->options[ Recompressor::OPTION_STATE ] = $this->empezado();
		$reintento                                   = time() + 600;
		$this->acciones[]                            = array( 'hook' => Recompressor::HOOK_PURGE, 'ts' => $reintento, 'group' => Recompressor::GROUP, 'status' => 'pending', 'priority' => 20 );

		$this->recompressor()->run();

		$purgas = $this->pendientes( Recompressor::HOOK_PURGE );
		self::assertCount( 1, $purgas, 'Ni se duplica ni se cancela.' );
		self::assertSame( $reintento, $purgas[0]['ts'], 'Cancelarla habría dejado el borde sirviendo copias pesadas 24 h más.' );
		self::assertSame( $reintento, Recompressor::state()['purga_agendada'] );
	}

	/**
	 * @testdox El reintento de la purga no se sale de la madrugada.
	 */
	public function test_the_purge_retry_stays_in_the_quiet_window(): void {
		$this->options[ Recompressor::OPTION_STATE ] = array_merge( $this->empezado(), array( 'finished' => 2000, 'purga_pendiente' => 1 ) );
		$this->options[ Recompressor::LOCK_OPTION ]  = (string) time();

		$this->recompressor()->purge_edge();

		$purgas = $this->pendientes( Recompressor::HOOK_PURGE );
		self::assertCount( 1, $purgas );
		$hora = (int) ( new \DateTimeImmutable( '@' . $purgas[0]['ts'] ) )->setTimezone( new \DateTimeZone( 'America/Santiago' ) )->format( 'G' );
		self::assertGreaterThanOrEqual( 4, $hora, 'Un `litespeed_purge_all` a media mañana enfría la tienda entera.' );
		self::assertLessThan( 6, $hora );
	}

	/**
	 * El candado es lo único que separa dos corridas, y la caché de objetos de
	 * producción devuelve valores viejos: si alguien lo reescribiera con la API de
	 * options, las demás pruebas seguirían en verde porque el doble comparte el array
	 * (revisión independiente, 2026-09-15).
	 *
	 * @testdox El candado nunca pasa por la API de options: sólo SQL sobre la fila.
	 */
	public function test_the_lock_never_goes_through_the_options_api(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/ImageQuality/Recompressor.php' );
		$fuente = (string) preg_replace( '#/\*[\s\S]*?\*/|//[^\n]*#', '', $fuente );

		foreach ( array( 'add_option', 'update_option', 'delete_option', 'get_option' ) as $funcion ) {
			self::assertDoesNotMatchRegularExpression(
				'/' . $funcion . '\(\s*self::LOCK_OPTION/',
				$fuente,
				$funcion . '() sobre el candado: add_option() hace INSERT … ON DUPLICATE KEY UPDATE y las lecturas pasarían por la caché de objetos.'
			);
		}
		foreach ( array( 'insert_if_absent', 'compare_and_swap', 'delete_if_equals', 'read_raw' ) as $metodo ) {
			self::assertStringContainsString(
				'OptionRow::' . $metodo . '( self::LOCK_OPTION',
				$fuente,
				'El candado se toma, renueva, suelta y lee por OptionRow, que es SQL atómico sobre la fila.'
			);
		}
	}
}
