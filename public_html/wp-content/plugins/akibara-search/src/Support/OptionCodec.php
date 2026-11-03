<?php
/**
 * OptionCodec — codificación de valores de `wp_options`, en un solo sitio.
 *
 * El plugin lee y escribe options SIN pasar por el object cache en varios
 * caminos del protocolo de publicación (CAS, receipts, outbox), así que
 * necesita reproducir exactamente lo que Core guarda en la columna. Ese par
 * serializar/deserializar estaba copiado en TRES clases —Install, PriceBuckets
 * y SearchAdminSection— y las copias no coincidían: la de Install deserializaba
 * con `@unserialize( $value )` a secas y la de PriceBuckets pasaba
 * `array( 'allowed_classes' => false )`. La misma operación, dos posturas de
 * seguridad, según qué fichero la llamara.
 *
 * Acá vive UNA, con el criterio más estricto de las tres: ningún camino del
 * plugin puede instanciar un objeto desde una fila de wp_options.
 *
 * El fallback sin Core no es decorativo: la suite corre sin `wp-includes`, y un
 * doble de `maybe_serialize()` que no modelara producción ya escondió un fatal
 * vivo una vez. Por eso el fallback replica la forma de Core, no una propia.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.41
 */
final class OptionCodec {

	/**
	 * Valor tal como Core lo escribiría en la columna `option_value`.
	 *
	 * @param mixed $value Valor de option.
	 * @return string
	 */
	public static function encode( $value ): string {
		if ( function_exists( 'maybe_serialize' ) ) {
			return (string) maybe_serialize( $value );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- fallback del harness; producción usa maybe_serialize().
		return is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
	}

	/**
	 * Valor de PHP a partir de lo que hay en la columna.
	 *
	 * NUNCA instancia objetos: una fila de wp_options es dato, no código. Un
	 * payload no serializado se devuelve tal cual, igual que hace Core.
	 *
	 * @param string $raw Valor crudo de la columna.
	 * @return mixed
	 */
	public static function decode( string $raw ) {
		$decoded = @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- supresión intencional de notice ante payload no serializado; allowed_classes => false previene deserialización de objetos.

		return false !== $decoded || 'b:0;' === $raw ? $decoded : $raw;
	}
}
