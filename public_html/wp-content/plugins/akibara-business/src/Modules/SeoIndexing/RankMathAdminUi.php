<?php
/**
 * RankMathAdminUi — conserva la configuración SEO en la interfaz oficial.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeoIndexing;

defined( 'ABSPATH' ) || exit;

/**
 * Permite editar el robots.txt virtual sin habilitar cambios de archivos.
 *
 * Rank Math reutiliza `rank_math/can_edit_file` para dos superficies distintas:
 * el robots.txt virtual (una opción de WordPress) y el archivo físico
 * `.htaccess`. Akibara mantiene `DISALLOW_FILE_MODS` por seguridad, habilita el
 * editor virtual solo en la pantalla administrativa correspondiente y retira el
 * editor de `.htaccess` del panel.
 */
final class RankMathAdminUi {

	public function register(): void {
		add_filter( 'rank_math/can_edit_file', array( $this, 'allow_virtual_robots_editor' ), PHP_INT_MAX );
		add_filter( 'rank_math/settings/general', array( $this, 'remove_htaccess_editor' ), PHP_INT_MAX );
	}

	/**
	 * Habilita únicamente la superficie administrativa donde vive robots.txt.
	 *
	 * Rank Math consulta el mismo filtro antes de escribir `.htaccess`. Por eso
	 * no se propaga un permiso previo: cualquier request REST o payload que
	 * intente editar `.htaccess` queda denegado, incluso si otro componente
	 * hubiese devuelto `true`. El POST normal de `robots_txt_content` permanece
	 * permitido porque guarda una opción de WordPress, no un archivo.
	 */
	public function allow_virtual_robots_editor( bool $allowed ): bool {
		unset( $allowed );

		if ( $this->is_rest_request() || $this->has_htaccess_payload() ) {
			return false;
		}

		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- contexto de lectura; no procesa cambios.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';

		return 'rank-math-options-general' === $page;
	}

	/**
	 * Identifica el endpoint React de ajustes; nunca necesita permisos físicos.
	 */
	private function is_rest_request(): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		return false !== strpos( $request_uri, '/wp-json/' );
	}

	/**
	 * Cubre el formulario CMB2 legacy y payloads form-encoded anidados.
	 */
	private function has_htaccess_payload(): bool {
		if ( isset( $_POST['htaccess_accept_changes'] ) || isset( $_POST['htaccess_content'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Sólo inspecciona la FORMA del POST para decidir si se oculta una pestaña de Rank Math; no lee ni escribe nada.
			return true;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Sólo se inspecciona la FORMA del POST para decidir si se oculta una pestaña de Rank Math (AdminPanel ya verificó el nonce); array de ajustes de Rank Math; sólo se comprueba su forma, cada valor lo sanea su propio guardado.
		$settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : null;

		return is_array( $settings )
			&& ( isset( $settings['htaccess_allow_editing'] ) || isset( $settings['htaccess_content'] ) );
	}

	/**
	 * Oculta la edición física de.htaccess mientras DISALLOW_FILE_MODS siga
	 * activo; todas las demás pestañas oficiales permanecen disponibles.
	 *
	 * @param array<string,mixed> $tabs Pestañas de ajustes generales.
	 * @return array<string,mixed>
	 */
	public function remove_htaccess_editor( array $tabs ): array {
		unset( $tabs['htaccess'] );

		return $tabs;
	}
}
