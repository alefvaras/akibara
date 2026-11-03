<?php
/**
 * La activación no puede sellar la versión de una tabla que no se creó.
 *
 * Hallazgo S-5a871596: `dbDelta()` falla EN SILENCIO —permisos, engine, largo
 * de clave en utf8mb4— y el hook de activación hacía `install()` seguido de
 * `update_option( versión )` a ciegas. Con el sello puesto, el guard de versión
 * de `maybe_install()` corta en cada petición siguiente y el módulo queda roto
 * para siempre, sin reinstalar y sin decir nada.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\BackInStock;

use PHPUnit\Framework\TestCase;

final class SelloDeEsquemaEnLaActivacionTest extends TestCase {

	private function codigo_sin_comentarios( string $ruta ): string {
		$codigo = '';
		foreach ( token_get_all( (string) file_get_contents( $ruta ) ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$codigo .= is_array( $token ) ? $token[1] : $token;
		}

		return $codigo;
	}

	/** @testdox La activación comprueba la tabla antes de sellar su versión. */
	public function test_activation_checks_the_table_before_sealing(): void {
		$codigo = $this->codigo_sin_comentarios( dirname( __DIR__, 3 ) . '/akibara-business.php' );

		self::assertStringContainsString( 'schema_ready()', $codigo );
		self::assertMatchesRegularExpression(
			'/if\s*\(\s*\$\w+::schema_ready\(\)\s*\)\s*\{\s*update_option\(/',
			$codigo,
			'El sello tiene que colgar de la comprobación, no ir antes ni al lado.'
		);
		self::assertStringContainsString( 'akibara_business_tablas_fallidas', $codigo, 'Y el dueño tiene que enterarse.' );
	}

	/**
	 * Revisión de 1.116.8: el aviso se consume al pintarse, y lo consumía el
	 * primer usuario que entrara al admin, pudiera o no hacer algo. Y prometía
	 * «si el aviso vuelve», cuando sólo lo escribe la activación.
	 *
	 * @testdox El aviso de tablas fallidas sólo lo consume quien puede activar plugins, y no promete que vuelva.
	 */
	public function test_the_failed_tables_notice_is_for_whoever_can_act(): void {
		$codigo = $this->codigo_sin_comentarios( dirname( __DIR__, 3 ) . '/akibara-business.php' );

		$inicio = strpos( $codigo, 'function akibara_business_aviso_tablas_fallidas()' );
		self::assertNotFalse( $inicio );
		$cuerpo = substr( $codigo, $inicio, (int) strpos( $codigo, "add_action( 'admin_notices', 'akibara_business_aviso_tablas_fallidas' );" ) - $inicio );

		$capacidad = strpos( $cuerpo, "current_user_can( 'activate_plugins' )" );
		self::assertNotFalse( $capacidad, 'Sin comprobar capacidad, un gestor de tienda se lleva el aviso del dueño.' );
		self::assertLessThan( (int) strpos( $cuerpo, 'get_transient(' ), $capacidad, 'La capacidad se mira ANTES de leer y borrar el aviso.' );
		self::assertStringNotContainsString( 'si el aviso vuelve', $cuerpo );
	}

	/** @testdox Los tres módulos con tabla saben comprobar si existe. */
	public function test_every_table_module_can_probe_its_schema(): void {
		foreach (
			array(
				'\\Akibara\\Business\\Modules\\Wishlist\\Install',
				'\\Akibara\\Business\\Modules\\BackInStock\\Install',
				'\\Akibara\\Business\\Modules\\SeriesFollow\\Install',
			) as $instalador
		) {
			self::assertTrue(
				method_exists( $instalador, 'schema_ready' ),
				"{$instalador} sella una versión de esquema: tiene que poder comprobar su tabla."
			);
		}
	}
}
