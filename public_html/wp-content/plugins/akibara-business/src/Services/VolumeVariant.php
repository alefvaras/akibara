<?php
/**
 * ¿Este producto es otra versión de un tomo que la serie ya tiene?
 *
 * Hermano de {@see VolumeTitle}: aquél dice QUÉ NÚMERO es un producto, éste dice
 * si ese número ya lo ocupa otro producto de la misma serie. Son preguntas
 * distintas y por eso no viven en la misma clase — la primera se contesta con el
 * título, la segunda NO se puede contestar con el título.
 *
 * ── Por qué una marca por ficha y no un regex sobre el nombre ────────────────
 * El barrido de producción del  dejó el argumento cerrado: de los 128
 * productos publicados con paréntesis o palabra de edición en el título, sólo
 * UNO colisiona de verdad con otro tomo del mismo número. Los otros son sanos y
 * un marcador por texto los rompería:
 *   - «Maximum Berserk 21 (Portada alternativa + 4 postales)» es el ÚNICO tomo
 *     21 de su serie: marcarlo dejaría la serie declarando 20 tomos y a todos
 *     sus compradores con un faltante inventado.
 *   - Los 13 «One Piece nº NN (3 en 1)» viven en su propia serie.
 *   - «Atelier of Witch Hat (Edición Grimorio), Vol. 01..03» también.
 *   - Kanzenban / Deluxe / Master Edition / Edición Inmortal ya son series
 *     propias en producción.
 * O sea: el texto del título no distingue «otra versión del mismo tomo» de
 * «tomo único con un nombre largo». Sólo lo sabe quien carga el catálogo, y por
 * eso es una palanca del panel de producto y no una heurística.
 *
 * El enum es CERRADO y se sanea AL LEER, no sólo al guardar: `wp_postmeta`
 * también se escribe por WP-CLI y por importaciones, y un valor desconocido no
 * puede convertir un tomo normal en invisible. Mismo criterio que
 * `Availability::sanitize_type()`.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Fuente única de «esto es una variante del mismo tomo».
 *
 * @since 1.116.0
 */
final class VolumeVariant {

	/**
	 * Postmeta con la marca.
	 *
	 * Prefijo `_akb_`, el del resto de los datos propios de producto. Ojo con la
	 * lectura fácil: `uninstall.php` barre options, termmeta y usermeta, pero NO
	 * postmeta —ni la de este módulo ni la de `_akb_eta_type`—, y es a propósito:
	 * es un dato del catálogo del dueño, no configuración del plugin.
	 */
	public const META_KEY = '_akb_variante_tomo';

	/** Tomo normal: ocupa su número él solo. Es el valor por defecto. */
	public const KIND_NONE = '';

	/** Edición especial del mismo número (el caso Demon Slayer 23). */
	public const KIND_EDICION_ESPECIAL = 'edicion_especial';

	/** Mismo tomo con otra portada. */
	public const KIND_PORTADA_ALTERNATIVA = 'portada_alternativa';

	/** Mismo tomo reeditado. */
	public const KIND_REEDICION = 'reedicion';

	/**
	 * Enum cerrado con la etiqueta que lee el dueño en la ficha de producto.
	 *
	 * @return array<string, string>
	 */
	public static function kinds(): array {
		return array(
			self::KIND_NONE                => 'Tomo normal — es el único producto con ese número en su serie',
			self::KIND_EDICION_ESPECIAL    => 'Edición especial del mismo tomo',
			self::KIND_PORTADA_ALTERNATIVA => 'Portada alternativa del mismo tomo',
			self::KIND_REEDICION           => 'Reedición del mismo tomo',
		);
	}

	/**
	 * Devuelve un valor del enum. Cualquier otra cosa es un tomo normal.
	 *
	 * @param mixed $raw Valor crudo (formulario, postmeta o WP-CLI).
	 */
	public static function sanitize_kind( $raw ): string {
		$value = is_string( $raw ) ? trim( $raw ) : '';

		return array_key_exists( $value, self::kinds() ) ? $value : self::KIND_NONE;
	}

	/**
	 * ¿Un valor YA leído (por SQL, por ejemplo) marca una variante?
	 *
	 * Las dos consultas que leen la marca en lote —la navegación de la ficha y
	 * el agregado de la landing— la traen en la propia fila para no pagar un
	 * `get_post_meta()` por tomo. Comparan con este método y no con `'' !==`
	 * para que el saneo siga viviendo en un solo sitio.
	 *
	 * @param mixed $raw Valor crudo del meta.
	 */
	public static function is_variant_kind( $raw ): bool {
		return self::KIND_NONE !== self::sanitize_kind( $raw );
	}

	/**
	 * Los valores del enum que SÍ marcan una variante.
	 *
	 * Existe para los lectores que preguntan en SQL: la marca viaja en la propia
	 * fila de dos consultas en lote —el agregado de la landing y la navegación de
	 * la ficha— y comparar allí «distinto de vacío» convertía en variante
	 * cualquier tipeo (`edicion-especial`, `1`, `yes`), justo lo que
	 * `sanitize_kind()` descarta. Con esta lista, PHP y SQL contestan lo mismo.
	 *
	 * @return array<int, string>
	 */
	public static function variant_kinds(): array {
		$kinds = self::kinds();
		unset( $kinds[ self::KIND_NONE ] );

		return array_values( array_keys( $kinds ) );
	}

	/**
	 * Condición SQL «esta fila es un TOMO NORMAL», con placeholders `%s`.
	 *
	 * El caller pega el resultado en su consulta y añade `variant_kinds()` a los
	 * argumentos de `prepare()` EN ESA POSICIÓN: son tantos `%s` como valores
	 * tenga el enum, así que agregar un tipo nuevo no obliga a tocar la consulta.
	 *
	 * `TRIM` porque `sanitize_kind()` también recorta, y una importación deja
	 * espacios con facilidad. La equivalencia no es total —`TRIM` de MySQL sólo
	 * quita espacios y el de PHP también tabuladores y saltos—, pero el sentido
	 * es el mismo y el residuo (un meta con un tabulador delante) no lo produce
	 * ninguna de las dos vías de escritura que existen: el panel y WP-CLI.
	 *
	 * @param string $column Columna ya cualificada, p. ej. `vm.meta_value`.
	 */
	public static function sql_normal_volume_condition( string $column ): string {
		$placeholders = implode( ', ', array_fill( 0, count( self::variant_kinds() ), '%s' ) );

		return "TRIM( COALESCE( {$column}, '' ) ) NOT IN ( {$placeholders} )";
	}

	/** Tipo de variante de un producto, o cadena vacía si es un tomo normal. */
	public static function kind_of( int $product_id ): string {
		if ( $product_id < 1 || ! function_exists( 'get_post_meta' ) ) {
			return self::KIND_NONE;
		}

		return self::sanitize_kind( get_post_meta( $product_id, self::META_KEY, true ) );
	}

	/** ¿Este producto es otra versión de un tomo que la serie ya tiene? */
	public static function is_variant( int $product_id ): bool {
		return self::KIND_NONE !== self::kind_of( $product_id );
	}
}
