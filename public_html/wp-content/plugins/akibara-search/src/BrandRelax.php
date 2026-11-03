<?php
/**
 * BrandRelax — el aviso de «esa editorial no, pero mira lo que sí tenemos».
 *
 * Cuando una búsqueda devuelve cero porque el cliente nombró una editorial que
 * no tiene esa serie, el motor suelta ese término y reintenta. Entonces el
 * cliente ve resultados que NO pidió literalmente, y hay que decírselo: sin el
 * aviso, «jujutsu kaisen ivrea» devolvería tomos de Panini sin explicar por qué,
 * que es peor que el cero. La mitad del valor de una recuperación es contarla.
 *
 * Dos superficies, como el fuzzy: el desplegable se lleva el aviso en el JSON
 * de su respuesta (atómico, sin side-channel que otro caller pueda pisar) y el
 * catálogo lo lee de aquí, del estático, en el mismo request.
 *
 * El texto lo escribe el dueño en Textos. Nunca pasa por sprintf: un «%» suyo
 * sería un fatal en la página de resultados.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.47
 */
final class BrandRelax {

	/** Texto editable del aviso. */
	public const OPTION_NOTICE = 'akb_search_relaxed_notice';

	/**
	 * Marcadores: `{editorial}` es lo que se ignoró, `{busqueda}` lo que quedó.
	 * Con `str_replace`, jamás con sprintf.
	 */
	public const DEFAULT_NOTICE = 'No tenemos {busqueda} de {editorial}. Esto es lo que tenemos de {busqueda}.';

	/** @var array{ignored: array<int, string>, kept: string}|null */
	private static ?array $last = null;

	/**
	 * Guarda lo que la búsqueda de catálogo tuvo que soltar.
	 *
	 * @param array{ignored: array<int, string>, kept: string} $relaxed Lo soltado y lo que quedó.
	 * @return void
	 */
	public static function note( array $relaxed ): void {
		self::$last = $relaxed;
	}

	/**
	 * Lo soltado en esta request, o null.
	 *
	 * @return array{ignored: array<int, string>, kept: string}|null
	 */
	public static function last(): ?array {
		return self::$last;
	}

	/**
	 * Reset (tests, e inicio de cada búsqueda de catálogo).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$last = null;
	}

	/**
	 * El aviso ya escrito, listo para pintar.
	 *
	 * @param array<int, string> $ignored Términos de editorial soltados.
	 * @param string             $kept    Lo que sí se buscó.
	 * @return string Vacío si no hay nada que decir.
	 */
	public static function sentence( array $ignored, string $kept ): string {
		$editorial = self::human_list( $ignored );
		$kept      = trim( $kept );
		if ( '' === $editorial || '' === $kept ) {
			return '';
		}

		$plantilla = self::template();

		return trim(
			str_replace(
				array( '{editorial}', '{busqueda}' ),
				array( $editorial, $kept ),
				$plantilla
			)
		);
	}

	/**
	 * El aviso con las dos piezas en negrita, listo para pintar.
	 *
	 * El dueño escribe texto plano y el sistema decide el énfasis: la editorial
	 * que no tenemos y el título que sí, que es lo que el ojo tiene que
	 * encontrar de un vistazo. Todo se escapa antes de envolver, así que un «<»
	 * suyo sale como texto y nunca como etiqueta.
	 *
	 * @param array<int, string> $ignored Términos de editorial soltados.
	 * @param string             $kept    Lo que sí se buscó.
	 * @return string HTML con `<strong>`, o vacío.
	 */
	public static function sentence_html( array $ignored, string $kept ): string {
		$editorial = self::human_list( $ignored );
		$kept      = trim( $kept );
		if ( '' === $editorial || '' === $kept ) {
			return '';
		}

		return trim(
			str_replace(
				array( '{editorial}', '{busqueda}' ),
				array(
					'<strong class="akb-relaxed__brand">' . esc_html( $editorial ) . '</strong>',
					'<strong>' . esc_html( $kept ) . '</strong>',
				),
				esc_html( self::template() )
			)
		);
	}

	/**
	 * La plantilla vigente: la del dueño si la escribió, el literal si no.
	 *
	 * @return string
	 */
	public static function template(): string {
		$raw = '';
		try {
			$stored = get_option( self::OPTION_NOTICE, '' );
			$raw    = is_string( $stored ) ? $stored : '';
		} catch ( \Throwable $throwable ) {
			$raw = '';
		}

		// Reutiliza `EmptyStateCopy::sanitize()` para asegurar consistencia exacta
		// de sanitización y longitud entre la persistencia en el panel y la lectura en runtime.
		return EmptyStateCopy::sanitize( $raw, self::DEFAULT_NOTICE );
	}

	/**
	 * «ivrea» → «Ivrea»; «ivrea, espana» → «Ivrea España». Los términos vienen
	 * normalizados (sin tildes, en minúsculas) porque así los compara el motor,
	 * pero el cliente lee su editorial escrita como se escribe.
	 *
	 * @param array<int, string> $ignored Tokens soltados.
	 * @return string
	 */
	private static function human_list( array $ignored ): string {
		$limpios = array();
		foreach ( $ignored as $token ) {
			if ( ! is_string( $token ) || '' === trim( $token ) ) {
				continue;
			}
			$limpios[] = self::pretty( trim( $token ) );
		}
		$limpios = array_values( array_unique( $limpios ) );

		return implode( ' ', $limpios );
	}

	/**
	 * Devuelve el token con la grafía real de la editorial cuando se reconoce
	 * («espana» → «España»), y capitalizado si no.
	 *
	 * @param string $token Token normalizado.
	 * @return string
	 */
	private static function pretty( string $token ): string {
		foreach ( self::brand_words() as $palabra ) {
			if ( Normalizer::normalize( $palabra ) === $token ) {
				return $palabra;
			}
		}

		return function_exists( 'mb_convert_case' )
			? mb_convert_case( $token, MB_CASE_TITLE, 'UTF-8' )
			: ucfirst( $token );
	}

	/**
	 * Palabras tal como están escritas en los nombres de las editoriales.
	 *
	 * @return array<int, string>
	 */
	private static function brand_words(): array {
		static $words = null;
		if ( is_array( $words ) ) {
			return $words;
		}

		$words = array();
		if ( ! function_exists( 'get_terms' ) ) {
			return $words;
		}

		try {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_brand',
					'hide_empty' => true,
					'fields'     => 'names',
				)
			);
		} catch ( \Throwable $throwable ) {
			return $words;
		}

		if ( ! is_array( $terms ) ) {
			return $words;
		}

		foreach ( $terms as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			foreach ( preg_split( '/\s+/u', $name ) ?: array() as $palabra ) {
				if ( is_string( $palabra ) && '' !== $palabra && ! in_array( $palabra, $words, true ) ) {
					$words[] = $palabra;
				}
			}
		}

		return $words;
	}
}
