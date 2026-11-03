<?php
/**
 * Contrato de dónde se ve la foto de una reseña.
 *
 * Decisión del dueño (2026-09-13): la foto —obligatoria para reseñar y
 * justificación de los 500 puntos— tiene que verse en la ficha del tomo Y en
 * la pantalla de moderación. Hasta ese día el único sitio que la pintaba era
 * el carrusel de la portada, y sólo con más de siete reseñas: el dueño
 * aprobaba a ciegas y el cliente no veía su foto en el tomo que reseñó.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Reviews\Media;
use Akibara\Rewards\Reviews\PhotoSurfaces;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

final class PhotoSurfacesTest extends RewardsTestCase {

	private PhotoSurfaces $surfaces;

	/** @var array<int,string> post_id => post_type */
	private array $tipos = array();

	private bool $en_admin = false;

	private bool $con_ssl = true;

	/** Tipo del post que cuelga de un comentario, tal como lo vería WordPress. */
	public function tipo_de( int $post_id ): string {
		return $this->tipos[ $post_id ] ?? 'post';
	}

	/** ¿La página se está sirviendo por https? */
	public function pagina_por_https(): bool {
		return $this->con_ssl;
	}

	/** ¿La reseña se está pintando en el escritorio (moderación)? */
	public function mirando_desde_el_escritorio(): bool {
		return $this->en_admin;
	}

	protected function setUp(): void {
		parent::setUp();

		$this->surfaces = new PhotoSurfaces();

		// Se captura la PRUEBA, no sus arrays: una función flecha copia lo que
		// usa en el momento de crearse, así que `use ( $tipos )` dejaría los
		// dobles mirando un catálogo vacío por mucho que el caso lo llene
		// después. Con el objeto delante, cada lectura va a la propiedad viva.
		$caso = $this;

		Functions\when( 'get_post_type' )->alias(
			static fn( int $post_id ): string => $caso->tipo_de( $post_id )
		);
		Functions\when( 'is_admin' )->alias(
			static fn(): bool => $caso->mirando_desde_el_escritorio()
		);
		Functions\when( 'is_ssl' )->alias(
			static fn(): bool => $caso->pagina_por_https()
		);
		Functions\when( 'set_url_scheme' )->alias(
			// Como el core: reescribe el esquema de una URL que ya lo trae.
			static fn( string $url, string $esquema = 'https' ): string => preg_replace( '#^\w+://#', $esquema . '://', $url )
		);
		Functions\when( 'wp_attachment_is' )->alias(
			// Los adjuntos «reales» de estas pruebas son los de id < 900.
			static fn( string $tipo, int $id ): bool => 'image' === $tipo && $id > 0 && $id < 900
		);
		Functions\when( 'wp_get_attachment_image_url' )->alias(
			static fn( int $id, string $size = 'thumbnail' ): string => sprintf( 'https://akibara.test/foto-%d-%s.jpg', $id, $size )
		);
		Functions\when( 'wp_get_attachment_image' )->alias(
			// Como el core: pinta TODOS los atributos que le pasan, no una
			// lista elegida por la prueba. Un doble que se comiera `loading`
			// dejaría pasar una imagen sin carga perezosa.
			static function ( int $id, string $size, bool $icon, array $attr ): string {
				$atributos = '';
				foreach ( $attr as $nombre => $valor ) {
					$atributos .= sprintf( ' %s="%s"', $nombre, $valor );
				}

				return sprintf(
					'<img src="https://akibara.test/foto-%d-%s.jpg"%s>',
					$id,
					$size,
					$atributos
				);
			}
		);
	}

	/** Reseña de producto con foto local utilizable. */
	private function resena_con_foto( int $comment_id = 500, int $attachment_id = 42 ): \WP_Comment {
		$this->tipos[7000] = 'product';
		$this->wpdb->comment_meta[ $comment_id ][ Media::META_LOCAL_IMAGE ] = array( $attachment_id );

		$comment                    = new \WP_Comment();
		$comment->comment_ID        = $comment_id;
		$comment->comment_post_ID   = 7000;
		$comment->comment_author    = 'Camila';

		return $comment;
	}

	/** @testdox En la ficha, la reseña con foto la pinta debajo del texto. */
	public function test_en_la_ficha_se_pinta_la_foto(): void {
		$salida = $this->surfaces->append_photo( '<p>Excelente edición.</p>', $this->resena_con_foto() );

		$this->assertStringContainsString( '<p>Excelente edición.</p>', $salida, 'El texto de la reseña no se toca.' );
		$this->assertStringContainsString( 'akb-rw-foto', $salida );
		$this->assertStringContainsString( 'foto-42-medium.jpg', $salida, 'En la ficha va a tamaño de lectura.' );
		$this->assertStringContainsString( 'Foto de la reseña de Camila', $salida, 'La foto necesita texto alternativo con el autor.' );
		$this->assertStringContainsString( 'loading="lazy"', $salida );
		$this->assertStringNotContainsString( '<a href', $salida, 'En la ficha la foto no enlaza a la biblioteca de medios.' );
	}

	/** @testdox En moderación va la miniatura y enlaza a la imagen completa. */
	public function test_en_moderacion_va_miniatura_enlazada(): void {
		$this->en_admin = true;

		$salida = $this->surfaces->append_photo( 'Texto', $this->resena_con_foto() );

		$this->assertStringContainsString( 'foto-42-thumbnail.jpg', $salida, 'En la tabla del escritorio va una miniatura.' );
		$this->assertStringContainsString( 'href="https://akibara.test/foto-42-large.jpg"', $salida, 'La miniatura enlaza a la foto completa.' );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $salida );
	}

	/** @testdox Una foto remota (importada) también se pinta. */
	public function test_una_foto_remota_tambien_se_pinta(): void {
		$comment = $this->resena_con_foto( 501, 950 ); // 950 no es adjunto válido.
		$this->wpdb->comment_meta[501][ Media::META_REMOTE_IMAGE ] = array( array( 'url' => 'http://cdn.example/opinion.jpg' ) );

		$salida = $this->surfaces->append_photo( 'Texto', $comment );

		$this->assertStringContainsString( 'https://cdn.example/opinion.jpg', $salida, 'Una remota en http se sirve por https: el navegador bloquea el contenido mixto y dejaría el hueco de la foto.' );
		$this->assertStringNotContainsString( 'http://cdn.example', $salida );
	}

	/** @testdox En un sitio servido por http la remota conserva su esquema. */
	public function test_sin_https_la_remota_conserva_el_esquema(): void {
		$this->con_ssl = false;
		$comment       = $this->resena_con_foto( 506, 950 );
		$this->wpdb->comment_meta[506][ Media::META_REMOTE_IMAGE ] = array( array( 'url' => 'http://cdn.example/opinion.jpg' ) );

		$salida = $this->surfaces->append_photo( 'Texto', $comment );

		$this->assertStringContainsString( 'http://cdn.example/opinion.jpg', $salida, 'Forzar https en un sitio sin TLS rompe la foto: medido en el espejo.' );
	}

	/** @testdox La foto nunca es más ancha que la tarjeta de la reseña. */
	public function test_el_tope_de_ancho_respeta_el_contenedor(): void {
		$local  = $this->surfaces->append_photo( 'Texto', $this->resena_con_foto() );
		$remota = $this->resena_con_foto( 507, 950 );
		$this->wpdb->comment_meta[507][ Media::META_REMOTE_IMAGE ] = array( array( 'url' => 'https://cdn.example/opinion.jpg' ) );

		foreach ( array( $local, $this->surfaces->append_photo( 'Texto', $remota ) ) as $salida ) {
			$this->assertStringContainsString( 'max-width:min(240px,100%)', $salida, 'Con max-width:240px a secas la foto se salía 30px de la tarjeta en un móvil de 375px.' );
		}
	}

	/** @testdox Una meta remota con un esquema raro no se pinta. */
	public function test_una_remota_con_esquema_raro_no_se_pinta(): void {
		$comment = $this->resena_con_foto( 505, 950 );
		$this->wpdb->comment_meta[505][ Media::META_REMOTE_IMAGE ] = array( array( 'url' => 'javascript:alert(1)' ) );

		$this->assertSame( 'Texto', $this->surfaces->append_photo( 'Texto', $comment ) );
	}

	/** @testdox El filtro no empata con force_balance_tags del core. */
	public function test_la_prioridad_no_empata_con_el_core(): void {
		$prioridades = array();
		Functions\when( 'add_filter' )->alias(
			static function ( string $hook, $cb, int $prioridad = 10, int $args = 1 ) use ( &$prioridades ): bool {
				$prioridades[ $hook ] = $prioridad;
				return true;
			}
		);

		( new PhotoSurfaces() )->register();

		$this->assertSame( 26, $prioridades['comment_text'] ?? 0, 'La 25 es la de force_balance_tags: ahí gana quien se registre primero.' );
	}

	/** @testdox Una meta huérfana no cuela un hueco donde iba la foto. */
	public function test_una_meta_huerfana_no_pinta_nada(): void {
		$comment = $this->resena_con_foto( 502, 950 ); // Adjunto inexistente.

		$this->assertSame( 'Texto', $this->surfaces->append_photo( 'Texto', $comment ) );
	}

	/** @testdox Un comentario del blog no se toca. */
	public function test_un_comentario_del_blog_no_se_toca(): void {
		$comment                  = $this->resena_con_foto( 503 );
		$this->tipos[7000]        = 'post';

		$this->assertSame( 'Texto', $this->surfaces->append_photo( 'Texto', $comment ) );
	}

	/** @testdox Sin comentario (otro filtro llamando suelto) devuelve el texto tal cual. */
	public function test_sin_comentario_devuelve_el_texto(): void {
		$this->assertSame( 'Texto', $this->surfaces->append_photo( 'Texto', null ) );
	}

	/** @testdox Una reseña sin foto queda igual que antes. */
	public function test_una_resena_sin_foto_queda_igual(): void {
		$this->tipos[7000]        = 'product';
		$comment                  = new \WP_Comment();
		$comment->comment_ID      = 504;
		$comment->comment_post_ID = 7000;

		$this->assertSame( 'Texto', $this->surfaces->append_photo( 'Texto', $comment ) );
	}
}
