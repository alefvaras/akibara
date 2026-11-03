<?php
/**
 * RewardsTestCase — base compartida de la suite.
 *
 * Cablea el FakeWpdb como $GLOBALS['wpdb'] y stubbea las funciones de
 * usermeta contra el MISMO store, de modo que la re-lectura bajo candado del
 * Ledger vea las escrituras previas exactamente como en MySQL.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @since 0.1.0
 */
abstract class RewardsTestCase extends TestCase {

	protected \FakeWpdb $wpdb;

	/** Bitácora WC del proceso: los eventos de dinero se afirman contra ella. */
	protected \Akibara_Rewards_Fake_Logger $logger;

	/** @var string|false */
	private $previous_error_log = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Red de seguridad: si algún camino llegara al fallback de
		// Support\Log (sin logger de Woo), la suite no ensucia stderr. El
		// destino normal de los eventos de dinero es $this->logger. Se guarda
		// el retorno CRUDO de ini_set: '' también es un valor previo legítimo
		// a restaurar (hallazgo BAJA de la célula).
		$this->previous_error_log = ini_set( 'error_log', '/dev/null' );

		$this->logger = new \Akibara_Rewards_Fake_Logger();
		$logger       = $this->logger;
		Functions\when( 'wc_get_logger' )->alias( static fn() => $logger );

		// El memo por PETICIÓN de la página pública sobrevive entre pruebas
		// dentro del mismo proceso: sin este reseteo, un test que la resuelve
		// ausente decidiría el resultado del siguiente (y la suite corre en
		// orden aleatorio).
		$memo = new \ReflectionProperty( \Akibara\Rewards\Points\ProgramPage::class, 'memo' );
		$memo->setAccessible( true );
		$memo->setValue( null, null );

		// Lo mismo con lo que el canje recuerda durante UNA petición: los pedidos
		// que desmarcó para pagar y si ya vigila el cierre.
		foreach ( array( 'awaiting_outcome' => array(), 'shutdown_hooked' => false ) as $nombre => $valor ) {
			$estatico = new \ReflectionProperty( \Akibara\Rewards\Points\Redemption::class, $nombre );
			$estatico->setAccessible( true );
			$estatico->setValue( null, $valor );
		}

		$this->wpdb        = new \FakeWpdb();
		$GLOBALS['wpdb']   = $this->wpdb;

		$wpdb = $this->wpdb;

		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key, bool $single = true ) use ( $wpdb ) {
				unset( $single );
				return $wpdb->user_meta[ $user_id ][ $key ] ?? '';
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, $value ) use ( $wpdb ): bool {
				$wpdb->user_meta[ $user_id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_user_meta' )->alias(
			static function ( int $user_id, string $key ) use ( $wpdb ): bool {
				unset( $wpdb->user_meta[ $user_id ][ $key ] );
				return true;
			}
		);

		// Fieles al core: cada meta es una LISTA de filas. $single devuelve la
		// primera (o '' si no hay); sin $single, el array completo. La ruta
		// local de CusRev escribe UNA FILA POR ADJUNTO (unique=false) y un
		// doble de fila única escondería justo esa forma (lección
		// maybe_serialize).
		Functions\when( 'get_comment_meta' )->alias(
			static function ( int $comment_id, string $key = '', bool $single = false ) use ( $wpdb ) {
				$rows = $wpdb->comment_meta[ $comment_id ][ $key ] ?? array();
				if ( $single ) {
					return array() === $rows ? '' : $rows[0];
				}
				return $rows;
			}
		);
		Functions\when( 'add_comment_meta' )->alias(
			static function ( int $comment_id, string $key, $value, bool $unique = false ) use ( $wpdb ) {
				if ( $unique && ! empty( $wpdb->comment_meta[ $comment_id ][ $key ] ) ) {
					return false;
				}
				$wpdb->comment_meta[ $comment_id ][ $key ][] = $value;
				return true;
			}
		);
		Functions\when( 'update_comment_meta' )->alias(
			static function ( int $comment_id, string $key, $value ) use ( $wpdb ): bool {
				$wpdb->comment_meta[ $comment_id ][ $key ] = array( $value );
				return true;
			}
		);
		Functions\when( 'delete_comment_meta' )->alias(
			static function ( int $comment_id, string $key ) use ( $wpdb ): bool {
				unset( $wpdb->comment_meta[ $comment_id ][ $key ] );
				return true;
			}
		);

		// `function_exists()` da true por contagio en la suite, así que estos
		// guardias del código se ejecutan y hay que tenerlos siempre stubeados.
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		// Pesos chilenos: la tienda muestra los precios sin decimales.
		Functions\when( 'wc_get_price_decimals' )->justReturn( 0 );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-08-30 12:00:00' );
		Functions\when( 'wp_timezone' )->alias(
			static fn(): \DateTimeZone => new \DateTimeZone( 'America/Santiago' )
		);
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => $default_value
		);
		Functions\when( 'update_option' )->justReturn( true );
		// Guardar el panel purga el carrusel entero (`Carousel::flush()`) cuando
		// cambia cualquier ajuste suyo: transient + portada. Van en el caso base
		// porque cualquier prueba que guarde el panel pasa por ahí.
		Functions\when( 'delete_transient' )->justReturn( true );
		// «¿Esta persona ya compró?» consulta pedidos anteriores. Por defecto no
		// hay ninguno: cada prueba que necesite historia la declara ella misma.
		Functions\when( 'wc_get_orders' )->justReturn( array() );
		// Las consulta el exportador de datos personales para entregar las
		// fotos de las reseñas. Sin reseñas por defecto: cada prueba declara
		// las suyas.
		Functions\when( 'get_comments' )->justReturn( array() );
		Functions\when( 'wp_get_attachment_url' )->justReturn( false );
		// La página de Mi cuenta: la pregunta el payload del carrito para el
		// gancho del invitado. Va AQUÍ y no en cada prueba porque Brain Monkey
		// define la función para toda la suite en cuanto una la dobla, y las
		// demás se quedaban sin expectativa (contagio de `function_exists`,
		// lección de 2026-08; lo repitió esta tanda el 2026-09-16).
		Functions\when( 'wc_get_page_id' )->justReturn( 9 );
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.test/mi-cuenta/' );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		// La cuenta dueña del saldo, que es a quien se le escribe el correo de
		// puntos desde la 0.4.9. Por defecto NO existe, que es el caso del
		// comprador invitado; una prueba que necesite cuenta la declara en
		// `$this->wpdb->users`. Va en el caso base por el contagio de
		// `function_exists()`: sin doble, cualquier prueba que envíe el correo
		// de un pedido muere según el orden de ejecución.
		Functions\when( 'get_user_by' )->alias(
			function ( string $field, $valor ) {
				foreach ( $this->wpdb->users as $id => $datos ) {
					$coincide = 'id' === strtolower( $field )
						? (int) $id === (int) $valor
						: strtolower( (string) ( $datos[ 'user_' . strtolower( $field ) ] ?? $datos[ $field ] ?? '' ) ) === strtolower( (string) $valor );
					if ( $coincide ) {
						$user             = new \WP_User( (int) $id );
						$user->user_email = (string) ( $datos['user_email'] ?? '' );
						$user->user_login = (string) ( $datos['user_login'] ?? '' );
						return $user;
					}
				}
				return false;
			}
		);
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '' ): string => 'https://akibara.test' . $path
		);
		// La arma el enlace de preferencias de correo de la invitación, que
		// sale en CUALQUIER prueba que envíe una. Va en el caso base porque
		// wp-stubs no la define y `function_exists()` da true por contagio:
		// doblarla sólo en su propia prueba deja a las demás con «not defined
		// nor mocked» según el orden de ejecución.
		// La sal que firma el enlace de baja de la invitación: sale en
		// cualquier prueba que envíe una. Fija, para que la firma sea
		// reproducible.
		Functions\when( 'wp_salt' )->justReturn( 'sal-de-pruebas' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value = null, $url = '' ): string {
				$url = (string) $url;
				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
			}
		);
		// Los correos del carril de puntos resuelven el destino de su botón con
		// esta función. El guard `function_exists()` del código NO alcanza: en la
		// suite devuelve TRUE por contagio de otra prueba y Brain Monkey lanza
		// «not defined nor mocked». Vive en el caso base para que el resultado no
		// dependa del orden de ejecución.
		Functions\when( 'wc_get_account_endpoint_url' )->alias(
			static fn( string $endpoint = '' ): string => 'https://akibara.test/mi-cuenta/' . $endpoint . '/'
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_email' )->alias(
			static fn( $v ): string => filter_var( (string) $v, FILTER_VALIDATE_EMAIL ) ? (string) $v : ''
		);
		Functions\when( 'sanitize_key' )->alias(
			static fn( $v ): string => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) )
		);
		Functions\when( 'shortcode_atts' )->alias(
			static fn( array $defaults, $atts ): array => array_merge( $defaults, array_intersect_key( (array) $atts, $defaults ) )
		);
		Functions\when( '__' )->returnArg();
		// Fieles al core: escapan DE VERDAD — un stub identidad dejaba
		// invisible cualquier regresión XSS en el markup (célula 2a).
		Functions\when( 'esc_html' )->alias(
			static fn( $text ): string => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false )
		);
		Functions\when( 'esc_attr' )->alias(
			static fn( $text ): string => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false )
		);
		Functions\when( 'esc_url' )->returnArg();
		// Lo usa la versión en TEXTO PLANO de los correos, que no escapa HTML.
		Functions\when( 'esc_url_raw' )->returnArg();
		// La parte plana del multipart se DERIVA del HTML; sin este doble el
		// derivador muere en cualquier prueba que envíe un correo.
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( $text ): string => trim( (string) strip_tags( (string) $text ) )
		);
		Functions\when( 'number_format_i18n' )->alias(
			static fn( $number ) => number_format( (float) $number, 0, ',', '.' )
		);
	}

	protected function tearDown(): void {
		if ( false !== $this->previous_error_log ) {
			ini_set( 'error_log', $this->previous_error_log );
		}
		unset( $GLOBALS['wpdb'], $GLOBALS['akb_rewards_test_wc'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Instala un contenedor WC() falso con sesión y mailer, y stubbea WC().
	 *
	 * @return \Akibara_Rewards_Fake_WC
	 */
	protected function install_fake_wc(): \Akibara_Rewards_Fake_WC {
		$wc          = new \Akibara_Rewards_Fake_WC();
		$wc->session = new \Akibara_Rewards_Fake_Session();

		$GLOBALS['akb_rewards_test_wc'] = $wc;

		Functions\when( 'WC' )->alias(
			static fn() => $GLOBALS['akb_rewards_test_wc']
		);

		return $wc;
	}

	/**
	 * Pedido de prueba con líneas ya calculadas.
	 *
	 * @param int   $id       ID.
	 * @param int   $customer Cliente.
	 * @param float $total    Total de líneas (post-descuento, sin IVA).
	 * @param float $tax      IVA de las líneas.
	 * @return \WC_Order
	 */
	protected function make_order( int $id, int $customer, float $total, float $tax ): \WC_Order {
		$order              = new \WC_Order();
		$order->id          = $id;
		$order->customer_id = $customer;
		$order->items['line_item'][] = new \WC_Order_Item( 'Tomo', $total, $tax );
		$order->date_created = new \WC_DateTime( '2026-08-25 10:00:00', new \DateTimeZone( 'America/Santiago' ) );

		return $order;
	}
}
