<?php
/**
 * Stopwords — palabras a ignorar en la búsqueda (paridad Relevanssi, nativo).
 * Fuente ÚNICA de verdad de stopwords del buscador: la usa QueryEngine (al
 * tokenizar la query) y Synonyms (al armar los grupos). Query-time: cambiar la
 * lista NO requiere reindexar.
 *
 * Config: option `akb_search_stopwords` (editable en el admin). Sin option, la
 * lista curada por defecto (es-CL + inglés básico). Los números NUNCA son
 * stopwords (el número de tomo es el patrón dominante del catálogo).
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class Stopwords {

	public const OPTION = 'akb_search_stopwords';

	/**
	 * Longitud máxima permitida para una palabra ignorada (caracteres).
	 * Aplica tanto al guardar en administración como al leer en runtime.
	 */
	public const MAX_WORD_LENGTH = 64;

	/** @var array<string, true>|null Set memoizado por request. */
	private static ?array $set = null;

	/**
	 * Lista por defecto, corta y conservadora (es-CL + inglés). NO incluye
	 * palabras de contenido de manga (ataque, titan, etc. no van acá).
	 *
	 * @return array<int, string>
	 */
	public static function defaults(): array {
		return array(
			'el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas', 'de', 'del', 'al',
			'a', 'y', 'o', 'u', 'e', 'en', 'con', 'por', 'para', 'su', 'sus', 'se',
			'lo', 'le', 'les', 'the', 'of', 'and', 'or', 'to', 'in', 'on',
		);
	}

	/**
	 * ¿Es una palabra ignorada de la forma que acepta el motor? Única definición.
	 *
	 * @param mixed $word Palabra.
	 */
	public static function word_is_valid( $word ): bool {
		return is_string( $word ) && '' !== trim( $word ) && mb_strlen( $word ) <= self::MAX_WORD_LENGTH;
	}

	/**
	 * Convierte lo que el dueño escribió en el panel en la lista que se guarda.
	 *
	 * Una palabra por línea o separada por comas; en minúsculas, sin repetir.
	 * Lo que no cumple `word_is_valid()` se descarta y se avisa.
	 *
	 * @param string    $raw       Texto del panel.
	 * @param bool|null $truncated Se marca true si hubo que descartar algo.
	 * @return array<int, string>
	 */
	public static function sanitize_words( string $raw, ?bool &$truncated = null ): array {
		$truncated = false;
		$words     = array();

		foreach ( preg_split( '/[\s,]+/', $raw ) ?: array() as $word ) {
			$word = trim( sanitize_text_field( mb_strtolower( $word ) ) );
			if ( '' === $word ) {
				continue;
			}
			if ( ! self::word_is_valid( $word ) ) {
				$truncated = true;
				continue;
			}
			$words[ $word ] = true;
		}

		return array_map( 'strval', array_keys( $words ) );
	}

	/**
	 * Lista vigente (option del admin, o defaults).
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		$stored = SemanticPublication::authoritative_value( self::OPTION, null );
		if ( ! is_array( $stored ) ) {
			return self::defaults();
		}
		// Solo strings: una option corrupta (o pisada por otro escritor) con
		// elementos anidados fataleaba TODA búsqueda en el strval del memo
		// ("Array to string conversion") — fail-safe a defaults por elemento.
		//
		// Un array vacío es un valor válido y deliberado (el operador configuró no ignorar
		// ninguna palabra). Solo se retorna `defaults()` cuando la option no es un array.
		return array_values( array_filter( $stored, 'is_string' ) );
	}

	/**
	 * @param string $token Token normalizado.
	 * @return bool True si es stopword.
	 */
	public static function is_stopword( string $token ): bool {
		if ( null === self::$set ) {
			self::$set = array_fill_keys( array_map( 'strval', self::all() ), true );
		}
		return isset( self::$set[ $token ] );
	}

	/**
	 * Reset del memo (tests que mutan la option, o guardado en admin).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$set = null;
	}
}
