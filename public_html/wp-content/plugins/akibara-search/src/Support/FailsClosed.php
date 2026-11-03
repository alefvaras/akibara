<?php
/**
 * FailsClosed — el protocolo de lectura fail-closed del buscador, en un sitio.
 *
 * Regla del módulo: una lectura que no se puede autorizar NO devuelve un
 * resultado degradado, cierra el servicio para el resto del request. Un cero
 * inventado es peor que un servicio apagado — se cachea, se publica y se
 * convierte en «el catálogo no tiene nada».
 *
 * Estaba copiado en SEIS clases (FacetService, QueryEngine, ProductPresenter,
 * ProductSourceAuthority, PopularQueries y SeriesSuggestions) con cuatro formas
 * distintas de leer `last_error` del `$wpdb`. Acá vive UNA, con las dos
 * propiedades que la sostienen:
 *
 *   · el PRIMER diagnóstico gana — el siguiente fallo suele ser consecuencia
 *     del primero y pisarlo borra la causa;
 *   · cerrar es IRREVERSIBLE dentro del request; nada reabre `$available`.
 *
 * Cada clase conserva su `guarded_call()` —difieren en qué envuelven y cómo
 * cuentan lecturas— y declara qué memo derivado invalidar al cerrar,
 * sobreescribiendo `forget_derived_state()`.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.41
 */
trait FailsClosed {

	/** Lectura autoritativa viva en este request. */
	private bool $available = true;

	/** Diagnóstico de la PRIMERA lectura fallida. */
	private string $read_error = '';

	/**
	 * ¿Conserva una lectura autoritativa en este request?
	 *
	 * @return bool
	 *
	 * @since 0.1.14
	 */
	public function is_available(): bool {
		return $this->available;
	}

	/**
	 * Diagnóstico interno de la primera lectura fallida.
	 *
	 * @return string
	 *
	 * @since 0.1.14
	 */
	public function last_error(): string {
		return $this->read_error;
	}

	/**
	 * Cierra el servicio conservando el diagnóstico más útil.
	 *
	 * Prefiere el error REAL de la base sobre el mensaje genérico del caller:
	 * «Could not read facet counts» no dice nada; «Table doesn't exist» sí.
	 *
	 * @param string $fallback Diagnóstico del caller si la base no dijo nada.
	 * @return void
	 */
	private function mark_unavailable( string $fallback ): void {
		if ( $this->available ) {
			$database_error   = $this->database_error();
			$this->read_error = '' !== $database_error ? $database_error : $fallback;
		}

		$this->available = false;
		$this->forget_derived_state();
	}

	/**
	 * Último error de la conexión, o '' si no hay conexión o no lo expone.
	 *
	 * Se comprueba la forma en vez de asumirla: los dobles de la suite y las
	 * clases sin `$db` pasan por acá igual, y una lectura defensiva de más es
	 * más barata que un fatal dentro del camino de error.
	 *
	 * @return string
	 */
	private function database_error(): string {
		if ( ! property_exists( $this, 'db' ) || ! is_object( $this->db ) ) {
			return '';
		}
		if ( ! property_exists( $this->db, 'last_error' ) || ! is_string( $this->db->last_error ) ) {
			return '';
		}

		return trim( $this->db->last_error );
	}

	/**
	 * Invalida los memos derivados de la clase al cerrar. Vacío por defecto: la
	 * sobreescribe quien tenga estado derivado que no puede sobrevivir al cierre.
	 *
	 * @return void
	 */
	private function forget_derived_state(): void {
	}
}
