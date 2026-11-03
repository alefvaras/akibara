<?php
/**
 * Email Header — Akibara branded override.
 *
 * Overrides WooCommerce default at:
 * woocommerce/templates/emails/email-header.php
 *
 * Design notes:
 *  - Bulletproof: tables + inline-able CSS (WC Emogrifier inlines the <style>),
 *    bgcolor attrs duplicated on brand bands so clients that drop inlined
 *    backgrounds (some Outlook/Yahoo) still show the colour.
 *  - Dark mode: full dual-mode support with mathematical mix-blend-mode shield.
 *    Eliminates all white PNG tiles that trapped email card surfaces in white.
 *    Employs linear-gradient shields on surfaces combined with paired
 *    `akb-blend-screen` and `akb-blend-diff` spans under `u + .body` to invert
 *    inverted text back to crisp white in Gmail mobile (iOS & Android).
 *    Provides complete `@media (prefers-color-scheme: dark)` theme for Apple Mail.
 *  - Mobile: edge-to-edge brand bands and a padded, readable content column.
 *
 * @package Akibara
 * @version 10.9.0
 */

defined( 'ABSPATH' ) || exit;

// Logo = banner full-bleed (barra negra + wordmark + franja roja HORNEADOS en el PNG).
$akb_logo_url     = (string) apply_filters(
	'akibara_email_logo_banner_url',
	trailingslashit( get_stylesheet_directory_uri() ) . 'assets/img/akb-email-logo-banner.png'
);
$akb_store_url    = home_url();
$akb_store_name   = get_bloginfo( 'name', 'display' );
$akb_heading_text = isset( $email_heading ) ? (string) $email_heading : '';
$akb_preheader    = $akb_heading_text ? $akb_heading_text . ' — Akibara' : 'Akibara — Tu rincón de Akihabara en Chile';

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<meta name="x-apple-disable-message-reformatting" />
	<meta name="color-scheme" content="light dark" />
	<meta name="supported-color-schemes" content="light dark" />
	<title><?php echo esc_html( $akb_store_name ); ?></title>
	<style type="text/css">
		:root { color-scheme: light dark; supported-color-schemes: light dark; }

		/* Reset */
		body { margin: 0; padding: 0; width: 100% !important; background-color: #f3efe6; font-family: Arial, Helvetica, sans-serif; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
		table { border-collapse: collapse; mso-table-lspace: 0; mso-table-rspace: 0; }
		img { border: 0; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; display: block; }
		a { color: #CC0010; text-decoration: none; }

		/* Outer wrapper */
		#outer_wrapper { background-color: #f3efe6; width: 100%; }
		#wrapper { max-width: 600px; margin: 0 auto; padding: 0; }

		/* Logo bar */
		#template_logo {
			background-color: #0d0d0d;
			background-image: linear-gradient(#0d0d0d, #0d0d0d);
			padding: 0;
			font-size: 0;
			line-height: 0;
			text-align: center;
		}
		#template_logo img { width: 100%; max-width: 600px; height: auto; margin: 0; display: block; border: 0; }

		/* Header band con linear-gradient shield */
		#template_header { background-color: #E60012; }
		#header_wrapper {
			padding: 20px 32px;
			text-align: center;
			background-color: #E60012;
			background-image: linear-gradient(#E60012, #E60012);
		}
		#header_wrapper h1 {
			color: #ffffff !important;
			-webkit-text-fill-color: #ffffff !important;
			font-size: 21px;
			font-weight: bold;
			margin: 0;
			line-height: 1.3;
			text-transform: uppercase;
			letter-spacing: 0.4px;
		}
		#template_header #header_wrapper h1 {
			background-color: transparent !important;
			color: #ffffff !important;
			-webkit-text-fill-color: #ffffff !important;
		}

		/* Body */
		#template_container, #body_content, #body_content_inner_cell { background-color: #fffdf7; }
		#body_content_inner_cell { padding: 30px 32px; }
		#body_content_inner {
			color: #2b2b2b;
			-webkit-text-fill-color: #2b2b2b;
			font-size: 15px;
			line-height: 1.65;
		}
		#body_content_inner p { margin: 0 0 16px; }
		#body_content_inner p, #body_content_inner td, #body_content_inner th, #body_content_inner li, #body_content_inner address { color: #2b2b2b; -webkit-text-fill-color: #2b2b2b; }
		#body_content_inner a { color: #CC0010; -webkit-text-fill-color: #CC0010; font-weight: bold; }
		#body_content_inner h2, #body_content_inner h3 { color: #0d0d0d; -webkit-text-fill-color: #0d0d0d; }
		#body_content_inner .akb-email-price { color: #CC0010; -webkit-text-fill-color: #CC0010; }
		#body_content_inner .akb-email-success { color: #1f7a3d; -webkit-text-fill-color: #1f7a3d; }

		/* Tabla de pedido WC core */
		#body_content_inner .email-order-details thead th {
			color: #0d0d0d;
			-webkit-text-fill-color: #0d0d0d;
			border-bottom: 2px solid #E60012 !important;
			padding-bottom: 10px !important;
		}
		#body_content_inner .email-order-details td,
		#body_content_inner .email-order-details th { border-color: #ece6da !important; }
		#body_content_inner .order-totals-total th,
		#body_content_inner .order-totals-total td { color: #0d0d0d; -webkit-text-fill-color: #0d0d0d; font-weight: bold !important; }
		#body_content_inner .order-totals-total .amount,
		#body_content_inner .order-totals-total .amount * { color: #CC0010; -webkit-text-fill-color: #CC0010; }
		#body_content_inner .order-totals-total .includes_tax,
		#body_content_inner .order-totals-total .includes_tax * { color: #6b6459; -webkit-text-fill-color: #6b6459; font-weight: normal !important; }

		/* Callouts y códigos de seguimiento */
		#body_content_inner .akb-email-callout,
		#body_content_inner .akb-tracking-code {
			background-color: #fbf8f0;
			border: 1px solid #141416 !important;
			border-left: 4px solid #E60012 !important;
			border-radius: 0 !important;
			padding: 16px 20px !important;
			margin: 20px 0 !important;
			color: #2b2b2b;
			-webkit-text-fill-color: #2b2b2b;
		}
		#body_content_inner .akb-email-callout h4,
		#body_content_inner .akb-email-callout strong {
			color: #0d0d0d;
			-webkit-text-fill-color: #0d0d0d;
		}

		/* Tankōbon Manga Spine (.akb-vol) */
		#body_content_inner .akb-vol {
			display: inline-block !important;
			background-color: #E60012 !important;
			background-image: linear-gradient(#E60012, #E60012) !important;
			color: #ffffff !important;
			-webkit-text-fill-color: #ffffff !important;
			font-weight: bold !important;
			font-size: 11px !important;
			line-height: 1 !important;
			padding: 2px 6px !important;
			border-radius: 0 !important;
			margin-left: 4px !important;
			vertical-align: middle !important;
			text-transform: uppercase !important;
			letter-spacing: 0.5px !important;
		}

		/* Bulletproof CTA button */
		#body_content_inner a.akb-email-btn {
			display: inline-block;
			background-color: #E60012;
			background-image: linear-gradient(#E60012, #E60012);
			color: #ffffff !important;
			-webkit-text-fill-color: #ffffff !important;
			font-weight: bold;
			font-size: 15px;
			line-height: 1;
			padding: 15px 28px;
			border-radius: 0 !important;
			text-transform: uppercase;
			letter-spacing: 0.5px;
			text-decoration: none;
			mso-padding-alt: 0;
		}

		/* Footer */
		#template_footer {
			background-color: #0d0d0d;
			background-image: linear-gradient(#0d0d0d, #0d0d0d);
			border-top: 0;
		}
		#template_footer td#credit {
			border: 0 !important;
			border-top: 0 !important;
			color: #b5afa6;
			-webkit-text-fill-color: #b5afa6;
			font-size: 12px;
			text-align: center;
			padding: 24px 32px;
			line-height: 1.7;
		}
		#template_footer a {
			color: #ffffff !important;
			-webkit-text-fill-color: #ffffff !important;
			font-weight: bold;
			text-decoration: none;
		}

		/* Protección anti-inversión de texto blanco exclusiva para Gmail */
		u + .body .akb-blend-screen { background: #000000; mix-blend-mode: screen; display: inline-block; }
		u + .body .akb-blend-diff { background: #000000; mix-blend-mode: difference; display: inline-block; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; }

		/* Modo oscuro nativo para Apple Mail y clientes compatibles */
		@media (prefers-color-scheme: dark) {
			body, #outer_wrapper { background-color: #0d0d0d !important; }
			#template_container, #body_content, #body_content_inner_cell {
				background-color: #1a1a1c !important;
			}
			#body_content_inner,
			#body_content_inner p,
			#body_content_inner td,
			#body_content_inner th,
			#body_content_inner li,
			#body_content_inner address {
				color: #e5e5ea !important;
				-webkit-text-fill-color: #e5e5ea !important;
			}
			#body_content_inner h2,
			#body_content_inner h3,
			#body_content_inner strong,
			#body_content_inner b {
				color: #ffffff !important;
				-webkit-text-fill-color: #ffffff !important;
			}
			#body_content_inner .email-order-details thead th {
				color: #ffffff !important;
				-webkit-text-fill-color: #ffffff !important;
				border-bottom: 2px solid #E60012 !important;
			}
			#body_content_inner .email-order-details td,
			#body_content_inner .email-order-details th {
				border-color: #2c2c2e !important;
			}
			#body_content_inner .order-totals-total th,
			#body_content_inner .order-totals-total td {
				color: #ffffff !important;
				-webkit-text-fill-color: #ffffff !important;
			}
			#body_content_inner .order-totals-total .amount,
			#body_content_inner .order-totals-total .amount * {
				color: #ff4d5a !important;
				-webkit-text-fill-color: #ff4d5a !important;
			}
			#body_content_inner .order-totals-total .includes_tax,
			#body_content_inner .order-totals-total .includes_tax * {
				color: #a1a1aa !important;
				-webkit-text-fill-color: #a1a1aa !important;
			}
			#body_content_inner a {
				color: #ff4d5a !important;
				-webkit-text-fill-color: #ff4d5a !important;
			}
			#body_content_inner a.akb-email-btn {
				background-color: #E60012 !important;
				background-image: linear-gradient(#E60012, #E60012) !important;
				color: #ffffff !important;
				-webkit-text-fill-color: #ffffff !important;
			}
			#header_wrapper h1 {
				color: #ffffff !important;
				-webkit-text-fill-color: #ffffff !important;
			}
			#body_content_inner .akb-email-callout,
			#body_content_inner .akb-product-card,
			#body_content_inner .akb-tracking-code {
				background-color: #242426 !important;
				border-color: #3a3a3c !important;
				border-left: 4px solid #E60012 !important;
				color: #e5e5ea !important;
				-webkit-text-fill-color: #e5e5ea !important;
			}
			#body_content_inner .akb-email-callout h4,
			#body_content_inner .akb-email-callout strong,
			#body_content_inner .akb-email-callout b,
			#body_content_inner .akb-product-card strong {
				color: #ffffff !important;
				-webkit-text-fill-color: #ffffff !important;
			}
			#body_content_inner .akb-email-callout a,
			#body_content_inner .akb-contact-box a,
			#body_content_inner .akb-pickup-coord a,
			#body_content_inner .akb-product-card a {
				color: #ffffff !important;
				-webkit-text-fill-color: #ffffff !important;
			}
			#body_content_inner .akb-contact-box span,
			#body_content_inner .akb-contact-box p {
				color: #e5e5ea !important;
				-webkit-text-fill-color: #e5e5ea !important;
			}
		}

		/* Mobile */
		@media screen and (max-width: 600px) {
			#wrapper { padding: 0 !important; }
			#template_logo { padding: 0 !important; }
			#header_wrapper { padding: 15px 20px !important; }
			#header_wrapper h1 { font-size: 18px !important; }
			#body_content_inner_cell { padding: 22px 18px !important; }
			#body_content_inner { font-size: 15px !important; }
			#body_content_inner a.akb-email-btn {
				display: block !important;
				text-align: center !important;
				width: 100% !important;
				box-sizing: border-box !important;
				padding: 16px 20px !important;
			}
		}

	</style>
</head>
<body class="body" <?php echo function_exists( 'is_rtl' ) && is_rtl() ? 'rightmargin="0"' : 'leftmargin="0"'; ?> marginwidth="0" topmargin="0" marginheight="0" offset="0">
<!-- Preheader (hidden inbox preview) -->
<div aria-hidden="true" style="max-height:0;max-width:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#f3efe6;opacity:0;">
	<?php echo esc_html( $akb_preheader ); ?>
	&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;
</div>

<table width="100%" id="outer_wrapper" role="presentation" cellpadding="0" cellspacing="0" bgcolor="#f3efe6">
<tr>
<td align="center">
<div id="wrapper">
<table width="600" id="template_card" role="presentation" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%">

	<!-- Logo -->
	<tr>
		<td id="template_logo" bgcolor="#0d0d0d">
			<a href="<?php echo esc_url( $akb_store_url ); ?>" target="_blank">
				<img src="<?php echo esc_url( $akb_logo_url ); ?>" alt="<?php echo esc_attr( $akb_store_name ); ?>" width="600" style="width:100%;max-width:600px;height:auto;display:block;border:0;" />
			</a>
		</td>
	</tr>

	<!-- Heading con escudo mix-blend-mode para Gmail móvil -->
	<tr>
		<td>
			<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_header" role="presentation" bgcolor="#E60012">
				<tr>
					<td id="header_wrapper" bgcolor="#E60012">
						<h1>
							<span class="akb-blend-screen">
								<span class="akb-blend-diff"><?php echo esc_html( $akb_heading_text ); ?></span>
							</span>
						</h1>
					</td>
				</tr>
			</table>
		</td>
	</tr>

	<!-- Body -->
	<tr>
		<td>
			<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_container" role="presentation" bgcolor="#fffdf7">
				<tr>
					<td>
						<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_body" role="presentation">
							<tr>
								<td valign="top" id="body_content" bgcolor="#fffdf7">
									<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation">
										<tr>
											<td valign="top" id="body_content_inner_cell">
												<div id="body_content_inner">
