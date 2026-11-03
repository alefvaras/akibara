<?php
/**
 * Mi cuenta — resolución pura de continuidad editorial.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\AccountCollection;

use Akibara\Business\Modules\SeriesFacts\PublicationStatus;
use Akibara\Business\Modules\Stock\StockStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Resuelve la próxima acción lineal de una edición sin consultar WordPress.
 */
final class ContinuityResolver {

	/**
	 * Resuelve un hueco, continuación, inicio o estado terminal de la edición.
	 *
	 * Los productos sin un número de tomo positivo quedan fuera de la continuidad
	 * lineal. Si un producto lineal está mal formado, el resultado falla cerrado
	 * como `unknown` para no transformar datos dudosos en una recomendación.
	 *
	 * @param array<int,mixed> $owned_products   Productos comprados en la edición.
	 * @param array<int,mixed> $catalog_products Productos actuales de la edición.
	 * @param array<string,mixed> $edition       Metadatos de la edición.
	 * @return array{
	 *   kind:'gap'|'next'|'start'|'complete'|'up_to_date'|'unknown',
	 *   availability:'available'|'preorder'|'out_of_stock'|'unknown',
	 *   volume_number:int|null,
	 *   product:array<string,mixed>|null,
	 *   options:list<array<string,mixed>>,
	 *   options_count:int
	 * }
	 *
	 * @since 1.104.0
	 */
	public function resolve( array $owned_products, array $catalog_products, array $edition ): array {
		$plan = self::plan( $owned_products, $catalog_products, $edition );
		if ( isset( $plan['terminal'] ) ) {
			return $plan['terminal'];
		}

		$options = $plan['options'];
		if ( array() === $options ) {
			return array(
				'kind'          => $plan['kind'],
				'availability'  => 'unknown',
				'volume_number' => $plan['volume_number'],
				'product'       => null,
				'options'       => array(),
				'options_count' => 0,
			);
		}

		usort( $options, array( self::class, 'compare_products' ) );
		$product = $options[0];

		return array(
			'kind'          => $plan['kind'],
			'availability'  => self::availability( $product ),
			'volume_number' => $plan['volume_number'],
			'product'       => $product,
			'options'       => $options,
			'options_count' => count( $options ),
		);
	}

	/**
	 * El número del tomo candidato, o null si la edición no ofrece ninguno.
	 *
	 * Existe para que quien necesita SÓLO el número —`CollectionResolver`, que
	 * hidrata stock y ETA de ese tomo antes de decidir la variante principal— no
	 * tenga que ejecutar un `resolve()` completo primero y otro después. El número
	 * no depende de la hidratación: sale de los tomos comprados, de los números
	 * del catálogo y del total editorial, y ninguno de los tres cambia al resolver
	 * stock.
	 *
	 * @param array<int,mixed>    $owned_products   Productos comprados en la edición.
	 * @param array<int,mixed>    $catalog_products Productos actuales de la edición.
	 * @param array<string,mixed> $edition          Metadatos de la edición.
	 */
	public function candidate_volume_number( array $owned_products, array $catalog_products, array $edition ): ?int {
		$plan = self::plan( $owned_products, $catalog_products, $edition );

		return isset( $plan['terminal'] ) ? null : $plan['volume_number'];
	}

	/**
	 * Cuerpo compartido: valida la edición, arma los slots y elige el candidato.
	 *
	 * Devuelve `terminal` con el resultado ya cerrado, o el candidato con sus
	 * opciones SIN ordenar (el orden depende de los campos hidratados y sólo le
	 * interesa a `resolve()`).
	 *
	 * @param array<int,mixed>    $owned_products
	 * @param array<int,mixed>    $catalog_products
	 * @param array<string,mixed> $edition
	 * @return array{terminal:array<string,mixed>}|array{kind:string,volume_number:int,options:list<array<string,mixed>>}
	 */
	private static function plan( array $owned_products, array $catalog_products, array $edition ): array {
		$is_complete = $edition['is_complete'] ?? false;
		if ( null === $is_complete ) {
			$is_complete = false;
		} elseif ( ! is_bool( $is_complete ) ) {
			return self::terminal( 'unknown' );
		}
		$edition_total = $edition['total'] ?? 0;
		if ( ! is_int( $edition_total ) || $edition_total < 0 ) {
			return self::terminal( 'unknown' );
		}
		$edition_state = $edition['state'] ?? '';
		if ( ! is_string( $edition_state ) ) {
			return self::terminal( 'unknown' );
		}
		$trusted_total = in_array( $edition_state, array( PublicationStatus::COMPLETE, PublicationStatus::ONGOING ), true )
			? $edition_total
			: 0;

		$owned = self::normalize_products( $owned_products );
		$catalog = self::normalize_products( $catalog_products );
		if ( null === $owned || null === $catalog ) {
			return self::terminal( 'unknown' );
		}

		$owned_slots = self::logical_slots( $owned );
		$catalog_slots = self::logical_slots( $catalog );
		if ( null === $owned_slots || null === $catalog_slots ) {
			return self::terminal( 'unknown' );
		}

		if ( array() === $catalog_slots && array() === $owned_slots ) {
			return self::terminal( $is_complete ? 'complete' : 'unknown' );
		}
		if ( array() === $catalog_slots && $trusted_total < 1 ) {
			return self::terminal( $is_complete ? 'complete' : 'unknown' );
		}

		$owned_numbers = array_fill_keys( array_keys( $owned_slots ), true );
		$catalog_numbers = array_keys( $catalog_slots );
		$observed_numbers = array_merge( array_keys( $owned_slots ), $catalog_numbers );
		$highest_observed = array() === $observed_numbers ? 0 : max( $observed_numbers );
		if ( $trusted_total > 0 && $highest_observed > $trusted_total ) {
			return self::terminal( 'unknown' );
		}

		$sequence_limit = $trusted_total > 0 ? $trusted_total : $highest_observed;
		$candidate = self::candidate( $owned_numbers, $sequence_limit );
		if ( null === $candidate ) {
			return self::terminal( $is_complete ? 'complete' : 'up_to_date' );
		}

		return array(
			'kind'          => $candidate['kind'],
			'volume_number' => $candidate['volume_number'],
			'options'       => $catalog_slots[ $candidate['volume_number'] ] ?? array(),
		);
	}

	/**
	 * Envuelve un resultado terminal para que `plan()` lo devuelva sin ambigüedad.
	 *
	 * @return array{terminal:array<string,mixed>}
	 */
	private static function terminal( string $kind ): array {
		return array( 'terminal' => self::terminal_result( $kind ) );
	}

	/**
	 * Normaliza productos lineales y omite deliberadamente los no lineales.
	 *
	 * @param array<int,mixed> $products Productos de entrada.
	 * @return list<array{id:int,title:string,url:string,volume_number:int,stock_status:string,is_purchasable:bool,preorder_label:string,availability_state:string,waitlist_url:string}>|null
	 */
	private static function normalize_products( array $products ): ?array {
		$normalized = array();

		foreach ( $products as $product ) {
			if ( ! is_array( $product ) ) {
				return null;
			}

			$volume_number = $product['volume_number'] ?? null;
			if ( null === $volume_number || ( is_int( $volume_number ) && $volume_number <= 0 ) ) {
				continue;
			}
			if ( ! is_int( $volume_number ) ) {
				return null;
			}

			$id = $product['id'] ?? null;
			if ( ! is_int( $id ) || $id <= 0 ) {
				return null;
			}

			$title = $product['title'] ?? '';
			$url = $product['url'] ?? '';
			$stock_status = $product['stock_status'] ?? '';
			$is_purchasable = $product['is_purchasable'] ?? false;
			$preorder_label = $product['preorder_label'] ?? '';
			// El estado comercial neutro admite cadena vacía cuando el llamador no lo aporta.
			$availability_state = $product['availability_state'] ?? '';
			$waitlist_url = $product['waitlist_url'] ?? '';
			if (
				! is_string( $title )
				|| ! is_string( $url )
				|| ! is_string( $stock_status )
				|| ! is_bool( $is_purchasable )
				|| ! is_string( $preorder_label )
				|| ! is_string( $availability_state )
				|| ! is_string( $waitlist_url )
			) {
				return null;
			}

			$normalized[] = array(
				'id'                 => $id,
				'title'              => $title,
				'url'                => $url,
				'volume_number'      => $volume_number,
				'stock_status'       => $stock_status,
				'is_purchasable'     => $is_purchasable,
				'preorder_label'     => $preorder_label,
				'availability_state' => $availability_state,
				'waitlist_url'       => $waitlist_url,
			);
		}

		return $normalized;
	}

	/**
	 * Agrupa IDs alternativos por número de tomo y rechaza identidades ambiguas.
	 *
	 * @param list<array{id:int,title:string,url:string,volume_number:int,stock_status:string,is_purchasable:bool,preorder_label:string,availability_state:string,waitlist_url:string}> $products Productos normalizados.
	 * @return array<int,list<array{id:int,title:string,url:string,volume_number:int,stock_status:string,is_purchasable:bool,preorder_label:string,availability_state:string,waitlist_url:string}>>|null
	 */
	private static function logical_slots( array $products ): ?array {
		$slots = array();
		$by_id = array();

		foreach ( $products as $product ) {
			$product_id = $product['id'];
			if ( isset( $by_id[ $product_id ] ) ) {
				if ( $by_id[ $product_id ] !== $product ) {
					return null;
				}
				continue;
			}

			$by_id[ $product_id ] = $product;
			$slots[ $product['volume_number'] ][] = $product;
		}

		ksort( $slots, SORT_NUMERIC );

		return $slots;
	}

	/**
	 * Selecciona primero el hueco más temprano y luego la continuación.
	 *
	 * La secuencia editorial tiene prioridad sobre la lista de productos: un
	 * tomo intermedio ausente del catálogo sigue siendo el siguiente paso, pero
	 * sin producto ni CTA. Así nunca se recomienda saltar del tomo 4 al 6.
	 *
	 * @param array<int,true> $owned_numbers Números comprados.
	 * @param int             $sequence_limit Último número fiable de la edición.
	 * @return array{kind:'gap'|'next'|'start',volume_number:int}|null
	 */
	private static function candidate( array $owned_numbers, int $sequence_limit ): ?array {
		if ( $sequence_limit < 1 ) {
			return null;
		}

		if ( array() === $owned_numbers ) {
			return array(
				'kind'          => 'start',
				'volume_number' => 1,
			);
		}

		// Busca huecos entre el tomo más bajo y el más alto que tiene el cliente.
		// Los tomos anteriores a su primera compra no se presuponen faltantes.
		$owned_keys = array_keys( $owned_numbers );
		$min_owned  = min( $owned_keys );
		$max_owned  = max( $owned_keys );
		$gap_limit  = min( $max_owned, $sequence_limit );
		for ( $volume_number = $min_owned; $volume_number <= $gap_limit; ++$volume_number ) {
			if ( ! isset( $owned_numbers[ $volume_number ] ) ) {
				return array(
					'kind'          => 'gap',
					'volume_number' => $volume_number,
				);
			}
		}

		if ( $max_owned < $sequence_limit ) {
			return array(
				'kind'          => 'next',
				'volume_number' => $max_owned + 1,
			);
		}

		return null;
	}

	/**
	 * Ordena opciones por posibilidad de compra y luego por ID.
	 *
	 * @param array<string,mixed> $left  Primera opción.
	 * @param array<string,mixed> $right Segunda opción.
	 */
	private static function compare_products( array $left, array $right ): int {
		$priority = self::product_priority( $left ) <=> self::product_priority( $right );

		return 0 !== $priority ? $priority : $left['id'] <=> $right['id'];
	}

	/**
	 * Devuelve la prioridad determinista de una opción.
	 *
	 * @param array<string,mixed> $product Producto normalizado.
	 */
	private static function product_priority( array $product ): int {
		if ( true === $product['is_purchasable'] && StockStatus::IN_STOCK === $product['stock_status'] ) {
			return 0;
		}
		if ( true === $product['is_purchasable'] && StockStatus::ON_BACKORDER === $product['stock_status'] ) {
			return 1;
		}

		return 2;
	}

	/**
	 * Resuelve disponibilidad sin confundir stock con comprabilidad.
	 *
	 * @param array<string,mixed> $product Producto normalizado.
	 * @return 'available'|'preorder'|'out_of_stock'|'unknown'
	 */
	private static function availability( array $product ): string {
		if ( true === $product['is_purchasable'] && '' !== trim( $product['preorder_label'] ) ) {
			return 'preorder';
		}
		if ( true === $product['is_purchasable'] && StockStatus::IN_STOCK === $product['stock_status'] ) {
			return 'available';
		}
		if ( true === $product['is_purchasable'] && StockStatus::ON_BACKORDER === $product['stock_status'] ) {
			return 'preorder';
		}
		if ( StockStatus::OUT_OF_STOCK === $product['stock_status'] ) {
			return 'out_of_stock';
		}

		return 'unknown';
	}

	/**
	 * Construye un estado sin producto accionable.
	 *
	 * @param 'complete'|'up_to_date'|'unknown' $kind Estado terminal.
	 * @return array{
	 *   kind:'complete'|'up_to_date'|'unknown',
	 *   availability:'unknown',
	 *   volume_number:null,
	 *   product:null,
	 *   options:list<array<string,mixed>>,
	 *   options_count:0
	 * }
	 */
	private static function terminal_result( string $kind ): array {
		return array(
			'kind'          => $kind,
			'availability'  => 'unknown',
			'volume_number' => null,
			'product'       => null,
			'options'       => array(),
			'options_count' => 0,
		);
	}
}
