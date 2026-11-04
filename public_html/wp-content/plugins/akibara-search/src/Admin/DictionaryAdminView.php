<?php
/**
 * DictionaryAdminView — renderizado de los tabs de diccionarios (Sinónimos y Stopwords).
 *
 * @package Akibara\Search\Admin
 */

declare( strict_types = 1 );

namespace Akibara\Search\Admin;

defined( 'ABSPATH' ) || exit;

use Akibara\Search\SearchAdminSection;
use Akibara\Search\Stopwords;
use Akibara\Search\Synonyms;

/**
 * Vistas administrativas para sinónimos y palabras ignoradas.
 */
final class DictionaryAdminView {

	/**
	 * Pestaña Sinónimos: lista de grupos y precarga contextual.
	 *
	 * @param SearchAdminSection $admin Instancia del controlador de administración.
	 * @return void
	 */
	public static function render_synonyms( SearchAdminSection $admin ): void {
		$lines = array();
		foreach ( Synonyms::groups() as $group ) {
			$lines[] = implode( ', ', array_map( 'strval', (array) $group ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sólo precarga texto en un formulario; el guardado tiene su propio nonce.
		$prefill = isset( $_GET[ SearchAdminSection::PARAM_PREFILL ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ SearchAdminSection::PARAM_PREFILL ] ) ) : '';
		$prefill = trim( mb_substr( $prefill, 0, (int) Synonyms::MAX_TERM_LENGTH ) );
		if ( '' !== $prefill ) {
			$lines[] = $prefill . ', ';
		}

		$shared = Synonyms::shared_terms( Synonyms::groups() );

		$admin->render_template(
			'tab-sinonimos.php',
			array(
				'lines'   => $lines,
				'prefill' => $prefill,
				'shared'  => $shared,
			)
		);
	}

	/**
	 * Pestaña Palabras ignoradas (Stopwords): términos vigentes y lista de fábrica.
	 *
	 * @param SearchAdminSection $admin Instancia del controlador de administración.
	 * @return void
	 */
	public static function render_stopwords( SearchAdminSection $admin ): void {
		$admin->render_template(
			'tab-stopwords.php',
			array(
				'vigentes' => Stopwords::all(),
				'defaults' => Stopwords::defaults(),
			)
		);
	}
}
