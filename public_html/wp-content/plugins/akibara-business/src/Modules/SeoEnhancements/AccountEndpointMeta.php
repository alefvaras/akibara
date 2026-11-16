<?php
/** Títulos de cuenta compuestos desde los endpoints nativos de WooCommerce. */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeoEnhancements;

defined( 'ABSPATH' ) || exit;

/** @api Callbacks de metadatos registrados por SeoEnhancements. */
final class AccountEndpointMeta {

	/**
	 * Conserva las partes del documento y sustituye solo su título de cuenta.
	 *
	 * @param array<string, string> $parts Partes del título de WordPress.
	 * @return array<string, string>
	 */
	public function document_title_parts( array $parts ): array {
		$title = $this->endpoint_title();
		if ( '' !== $title ) {
			$parts['title'] = $title;
		}
		return $parts;
	}

	/** Mismo título para el documento de Rank Math y sus tarjetas sociales. */
	public function title( string $title ): string {
		$endpoint_title = $this->endpoint_title();
		if ( '' === $endpoint_title ) {
			return $title;
		}
		return $endpoint_title . ' ' . apply_filters( 'document_title_separator', '-' ) . ' ' . get_bloginfo( 'name' );
	}

	/** Corrige la tarjeta social de endpoints raíz sin publicar parámetros privados. */
	public function social_url( string $url ): string {
		$endpoint = $this->current_endpoint();
		if ( '' === $endpoint || '' !== get_query_var( $endpoint, '' ) ) {
			return $url;
		}
		return wc_get_account_endpoint_url( $endpoint );
	}

	/** Reutiliza el título del endpoint; no mantiene una segunda lista de nombres. */
	private function endpoint_title(): string {
		$endpoint = $this->current_endpoint();
		$title = '' === $endpoint ? '' : WC()->query?->get_endpoint_title( $endpoint );
		return trim( wp_strip_all_tags( $title ?? '', true ) );
	}

	/** La misma detección nativa gobierna el documento y sus metadatos sociales. */
	private function current_endpoint(): string {
		return is_account_page() ? ( WC()->query?->get_current_endpoint() ?? '' ) : '';
	}
}
