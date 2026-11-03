<?php
/**
 * Renderizado de la fotografía de la reseña en las superficies necesarias.
 *
 * Muestra la foto adjunta tanto en la ficha de producto (PDP) como en la
 * pantalla de moderación de comentarios en el escritorio de administración.
 *
 * Utiliza el filtro `comment_text` porque es el punto común que procesa ambas
 * superficies (listado de reseñas en el front y tabla de moderación administrativa).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

defined( 'ABSPATH' ) || exit;

final class PhotoSurfaces {

	/** Ancho máximo de la foto en la ficha, en píxeles. */
	private const ANCHO_FICHA = 240;

	/** Ancho de la miniatura en la pantalla de moderación, en píxeles. */
	private const ANCHO_ADMIN = 96;

	public function register(): void {
		// Prioridad 26: después de la insignia de reseña transcrita (20), para
		// mantener el orden secuencial texto -> insignia -> foto.
		add_filter( 'comment_text', array( $this, 'append_photo' ), 26, 2 );
	}

	/**
	 * Añade la foto de la reseña al final de su texto.
	 *
	 * @param string           $text    Texto del comentario ya filtrado.
	 * @param \WP_Comment|null $comment Comentario, cuando el llamador lo pasa.
	 * @return string
	 */
	public function append_photo( $text, $comment = null ): string {
		$text = (string) $text;

		if ( ! $comment instanceof \WP_Comment ) {
			return $text;
		}

		// Sólo reseñas de producto: los comentarios del blog no llevan foto.
		if ( 'product' !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return $text;
		}

		$foto = self::foto( (int) $comment->comment_ID );
		if ( array() === $foto ) {
			return $text;
		}

		$autor = trim( (string) $comment->comment_author );
		$alt   = '' !== $autor
			? sprintf( 'Foto de la reseña de %s', $autor )
			: 'Foto de la reseña';

		return $text . self::figura( $foto, $alt );
	}

	/**
	 * La foto de una reseña: primero la local (adjunto), luego la remota.
	 *
	 * Misma vara que `Media::has_real_photo()` y que el carrusel: el adjunto
	 * tiene que EXISTIR y ser imagen. Una meta huérfana no puede colar un hueco
	 * donde iba la foto.
	 *
	 * @param int $comment_id Reseña.
	 * @return array{id:int,url:string}|array{} Vacío si no hay foto utilizable.
	 */
	private static function foto( int $comment_id ): array {
		foreach ( (array) get_comment_meta( $comment_id, Media::META_LOCAL_IMAGE ) as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			if ( $attachment_id > 0 && wp_attachment_is( 'image', $attachment_id ) ) {
				return array(
					'id'  => $attachment_id,
					'url' => (string) wp_get_attachment_image_url( $attachment_id, 'large' ),
				);
			}
		}

		foreach ( (array) get_comment_meta( $comment_id, Media::META_REMOTE_IMAGE ) as $remota ) {
			if ( ! is_array( $remota ) || empty( $remota['url'] ) || ! is_string( $remota['url'] ) ) {
				continue;
			}

			// En entornos HTTPS, asegurar esquema https para evitar bloqueo de contenido mixto.
			// En entornos locales o sin SSL, preservar el esquema original.
			$url = is_ssl() ? set_url_scheme( $remota['url'], 'https' ) : $remota['url'];

			// Sólo web: una meta manipulada no puede colar un esquema raro.
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				continue;
			}

			return array(
				'id'  => 0,
				'url' => $url,
			);
		}

		return array();
	}

	/**
	 * Estilo en línea de la foto: tope de ancho contenido que respeta su contenedor responsivo.
	 *
	 * Utiliza min(ancho, 100%) para evitar desbordes en contenedores estrechos en vista móvil.
	 *
	 * @param int $ancho Tope en píxeles.
	 * @return string
	 */
	private static function estilo( int $ancho ): string {
		return sprintf( 'max-width:min(%dpx,100%%);height:auto;border-radius:4px;display:block;', $ancho );
	}

	/**
	 * El marcado de la foto, distinto según quién mira.
	 *
	 * En el escritorio va una miniatura: la tabla de moderación tiene una fila
	 * por reseña y una foto grande la vuelve ilegible. En la ficha va la imagen
	 * a tamaño de lectura, perezosa, porque puede haber muchas reseñas abajo.
	 *
	 * @param array{id:int,url:string} $foto Foto resuelta.
	 * @param string                   $alt  Texto alternativo.
	 * @return string
	 */
	private static function figura( array $foto, string $alt ): string {
		$en_admin = is_admin();
		$ancho    = $en_admin ? self::ANCHO_ADMIN : self::ANCHO_FICHA;

		if ( $foto['id'] > 0 ) {
			$img = wp_get_attachment_image(
				$foto['id'],
				$en_admin ? 'thumbnail' : 'medium',
				false,
				array(
					'class'   => 'akb-rw-foto__img',
					'alt'     => $alt,
					'loading' => 'lazy',
					'style'   => self::estilo( $ancho ),
				)
			);
		} else {
			$img = sprintf(
				'<img src="%s" alt="%s" class="akb-rw-foto__img" loading="lazy" style="%s">',
				esc_url( $foto['url'] ),
				esc_attr( $alt ),
				esc_attr( self::estilo( $ancho ) )
			);
		}

		if ( '' === $img ) {
			return '';
		}

		// En el escritorio la miniatura enlaza a la imagen completa: el dueño
		// aprueba mirando el detalle, no un sello de 96 píxeles.
		if ( $en_admin && '' !== $foto['url'] ) {
			$img = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( $foto['url'] ),
				$img
			);
		}

		return sprintf(
			'<figure class="akb-rw-foto" style="margin:10px 0 0;">%s</figure>',
			$img
		);
	}
}
