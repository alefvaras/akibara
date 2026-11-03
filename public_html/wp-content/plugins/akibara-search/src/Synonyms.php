<?php
/**
 * Synonyms — expansión de sinónimos en tiempo de query (paridad Relevanssi, sin
 * su implementación). Cada GRUPO es una lista de TÉRMINOS equivalentes y cada
 * término conserva sus límites de frase. Así "opm" equivale a la secuencia
 * "one punch man", nunca a los tokens sueltos "one", "punch" o "man".
 *
 * Config: option `akb_search_synonyms` (editable desde el admin). Si no hay
 * option, se usa el mapa curado por defecto. La expansión es query-time: NO
 * requiere reindexar al cambiar los grupos.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class Synonyms {

	public const OPTION = 'akb_search_synonyms';

	/** Máximo de rewrites para evitar explosión combinatoria entre grupos. */
	private const MAX_QUERY_ALTERNATIVES = 16;

	/**
	 * Presupuesto ponderado para la consulta SQL factorizada. El término original
	 * cuesta lineal; cada rewrite cuesta tokens² porque una frase larga agrega
	 * self-JOINs y multiplica el costo mucho más que un alias de un token.
	 */
	private const MAX_QUERY_COST = 32;

	/** Tope defensivo de términos equivalentes dentro de un solo segmento. */
	private const MAX_SEGMENT_CHOICES = 8;

	/** Límites públicos: la UI administrativa aplica exactamente este contrato. */
	public const MAX_GROUPS = 64;
	public const MAX_TERMS_PER_GROUP = 8;
	public const MAX_TERM_LENGTH = 128;

	/** Máximo de palabras por término configurado, contando también las de relleno. */
	public const MAX_TERM_TOKENS = 4;

	/** @var array<string, array{tokens: array<int, string>, query: string}>|null Términos por clave de frase. */
	private static ?array $terms = null;

	/** @var array<string, array<int, string>>|null Grafo de equivalencias entre términos completos. */
	private static ?array $adjacency = null;

	/** @var array<int, string>|null Claves ordenadas por frase más larga primero. */
	private static ?array $ordered_keys = null;

	/**
	 * Grupos por defecto, curados para manga/cómics es-CL. Cada grupo = términos
	 * equivalentes (romaji, localizado, abreviatura). Editables desde el admin.
	 *
	 * @return array<int, array<int, string>>
	 */
	public static function defaults(): array {
		return array(
			// «shingeki» suelto cubre búsquedas parciales coloquiales habituales.
			// Unificación en un solo grupo: dos grupos que comparten un término
			// se fusionan semánticamente en el motor (ver shared_terms()).
			array( 'aot', 'snk', 'attack on titan', 'shingeki no kyojin', 'ataque a los titanes', 'shingeki' ),
			array( 'jjk', 'jujutsu kaisen' ),
			array( 'hxh', 'hunter x hunter' ),
			array( 'mha', 'bnha', 'my hero academia', 'boku no hero academia' ),
			array( 'opm', 'one punch man' ),
			array( 'kimetsu no yaiba', 'demon slayer', 'guardianes de la noche' ),
			array( 'tomo', 'volumen', 'vol' ),
			array( 'comic', 'comics', 'historieta' ),
		);
	}

	/**
	 * Grupos vigentes (option del admin, o defaults).
	 *
	 * @return array<int, array<int, string>>
	 */
	public static function groups(): array {
		$stored = SemanticPublication::authoritative_value( self::OPTION, null );
		return self::sanitize_groups( is_array( $stored ) ? $stored : self::defaults() );
	}

	/**
	 * Sanitiza y acota el contrato persistido. Centralizarlo evita que la UI
	 * anuncie como guardado un término que el motor luego ignore por hardening.
	 *
	 * @param array<int, mixed> $groups    Grupos crudos.
	 * @param bool|null         $truncated Se marca true si hubo que recortar.
	 * @return array<int, array<int, string>>
	 */
	public static function sanitize_groups( array $groups, ?bool &$truncated = null ): array {
		$truncated = count( $groups ) > self::MAX_GROUPS;
		$out       = array();

		foreach ( array_slice( $groups, 0, self::MAX_GROUPS ) as $group ) {
			if ( ! is_array( $group ) ) {
				$truncated = true;
				continue;
			}
			if ( count( $group ) > self::MAX_TERMS_PER_GROUP ) {
				$truncated = true;
			}

			$terms = array();
			$seen  = array();
			foreach ( array_slice( $group, 0, self::MAX_TERMS_PER_GROUP ) as $term ) {
				if ( ! is_string( $term ) && ! is_numeric( $term ) ) {
					$truncated = true;
					continue;
				}

				$clean = trim( sanitize_text_field( (string) $term ) );
				$short = mb_substr( $clean, 0, self::MAX_TERM_LENGTH );
				if ( $short !== $clean || $clean !== (string) $term ) {
					$truncated = true;
				}
				if ( '' === $short ) {
					$truncated = true;
					continue;
				}
				// La MISMA regla que aplica el lector: un término que el panel
				// guarda y el lector rechaza invalida la configuración entera y
				// apaga a la vez indexación, sugerencias y filtros.
				if ( ! self::term_is_canonical( $short ) ) {
					$truncated = true;
					continue;
				}

				$key = mb_strtolower( $short );
				if ( isset( $seen[ $key ] ) ) {
					$truncated = true;
					continue;
				}
				$seen[ $key ] = true;
				$terms[]      = $short;
			}

			if ( count( $terms ) >= 2 ) {
				$out[] = $terms;
			} else {
				$truncated = true;
			}
		}

		return $out;
	}

	/**
	 * Valida que los bytes persistidos sean ya la forma exacta que consumirá el
	 * motor. Evita autorizar un receipt cuyo lector luego omita, normalice o
	 * trunque términos silenciosamente.
	 *
	 * @param array<int, mixed> $groups Configuración decodificada.
	 */
	public static function configuration_is_canonical( array $groups ): bool {
		$keys = array_keys( $groups );
		if ( $keys !== ( empty( $groups ) ? array() : range( 0, count( $groups ) - 1 ) ) ) {
			return false;
		}
		if ( count( $groups ) > self::MAX_GROUPS ) {
			return false;
		}
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || count( $group ) < 2 || count( $group ) > self::MAX_TERMS_PER_GROUP ) {
				return false;
			}
			$group_keys = array_keys( $group );
			if ( $group_keys !== range( 0, count( $group ) - 1 ) ) {
				return false;
			}
			$seen = array();
			foreach ( $group as $term ) {
				if ( ! self::term_is_canonical( $term ) ) {
					return false;
				}
				$key = mb_strtolower( $term );
				if ( isset( $seen[ $key ] ) ) {
					return false;
				}
				$seen[ $key ] = true;
			}
		}
		return true;
	}

	/**
	 * ¿Este término es de la forma exacta que acepta el motor?
	 *
	 * Única definición de validación compartida estrictamente entre el guardado
	 * del panel administrativo y el lector del motor de búsqueda.
	 * Se cuentan todas las palabras de manera determinista: evaluar únicamente
	 * palabras significativas generaría acoplamiento inestable con la lista de
	 * stopwords editables, arriesgando desalineaciones entre lectura y guardado.
	 *
	 * @param mixed $term Término.
	 */
	private static function term_is_canonical( $term ): bool {
		if ( ! is_string( $term ) || $term !== trim( $term ) || '' === $term
			|| mb_strlen( $term ) > self::MAX_TERM_LENGTH
			|| 1 === preg_match( '/[\x00-\x1F\x7F<>]/', $term ) ) {
			return false;
		}

		$normalized = Normalizer::normalize( $term );
		$tokens     = '' === $normalized ? array() : explode( ' ', $normalized );

		return array() !== $tokens && count( $tokens ) <= self::MAX_TERM_TOKENS;
	}

	/**
	 * Segmenta una query en cláusulas AND cuyos términos equivalentes son OR.
	 * La forma conserva la factorización y evita ejecutar el producto
	 * cartesiano como consultas SQL independientes.
	 *
	 * @param array<int, string> $tokens Tokens significativos en orden, con duplicados.
	 * @return array<int, array<int, array{tokens: array<int, string>, query: string}>>
	 *
	 * @since 0.1.5
	 */
	public static function query_segments( array $tokens ): array {
		$tokens = array_values(
			array_filter(
				array_map( 'strval', $tokens ),
				static fn( string $token ): bool => '' !== $token
			)
		);
		if ( empty( $tokens ) ) {
			return array();
		}

		self::build_index();

		$segments = array();
		$offset   = 0;
		$count    = count( $tokens );

		while ( $offset < $count ) {
			$matched_key = self::matching_key( $tokens, $offset );
			if ( null === $matched_key ) {
				$segments[] = array(
					array(
						'tokens' => array( $tokens[ $offset ] ),
						'query'  => $tokens[ $offset ],
					),
				);
				++$offset;
				continue;
			}

			$segments[] = self::equivalent_terms( $matched_key );
			$offset    += count( self::$terms[ $matched_key ]['tokens'] );
		}

		return self::limit_segments( $segments );
	}

	/**
	 * Limita la consulta factorizada con un presupuesto ponderado: el original
	 * cuesta linealmente por token y cada rewrite cuesta tokens² para aproximar
	 * sus self-JOINs. Siempre conserva el original; las equivalencias que caben
	 * se distribuyen en rondas con un orden determinista por término, de modo que
	 * reordenar cláusulas AND no cambie qué grupos tardíos conservan sinónimos.
	 *
	 * @param array<int, array<int, array{tokens: array<int, string>, query: string}>> $segments Segmentos sin limitar.
	 * @return array<int, array<int, array{tokens: array<int, string>, query: string}>>
	 */
	private static function limit_segments( array $segments ): array {
		$expandable = array();
		foreach ( $segments as $i => $choices ) {
			if ( count( $choices ) > 1 ) {
				$expandable[] = $i;
			}
		}
		$expandable = array_fill_keys(
			$expandable,
			true
		);

		$limited = array();
		$cursor  = array();
		$cost    = 0;

		foreach ( $segments as $i => $choices ) {
			$choices       = array_slice( array_values( $choices ), 0, self::MAX_SEGMENT_CHOICES );
			$original      = $choices[0];
			$limited[ $i ] = array( $original );
			$cursor[ $i ]  = isset( $expandable[ $i ] ) ? 1 : count( $choices );
			$cost         += self::choice_cost( $original, false );
			$segments[ $i ] = $choices;
		}

		$allocation_order = array_keys( $expandable );
		usort(
			$allocation_order,
			static function ( int $a, int $b ) use ( $segments ): int {
				return strcmp(
					(string) ( $segments[ $a ][0]['query'] ?? '' ),
					(string) ( $segments[ $b ][0]['query'] ?? '' )
				);
			}
		);

		do {
			$advanced = false;
			foreach ( $allocation_order as $i ) {
				if ( ! isset( $segments[ $i ][ $cursor[ $i ] ] ) ) {
					continue;
				}

				$choice      = $segments[ $i ][ $cursor[ $i ] ];
				$choice_cost = self::choice_cost( $choice, true );
				++$cursor[ $i ];
				$advanced = true;

				if ( $cost + $choice_cost > self::MAX_QUERY_COST ) {
					continue;
				}

				$limited[ $i ][] = $choice;
				$cost           += $choice_cost;
			}
		} while ( $advanced && $cost < self::MAX_QUERY_COST );

		ksort( $limited );
		return array_values( $limited );
	}

	/**
	 * Costo estimado de una alternativa. Los rewrites usan tokens² para modelar
	 * el costo de sus self-JOINs; el original conserva costo lineal.
	 *
	 * @param array{tokens?: array<int, string>} $choice  Alternativa.
	 * @param bool                                $rewrite Si es una equivalencia.
	 * @return int
	 */
	private static function choice_cost( array $choice, bool $rewrite ): int {
		$tokens = max( 1, count( array_unique( array_map( 'strval', $choice['tokens'] ?? array() ) ) ) );
		return $rewrite ? $tokens * $tokens : $tokens;
	}

	/**
	 * Reescribe una secuencia de query usando términos completos. Las
	 * equivalencias se combinan por producto cartesiano acotado; el primer
	 * resultado siempre conserva la secuencia original.
	 *
	 * @param array<int, string> $tokens Tokens significativos en orden, con duplicados.
	 * @return array<int, array{tokens: array<int, string>, query: string}>
	 *
	 * @since 0.1.5
	 */
	public static function query_alternatives( array $tokens ): array {
		$segments = self::query_segments( $tokens );
		if ( empty( $segments ) ) {
			return array();
		}

		$states = array(
			array(
				'tokens'      => array(),
				'query_parts' => array(),
			),
		);

		foreach ( $segments as $choices ) {
			$next = array();
			foreach ( $states as $state ) {
				foreach ( $choices as $choice ) {
					$next[] = array(
						'tokens'      => array_merge( $state['tokens'], $choice['tokens'] ),
						'query_parts' => array_merge( $state['query_parts'], array( $choice['query'] ) ),
					);
					if ( count( $next ) >= self::MAX_QUERY_ALTERNATIVES ) {
						break 2;
					}
				}
			}
			$states = $next;
		}

		$out  = array();
		$seen = array();
		foreach ( $states as $state ) {
			$key = implode( "\x1F", $state['tokens'] );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'tokens' => $state['tokens'],
				'query'  => trim( implode( ' ', $state['query_parts'] ) ),
			);
		}

		return $out;
	}

	/**
	 * Construye el grafo término↔término. Los grupos que comparten una frase se
	 * unen transitivamente, sin aplanar jamás los componentes de esa frase.
	 *
	 * @return void
	 */
	private static function build_index(): void {
		if ( null !== self::$terms && null !== self::$adjacency && null !== self::$ordered_keys ) {
			return;
		}

		$terms     = array();
		$adjacency = array();

		foreach ( array_slice( self::groups(), 0, self::MAX_GROUPS ) as $group ) {
			$group_keys = array();
			foreach ( array_slice( (array) $group, 0, self::MAX_TERMS_PER_GROUP ) as $term ) {
				$query  = Normalizer::normalize( mb_substr( (string) $term, 0, self::MAX_TERM_LENGTH ) );
				$tokens = self::significant_tokens( $query );
				if ( '' === $query || empty( $tokens ) ) {
					continue;
				}
				$key = implode( "\x1F", $tokens );
				if ( ! isset( $terms[ $key ] ) ) {
					$terms[ $key ] = array(
						'tokens' => $tokens,
						'query'  => $query,
					);
				}
				$group_keys[ $key ] = $key;
			}

			$group_keys = array_values( $group_keys );
			if ( count( $group_keys ) < 2 ) {
				continue;
			}

			foreach ( $group_keys as $key ) {
				$adjacency[ $key ] = $adjacency[ $key ] ?? array();
				foreach ( $group_keys as $other ) {
					if ( $key !== $other && ! in_array( $other, $adjacency[ $key ], true ) ) {
						$adjacency[ $key ][] = $other;
					}
				}
			}
		}

		$ordered_keys = array_keys( $adjacency );
		usort(
			$ordered_keys,
			static function ( string $a, string $b ) use ( $terms ): int {
				$length = count( $terms[ $b ]['tokens'] ) <=> count( $terms[ $a ]['tokens'] );
				return 0 !== $length ? $length : strcmp( $a, $b );
			}
		);

		self::$terms        = $terms;
		self::$adjacency    = $adjacency;
		self::$ordered_keys = $ordered_keys;
	}

	/**
	 * Tokeniza un término con la misma política significativa del motor,
	 * preservando orden y duplicados para distinguir "hunter x hunter" de
	 * "hunter".
	 *
	 * @param string $normalized Texto normalizado.
	 * @return array<int, string> Como mucho MAX_TERM_TOKENS palabras.
	 */
	private static function significant_tokens( string $normalized ): array {
		$out = array();
		foreach ( explode( ' ', $normalized ) as $token ) {
			if ( '' === $token ) {
				continue;
			}
			if ( strlen( $token ) < 2 && ! ctype_digit( $token ) ) {
				continue;
			}
			if ( ! ctype_digit( $token ) && Stopwords::is_stopword( $token ) ) {
				continue;
			}
			$out[] = $token;
			if ( count( $out ) >= self::MAX_TERM_TOKENS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Términos que para el buscador son EL MISMO y aparecen en más de un grupo.
	 *
	 * Los grupos forman clases de equivalencia transitivas: si un término
	 * coincide entre dos grupos distintos, ambos se unifican en la consulta.
	 * Este método identifica y expone visualmente dichas coincidencias para
	 * que la administración del catálogo tenga total visibilidad de las fusiones.
	 *
	 * @param array<int, array<int, string>> $groups Grupos ya saneados, en el orden en que se pintan.
	 * @return array<int, array{terminos: array<int, string>, grupos: array<int, int>}> Grupos en base 1 (la línea del panel).
	 */
	public static function shared_terms( array $groups ): array {
		$por_clave = array();
		foreach ( array_values( $groups ) as $indice => $group ) {
			foreach ( (array) $group as $term ) {
				$tokens = self::significant_tokens( Normalizer::normalize( mb_substr( (string) $term, 0, self::MAX_TERM_LENGTH ) ) );
				if ( array() === $tokens ) {
					continue;
				}
				$clave                                         = implode( "\x1F", $tokens );
				$por_clave[ $clave ]['terminos'][ (string) $term ] = (string) $term;
				$por_clave[ $clave ]['grupos'][ $indice + 1 ]      = $indice + 1;
			}
		}

		$compartidos = array();
		foreach ( $por_clave as $dato ) {
			if ( count( $dato['grupos'] ) > 1 ) {
				$compartidos[] = array(
					'terminos' => array_values( $dato['terminos'] ),
					'grupos'   => array_values( $dato['grupos'] ),
				);
			}
		}

		return $compartidos;
	}

	/**
	 * Busca el término completo más largo en la posición indicada.
	 *
	 * @param array<int, string> $tokens Query.
	 * @param int                $offset Posición inicial.
	 * @return string|null
	 */
	private static function matching_key( array $tokens, int $offset ): ?string {
		foreach ( self::$ordered_keys as $key ) {
			$needle = self::$terms[ $key ]['tokens'];
			if ( $needle === array_slice( $tokens, $offset, count( $needle ) ) ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Componente conexa de equivalencias, comenzando por el término original.
	 *
	 * @param string $start Clave del término.
	 * @return array<int, array{tokens: array<int, string>, query: string}>
	 */
	private static function equivalent_terms( string $start ): array {
		$out   = array();
		$seen  = array();
		$queue = array( $start );

		while ( ! empty( $queue ) ) {
			$key = array_shift( $queue );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = self::$terms[ $key ];
			if ( count( $out ) >= self::MAX_SEGMENT_CHOICES ) {
				break;
			}

			foreach ( self::$adjacency[ $key ] ?? array() as $next ) {
				if ( ! isset( $seen[ $next ] ) ) {
					$queue[] = $next;
				}
			}
		}

		return $out;
	}

	/**
	 * Reset del memo (tests que mutan la option, o guardado en admin).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$terms        = null;
		self::$adjacency    = null;
		self::$ordered_keys = null;
	}
}
