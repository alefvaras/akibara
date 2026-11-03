<?php
/**
 * La serie de una guía del blog: tarjeta cerca del inicio y CTA final a sus tomos.
 *
 * Vinculación de guías editoriales con series del catálogo:
 * Presenta una tarjeta contextual en el cuerpo del artículo y un CTA directo
 * a la serie relacionada, facilitando la conversión de lectores en clientes.
 *
 * Arquitectura y distribución de responsabilidades:
 *   - akibara-business gestiona los datos: resolución de serie relacionada,
 *     portada (`SeriesCover`), desglose de stock (`SeriesFacts::stock_rows()`)
 *     e invalidación de caché vía filtro `akb_guide_series_card`.
 *   - El tema se encarga exclusivamente del renderizado semántico y accesible.
 *
 * Qué sale en cada caso:
 *   - Entrada sin serie ligada (o sin akibara-business): como antes, sin
 *     tarjeta y con el cierre a la tienda.
 *   - Serie ligada: tarjeta con portada, conteos y botón, y el cierre a la
 *     serie. Si business no trae portada o conteos, la tarjeta pierde esa
 *     línea; no inventa datos.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/** Marcador del nombre de la serie en los textos del dueño (nunca sprintf). */
const AKIBARA_GP_GUIDE_SERIES_MARKER = '{serie}';

/**
 * Datos de la tarjeta de la serie de una entrada, o null si no tiene.
 *
 * @param int $post_id ID de la entrada.
 * @return array{name:string,url:string,cover?:string,estados?:array<int,array{label:string,texto:string}>}|null
 */
function akibara_gp_guide_series_data( int $post_id ): ?array {
	if ( $post_id < 1 ) {
		return null;
	}

	/**
	 * Datos de la tarjeta de la serie ligada a una guía del blog.
	 *
	 * Los pone akibara-business (RelatedGuides\GuideSeriesCard): nombre, URL de
	 * la landing, portada y tomos por estado ya redactados. Null = sin serie.
	 *
	 * @param array|null $datos   Null por defecto.
	 * @param int        $post_id Entrada.
	 */
	$datos = apply_filters( 'akb_guide_series_card', null, $post_id );
	if ( ! is_array( $datos ) || '' === (string) ( $datos['name'] ?? '' ) || '' === (string) ( $datos['url'] ?? '' ) ) {
		return null;
	}

	return $datos;
}

/**
 * Copy del dueño con el nombre de la serie en lugar del marcador. El texto se
 * escapa ANTES de reemplazar y el nombre también: nunca pasa por sprintf, así un
 * «%» del dueño no rompe nada.
 *
 * @param string $copia Texto del dueño.
 * @param string $serie Nombre de la serie.
 * @return string HTML escapado.
 */
function akibara_gp_guide_series_copy( string $copia, string $serie ): string {
	return str_replace( AKIBARA_GP_GUIDE_SERIES_MARKER, esc_html( $serie ), esc_html( $copia ) );
}

/**
 * HTML de la tarjeta. Pura: recibe todo resuelto.
 *
 * Un solo enlace a la landing (el botón). La portada no enlaza aparte: dos
 * enlaces al mismo destino son dos paradas de Tab y dos anuncios idénticos.
 * El alto de la portada va declarado (width/height + aspect-ratio en blog.css):
 * la tarjeta no empuja el texto al cargar la imagen.
 *
 * El rótulo siempre está: akibara_gp_copy() vuelve al de fábrica si la option
 * está vacía, así que no existe una tarjeta «sin rótulo» y la región se nombra
 * siempre con el rótulo y la serie (revisión T7-2, D4: la rama sin rótulo era
 * código muerto que sólo la prueba ejecutaba).
 *
 * @param array{name:string,url:string,cover?:string,estados?:array<int,array{label:string,texto:string}>} $data   Datos de la serie.
 * @param string                                                                                           $kicker Rótulo sobre el nombre.
 * @param string                                                                                           $cta    Texto del botón (con {serie}).
 */
function akibara_gp_render_guide_series_card( array $data, string $kicker, string $cta ): string {
	$name = (string) ( $data['name'] ?? '' );
	$url  = (string) ( $data['url'] ?? '' );
	if ( '' === $name || '' === $url ) {
		return '';
	}

	// El nombre accesible de la región lo dan el rótulo del dueño y el nombre de
	// la serie que ya están escritos dentro (aria-labelledby), no un prefijo
	// «Serie: » clavado aquí. Una sola tarjeta por página: los id no se repiten.
	$html = '<aside class="akb-guide-series" aria-labelledby="akb-guide-series-kicker akb-guide-series-name">';

	$cover = (string) ( $data['cover'] ?? '' );
	if ( '' !== $cover ) {
		$html .= '<span class="akb-guide-series__cover akb-cover-cage"><img src="' . esc_url( $cover ) . '" alt="" width="300" height="450" loading="lazy" decoding="async"></span>';
	}

	$html .= '<div class="akb-guide-series__body">';
	$html .= '<p class="akb-guide-series__kicker" id="akb-guide-series-kicker">' . esc_html( $kicker ) . '</p>';
	$html .= '<p class="akb-guide-series__name" id="akb-guide-series-name">' . esc_html( $name ) . '</p>';

	$estados = (array) ( $data['estados'] ?? array() );
	if ( array() !== $estados ) {
		$html .= '<ul class="akb-guide-series__stock">';
		foreach ( $estados as $estado ) {
			// Palabra y conteo («Disponible» · «3 tomos») ya redactados por
			// business con el vocabulario de la ficha de la landing: aquí sólo se
			// imprimen.
			$html .= '<li><span class="akb-guide-series__state">' . esc_html( (string) ( $estado['label'] ?? '' ) ) . '</span> '
				. esc_html( (string) ( $estado['texto'] ?? '' ) ) . '</li>';
		}
		$html .= '</ul>';
	}

	$html .= '<a class="aki-btn akb-guide-series__cta" href="' . esc_url( $url ) . '">' . akibara_gp_guide_series_copy( $cta, $name ) . '</a>';
	$html .= '</div></aside>';

	return $html;
}

/**
 * Elementos que pueden envolver un H2 o un párrafo del contenido y que siempre
 * llevan etiqueta de cierre: el índice de Rank Math (un `<div>` con su propio
 * H2 «Tabla de contenidos»), un grupo, un `<details>` de preguntas, una tabla,
 * una cita, una lista… Contar sólo éstos basta para saber si una etiqueta está
 * en el primer nivel del contenido: los vacíos (`<img>`, `<br>`) no encierran
 * nada y un H2 no puede ir dentro de un párrafo (el HTML lo cierra antes).
 */
const AKIBARA_GP_GUIDE_CONTENEDORES = 'a|article|aside|blockquote|details|dialog|div|dl|fieldset|figure|footer|form|header|main|nav|ol|section|table|ul';

/**
 * Inserta la tarjeta cerca del inicio del contenido: justo antes del primer H2,
 * que en las guías cierra la respuesta corta con la que abren («The Climber está
 * completo en 17 tomos…») y abre la primera sección. Sin H2, tras el primer
 * párrafo; sin párrafos, al principio.
 *
 * Sólo cuentan el H2 y el párrafo del PRIMER nivel del contenido (revisión T7-2,
 * D2). Antes se buscaba el primer `<h2` de cualquier nivel, y una guía que
 * abriera con el índice de Rank Math, un grupo o un `<details>` habría metido la
 * tarjeta DENTRO de ese contenedor (en un `<details>` cerrado, invisible). Si la
 * guía entera va dentro de un solo contenedor, la tarjeta queda al principio,
 * fuera de él: visible, nunca dentro de algo ajeno. Los comentarios HTML y el
 * interior de `<script>`/`<style>`/`<template>`/`<textarea>` no cuentan.
 *
 * @param string $content Contenido ya filtrado.
 * @param string $card    HTML de la tarjeta ('' = no tocar nada).
 */
function akibara_gp_insert_guide_series_card( string $content, string $card ): string {
	if ( '' === $card ) {
		return $content;
	}

	$patron = '~<!--.*?-->|<(script|style|template|textarea)\b.*?</\1\s*>|<(/?)(h2|p|' . AKIBARA_GP_GUIDE_CONTENEDORES . ')(?=[\s/>])[^>]*>~is';
	preg_match_all( $patron, $content, $marcas, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );

	$nivel  = 0;
	$tras_p = null;
	foreach ( $marcas as $marca ) {
		// Comentario o texto crudo: el grupo de la etiqueta no participa.
		if ( ! isset( $marca[3] ) || $marca[3][1] < 0 ) {
			continue;
		}
		$etiqueta = strtolower( $marca[3][0] );
		$cierra   = '/' === $marca[2][0];

		if ( 'h2' === $etiqueta ) {
			if ( ! $cierra && 0 === $nivel ) {
				$pos = (int) $marca[0][1];
				return substr( $content, 0, $pos ) . $card . "\n" . substr( $content, $pos );
			}
			continue;
		}
		if ( 'p' === $etiqueta ) {
			if ( $cierra && 0 === $nivel && null === $tras_p ) {
				$tras_p = (int) $marca[0][1] + strlen( $marca[0][0] );
			}
			continue;
		}
		// Un cierre de más (HTML mal formado) no deja el nivel en negativo.
		$nivel = $cierra ? max( 0, $nivel - 1 ) : $nivel + 1;
	}

	if ( null !== $tras_p ) {
		return substr( $content, 0, $tras_p ) . "\n" . $card . substr( $content, $tras_p );
	}

	return $card . "\n" . $content;
}
