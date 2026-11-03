<?php
/**
 * Header — orchestrator only.
 *
 * Solo carga las partes atómicas en orden + replica los hooks de GP
 * (Hook Proxy pattern) para que plugins futuros puedan inyectar UI sin
 * tener que editar este archivo.
 *
 * Estructura:
 *   <head>           → wp_head() inyecta WP/plugin assets.
 *   skip-link        → a11y WCAG 2.4.1: PRIMER control tabulable de la página.
 *   <body>           → wp_body_open() para tracking pixels, barra de anuncios.
 *   scroll progress  → barra de progreso top (main.js la actualiza).
 *   site-header      → hooks proxy + 3 partes (shell, navigation, mobile-drawer).
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="theme-color" content="#0A0A0A">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>

<?php
/*
 * El skip link va ANTES de `wp_body_open()`, no después: la barra de anuncios
 * de akibara-business se engancha a ese hook y contiene un botón «Siguiente
 * anuncio», así que con el orden anterior el primer Tab de la página caía en
 * ese botón y el mecanismo para saltar la navegación quedaba en segundo lugar
 * (WCAG 2.4.1 exige que el bypass sea alcanzable al inicio del orden de foco).
 * Como marcado literal del template ninguna prioridad de hook puede adelantarlo.
 */
?>
<a href="#main" class="skip-link">Saltar al contenido</a>

<?php wp_body_open(); ?>

<div id="aki-scroll-progress" aria-hidden="true"></div>

<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook del tema padre GeneratePress (de terceros): el nombre es su contrato público, no un hook propio del tema hijo. Renombrarlo dejaría sin disparar todo lo enganchado por GeneratePress y sus add-ons.
do_action( 'generate_before_header' ); ?>

<header class="site-header" id="site-header">
	<?php
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook del tema padre GeneratePress (de terceros): el nombre es su contrato público, no un hook propio del tema hijo. Renombrarlo dejaría sin disparar todo lo enganchado por GeneratePress y sus add-ons.
do_action( 'generate_inside_header' );

	get_template_part( 'template-parts/header/shell' );
	get_template_part( 'template-parts/header/navigation' );
	?>
</header>

<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook del tema padre GeneratePress (de terceros): el nombre es su contrato público, no un hook propio del tema hijo. Renombrarlo dejaría sin disparar todo lo enganchado por GeneratePress y sus add-ons.
do_action( 'generate_after_header' ); ?>

<?php get_template_part( 'template-parts/header/mobile-drawer' ); ?>

<?php
/*
 * Wrapper de layout — NO es el landmark <main>.
 *
 * El landmark <main> real lo emite cada template (child o GeneratePress)
 * como <main id="main">: home/archive/single.php, 404.php, y el padre GP
 * en page/index/search + la integración WooCommerce. Mantener un único
 * <main> por página (WCAG 1.3.1 / regla HTML: un solo landmark `main`)
 * exige que este wrapper sea un <div>, no otro <main>.
 *
 * Conserva el rol de contenedor de layout que en GP cumple `.site-content`
 * (este theme lo reemplazó por #main-content en su header custom): los
 * selectores `#main-content` de woocommerce.css (grid de sidebar WC) y
 * base.css siguen aplicando sin cambios de markup adicionales.
 */
?>
<div id="main-content">
