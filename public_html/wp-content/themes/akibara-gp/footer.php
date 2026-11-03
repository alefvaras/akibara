<?php
/**
 * Footer — orchestrator only.
 *
 * Cierra el wrapper de layout #main-content (ver header.php; NO es el
 * landmark <main>, ese lo cierra cada template), monta partes atómicas
 * (footer main, bottom-nav)
 * + Hook Proxy de GP para compat de plugins.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;
?>

</div><?php // cierra #main-content (wrapper de layout abierto en header.php). ?>

<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook del tema padre GeneratePress (de terceros): el nombre es su contrato público, no un hook propio del tema hijo. Renombrarlo dejaría sin disparar todo lo enganchado por GeneratePress y sus add-ons.
do_action( 'generate_before_footer' ); ?>

<?php get_template_part( 'template-parts/footer/main' ); ?>

<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook del tema padre GeneratePress (de terceros): el nombre es su contrato público, no un hook propio del tema hijo. Renombrarlo dejaría sin disparar todo lo enganchado por GeneratePress y sus add-ons.
do_action( 'generate_after_footer' ); ?>

<?php get_template_part( 'template-parts/footer/bottom-nav' ); ?>

<?php if ( function_exists( 'is_product' ) && is_product() ) : ?>
	<?php get_template_part( 'template-parts/woocommerce/sticky-atc' ); ?>
<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
