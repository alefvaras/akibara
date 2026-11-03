<?php
/**
 * Parser de la cabecera `Accept` con q-values, según RFC 9110 §12.5.1.
 *
 * Está separado del módulo de WordPress a propósito: es la única pieza de la
 * negociación de contenido que tiene aristas de verdad, y así se puede probar
 * sin levantar una petición.
 *
 * La trampa que evita, textual de acceptmarkdown.com: «Don't substring-match on
 * `text/markdown`. A naive `accept.includes("text/markdown")` happens to work,
 * but `accept.startsWith("text/html")` breaks on real Chrome headers.» El
 * navegador manda cosas como
 * `text/html,application/xhtml+xml,application/xml;q=0.9,*​/*;q=0.8`, donde el
 * comodín final ACEPTA markdown con q=0.8 — servírselo a Chrome sería un
 * desastre. Lo que decide no es si el tipo aparece, sino quién gana al ordenar.
 *
 * Orden de desempate (RFC 9110): primero q descendente; a igual q, gana el
 * tipo más específico (`text/markdown` > `text/*` > *​/*). Un `q=0` es un
 * rechazo explícito, no una preferencia baja.
 *
 * @package Akibara\Business
 */

declare(strict_types=1);

namespace Akibara\Business\Modules\AgentReadiness;

final class AcceptParser {

	/**
	 * Ausencia de cabecera = el cliente acepta cualquier cosa (RFC 9110).
	 */
	private const SIN_CABECERA = '*/*';

	/**
	 * Elige el mejor tipo que sabemos producir, o null si el cliente los
	 * rechaza todos — que es el único caso donde corresponde un 406.
	 *
	 * @param string   $cabecera  Valor crudo de `Accept`.
	 * @param string[] $ofrecidos Tipos que el servidor sabe generar, en orden de
	 *                            preferencia propia para desempatar.
	 * @return string|null
	 */
	public static function mejor( string $cabecera, array $ofrecidos ): ?string {
		if ( array() === $ofrecidos ) {
			return null;
		}

		$cabecera = trim( $cabecera );
		if ( '' === $cabecera ) {
			$cabecera = self::SIN_CABECERA;
		}

		$rangos = self::rangos( $cabecera );
		if ( array() === $rangos ) {
			// Cabecera presente pero ilegible: se trata como ausente en vez de
			// devolver 406. Un 406 por un header mal formado deja al cliente sin
			// nada, y la degradación correcta es servir la representación normal.
			return $ofrecidos[0];
		}

		$mejor      = null;
		$mejor_peso = null;

		foreach ( $ofrecidos as $indice => $tipo ) {
			$coincidencia = self::coincidencia_mas_especifica( $tipo, $rangos );
			if ( null === $coincidencia ) {
				continue;
			}

			[ $q, $especificidad ] = $coincidencia;
			if ( 0.0 === $q ) {
				continue; // Rechazo explícito.
			}

			// Orden lexicográfico: q, luego especificidad del rango que casó,
			// luego la preferencia del servidor (índice ascendente).
			$peso = array( $q, $especificidad, -$indice );
			if ( null === $mejor_peso || $peso > $mejor_peso ) {
				$mejor      = $tipo;
				$mejor_peso = $peso;
			}
		}

		return $mejor;
	}

	/**
	 * @return array<int, array{0:string,1:string,2:float}> tipo, subtipo, q.
	 */
	private static function rangos( string $cabecera ): array {
		$rangos = array();

		foreach ( explode( ',', $cabecera ) as $trozo ) {
			$partes = explode( ';', $trozo );
			$medio  = strtolower( trim( (string) array_shift( $partes ) ) );

			if ( ! str_contains( $medio, '/' ) ) {
				continue;
			}

			[ $tipo, $subtipo ] = explode( '/', $medio, 2 );
			if ( '' === $tipo || '' === $subtipo ) {
				continue;
			}

			$q = 1.0;
			foreach ( $partes as $parametro ) {
				$parametro = trim( $parametro );
				if ( 0 !== stripos( $parametro, 'q=' ) ) {
					continue;
				}
				$valor = trim( substr( $parametro, 2 ) );
				if ( ! is_numeric( $valor ) ) {
					continue;
				}
				$q = max( 0.0, min( 1.0, (float) $valor ) );
			}

			$rangos[] = array( $tipo, $subtipo, $q );
		}

		return $rangos;
	}

	/**
	 * Devuelve [q, especificidad] del rango MÁS ESPECÍFICO que casa con el tipo.
	 *
	 * La especificidad se compara antes que el orden de aparición porque un
	 * `*​/*;q=0.8` no puede pisar a un `text/markdown;q=0.8` escrito después.
	 *
	 * @param array<int, array{0:string,1:string,2:float}> $rangos Rangos.
	 * @return array{0:float,1:int}|null
	 */
	private static function coincidencia_mas_especifica( string $ofrecido, array $rangos ): ?array {
		$ofrecido = strtolower( $ofrecido );
		$corte    = strpos( $ofrecido, ';' );
		if ( false !== $corte ) {
			$ofrecido = substr( $ofrecido, 0, $corte );
		}

		if ( ! str_contains( $ofrecido, '/' ) ) {
			return null;
		}

		[ $o_tipo, $o_sub ] = explode( '/', trim( $ofrecido ), 2 );

		$mejor = null;
		foreach ( $rangos as $rango ) {
			[ $tipo, $subtipo, $q ] = $rango;

			if ( '*' === $tipo && '*' === $subtipo ) {
				$especificidad = 0;
			} elseif ( $tipo === $o_tipo && '*' === $subtipo ) {
				$especificidad = 1;
			} elseif ( $tipo === $o_tipo && $subtipo === $o_sub ) {
				$especificidad = 2;
			} else {
				continue;
			}

			if ( null === $mejor || $especificidad > $mejor[1] ) {
				$mejor = array( $q, $especificidad );
			}
		}

		return $mejor;
	}
}
