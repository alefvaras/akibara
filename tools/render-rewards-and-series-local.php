<?php
/**
 * Render Akibara Rewards and Series/Stock emails to HTML files for visual QA auditing.
 */

$out_dir = '/tmp/all-rendered-emails';
if ( ! is_dir( $out_dir ) ) {
	mkdir( $out_dir, 0777, true );
}

use Akibara\Rewards\Points\Emails as RewardsEmails;
use Akibara\Rewards\Support\Options as RewardsOptions;
use Akibara\Rewards\Points\BrandedEmail;

$user = get_user_by( 'email', 'ale.fvaras@gmail.com' ) ?: wp_get_current_user();
if ( ! $user || 0 === $user->ID ) {
	$users = get_users( array( 'number' => 1 ) );
	$user  = ! empty( $users ) ? $users[0] : null;
}
$user_id = $user ? (int) $user->ID : 1;

$orders = wc_get_orders( array( 'limit' => 5, 'status' => 'any' ) );
$order  = ! empty( $orders ) ? $orders[0] : null;

$mailer = WC()->mailer();

// Helper to wrap and style like WooCommerce does
$render_and_save = function( string $id, string $heading, string $body, string $alt_key = 'puntos' ) use ( $out_dir, $mailer ) {
	$wrapped = $mailer->wrap_message( $heading, $body );
	$branded = BrandedEmail::for( $alt_key, '' );
	$inlined = $branded->style_inline( $wrapped );
	$final   = apply_filters( 'woocommerce_mail_content', $inlined );

	$file_path = $out_dir . '/' . $id . '.html';
	file_put_contents( $file_path, $final );

	$white_png    = substr_count( $final, 'akb-hdr-white' );
	$u_body       = substr_count( $final, 'u + .body' );
	$blend_spans  = substr_count( $final, 'akb-blend-screen' );
	$vols         = substr_count( $final, 'class="akb-vol"' );
	$has_footer   = substr_count( $final, 'template_footer' );
	$red_filete   = substr_count( $final, '#E60012' );

	echo sprintf( "OK: %-30s -> %s (Blend: %d, WhitePNG: %d, Red: %d, Footer: %d)\n",
		$id, basename( $file_path ), $blend_spans, $white_png, $red_filete, $has_footer
	);
};

echo "=== Rendering Akibara Rewards Emails ===\n";

// 1. Order Points
$order_num = $order ? $order->get_order_number() : '28560';
$body_order = sprintf(
	'<p style="margin:0 0 12px">¡Gracias por tu compra! Tu pedido <strong>#%s</strong> sumó <strong>%s Puntos Akibara</strong>.</p>
	<p style="margin:0 0 12px">Tu saldo actual es de <strong>%s puntos</strong> — cada punto vale %s en tu próxima compra.</p>
	<p style="margin:0 0 16px;color:#666;font-size:13px">Los puntos se activan inmediatamente para tu siguiente compra en akibara.cl.</p>%s',
	esc_html( (string) $order_num ),
	esc_html( number_format_i18n( 450 ) ),
	esc_html( number_format_i18n( 1250 ) ),
	esc_html( RewardsOptions::redeem_label() ),
	RewardsEmails::cta( 'Ver mis puntos', RewardsEmails::rewards_url() )
);
$render_and_save( 'akb_rewards_order', '¡Sumaste Puntos Akibara!', $body_order, 'order' );

// 2. Referral Reward
$body_referral = sprintf(
	'<p style="margin:0 0 12px">¡Buenas noticias! Tu amigo completó su primera compra y acreditamos <strong>%s Puntos Akibara</strong> en tu cuenta.</p>
	<p style="margin:0 0 12px">Tu saldo actual es de <strong>%s puntos</strong> — cada punto equivale a %s para descontar en tus próximos mangas y cómics.</p>
	<p style="margin:0;color:#888;font-size:13px">¡Muchas gracias por recomendar Akibara a tus amigos!</p>%s',
	esc_html( number_format_i18n( 1000 ) ),
	esc_html( number_format_i18n( 2250 ) ),
	esc_html( RewardsOptions::redeem_label() ),
	RewardsEmails::cta( 'Ver mis puntos', RewardsEmails::rewards_url() )
);
$render_and_save( 'akb_rewards_referral', '¡Tu amigo completó su primera compra!', $body_referral, 'referral' );

// 3. Birthday Reward
$body_birthday = sprintf(
	'<p style="margin:0 0 12px">¡Feliz cumpleaños! 🎂 Te regalamos <strong>%s Puntos Akibara</strong> por tu día.</p>
	<p style="margin:0 0 12px">Ya están en tu cuenta: tu saldo es de <strong>%s puntos</strong> y cada punto vale %s de descuento.</p>%s',
	esc_html( number_format_i18n( 1000 ) ),
	esc_html( number_format_i18n( 2250 ) ),
	esc_html( RewardsOptions::redeem_label() ),
	RewardsEmails::cta( 'Canjear mis puntos', RewardsEmails::rewards_url() )
);
$render_and_save( 'akb_rewards_birthday', '¡Feliz cumpleaños de parte de Akibara!', $body_birthday, 'birthday' );

// 4. Expiry Warning
$body_expiry = sprintf(
	'<p style="margin:0 0 12px">Tienes <strong>%s Puntos Akibara</strong> que vencerán dentro de los próximos %d días.</p>
	<p style="margin:0 0 12px">Cada punto vale %s de descuento: úsalos en tu próximo tomo antes de que se pierdan.</p>%s',
	esc_html( number_format_i18n( 500 ) ),
	30,
	esc_html( RewardsOptions::redeem_label() ),
	RewardsEmails::cta( 'Canjear mis puntos', RewardsEmails::rewards_url() )
);
$render_and_save( 'akb_rewards_expiry', 'Tus puntos están por vencer', $body_expiry, 'expiry' );

// 5. Review Reward
$body_review = sprintf(
	'<p style="margin:0 0 12px">¡Tu reseña de <strong>Frieren 10</strong> fue aprobada! Sumaste <strong>%s Puntos Akibara</strong> por compartirla con foto, equivalentes a <strong>%s CLP de descuento</strong>.</p>
	<p style="margin:0 0 12px">Tu saldo actual es de <strong>%s puntos</strong> — cada punto vale %s en tu próxima compra.</p>
	<p style="margin:0"><span style="color:#CC0010;font-weight:bold">Gracias por ayudar a otros coleccionistas a decidir.</span></p>%s',
	esc_html( number_format_i18n( 500 ) ),
	esc_html( RewardsOptions::points_label( 500 ) ),
	esc_html( number_format_i18n( 1750 ) ),
	esc_html( RewardsOptions::redeem_label() ),
	RewardsEmails::cta( 'Ver mis puntos', RewardsEmails::rewards_url() )
);
$render_and_save( 'akb_rewards_review', 'Tu reseña sumó puntos', $body_review, 'review' );

// 6. Admin Adjust
$body_admin = sprintf(
	'<p style="margin:0 0 12px">¡Sumaste Puntos Akibara! Te regalamos <strong>%s puntos</strong> por %s.</p>
	<p style="margin:0 0 12px">Tu saldo actual es de <strong>%s puntos</strong> — cada punto vale %s en tu próxima compra.</p>%s',
	esc_html( number_format_i18n( 300 ) ),
	'Cortesía por fidelidad',
	esc_html( number_format_i18n( 2050 ) ),
	esc_html( RewardsOptions::redeem_label() ),
	RewardsEmails::cta( 'Ver mis puntos', RewardsEmails::rewards_url() )
);
$render_and_save( 'akb_rewards_admin_adjust', '¡Sumaste Puntos Akibara!', $body_admin, 'admin_adjust' );

echo "\n=== Rendering Series Follow & Back In Stock ===\n";

$products = wc_get_products( array( 'limit' => 5 ) );
$sample_product = ! empty( $products ) ? $products[0] : null;

foreach ( $mailer->get_emails() as $id => $email_obj ) {
	// 7. Series Follow
	if ( 'AkibaraSeriesFollowEmail' === $id ) {
		$ref = new ReflectionClass( $email_obj );
		
		$p_user_id = $ref->getProperty( 'user_id' );
		$p_user_id->setAccessible( true );
		$p_user_id->setValue( $email_obj, $user_id );

		$p_groups = $ref->getProperty( 'groups' );
		$p_groups->setAccessible( true );
		$p_groups->setValue( $email_obj, array(
			array(
				'series_name'    => 'Chainsaw Man',
				'volume'         => '17',
				'volumes_count'  => 1,
				'volumes_phrase' => '',
				'is_gap'         => false,
				'url'            => home_url( '/producto/chainsaw-man-17/' ),
				'image_url'      => '',
				'title'          => 'Chainsaw Man 17',
				'price_html'     => '<span class="woocommerce-Price-amount amount">$8.990</span>',
				'availability'   => 'Disponible',
				'cutoff'         => 'Despacho en 24-48 hrs',
				'timeframe_copy' => 'Llega esta semana',
				'unfollow_url'   => home_url( '/mi-cuenta/coleccion/?unfollow=chainsaw-man' ),
			)
		) );

		$p_unsub = $ref->getProperty( 'unsubscribe_url' );
		$p_unsub->setAccessible( true );
		$p_unsub->setValue( $email_obj, home_url( '/mi-cuenta/coleccion/?unfollow=all' ) );

		$email_obj->recipient = (string) ( $user ? $user->user_email : 'ale.fvaras@gmail.com' );

		try {
			$c = $email_obj->get_content();
			if ( ! empty( $c ) ) {
				$inl = $email_obj->style_inline( $c );
				$fin = apply_filters( 'woocommerce_mail_content', $inl );
				file_put_contents( $out_dir . '/AkibaraSeriesFollowEmail.html', $fin );
				echo "OK: AkibaraSeriesFollowEmail     -> AkibaraSeriesFollowEmail.html (Blend: " . substr_count( $fin, 'akb-blend-screen' ) . ", Red: " . substr_count( $fin, '#E60012' ) . ", Footer: " . substr_count( $fin, 'template_footer' ) . ")\n";
			}
		} catch ( \Throwable $e ) {
			echo "Series follow render err: " . $e->getMessage() . "\n";
		}
	}

	// 8. Back in stock
	if ( 'AkibaraBisInstockEmail' === $id && $sample_product ) {
		$email_obj->object    = $sample_product;
		$email_obj->recipient = (string) ( $user ? $user->user_email : 'ale.fvaras@gmail.com' );
		
		$ref = new ReflectionClass( $email_obj );
		if ( $ref->hasProperty( 'unsubscribe_url' ) ) {
			$p_unsub = $ref->getProperty( 'unsubscribe_url' );
			$p_unsub->setAccessible( true );
			$p_unsub->setValue( $email_obj, home_url( '/aviso/?baja=123' ) );
		}

		try {
			$c = $email_obj->get_content();
			if ( ! empty( $c ) ) {
				$inl = $email_obj->style_inline( $c );
				$fin = apply_filters( 'woocommerce_mail_content', $inl );
				file_put_contents( $out_dir . '/AkibaraBisInstockEmail.html', $fin );
				echo "OK: AkibaraBisInstockEmail       -> AkibaraBisInstockEmail.html (Blend: " . substr_count( $fin, 'akb-blend-screen' ) . ", Red: " . substr_count( $fin, '#E60012' ) . ", Footer: " . substr_count( $fin, 'template_footer' ) . ")\n";
			}
		} catch ( \Throwable $e ) {
			echo "Back in stock render err: " . $e->getMessage() . "\n";
		}
	}
}

echo "=== Rewards & Series/Stock Render Completed ===\n";
