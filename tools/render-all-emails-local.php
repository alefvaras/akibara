<?php
/**
 * Render all transactional emails to HTML files for visual QA auditing.
 */

$out_dir = '/tmp/all-rendered-emails';
if ( ! is_dir( $out_dir ) ) {
	mkdir( $out_dir, 0777, true );
}

$mailer = WC()->mailer();
$emails = $mailer->get_emails();

// Find an order with items and volume badges
$orders = wc_get_orders( array( 'limit' => 20, 'status' => 'any' ) );
$order  = null;
foreach ( $orders as $o ) {
	foreach ( $o->get_items() as $item ) {
		if ( preg_match( '/\d+/', $item->get_name() ) ) {
			$order = $o;
			break 2;
		}
	}
}
if ( ! $order && ! empty( $orders ) ) {
	$order = $orders[0];
}

$user = get_user_by( 'email', 'ale.fvaras@gmail.com' ) ?: wp_get_current_user();
if ( ! $user || 0 === $user->ID ) {
	$users = get_users( array( 'number' => 1 ) );
	$user  = ! empty( $users ) ? $users[0] : null;
}

echo "Using order ID: " . ( $order ? $order->get_id() : 'none' ) . "\n";
echo "Using user: " . ( $user ? $user->user_email : 'none' ) . "\n";

$rendered_summary = array();

foreach ( $emails as $id => $email ) {
	if ( ! $email->is_enabled() ) {
		echo "Skipping disabled: $id\n";
		continue;
	}

	// Setup mock data according to email class
	$is_order_email = (
		stripos( $id, 'order' ) !== false ||
		stripos( $id, 'note' ) !== false ||
		stripos( $id, 'tracking' ) !== false ||
		stripos( $id, 'preventa' ) !== false
	);

	if ( $is_order_email ) {
		if ( ! $order ) {
			echo "No order for $id\n";
			continue;
		}
		$email->object = $order;
		$email->find['order-number']    = $order->get_order_number();
		$email->find['order-date']      = wc_format_datetime( $order->get_date_created() );
		$email->replace['order-number'] = $order->get_order_number();
		$email->replace['order-date']   = wc_format_datetime( $order->get_date_created() );

		if ( false !== stripos( $id, 'note' ) ) {
			$email->customer_note = "Estimado cliente, tu paquete ha sido embalado cuidadosamente con papel kraft y refuerzo en las esquinas para garantizar su máxima protección durante el despacho.";
		}
		if ( false !== stripos( $id, 'refund' ) ) {
			$email->partial_refund = false;
		}
	} else {
		if ( ! $user ) {
			echo "No user for $id\n";
			continue;
		}
		$email->object     = $user;
		$email->user_login = $user->user_login;
		$email->user_email = $user->user_email;
		$email->reset_key  = 'demo_key_777';
	}

	try {
		$content = $email->get_content();
		if ( empty( $content ) ) {
			echo "Content empty for $id\n";
			continue;
		}
		$inlined = $email->style_inline( $content );
		$final   = apply_filters( 'woocommerce_mail_content', $inlined );

		$safe_name = preg_replace( '/[^a-zA-Z0-9_-]/', '_', $id );
		$file_path = $out_dir . '/' . $safe_name . '.html';
		file_put_contents( $file_path, $final );

		$white_png    = substr_count( $final, 'akb-hdr-white' );
		$u_body       = substr_count( $final, 'u + .body' );
		$blend_spans  = substr_count( $final, 'akb-blend-screen' );
		$vols         = substr_count( $final, 'class="akb-vol"' );
		$footer_links = substr_count( $final, 'template_footer' );

		$rendered_summary[ $safe_name ] = array(
			'file'         => $file_path,
			'white_png'    => $white_png,
			'u_body'       => $u_body,
			'blend_spans'  => $blend_spans,
			'vols'         => $vols,
		);

		echo sprintf( "OK: %-35s -> %s (Blend: %d, Vols: %d, WhitePNG: %d)\n",
			$id, basename( $file_path ), $blend_spans, $vols, $white_png
		);
	} catch ( \Throwable $e ) {
		echo "ERROR on $id: " . $e->getMessage() . "\n";
	}
}

file_put_contents( $out_dir . '/summary.json', json_encode( $rendered_summary, JSON_PRETTY_PRINT ) );
echo "Rendered " . count( $rendered_summary ) . " emails successfully into $out_dir\n";
