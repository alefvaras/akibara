<?php
/**
 * BrandTokens — identificación de tokens asociados a marcas o editoriales en búsquedas.
 *
 * El motor por defecto intersecta todos los tokens ingresados. Cuando un usuario
 * incluye una editorial distinta a la que edita la serie en catálogo (ej. «jujutsu kaisen ivrea»
 * cuando está editada por Panini), la intersección estricta devolvería cero resultados.
 *
 * Esta clase determina qué tokens pueden relajarse de forma segura para reintentar la búsqueda:
 *   1) El token identifica a una marca/editorial del catálogo (`product_brand`).
 *   2) El token NO forma parte del vocabulario de títulos, series, autores ni SKUs del catálogo.
 *
 * La segunda regla previene soltar términos cuando coinciden con el título de una obra.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.47
 */
final class BrandTokens {

	/** Caché por generación del índice: el mismo sello que el vocabulario del fuzzy. */
	public const TRANSIENT = 'akb_search_brand_tokens';

	/** Tope de tokens distintos; con 8 editoriales sobran de largo. */
	private const MAX_TOKENS = 60;

	/**
	 * Un token de una sola letra o dos nunca se suelta: «ar» o «es» son ruido
	 * y soltarlos cambiaría búsquedas legítimas.
	 */
	private const MIN_LENGTH = 3;

	/** @var array<int, string>|null Memo por request. */
	private static ?array $memo = null;

	/**
	 * Los tokens soltables, en minúsculas normalizadas.
	 *
	 * Devuelve lista vacía ante cualquier duda (sin taxonomía, sin índice,
	 * lectura rota): sin lista no hay reintento y el buscador se comporta
	 * exactamente como antes. Fallar cerrado aquí sólo cuesta un cero que ya
	 * ocurría.
	 *
	 * @param \wpdb|null $db Conexión; por defecto la global.
	 * @return array<int, string>
	 */
	public static function droppable( $db = null ): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		$stamp = self::stamp();
		$cached = self::cached( $stamp );
		if ( null !== $cached ) {
			self::$memo = $cached;
			return self::$memo;
		}

		$nombres = self::brand_names();
		if ( array() === $nombres ) {
			return array(); // Sin memo: puede ser un request temprano, no una respuesta.
		}

		$candidatos = self::tokens_from( $nombres );
		if ( array() === $candidatos ) {
			return array();
		}

		$usados = self::tokens_used_in_content( $db, $candidatos );
		if ( null === $usados ) {
			return array(); // Lectura no autoritativa: sin reintento.
		}

		$soltables = array_values( array_diff( $candidatos, $usados ) );
		sort( $soltables );
		$soltables = array_slice( $soltables, 0, self::MAX_TOKENS );

		self::$memo = $soltables;
		self::store( $stamp, $soltables );

		return self::$memo;
	}

	/**
	 * Los tokens de la consulta que nombran editorial, en el orden en que se
	 * escribieron (para poder decirle al cliente qué se ignoró).
	 *
	 * @param array<int, string> $query_tokens Tokens de la consulta.
	 * @param \wpdb|null         $db           Conexión.
	 * @return array<int, string>
	 */
	public static function found_in( array $query_tokens, $db = null ): array {
		$soltables = self::droppable( $db );
		if ( array() === $soltables ) {
			return array();
		}

		$out = array();
		foreach ( $query_tokens as $token ) {
			if ( is_string( $token ) && in_array( $token, $soltables, true ) && ! in_array( $token, $out, true ) ) {
				$out[] = $token;
			}
		}

		return $out;
	}

	/**
	 * Olvida lo memorizado.
	 *
	 * En runtime no hace falta llamarla: la caché va sellada con la generación
	 * del índice (`stamp()`), así que un reindexado la invalida sola. La usan
	 * los tests, y queda como salida de emergencia si algún día hay que forzar
	 * el recálculo sin esperar a la próxima generación.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$memo = null;
		try {
			delete_transient( self::TRANSIENT );
		} catch ( \Throwable $throwable ) {
			// Caché best-effort.
		}
	}

	/**
	 * Nombres de las editoriales con productos publicados.
	 *
	 * @return array<int, string>
	 */
	private static function brand_names(): array {
		if ( ! function_exists( 'get_terms' ) ) {
			return array();
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
			return array();
		}

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$out = array();
		foreach ( $terms as $name ) {
			if ( is_string( $name ) && '' !== $name ) {
				$out[] = $name;
			}
		}

		return $out;
	}

	/**
	 * Trocea los nombres en tokens normalizados, con la MISMA normalización que
	 * usa el motor (si no, «España» del nombre no casaría con `espana` tecleado).
	 *
	 * @param array<int, string> $nombres Nombres de editorial.
	 * @return array<int, string>
	 */
	private static function tokens_from( array $nombres ): array {
		$out = array();
		foreach ( $nombres as $nombre ) {
			foreach ( Normalizer::tokenize( $nombre ) as $token ) {
				if ( strlen( $token ) >= self::MIN_LENGTH && ! in_array( $token, $out, true ) ) {
					$out[] = $token;
				}
			}
		}

		return $out;
	}

	/**
	 * Cuáles de esos tokens aparecen en título, serie, autor o SKU. Esos NO se
	 * sueltan nunca: son parte de lo que el cliente busca, no un filtro.
	 *
	 * @param \wpdb|null         $db         Conexión.
	 * @param array<int, string> $candidatos Tokens a comprobar.
	 * @return array<int, string>|null Null si la lectura no es de fiar.
	 */
	private static function tokens_used_in_content( $db, array $candidatos ): ?array {
		if ( null === $db ) {
			$db = $GLOBALS['wpdb'] ?? null;
		}
		if ( ! is_object( $db ) || ! method_exists( $db, 'get_col' ) || ! method_exists( $db, 'prepare' ) ) {
			return null;
		}

		$tabla   = Install::tokens_table();
		$campos  = array( DocumentBuilder::FIELD_TITLE, DocumentBuilder::FIELD_SERIE, DocumentBuilder::FIELD_AUTOR, DocumentBuilder::FIELD_SKU );
		$huecos  = implode( ',', array_fill( 0, count( $candidatos ), '%s' ) );
		$campos_ = implode( ',', array_fill( 0, count( $campos ), '%d' ) );

		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- el nombre de tabla viene de Install; los valores van por placeholders.
			$sql = $db->prepare(
				"SELECT DISTINCT token FROM {$tabla} WHERE token IN ({$huecos}) AND field IN ({$campos_})",
				array_merge( $candidatos, $campos )
			);
		} catch ( \Throwable $throwable ) {
			return null;
		}

		if ( ! is_string( $sql ) || '' === $sql ) {
			return null;
		}

		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparada arriba.
			$rows = $db->get_col( $sql );
		} catch ( \Throwable $throwable ) {
			return null;
		}

		if ( ! is_array( $rows ) ) {
			return null;
		}
		if ( isset( $db->last_error ) && is_string( $db->last_error ) && '' !== $db->last_error ) {
			return null;
		}

		$out = array();
		foreach ( $rows as $token ) {
			if ( is_string( $token ) && '' !== $token ) {
				$out[] = $token;
			}
		}

		return $out;
	}

	/**
	 * @return string
	 */
	private static function stamp(): string {
		$generation = SemanticPublication::authoritative_value( Install::OPTION_INDEX_GEN, '0' );
		if ( ! SemanticPublication::authoritative_available() || ! is_scalar( $generation ) ) {
			return '';
		}

		return hash( 'sha256', (string) $generation );
	}

	/**
	 * @param string $stamp Sello de la generación del índice.
	 * @return array<int, string>|null
	 */
	private static function cached( string $stamp ): ?array {
		if ( '' === $stamp ) {
			return null;
		}

		try {
			$cached = get_transient( self::TRANSIENT );
		} catch ( \Throwable $throwable ) {
			return null;
		}

		if ( ! is_array( $cached ) || ! isset( $cached['stamp'], $cached['data'] ) ) {
			return null;
		}
		if ( ! is_string( $cached['stamp'] ) || ! hash_equals( $stamp, $cached['stamp'] ) || ! is_array( $cached['data'] ) ) {
			return null;
		}

		$out = array();
		foreach ( $cached['data'] as $token ) {
			if ( ! is_string( $token ) || '' === $token ) {
				return null;
			}
			$out[] = $token;
		}

		return $out;
	}

	/**
	 * @param string             $stamp     Sello.
	 * @param array<int, string> $soltables Tokens.
	 * @return void
	 */
	private static function store( string $stamp, array $soltables ): void {
		if ( '' === $stamp ) {
			return;
		}

		try {
			set_transient( self::TRANSIENT, array( 'stamp' => $stamp, 'data' => $soltables ), DAY_IN_SECONDS );
		} catch ( \Throwable $throwable ) {
			// Caché best-effort.
		}
	}
}
