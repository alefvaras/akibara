<?php
/**
 * SeriesResolver — de un producto a «qué serie y qué tomo es».
 *
 * Puro y sin estado. Devuelve null en cuanto la respuesta deja de ser
 * inequívoca, porque el consumidor es un correo: un aviso equivocado sobre una
 * edición especial cuesta más que el aviso que no sale.
 *
 * Dos descartes deliberados, heredados de las reglas que ya usa la casa:
 *   - `1 !== count($terms)`: un producto con 0 o 2 `pa_serie` es inclasificable
 *     (misma regla que `AccountCollection\CollectionResolver`).
 *   - `null === $volume`: `VolumeTitle::extract_number()` devuelve null para el
 *     tomo 0, los decimales (`1.5`), los omnibus y los especiales tipo
 *     «Shangri La Frontier 1 Expansion Pass». Consecuencia dicha en voz alta:
 *     esos productos NO se avisan nunca, a propósito.
 *   - `VolumeVariant::is_variant()`: la misma política extendida a los
 *     especiales CON número. Una edición especial del tomo 23 no es el tomo 23
 *     saliendo por segunda vez.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFollow;

use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Services\VolumeTitle;
use Akibara\Business\Services\VolumeVariant;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.2
 */
final class SeriesResolver {

	/**
	 * Serie y tomo de un producto publicado.
	 *
	 * @param int $product_id Producto a clasificar.
	 * @return array{term_id:int, term_taxonomy_id:int, name:string, volume:int}|null
	 */
	public static function resolve( int $product_id ): ?array {
		if ( $product_id < 1 || ! function_exists( 'get_the_terms' ) ) {
			return null;
		}

		$terms = get_the_terms( $product_id, SeriesCatalogEligibility::TAXONOMY );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $terms ) ) {
			return null;
		}
		if ( ! is_array( $terms ) || 1 !== count( $terms ) ) {
			return null;
		}

		$term = reset( $terms );
		if ( ! is_object( $term ) || ! isset( $term->term_id, $term->term_taxonomy_id ) ) {
			return null;
		}

		$term_id          = (int) $term->term_id;
		$term_taxonomy_id = (int) $term->term_taxonomy_id;
		if ( $term_id < 1 || $term_taxonomy_id < 1 ) {
			return null;
		}

		$volume = VolumeTitle::extract_number( (string) get_the_title( $product_id ) );
		if ( null === $volume ) {
			return null;
		}

		// Una variante NUNCA es un tomo nuevo. El descarte va acá y no en el
		// runner porque acá está la política entera: `PublishListener::on_terms()`
		// encola un escaneo en cuanto se asigna `pa_serie`, aunque el producto
		// lleve una semana publicado, y la única puerta temporal del runner son
		// los 60 días de `DigestRunner::NOVELTY_DAYS`. Sin este corte, meter una
		// edición especial dentro de su serie anuncia «ya salió el tomo 23» de un
		// tomo que salió meses atrás, y la idempotencia no lo frena: su clave es
		// `u{user}:p{product}`, o sea por PRODUCTO, así que quien ya recibió ese
		// aviso por el tomo normal recibiría un segundo aviso del mismo número.
		if ( VolumeVariant::is_variant( $product_id ) ) {
			return null;
		}

		return array(
			'term_id'          => $term_id,
			'term_taxonomy_id' => $term_taxonomy_id,
			'name'             => isset( $term->name ) ? (string) $term->name : '',
			'volume'           => $volume,
		);
	}

	/**
	 * Explica por qué resolve() descartó un producto.
	 *
	 * Recorre los mismos guardas en el mismo orden y agrupa el resultado en serie,
	 * número y variante. El registro debe comunicar la causa real sin atribuir a
	 * un problema de serie un descarte por edición especial.
	 *
	 * @param int $product_id Producto.
	 * @return string 'sin_serie_unica' | 'sin_numero_de_tomo' | 'variante' | 'resuelto'.
	 */
	public static function discard_reason( int $product_id ): string {
		if ( $product_id < 1 || ! function_exists( 'get_the_terms' ) ) {
			return 'sin_serie_unica';
		}

		$terms = get_the_terms( $product_id, SeriesCatalogEligibility::TAXONOMY );
		if ( ( function_exists( 'is_wp_error' ) && is_wp_error( $terms ) ) || ! is_array( $terms ) || 1 !== count( $terms ) ) {
			return 'sin_serie_unica';
		}

		// Mismas dos puertas de identidad del término que `resolve()`: un
		// término sin ids no es una serie utilizable (revisión independiente, B1).
		$term = reset( $terms );
		if ( ! is_object( $term ) || ! isset( $term->term_id, $term->term_taxonomy_id )
			|| (int) $term->term_id < 1 || (int) $term->term_taxonomy_id < 1 ) {
			return 'sin_serie_unica';
		}

		if ( null === VolumeTitle::extract_number( (string) get_the_title( $product_id ) ) ) {
			return 'sin_numero_de_tomo';
		}

		if ( VolumeVariant::is_variant( $product_id ) ) {
			return 'variante';
		}

		return 'resuelto';
	}

	/**
	 * @param string $reason Motivo de `discard_reason()`.
	 * @return string Texto para el registro.
	 */
	public static function discard_reason_text( string $reason ): string {
		return match ( $reason ) {
			'variante'           => 'es una edición especial o variante, no un tomo nuevo',
			'sin_numero_de_tomo' => 'sin número de tomo inequívoco en el título',
			'sin_serie_unica'    => 'sin una única pa_serie utilizable',
			default              => 'sin motivo conocido',
		};
	}
}
