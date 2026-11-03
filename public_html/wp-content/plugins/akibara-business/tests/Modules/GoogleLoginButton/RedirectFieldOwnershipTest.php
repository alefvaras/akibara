<?php
/**
 * Un solo campo de redirección en el muro de acceso, y validado.
 *
 * WooCommerce Blocks añade `<input name="redirect">` al final del formulario de
 * acceso con el `redirect_to` de la URL TAL CUAL, y sólo ahí: el formulario de
 * crear cuenta se quedaba sin destino. La plantilla del muro lo imprime en los
 * dos, con el destino ya filtrado; si el de Blocks siguiera vivo, en el
 * formulario de acceso habría dos campos con el mismo nombre y ganaría el suyo,
 * que no comprueba a dónde apunta.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\GoogleLoginButton;

use Akibara\Business\Modules\GoogleLoginButton\GoogleLoginButton;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/fixtures/woocommerce-blocks-controller.php';

/** Doble del contenedor de callbacks de un hook de WordPress. */
final class HookDouble {

	/** @var array<int, array<string, array{function: mixed, accepted_args: int}>> */
	public array $callbacks = array();
}

final class RedirectFieldOwnershipTest extends TestCase {

	/** @var mixed */
	private $wp_filter_original;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->wp_filter_original = $GLOBALS['wp_filter'] ?? null;
	}

	protected function tearDown(): void {
		if ( null === $this->wp_filter_original ) {
			unset( $GLOBALS['wp_filter'] );
		} else {
			$GLOBALS['wp_filter'] = $this->wp_filter_original;
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Se retira el campo sin validar de WooCommerce Blocks y nada más.
	 */
	public function test_only_the_blocks_field_is_removed(): void {
		$blocks = new \Automattic\WooCommerce\Blocks\BlockTypesController();
		$ajeno  = new class() {
			public function redirect_to_field(): void {
			}
		};

		$hook                             = new HookDouble();
		$hook->callbacks                  = array(
			10 => array(
				'blocks' => array(
					'function'      => array( $blocks, 'redirect_to_field' ),
					'accepted_args' => 1,
				),
				'ajeno'  => array(
					'function'      => array( $ajeno, 'redirect_to_field' ),
					'accepted_args' => 1,
				),
				'suelta' => array(
					'function'      => 'alguna_funcion',
					'accepted_args' => 1,
				),
			),
		);
		$GLOBALS['wp_filter']['woocommerce_login_form_end'] = $hook;

		$retirados = array();
		Functions\when( 'remove_action' )->alias(
			static function ( string $hook_name, $callback, int $priority ) use ( &$retirados ): bool {
				$retirados[] = array( $hook_name, get_class( $callback[0] ), $priority );

				return true;
			}
		);

		( new GoogleLoginButton() )->drop_unvalidated_redirect_field();

		self::assertCount( 1, $retirados, 'Sólo el de WooCommerce Blocks: un homónimo de otro plugin no es asunto nuestro.' );
		self::assertSame( 'woocommerce_login_form_end', $retirados[0][0] );
		self::assertSame( \Automattic\WooCommerce\Blocks\BlockTypesController::class, $retirados[0][1] );
		self::assertSame( 10, $retirados[0][2] );
	}

	/**
	 * @testdox Sin el hook registrado no revienta: el muro se pinta igual.
	 */
	public function test_it_survives_an_absent_hook(): void {
		unset( $GLOBALS['wp_filter'] );

		$modulo = new GoogleLoginButton();

		self::assertNull( $modulo->drop_unvalidated_redirect_field() );
	}
}
