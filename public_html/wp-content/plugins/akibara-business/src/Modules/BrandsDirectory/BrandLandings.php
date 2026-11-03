<?php
/**
 * Fuente única del mapa editorial → landing de familia, compartida por menú
 * y bloques de archivo. Agrupa las ediciones nacionales de una misma editorial.
 * Resuelve páginas por slug, sin IDs fijos ni memoización que pueda contaminar
 * pruebas del mismo proceso. El filtro akibara_brand_landings amplía el mapa;
 * los textos de cliente se editan en Editoriales · Ediciones.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BrandsDirectory;

use Akibara\Business\Utilities\Text;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

final class BrandLandings {

	/**
	 * Tabla editorial → landing.
	 *
	 * `menu_label` lleva el sufijo «todas las ediciones» a propósito: el mega
	 * de Editoriales ya imprime «Ivrea» dos veces (una por región, sin país,
	 * porque BrandRegion::display_name() lo recorta), y tres anclas con el
	 * mismo texto y destinos distintos incumplen WCAG 2.4.4.
	 *
	 * @return array<string, array{page_slug:string,menu_label:string,callout_label:string,brands:list<string>}>
	 */
	public static function all(): array {
		$landings = self::base();
		foreach ( $landings as $key => $landing ) {
			$landings[ $key ] = array_merge( $landing, self::labels( (string) $key, $landing ) );
		}

		return $landings;
	}

	/**
	 * La tabla con los literales (y las familias que añada el filtro), SIN las
	 * options del dueño: es a lo que se cae cuando una etiqueta se vacía.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function base(): array {
		$structure = array(
			'ivrea'  => array(
				'page_slug' => 'ivrea',
				'brands'    => array( 'ivrea-argentina', 'ivrea-espana' ),
			),
			'panini' => array(
				'page_slug' => 'panini',
				'brands'    => array( 'panini-argentina', 'panini-espana' ),
			),
		);
		$defaults  = array();
		foreach ( $structure as $key => $row ) {
			$defaults[ $key ] = $row + ( self::literals()[ $key ] ?? array() );
		}

		return (array) apply_filters( 'akibara_brand_landings', $defaults );
	}

	/**
	 * Los campos de texto de una familia que el dueño puede cambiar, con su rótulo.
	 *
	 * @since 1.114.18
	 *
	 * @return array<string, string>
	 */
	public static function label_fields(): array {
		return array(
			'menu_label'    => 'Texto en el menú de Editoriales',
			'callout_label' => 'Nombre de la familia (conmutador, tarjetas y datos estructurados de la landing)',
		);
	}

	/**
	 * Etiquetas vigentes de una familia: la option si el dueño la escribió, el literal si no.
	 *
	 * @since 1.114.18
	 *
	 * @param string                    $key      Clave de la familia.
	 * @param array<string, mixed>|null $defaults Fila con los literales (null = se busca en la tabla).
	 * @return array<string, string>
	 */
	public static function labels( string $key, ?array $defaults = null ): array {
		if ( null === $defaults ) {
			$defaults = self::all()[ $key ] ?? array();
		}
		$out = array();
		foreach ( array_keys( self::label_fields() ) as $field ) {
			$literal       = (string) ( $defaults[ $field ] ?? '' );
			$out[ $field ] = self::one_line( get_option( self::option_name( $key, $field ), $literal ), $literal );
		}
		return $out;
	}

	/**
	 * Sanea lo que llegó del panel: una línea por campo; vacío = vuelve al literal.
	 *
	 * @since 1.114.18
	 *
	 * @param array<string, mixed> $raw Campos crudos.
	 * @param string               $key Clave de la familia.
	 * @return array<string, string>
	 */
	public static function sanitize_labels( array $raw, string $key ): array {
		$literals = self::base()[ $key ] ?? array();
		$out      = array();
		foreach ( array_keys( self::label_fields() ) as $field ) {
			$out[ $field ] = self::one_line( $raw[ $field ] ?? '', (string) ( $literals[ $field ] ?? '' ) );
		}
		return $out;
	}

	/**
	 * Escribe las etiquetas de una familia. Igual al literal = se borra la option.
	 *
	 * @since 1.114.18
	 *
	 * @param string                $key    Clave de la familia.
	 * @param array<string, string> $labels Campos saneados.
	 * @return void
	 */
	public static function save_labels( string $key, array $labels ): void {
		$literals = self::base()[ $key ] ?? array();
		foreach ( array_keys( self::label_fields() ) as $field ) {
			$value = (string) ( $labels[ $field ] ?? '' );
			if ( '' === $value || $value === (string) ( $literals[ $field ] ?? '' ) ) {
				delete_option( self::option_name( $key, $field ) );
			} else {
				// Autoload: el menú las lee en TODAS las páginas; sin autoload
				// serían consultas sueltas en cada petición sin caché de objetos.
				update_option( self::option_name( $key, $field ), $value, true );
			}
		}
	}

	/**
	 * Las options que el menú de Editoriales lee en cada página del front
	 * (MenuInjector → all() → labels()); Plugin las calienta en una sola consulta.
	 *
	 * @since 1.114.18
	 *
	 * @return list<string>
	 */
	public static function front_read_options(): array {
		$out = array();
		foreach ( array_keys( self::literals() ) as $key ) {
			foreach ( array_keys( self::label_fields() ) as $field ) {
				$out[] = self::option_name( (string) $key, $field );
			}
		}
		return $out;
	}

	/**
	 * @param string $key   Clave de la familia.
	 * @param string $field Campo.
	 * @return string
	 */
	private static function option_name( string $key, string $field ): string {
		return 'akb_brand_landing_' . $key . '_' . $field;
	}

	/**
	 * Una línea sin etiquetas y acotada (Text::one_line); vacío o ilegible cae al literal.
	 *
	 * @param mixed  $raw     Lo que llegó.
	 * @param string $literal A qué se cae.
	 * @return string
	 */
	private static function one_line( $raw, string $literal ): string {
		return Text::one_line( is_scalar( $raw ) ? (string) $raw : '', $literal );
	}

	/**
	 * Los literales de las familias de fábrica (las del filtro traen los suyos).
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function literals(): array {
		return array(
			'ivrea'  => array(
				'menu_label'    => 'Ivrea · todas las ediciones',
				'callout_label' => 'Ivrea',
			),
			'panini' => array(
				'menu_label'    => 'Panini · todas las ediciones',
				'callout_label' => 'Panini',
			),
		);
	}

	/**
	 * Landing a la que pertenece un slug de marca, o null si no tiene.
	 *
	 * @return array{key:string,page_slug:string,menu_label:string,callout_label:string,brands:list<string>}|null
	 */
	public static function for_slug( string $brand_slug ): ?array {
		$brand_slug = trim( $brand_slug );
		if ( '' === $brand_slug ) {
			return null;
		}

		foreach ( self::all() as $key => $landing ) {
			$brands = isset( $landing['brands'] ) ? (array) $landing['brands'] : array();
			if ( in_array( $brand_slug, $brands, true ) ) {
				$landing['key'] = (string) $key;
				return $landing;
			}
		}

		return null;
	}

	/**
	 * Igual que for_slug() pero a partir del término consultado.
	 *
	 * @param mixed $term Término de `product_brand`, o cualquier otra cosa.
	 * @return array{key:string,page_slug:string,menu_label:string,callout_label:string,brands:list<string>}|null
	 */
	public static function for_term( $term ): ?array {
		if ( ! $term instanceof WP_Term ) {
			return null;
		}

		return self::for_slug( (string) $term->slug );
	}

	/**
	 * Landing cuya página es el slug dado, o null. Es el inverso de url():
	 * lo usa el schema para saber si la página que se está renderizando ES una
	 * landing paraguas.
	 *
	 * @return array{key:string,page_slug:string,menu_label:string,callout_label:string,brands:list<string>}|null
	 */
	public static function for_page_slug( string $page_slug ): ?array {
		$page_slug = trim( $page_slug );
		if ( '' === $page_slug ) {
			return null;
		}

		foreach ( self::all() as $key => $landing ) {
			if ( (string) ( $landing['page_slug'] ?? '' ) === $page_slug ) {
				$landing['key'] = (string) $key;
				return $landing;
			}
		}

		return null;
	}

	/**
	 * URL de la landing, o cadena vacía si la página no existe o no está
	 * publicada. Devolver '' —y no un enlace roto— es lo que permite que el
	 * menú degrade exactamente al de hoy cuando la página falta.
	 */
	public static function url( string $key ): string {
		$landings = self::all();
		if ( ! isset( $landings[ $key ] ) ) {
			return '';
		}

		$page_slug = (string) ( $landings[ $key ]['page_slug'] ?? '' );
		if ( '' === $page_slug ) {
			return '';
		}

		$page = get_page_by_path( $page_slug );
		if ( ! $page instanceof WP_Post || 'publish' !== (string) $page->post_status ) {
			return '';
		}

		$permalink = get_permalink( $page );
		return is_string( $permalink ) ? $permalink : '';
	}
}
