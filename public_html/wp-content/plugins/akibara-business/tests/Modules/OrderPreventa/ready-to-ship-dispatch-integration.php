<?php
/**
 * Gate de integración del despacho de "preventa lista" (P1).
 *
 * Las pruebas unitarias (`ReadyToShipEmailDispatchTest`) fijan el control de
 * flujo con dobles. Este gate ejercita el MISMO `trigger()` contra WooCommerce,
 * MariaDB y el locale reales, y verifica lo que ningún doble puede:
 *
 *   - `is_locale_switched()` — locale del cliente colgado en el worker;
 *   - `has_filter()` sobre los tres callbacks que instala `WC_Email::send()`;
 *   - `WC_Email::$sending`, que WooCommerce enciende en `get_content()` y sólo
 *     apaga desde `phpmailer_init`;
 *   - exclusión REAL del lock de despacho, con **dos conexiones MariaDB
 *     distintas**: `GET_LOCK()` es reentrante para la sesión dueña, así que
 *     consultar desde la misma conexión demuestra la liberación pero NO la
 *     exclusión entre workers [revisión adversarial Codex, P1-E];
 *   - `marker_is_durable()` real contra el almacenamiento (HPOS o `postmeta`).
 *
 * Cero correo externo por contrato: se fuerzan `woocommerce_mail_callback` —que
 * puede REEMPLAZAR `wp_mail` y eludir cualquier filtro sobre él— y `pre_wp_mail`
 * como cinturón redundante. Ambos se retiran en el `finally` [P1-F].
 *
 * Todo recurso creado lleva el `run_id` de la corrida y se borra en el
 * `finally`; el resumen final vuelve a contar para probar cero residuo. Las
 * salidas por error usan excepciones, NUNCA `WP_CLI::error()` dentro del cuerpo:
 * `error()` llama `exit` y PHP no garantiza el `finally` después de un `exit`
 * [P0-D, mismo defecto de harness].
 *
 * Ejecutar con el wrapper, que detiene cron y lo repone con `trap`:
 *
 *   bash tools/akibara-releases/qa-preventa-dispatch.sh
 *
 * @package Akibara\Business\Tests
 */

$run_id   = 'p1gate-' . substr( md5( (string) getmypid() . microtime() ), 0, 8 );
$failures = array();
$log      = static function ( $line ) {
	WP_CLI::log( $line );
};
$check    = static function ( $name, $ok ) use ( &$failures, $log ) {
	$log( ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name );
	if ( ! $ok ) {
		$failures[] = $name;
	}
};

$log( "=== Gate de integración del despacho ready-to-ship — run {$run_id} ===" );

global $wpdb;

// --- Preflight: este gate escribe pedidos; sólo puede correr en local --------
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Este gate sólo corre en WP_ENVIRONMENT_TYPE=local: crea y borra pedidos reales.' );
}
if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce no está activo.' );
}

// --- Segunda conexión MariaDB, para probar exclusión de verdad --------------
$second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$second->suppress_errors( true );

$cid_main   = (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
$cid_second = (string) $second->get_var( 'SELECT CONNECTION_ID()' );

$log( "Conexiones: principal={$cid_main} · secundaria={$cid_second}" );

// --- Cero correo externo, por contrato --------------------------------------
$mail_calls = 0;

$fake_mailer = static function () use ( &$mail_calls ) {
	++$mail_calls;
	return true; // nunca toca red ni PHPMailer.
};

$force_callback = static function () use ( $fake_mailer ) {
	return $fake_mailer;
};
add_filter( 'woocommerce_mail_callback', $force_callback, PHP_INT_MAX );

$block_wp_mail = static function () use ( &$mail_calls ) {
	++$mail_calls;
	return true;
};
add_filter( 'pre_wp_mail', $block_wp_mail, PHP_INT_MAX );

// --- Utilidades --------------------------------------------------------------
$orders = array();

$make_order = static function () use ( &$orders, $run_id ) {
	$product_id = (int) current(
		get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		)
	);

	if ( $product_id <= 0 ) {
		throw new RuntimeException( 'No hay productos publicados para armar la orden de prueba.' );
	}

	$order = wc_create_order();
	$order->set_status( 'processing' );
	$order->set_billing_email( $run_id . '@akibara.local' );
	$item_id = $order->add_product( wc_get_product( $product_id ), 1 );
	$order->update_meta_data( \Akibara\Business\Modules\OrderPreventa\MetaKeys::ORDER_HAS_PREVENTA, 'yes' );
	$order->update_meta_data( '_akb_gate_run_id', $run_id );
	$order->save();

	$item = $order->get_item( $item_id );
	$item->update_meta_data( \Akibara\Business\Modules\OrderPreventa\MetaKeys::ITEM_WAS_PREVENTA, 'yes' );
	$item->save();

	$orders[] = $order->get_id();

	return $order;
};

$marker_of = static function ( $order_id ) {
	$fresh = wc_get_order( $order_id );
	return (string) $fresh->get_meta( \Akibara\Business\Modules\OrderPreventa\MetaKeys::ORDER_READY_EMAIL_SENT );
};

/**
 * Exclusión real: ¿puede la SEGUNDA conexión tomar el lock de este pedido?
 * Devuelve true sólo si lo adquirió (y entonces lo libera).
 */
$second_can_lock = static function ( $order_id ) use ( $second ) {
	$name = 'akb-ready-email-' . $order_id;
	$got  = (int) $second->get_var( $second->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
	if ( 1 === $got ) {
		$second->get_var( $second->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		return true;
	}
	return false;
};

// Los tres callbacks que instala `WC_Email::send()`, por tag y método.
$mail_filters = array(
	'wp_mail_from'         => 'get_from_address',
	'wp_mail_from_name'    => 'get_from_name',
	'wp_mail_content_type' => 'get_content_type',
);

/**
 * Verificación por CALLBACK EXACTO de una instancia.
 *
 * `has_filter( $tag )` sin callback devuelve un booleano del tag COMPLETO: con
 * cualquier filtro ajeno enganchado, una fuga del callback de este email no
 * cambia ese booleano y el gate pasaría en falso [Codex ronda 2, P1-C]. Por eso
 * se pregunta por el par (tag, callback de ESTA instancia).
 */
$instance_filters_gone = static function ( $email ) use ( $mail_filters ) {
	foreach ( $mail_filters as $tag => $method ) {
		if ( false !== has_filter( $tag, array( $email, $method ) ) ) {
			return false;
		}
	}
	return true;
};

// Filtro AJENO sembrado en los tres tags: obliga a que el gate distinga entre
// "el tag tiene filtros" y "el callback de este email quedó colgado", y además
// prueba que la limpieza no se lleva por delante lo de terceros.
$foreign_filter = static function ( $value ) {
	return $value;
};
foreach ( array_keys( $mail_filters ) as $tag ) {
	add_filter( $tag, $foreign_filter, 5 );
}

$foreign_filters_intact = static function () use ( $mail_filters, $foreign_filter ) {
	foreach ( array_keys( $mail_filters ) as $tag ) {
		if ( false === has_filter( $tag, $foreign_filter ) ) {
			return false;
		}
	}
	return true;
};

try {
	// -----------------------------------------------------------------------
	// 1. Exclusión mutua REAL mientras el despacho corre [P1-E].
	// -----------------------------------------------------------------------
	$order         = $make_order();
	$locked_during = null;

	$probe = new class() extends \Akibara\Business\Modules\OrderPreventa\ReadyToShipEmail {
		/** @var callable|null */
		public $akb_probe = null;

		public $akb_sent = 0;

		public function get_content_html(): string {
			if ( is_callable( $this->akb_probe ) ) {
				call_user_func( $this->akb_probe );
			}
			return '<p>gate</p>';
		}

		public function send( $to, $subject, $message, $headers, $attachments ) {
			++$this->akb_sent;
			return parent::send( $to, $subject, $message, $headers, $attachments );
		}
	};
	$probe->enabled   = 'yes';
	$probe->akb_probe = static function () use ( &$locked_during, $second_can_lock, $order ) {
		// Estamos DENTRO del despacho: el worker principal tiene el lock.
		$locked_during = $second_can_lock( $order->get_id() );
	};

	$probe->trigger( $order->get_id() );

	$check( 'las dos conexiones son sesiones distintas', $cid_main !== $cid_second && '' !== $cid_second );
	$check( 'con el despacho en curso, otra conexión NO puede tomar el lock', false === $locked_during );
	$check( 'terminado el despacho, la otra conexión SÍ puede tomarlo', true === $second_can_lock( $order->get_id() ) );
	$check( 'el despacho entregó exactamente una vez', 1 === $probe->akb_sent );
	$check( 'el correo nunca salió a la red (callback local)', $mail_calls > 0 );
	$check( 'camino feliz: locale restaurado', ! is_locale_switched() );
	$check( 'camino feliz: $sending apagado', false === $probe->sending );
	$check( 'camino feliz: el callback exacto de esta instancia no quedó filtrado', $instance_filters_gone( $probe ) );
	$check( 'camino feliz: el filtro AJENO sigue intacto', $foreign_filters_intact() );
	$check(
		'el chequeo booleano de has_filter() habría pasado en falso (por eso se mide el callback exacto)',
		false !== has_filter( 'wp_mail_from' )
	);
	$check( 'camino feliz: marker persistido', '' !== $marker_of( $order->get_id() ) );

	// -----------------------------------------------------------------------
	// 2. Excepción DENTRO de setup_locale() [P1-A].
	// -----------------------------------------------------------------------
	$order_locale = $make_order();

	$throws_in_setup = new class() extends \Akibara\Business\Modules\OrderPreventa\ReadyToShipEmail {
		public $akb_sent = 0;

		public function setup_locale() {
			parent::setup_locale();
			throw new RuntimeException( 'plugin_locale reventó (gate)' );
		}

		public function send( $to, $subject, $message, $headers, $attachments ) {
			++$this->akb_sent;
			return parent::send( $to, $subject, $message, $headers, $attachments );
		}
	};
	$throws_in_setup->enabled = 'yes';

	$threw = false;
	try {
		$throws_in_setup->trigger( $order_locale->get_id() );
	} catch ( \Throwable $e ) {
		$threw = ( 'plugin_locale reventó (gate)' === $e->getMessage() );
	}

	$check( 'setup_locale que lanza propaga la excepción', $threw );
	$check( 'setup_locale que lanza NO deja el locale cambiado', ! is_locale_switched() );
	$check( 'setup_locale que lanza libera el lock (verificado desde la otra conexión)', $second_can_lock( $order_locale->get_id() ) );
	$check( 'setup_locale que lanza no entrega nada', 0 === $throws_in_setup->akb_sent );
	$check( 'setup_locale que lanza deja el marker ausente', '' === $marker_of( $order_locale->get_id() ) );

	// -----------------------------------------------------------------------
	// 3. Excepción en el render, con el ciclo real de $sending [P1-B].
	// -----------------------------------------------------------------------
	$order_render = $make_order();

	$throws_in_render = new class() extends \Akibara\Business\Modules\OrderPreventa\ReadyToShipEmail {
		public $akb_sent = 0;

		public function get_content_html(): string {
			// La base ya encendió $sending en get_content().
			throw new RuntimeException( 'plantilla rota (gate)' );
		}

		public function send( $to, $subject, $message, $headers, $attachments ) {
			++$this->akb_sent;
			return parent::send( $to, $subject, $message, $headers, $attachments );
		}
	};
	$throws_in_render->enabled = 'yes';

	$threw = false;
	try {
		$throws_in_render->trigger( $order_render->get_id() );
	} catch ( \Throwable $e ) {
		$threw = ( 'plantilla rota (gate)' === $e->getMessage() );
	}

	$check( 'render que lanza propaga la excepción', $threw );
	$check( 'render que lanza NO deja $sending encendido', false === $throws_in_render->sending );
	$check( 'render que lanza NO deja el locale cambiado', ! is_locale_switched() );
	$check( 'render que lanza libera el lock (otra conexión)', $second_can_lock( $order_render->get_id() ) );
	$check( 'render que lanza deja el marker ausente', '' === $marker_of( $order_render->get_id() ) );
	$check( 'render que lanza no entrega nada', 0 === $throws_in_render->akb_sent );

	// -----------------------------------------------------------------------
	// 4. Excepción DENTRO de WC_Email::send(), con los filtros ya instalados
	//    [P1-C]. `woocommerce_mail_content` corre después de los tres
	//    add_filter y antes del callback: es el punto exacto donde la clase
	//    base se salta sus propios remove_filter.
	// -----------------------------------------------------------------------
	$order_filters = $make_order();

	$explode_in_send = static function () {
		throw new RuntimeException( 'woocommerce_mail_content reventó (gate)' );
	};
	add_filter( 'woocommerce_mail_content', $explode_in_send, PHP_INT_MAX );

	$inside_send = new \Akibara\Business\Modules\OrderPreventa\ReadyToShipEmail();
	$inside_send->enabled = 'yes';

	$threw = false;
	try {
		$inside_send->trigger( $order_filters->get_id() );
	} catch ( \Throwable $e ) {
		$threw = ( 'woocommerce_mail_content reventó (gate)' === $e->getMessage() );
	}

	remove_filter( 'woocommerce_mail_content', $explode_in_send, PHP_INT_MAX );

	$check( 'excepción dentro de send() propaga', $threw );
	$check( 'excepción dentro de send() retira el callback EXACTO de los 3 tags', $instance_filters_gone( $inside_send ) );
	$check( 'excepción dentro de send() no se lleva el filtro ajeno', $foreign_filters_intact() );
	$check( 'excepción dentro de send() NO deja $sending encendido', false === $inside_send->sending );
	$check( 'excepción dentro de send() NO deja el locale cambiado', ! is_locale_switched() );
	$check( 'excepción dentro de send() libera el lock (otra conexión)', $second_can_lock( $order_filters->get_id() ) );
	$check( 'excepción dentro de send(): el marker se CONSERVA (entrega ambigua)', '' !== $marker_of( $order_filters->get_id() ) );
	$check(
		'excepción dentro de send() deja nota durable',
		count( wc_get_order_notes( array( 'order_id' => $order_filters->get_id() ) ) ) > 0
	);

	// -----------------------------------------------------------------------
	// 5. `marker_is_durable()` REAL contra el almacenamiento [P1-D].
	// -----------------------------------------------------------------------
	$order_marker = $make_order();

	$durability = new class() extends \Akibara\Business\Modules\OrderPreventa\ReadyToShipEmail {
		public function akb_probe_durable( $order_id, $expected ) {
			return $this->marker_is_durable( $order_id, $expected );
		}
	};

	$stamp = time();
	$order_marker->update_meta_data( \Akibara\Business\Modules\OrderPreventa\MetaKeys::ORDER_READY_EMAIL_SENT, $stamp );
	$order_marker->save();

	$check( 'marker_is_durable() confirma un marker realmente guardado', true === $durability->akb_probe_durable( $order_marker->get_id(), $stamp ) );
	$check( 'marker_is_durable() rechaza un sello que NO está en el almacenamiento', false === $durability->akb_probe_durable( $order_marker->get_id(), $stamp + 999 ) );

	// -----------------------------------------------------------------------
	// 6. Camino NO DURABLE recorrido de punta a punta, con las dos conexiones
	//    [Codex ronda 2, P1-E]. El fallo de durabilidad no puede dejar el
	//    marker puesto: si lo dejara, el retry entraría como no-op y el
	//    cliente nunca recibiría el correo pese a que el transporte jamás se
	//    invocó.
	// -----------------------------------------------------------------------
	$order_nd = $make_order();

	$not_durable = new class() extends \Akibara\Business\Modules\OrderPreventa\ReadyToShipEmail {
		public $akb_sent = 0;

		protected function marker_is_durable( int $order_id, int $expected ): bool {
			unset( $order_id, $expected );
			return false; // el almacenamiento no confirmó el guard.
		}

		public function send( $to, $subject, $message, $headers, $attachments ) {
			++$this->akb_sent;
			return parent::send( $to, $subject, $message, $headers, $attachments );
		}
	};
	$not_durable->enabled = 'yes';

	$mail_before_nd = $mail_calls;
	$threw          = false;
	try {
		$not_durable->trigger( $order_nd->get_id() );
	} catch ( \Throwable $e ) {
		$threw = ( $e instanceof \Akibara\Business\Modules\OrderPreventa\ReadyToShipEmailMarkerNotPersistedException );
	}

	$nd_reloaded = wc_get_order( $order_nd->get_id() );

	$check( 'marker no durable: se propaga como fallo pre-transporte', $threw );
	$check( 'marker no durable: cero transporte', 0 === $not_durable->akb_sent );
	$check( 'marker no durable: cero correo', $mail_calls === $mail_before_nd );
	$check( 'marker no durable: el marker queda AUSENTE en el almacenamiento', '' === $marker_of( $order_nd->get_id() ) );
	$check(
		'marker no durable: el contador de intentos avanzó',
		1 === (int) $nd_reloaded->get_meta( \Akibara\Business\Modules\OrderPreventa\MetaKeys::ORDER_READY_EMAIL_ATTEMPTS )
	);
	$check( 'marker no durable: la otra conexión puede tomar el lock', $second_can_lock( $order_nd->get_id() ) );
	$check( 'marker no durable: locale sin fugas', ! is_locale_switched() );
	$check( 'marker no durable: el callback exacto no quedó filtrado', $instance_filters_gone( $not_durable ) );

	// …y el retry posterior entrega exactamente una vez.
	$retry = new class() extends \Akibara\Business\Modules\OrderPreventa\ReadyToShipEmail {
		public $akb_sent = 0;

		public function send( $to, $subject, $message, $headers, $attachments ) {
			++$this->akb_sent;
			return parent::send( $to, $subject, $message, $headers, $attachments );
		}
	};
	$retry->enabled = 'yes';
	$retry->trigger( $order_nd->get_id() );

	$check( 'tras el fallo de durabilidad, el retry entrega EXACTAMENTE una vez', 1 === $retry->akb_sent );
	$check( 'tras el retry el marker sí queda persistido', '' !== $marker_of( $order_nd->get_id() ) );

	// Un tercer intento ya no puede duplicar.
	$third = new class() extends \Akibara\Business\Modules\OrderPreventa\ReadyToShipEmail {
		public $akb_sent = 0;

		public function send( $to, $subject, $message, $headers, $attachments ) {
			++$this->akb_sent;
			return parent::send( $to, $subject, $message, $headers, $attachments );
		}
	};
	$third->enabled = 'yes';
	$third->trigger( $order_nd->get_id() );

	$check( 'un tercer intento no duplica la entrega', 0 === $third->akb_sent );

	// El gate entero corrió sin llevarse por delante un filtro de terceros.
	$check( 'el filtro AJENO sobrevivió a todos los caminos del gate', $foreign_filters_intact() );
} finally {
	$log( 'Limpieza…' );

	remove_filter( 'woocommerce_mail_callback', $force_callback, PHP_INT_MAX );
	remove_filter( 'pre_wp_mail', $block_wp_mail, PHP_INT_MAX );
	foreach ( array_keys( $mail_filters ) as $tag ) {
		remove_filter( $tag, $foreign_filter, 5 );
	}

	foreach ( $orders as $id ) {
		$o = wc_get_order( $id );
		if ( $o ) {
			$o->delete( true );
		}
	}
}

// --- Cero residuo, verificado ------------------------------------------------
$leftover = 0;
foreach ( $orders as $id ) {
	if ( wc_get_order( $id ) ) {
		++$leftover;
	}
}

$locks_free = true;
foreach ( $orders as $id ) {
	if ( ! $second_can_lock( $id ) ) {
		$locks_free = false;
	}
}

$check( 'cero pedidos de prueba sobrevivientes', 0 === $leftover );
$check( 'cero locks de despacho retenidos', $locks_free );
$check( 'el callback de correo forzado quedó retirado', false === has_filter( 'woocommerce_mail_callback' ) );
$check( 'el gate retiró su propio filtro ajeno de prueba', ! $foreign_filters_intact() );
$check( 'el locale quedó como al inicio', ! is_locale_switched() );

$second->close();

if ( ! empty( $failures ) ) {
	WP_CLI::error( sprintf( "%d comprobación(es) fallaron:\n  - %s", count( $failures ), implode( "\n  - ", $failures ) ) );
}

WP_CLI::success( sprintf( 'Gate de integración del despacho ready-to-ship OK (run %s).', $run_id ) );
