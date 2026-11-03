<?php
/**
 * Reglas puras de selección de recomendaciones.
 *
 * Sin WordPress ni WooCommerce: reciben registros ya resueltos (id, serie,
 * número de tomo, si se puede comprar) y devuelven ids en el orden en que
 * conviene ofrecerlos. Los adaptadores ({@see RelatedProducts},
 * {@see UpsellsDisplay}, PopularProducts) hacen las consultas y la caché; aquí
 * sólo vive el criterio, para poder fijarlo con pruebas.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Recommendations;

use Akibara\Business\Services\VolumeTitle;

defined( 'ABSPATH' ) || exit;

final class Picker {

	/**
	 * Prioriza entradas de series distintas para Productos relacionados.
	 *
	 * Devuelve primero entradas que abren su serie y productos comprables sin serie;
	 * después entradas cuya primera entrega está agotada, otros comprables repetidos,
	 * comprables de la misma serie de la ficha y, al final, candidatos no comprables.
	 * La serie de la ficha ya dispone de su navegación y de upsells propios.
	 * WooCommerce corta a los huecos disponibles: los grupos posteriores permiten
	 * completar tarjetas si faltan series distintas, sin vaciar el bloque.
	 *
	 * @param array<int, array{id:int, serie:int|null, numero:int|null, comprable:bool}> $candidatos      En el orden del pool (fijo por ficha).
	 * @param array<int, int>                                                              $series_actuales Series de la ficha.
	 * @param array<int, array<int, array{id:int, numero:int|null, comprable:bool}>>       $estanterias     Por serie, sus primeros tomos en orden de lectura.
	 * @param array<int, int>                                                              $excluidos       Ids que no pueden salir (la ficha, sus upsells).
	 * @return array<int, int>
	 */
	public static function related( array $candidatos, array $series_actuales, array $estanterias, array $excluidos = array() ): array {
		$actuales = array_fill_keys( array_map( 'intval', $series_actuales ), true );

		$abren      = array();
		$entradas   = array();
		$repetidos  = array();
		$misma      = array();
		$sin_compra = array();
		$vistas     = array();

		foreach ( $candidatos as $candidato ) {
			$id    = (int) $candidato['id'];
			$serie = $candidato['serie'];

			if ( null !== $serie && isset( $actuales[ $serie ] ) ) {
				if ( $candidato['comprable'] ) {
					$misma[] = $id;
				} else {
					$sin_compra[] = $id;
				}
				continue;
			}

			if ( null === $serie ) {
				// Un producto sin serie es su propia puerta de entrada.
				if ( $candidato['comprable'] ) {
					$abren[] = $id;
				} else {
					$sin_compra[] = $id;
				}
				continue;
			}

			if ( isset( $vistas[ $serie ] ) ) {
				if ( $candidato['comprable'] ) {
					$repetidos[] = $id;
				} else {
					$sin_compra[] = $id;
				}
				continue;
			}
			$vistas[ $serie ] = true;

			$entrada = self::entrada_de_serie( $estanterias[ $serie ] ?? array(), $serie, $candidatos );
			if ( null === $entrada ) {
				$sin_compra[] = $id;
				continue;
			}

			if ( $entrada['abre'] ) {
				$abren[] = $entrada['id'];
			} else {
				$entradas[] = $entrada['id'];
			}

			if ( $entrada['id'] !== $id ) {
				if ( $candidato['comprable'] ) {
					$repetidos[] = $id;
				} else {
					$sin_compra[] = $id;
				}
			}
		}

		$fuera = array_fill_keys( array_map( 'intval', $excluidos ), true );
		$ids   = array();
		foreach ( array( $abren, $entradas, $repetidos, $misma, $sin_compra ) as $grupo ) {
			foreach ( $grupo as $id ) {
				if ( ! isset( $fuera[ $id ] ) && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
		}

		return $ids;
	}

	/**
	 * Tomo de entrada de una serie: el más bajo que se puede comprar.
	 *
	 * Primero en su estantería (los primeros tomos del catálogo, en orden de
	 * lectura); si ninguno de ésos se puede comprar, el candidato del pool de
	 * esa serie con el número más bajo que sí se pueda. `abre` dice si la
	 * entrada es el primer tomo de la estantería (en una serie sin tomos
	 * numerados, cualquiera de sus productos: todos son el «primero»).
	 *
	 * @param array<int, array{id:int, numero:int|null, comprable:bool}>                  $estante    Primeros tomos, ya ordenados.
	 * @param int                                                                          $serie      Serie.
	 * @param array<int, array{id:int, serie:int|null, numero:int|null, comprable:bool}> $candidatos Pool completo.
	 * @return array{id:int, abre:bool}|null
	 */
	private static function entrada_de_serie( array $estante, int $serie, array $candidatos ): ?array {
		$primero = $estante[0]['numero'] ?? null;
		foreach ( $estante as $tomo ) {
			if ( $tomo['comprable'] ) {
				return array(
					'id'   => (int) $tomo['id'],
					'abre' => $tomo['numero'] === $primero,
				);
			}
		}

		$del_pool = array();
		foreach ( $candidatos as $candidato ) {
			if ( $serie === $candidato['serie'] && $candidato['comprable'] ) {
				$del_pool[] = array(
					'id'            => (int) $candidato['id'],
					'title'         => '',
					'volume_number' => $candidato['numero'],
				);
			}
		}
		if ( array() === $del_pool ) {
			return null;
		}

		// Sin estantería no se sabe si abre la serie: cuenta como entrada, no
		// como apertura, y queda detrás de las que sí se sabe que abren.
		return array(
			'id'   => (int) VolumeTitle::sort_records( $del_pool )[0]['id'],
			'abre' => false,
		);
	}

	/**
	 * Ordena upsells por lectura y prioriza los tomos comprables.
	 *
	 * Si ninguno se puede comprar, el modo configurado determina si se mantienen
	 * con su aviso de reposición, se sustituyen por los siguientes comprables de
	 * la serie o se omite el bloque.
	 *
	 * @param array<int, array{id:int, numero:int|null, comprable:bool}> $guardados  Upsells de la ficha.
	 * @param array<int, int>                                             $siguientes Próximos tomos comprables, en orden de lectura.
	 * @param string                                                      $modo       Uno de Settings::AGOTADA_*.
	 * @return array<int, int>
	 */
	public static function upsells( array $guardados, array $siguientes, string $modo ): array {
		$registros = array();
		foreach ( $guardados as $guardado ) {
			$registros[] = array(
				'id'            => (int) $guardado['id'],
				'title'         => '',
				'volume_number' => $guardado['numero'],
				'comprable'     => (bool) $guardado['comprable'],
			);
		}

		$comprables = array();
		$agotados   = array();
		foreach ( VolumeTitle::sort_records( $registros ) as $registro ) {
			if ( $registro['comprable'] ) {
				$comprables[] = (int) $registro['id'];
			} else {
				$agotados[] = (int) $registro['id'];
			}
		}

		if ( array() !== $comprables || Settings::AGOTADA_MOSTRAR === $modo ) {
			return array_merge( $comprables, $agotados );
		}

		if ( Settings::AGOTADA_OCULTAR === $modo ) {
			return array();
		}

		$siguientes = array_values( array_diff( array_map( 'intval', $siguientes ), $agotados ) );
		if ( array() === $siguientes ) {
			return $agotados;
		}

		return array_slice( array_merge( $siguientes, $agotados ), 0, count( $guardados ) );
	}

	/**
	 * Selecciona una tarjeta por serie respetando el orden de entrada.
	 *
	 * Si no hay series distintas suficientes, completa los huecos con los productos
	 * saltados en el mismo orden, sin reducir la cantidad solicitada.
	 *
	 * @param array<int, int>      $ids    Ids en orden (más vendido primero).
	 * @param array<int, int|null> $series Serie de cada id (null = sin serie).
	 * @param int                  $limite Tarjetas que se piden.
	 * @return array<int, int>
	 */
	public static function una_por_serie( array $ids, array $series, int $limite ): array {
		$elegidos = array();
		$saltados = array();
		$vistas   = array();

		foreach ( $ids as $id ) {
			$id    = (int) $id;
			$serie = $series[ $id ] ?? null;
			if ( null !== $serie && isset( $vistas[ $serie ] ) ) {
				$saltados[] = $id;
				continue;
			}
			if ( null !== $serie ) {
				$vistas[ $serie ] = true;
			}
			$elegidos[] = $id;
		}

		return array_slice( array_merge( $elegidos, $saltados ), 0, max( 0, $limite ) );
	}
}
