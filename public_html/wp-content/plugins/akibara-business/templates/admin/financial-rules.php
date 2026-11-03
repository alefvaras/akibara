<?php
/**
 * Vista del Panel de Administración de Reglas Financieras & Promociones.
 * Shell modular que delega la presentación a plantillas por pestaña.
 *
 * @package Akibara\Business
 *
 * @var string                                  $tab
 * @var array<string, string>                   $tabs
 * @var array<int|string, array<string, mixed>> $byp_map
 * @var string                                  $ajax_nonce
 * @var string                                  $menu_slug
 * @var string                                  $action_save
 * @var string                                  $nonce_save
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap akb-financial-rules-wrap">
	<h1>Akibara · Reglas Financieras, Promociones & Transaccional (v1.1)</h1>
	<p class="description">Panel consolidado de ingeniería financiera y parametrización de márgenes comerciales para Akibara SpA.</p>

	<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><strong>Ajustes guardados correctamente.</strong></p></div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper wp-clearfix">
		<?php foreach ( $tabs as $akb_tab_key => $akb_tab_label ) : ?>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => $menu_slug, 'tab' => $akb_tab_key ), admin_url( 'admin.php' ) ) ); ?>"
			   class="nav-tab <?php echo $tab === $akb_tab_key ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html( $akb_tab_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:20px;">
		<input type="hidden" name="action" value="<?php echo esc_attr( $action_save ); ?>">
		<input type="hidden" name="current_tab" value="<?php echo esc_attr( $tab ); ?>">
		<?php wp_nonce_field( $nonce_save ); ?>

		<?php
		$akb_tab_template = __DIR__ . '/financial-rules/tab-' . $tab . '.php';
		if ( file_exists( $akb_tab_template ) ) {
			include $akb_tab_template;
		}
		?>

		<p class="submit" style="margin-top:25px;">
			<input type="submit" name="submit" id="submit" class="button button-primary" value="Guardar Ajustes Financieros">
		</p>
	</form>
</div>

<?php
$akb_script_template = __DIR__ . '/financial-rules/script.php';
if ( file_exists( $akb_script_template ) ) {
	include $akb_script_template;
}
