<?php
/**
 * EmptyStateCopy — lo que lee el cliente cuando la tienda no encuentra nada.
 *
 * Es el momento en que la tienda le falla: la única frase que puede recuperar
 * la venta (pedir por WhatsApp, encargar el tomo, mirar lo que llega este mes)
 * estaba escrita a mano en dos archivos, uno de ellos un bundle JavaScript que
 * en producción se sirve MINIFICADO. Cambiarla exigía tocar código y volver a
 * generar el `.min`.
 *
 * Tres momentos y por eso TRES campos, no uno:
 *
 *   - el catálogo y los filtros, donde la pista termina en un botón que limpia
 *     los filtros aplicados y la búsqueda;
 *   - una búsqueda que SÍ encuentra productos y que los filtros marcados dejan
 *     en cero («frieren» con el género «terror»): ahí el botón quita los
 *     filtros y conserva lo escrito. Con la pista general, el aviso culpaba a
 *     los filtros y su «limpia todo» se llevaba también la búsqueda: el cliente
 *     acababa en la tienda entera;
 *   - el desplegable del buscador, donde no hay filtros que limpiar y la frase
 *     de hoy es otra.
 *
 * Un solo campo habría cambiado el texto servido en el desplegable el mismo día
 * que se estrenó la pantalla, o habría metido un botón «limpia todo» donde no
 * hay nada que limpiar.
 *
 * Los TÍTULOS («Sin resultados para «X».») no se tocan: son el hecho, no el
 * mensaje comercial, y llevan el término interpolado.
 *
 * Desde 0.1.75 el aviso del catálogo ofrece además la serie que más se parece a
 * lo escrito y lo más buscado (`EmptyStateSuggestions`). Sus dos rótulos viven
 * aquí por la misma razón que las pistas: son texto que el cliente lee y el
 * dueño tiene que poder cambiar sin tocar código.
 *
 * Y el aviso del otro momento en que el catálogo le falla al cliente: un
 * filtro, el orden o una página de resultados que no llega a cargar porque se
 * cortó la conexión (REV-C-S-1o-1 y 1o-2). Mismas reglas que las pistas: texto
 * plano, y lo que va entre corchetes es su botón, «Reintentar».
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.41
 */
final class EmptyStateCopy {

	/** Pista del catálogo y de los filtros. */
	public const OPTION_CATALOG = 'akb_search_empty_hint_catalog';

	/** Pista del desplegable del buscador. */
	public const OPTION_SUGGEST = 'akb_search_empty_hint_suggest';

	/** Pista cuando los filtros vacían una búsqueda que sí encuentra productos. */
	public const OPTION_FILTERS = 'akb_search_empty_hint_filters';

	/**
	 * El literal que servía `facets-render.php`. Lo que va entre CORCHETES es la
	 * etiqueta del botón que limpia los filtros: así el dueño puede reescribir la
	 * frase entera —o mover el botón— sin escribir una línea de HTML, y sin que
	 * un `<button>` pegado a mano acabe en la página.
	 */
	public const DEFAULT_CATALOG = 'Prueba con menos filtros, el nombre de la serie, o [limpia todo].';

	/** El literal que servía `search-suggest.js`. */
	public const DEFAULT_SUGGEST = 'Prueba con el nombre de la serie.';

	/**
	 * El título ya dice que son los filtros; la pista dice que lo escrito sí
	 * existe y el botón, entre corchetes, lo muestra sin ellos.
	 */
	public const DEFAULT_FILTERS = 'Tu búsqueda sí tiene resultados: [quita los filtros] para verlos.';

	/**
	 * Valor de `data-akb-clear` del botón que quita los filtros y conserva la
	 * búsqueda (`akb-search.js`). Sin valor, el botón lo limpia todo.
	 */
	public const CLEAR_FILTERS = 'filtros';

	/** Rótulo de la serie que el motor ofrece cuando una búsqueda da cero. */
	public const OPTION_SERIES_LABEL = 'akb_search_empty_series_label';

	/** Rótulo de las búsquedas frecuentes que se ofrecen en el mismo aviso. */
	public const OPTION_POPULAR_LABEL = 'akb_search_empty_popular_label';

	/** Sirve igual con una serie que con dos: no concuerda en número con nada. */
	public const DEFAULT_SERIES_LABEL = 'Quizá buscabas';

	/** El mismo nombre que el bloque del desplegable, visto desde el cliente. */
	public const DEFAULT_POPULAR_LABEL = 'Lo más buscado';

	/** Aviso de un filtro que no carga por la conexión. */
	public const OPTION_NETWORK = 'akb_search_network_notice';

	/**
	 * Dice qué pasó sin culpar a nadie; entre corchetes, el botón que vuelve a
	 * pedir lo que el cliente eligió.
	 */
	public const DEFAULT_NETWORK = 'No pudimos cargar los productos por un problema de conexión. [Reintentar]';

	/** Tope: una pista más larga que esto ya no se lee, se salta. */
	public const MAX_LENGTH = 300;

	/** Pista vigente del catálogo, en texto plano (con sus corchetes). */
	public static function catalog(): string {
		return self::stored( self::OPTION_CATALOG, self::DEFAULT_CATALOG );
	}

	/** Pista vigente del desplegable, en texto plano. */
	public static function suggest(): string {
		return self::stored( self::OPTION_SUGGEST, self::DEFAULT_SUGGEST );
	}

	/** Pista vigente de una búsqueda vaciada por los filtros, en texto plano (con sus corchetes). */
	public static function filters(): string {
		return self::stored( self::OPTION_FILTERS, self::DEFAULT_FILTERS );
	}

	/** Rótulo vigente de la serie sugerida, en texto plano. */
	public static function series_label(): string {
		return self::stored( self::OPTION_SERIES_LABEL, self::DEFAULT_SERIES_LABEL );
	}

	/** Rótulo vigente de lo más buscado, en texto plano. */
	public static function popular_label(): string {
		return self::stored( self::OPTION_POPULAR_LABEL, self::DEFAULT_POPULAR_LABEL );
	}

	/** Aviso vigente de un filtro que no carga por la conexión, en texto plano (con sus corchetes). */
	public static function network(): string {
		return self::stored( self::OPTION_NETWORK, self::DEFAULT_NETWORK );
	}

	/**
	 * Pista del catálogo ya en HTML, con el tramo entre corchetes convertido en
	 * el botón que limpia los filtros.
	 *
	 * Sólo se honra el PRIMER par de corchetes: dos botones «limpia todo» en la
	 * misma frase no significan nada, y aceptarlos convertiría un tipeo en una
	 * interfaz rota. Sin corchetes, la pista queda como texto y el aviso pierde
	 * el botón — que es una decisión legítima, no un error.
	 */
	public static function catalog_html(): string {
		return self::hint_html( self::catalog(), 'data-akb-clear' );
	}

	/**
	 * Pista de una búsqueda vaciada por los filtros, ya en HTML: el tramo entre
	 * corchetes es el botón que quita los filtros y CONSERVA la búsqueda. Mismas
	 * reglas que `catalog_html()`.
	 */
	public static function filters_html(): string {
		return self::hint_html( self::filters(), 'data-akb-clear="' . esc_attr( self::CLEAR_FILTERS ) . '"' );
	}

	/**
	 * Aviso de un filtro que no carga por la conexión, ya en HTML: el tramo
	 * entre corchetes es el botón que vuelve a pedir lo que el cliente eligió
	 * (`data-akb-reintentar`, que atiende `akb-search.js`). Mismas reglas que
	 * `catalog_html()`: sin corchetes el aviso queda sin botón, y el cliente
	 * sigue pudiendo volver a tocar el filtro, que navega sin PJAX.
	 */
	public static function network_html(): string {
		return self::hint_html( self::network(), 'data-akb-reintentar' );
	}

	/**
	 * Una pista en HTML: el primer par de corchetes se convierte en el botón.
	 *
	 * @param string $hint      Pista en texto plano, con sus corchetes.
	 * @param string $attribute Atributo que dice qué hace el botón (`data-akb-clear`,
	 *                          `data-akb-clear="filtros"`, `data-akb-reintentar`), ya
	 *                          escapado: sale tal cual.
	 * @return string HTML escapado.
	 */
	private static function hint_html( string $hint, string $attribute ): string {
		if ( 1 !== preg_match( '/^(.*?)\[([^\]]*)\](.*)$/s', $hint, $matches ) ) {
			return esc_html( $hint );
		}

		$label = trim( $matches[2] );
		if ( '' === $label ) {
			return esc_html( $matches[1] . $matches[3] );
		}

		return esc_html( $matches[1] )
			. '<button type="button" class="akb-link" ' . $attribute . '>' . esc_html( $label ) . '</button>'
			. esc_html( $matches[3] );
	}

	/**
	 * Normaliza una pista al guardarla.
	 *
	 * Se aplana a una línea y se le quitan las etiquetas: la del catálogo viaja
	 * a un `<p>` y la del desplegable a una cadena de JavaScript, así que HTML
	 * pegado a mano no puede terminar inyectado en ninguna de las dos. Vacía cae
	 * al literal de siempre — un aviso «no encontramos nada» sin ninguna salida
	 * es peor que uno genérico.
	 *
	 * El panel detecta si el texto guardado difiere del ingresado por el
	 * usuario (por exceder longitud máxima o contener etiquetas HTML no
	 * permitidas) para notificarlo explícitamente en el aviso de guardado.
	 *
	 * @param mixed     $raw           Candidato crudo del formulario.
	 * @param string    $fallback      Literal al que caer.
	 * @param bool|null $recortado     Sale true si hubo que cortarlo al tope.
	 * @param bool|null $sin_etiquetas Sale true si se le quitaron etiquetas
	 *                                 (o algo que `strip_tags` toma por una,
	 *                                 como «<3»).
	 */
	public static function sanitize( $raw, string $fallback, ?bool &$recortado = null, ?bool &$sin_etiquetas = null ): string {
		$recortado     = false;
		$sin_etiquetas = false;
		if ( ! is_string( $raw ) ) {
			return $fallback;
		}

		$stripped = wp_strip_all_tags( $raw );
		$clean    = trim( (string) preg_replace( '/\s+/u', ' ', $stripped ) );

		if ( '' === $clean ) {
			return $fallback;
		}
		// `wp_strip_all_tags()` sólo recorta los bordes cuando no hay nada que
		// quitar: cualquier otra diferencia es texto que el dueño escribió y no
		// quedó.
		$sin_etiquetas = trim( $raw ) !== $stripped;

		if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) ) {
			if ( mb_strlen( $clean, 'UTF-8' ) > self::MAX_LENGTH ) {
				$clean     = rtrim( mb_substr( $clean, 0, self::MAX_LENGTH, 'UTF-8' ) );
				$recortado = true;
			}
		} elseif ( strlen( $clean ) > self::MAX_LENGTH ) {
			$clean     = rtrim( substr( $clean, 0, self::MAX_LENGTH ) );
			$recortado = true;
		}

		// Aplicado únicamente si hubo recorte: un carácter '{' en texto que cumple
		// la longitud permitida puede corresponder a un emoticón o signo válido.
		// Sólo ante un recorte forzado indica un marcador potencialmente trunco.
		return $recortado ? self::drop_cut_placeholder( $clean, $fallback ) : $clean;
	}

	/**
	 * Un recorte no puede dejar un marcador a medias.
	 *
	 * Las plantillas de aviso soportan marcadores como `{editorial}` y `{busqueda}`.
	 * Si la longitud máxima corta el texto en mitad de un marcador, se elimina
	 * desde la llave de apertura para evitar mostrar sintaxis trunca al cliente.
	 *
	 * @param string $clean    Texto ya saneado y recortado.
	 * @param string $fallback Literal al que caer si no queda nada.
	 * @return string
	 */
	private static function drop_cut_placeholder( string $clean, string $fallback ): string {
		$abre   = strrpos( $clean, '{' );
		$cierra = strrpos( $clean, '}' );
		if ( false === $abre || ( false !== $cierra && $cierra > $abre ) ) {
			return $clean;
		}

		$clean = rtrim( substr( $clean, 0, $abre ) );

		return '' === $clean ? $fallback : $clean;
	}

	/**
	 * Valor guardado ya normalizado. Se sanea también AL LEER porque la option
	 * puede llegar por `wp option update`, que no pasa por el formulario.
	 */
	private static function stored( string $option, string $fallback ): string {
		return self::sanitize( get_option( $option, $fallback ), $fallback );
	}
}
