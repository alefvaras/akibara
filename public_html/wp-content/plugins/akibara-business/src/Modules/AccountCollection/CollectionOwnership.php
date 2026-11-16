<?php
/** Consulta de propiedad de un tomo y sus variantes dentro de la misma edición. */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\AccountCollection;

use Akibara\Business\Services\VolumeTitle;

defined( 'ABSPATH' ) || exit;

final class CollectionOwnership {

	public static function owns_product( int $user_id, int $product_id ): bool {
		$purchased = ( new CustomerCollection() )->owned_product_ids_result( $user_id );
		$owned     = $purchased['product_ids'];
		if ( in_array( $product_id, $owned, true ) ) {
			return true;
		}
		_prime_post_caches( array_merge( $owned, array( $product_id ) ), true, true );
		$target = self::slot( $product_id );
		if ( null === $target ) {
			return false;
		}
		foreach ( $owned as $id ) {
			if ( self::slot( $id ) === $target ) {
				return true;
			}
		}
		return false;
	}

	private static function slot( int $id ): ?string {
		$volume = VolumeTitle::extract_number( (string) get_the_title( $id ) );
		$terms = get_the_terms( $id, 'pa_serie' );
		if ( null === $volume || ! is_array( $terms ) || 1 !== count( $terms ) ) {
			return null;
		}
		return $terms[0]->term_id . ':' . $volume;
	}
}
