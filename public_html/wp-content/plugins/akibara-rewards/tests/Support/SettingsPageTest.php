<?php
/**
 * Contrato de la pantalla que fija TODOS los montos del programa.
 *
 * Es la superficie donde el dueño cambia dinero con un clic y no tenía ni una
 * prueba: un campo mal saneado acá acredita de más en toda la tienda.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Support;

use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\Schema;
use Akibara\Rewards\Support\SettingsPage;
use Brain\Monkey\Functions;

/**
 * @since 0.3.1
 */
final class SettingsPageTest extends RewardsTestCase {

	private SettingsPage $page;

	/** @var array<int,array{option:string,value:mixed,autoload:mixed}> */
	private array $saved = array();

	/** @var array<int,array{key:string,value:mixed}> */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();

		$this->page = new SettingsPage();

		$saved = &$this->saved;
		Functions\when( 'update_option' )->alias(
			static function ( string $option, $value, $autoload = null ) use ( &$saved ): bool {
				$saved[] = array(
					'option'   => $option,
					'value'    => $value,
					'autoload' => $autoload,
				);
				return true;
			}
		);
		// Fiel al núcleo: con `trim( strip_tags() )` el «<» suelto no crecía
		// a «&lt;», y el tope del título cortaba entidades sin que ninguna
		// prueba lo viera (REV-QF-RW-1).
		Functions\when( 'sanitize_text_field' )->alias( array( self::class, 'sanear_como_wordpress' ) );
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( $v ): string => (string) preg_replace( '/<[^>]*>/', '', (string) $v )
		);

		$transients = &$this->transients;
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value, $ttl = 0 ) use ( &$transients ): bool {
				unset( $ttl );
				$transients[] = array(
					'key'   => $key,
					'value' => $value,
				);
				return true;
			}
		);
	}

	/**
	 * Lo que el formulario manda de los interruptores de ficha que vienen
	 * ENCENDIDOS de fábrica. Un checkbox apagado no viaja en el POST: un
	 * guardado de prueba sin ellos los APAGA, y eso cambia la ficha (y purga).
	 * «Guardar sin cambiar nada» es mandarlos como están.
	 *
	 * @param array<string,string> $input Lo demás del formulario.
	 * @return array<string,string>
	 */
	private static function sin_tocar_la_ficha( array $input = array() ): array {
		return array_merge(
			array(
				'akb_rewards_pdp_notice_enabled' => 'yes',
				'akb_rewards_welcome_enabled'    => 'yes',
				'akb_rewards_earn_enabled'       => 'yes',
			),
			$input
		);
	}

	/**
	 * «Guardar sin cambiar nada» salvo UNA casilla de fábrica, que el dueño
	 * desmarca: no viaja en el POST.
	 *
	 * @param string $option La casilla desmarcada.
	 * @return array<string,string>
	 */
	private static function sin_la_casilla( string $option ): array {
		return array_diff_key( self::sin_tocar_la_ficha(), array( $option => true ) );
	}

	/**
	 * Revisión independiente 2026-09-13: los datos para Google viven dentro de
	 * cada ficha cacheada. Sin purgar, el interruptor no cambiaba nada durante
	 * días. No promete nada al cliente, así que basta con el origen: la purga
	 * total vaciaba además el CSS/JS minificado, la caché de objetos, OPcache
	 * y el borde entero de Cloudflare (REV-R-R-1-P1).
	 *
	 * @testdox Cambiar «Sacar de Google el texto de las reseñas premiadas» purga el origen, no todo; guardar sin cambiarlo, nada.
	 */
	public function test_the_structured_data_switch_purges_only_when_it_changes(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_schema_exclude_rewarded' === $option ? 'no' : $default_value
		);

		\Brain\Monkey\Actions\expectDone( 'litespeed_purge' )->once()->with( '*' );
		\Brain\Monkey\Actions\expectDone( 'litespeed_purge_all' )->never();
		$encendido = $this->page->save_fields( self::sin_tocar_la_ficha( array( 'akb_rewards_schema_exclude_rewarded' => 'yes' ) ) );
		$igual     = $this->page->save_fields( self::sin_tocar_la_ficha() ); // Queda en «no», como estaba: no purga.

		$this->assertSame( 'yes', $encendido['akb_rewards_schema_exclude_rewarded'] );
		$this->assertSame( 'no', $igual['akb_rewards_schema_exclude_rewarded'] );
	}

	/**
	 * Reapertura S-80f7d8ac (2026-09-19): el aviso de divulgación cambia el
	 * HTML de CADA ficha (el listado de reseñas, vía `comment_text`) igual
	 * que el interruptor de Google cambia el JSON-LD — pero sólo éste
	 * purgaba. Encender o apagar la divulgación dejaba la ficha cacheada
	 * mostrando el aviso viejo (o el nuevo) hasta que la caché caducara sola.
	 *
	 * @testdox Cambiar «Avisar en la reseña que ganó puntos» también purga la caché de las fichas; guardar sin cambiarlo, no.
	 */
	public function test_el_interruptor_de_divulgacion_purga_la_cache_de_las_fichas(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_disclosure_enabled' === $option ? 'no' : $default_value
		);
		Functions\when( 'delete_transient' )->justReturn( true );

		\Brain\Monkey\Actions\expectDone( 'litespeed_purge_all' )->once();
		$encendido = $this->page->save_fields( self::sin_tocar_la_ficha( array( 'akb_rewards_disclosure_enabled' => 'yes' ) ) );
		$igual     = $this->page->save_fields( self::sin_tocar_la_ficha() ); // Queda en «no», como estaba: no vuelve a purgar.

		$this->assertSame( 'yes', $encendido['akb_rewards_disclosure_enabled'] );
		$this->assertSame( 'no', $igual['akb_rewards_disclosure_enabled'] );
	}

	/**
	 * Los dos interruptores de ficha (Google y divulgación) reescriben el
	 * MISMO HTML cacheado: si el dueño cambia ambos en el mismo guardado, la
	 * purga se dispara una sola vez, no dos. Y la de la divulgación, que es la
	 * total, ya incluye la del origen que pide el de Google.
	 *
	 * @testdox Cambiar los dos interruptores de ficha en el mismo guardado purga la caché UNA sola vez, la total.
	 */
	public function test_los_dos_interruptores_de_ficha_purgan_una_sola_vez(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => in_array( $option, array( 'akb_rewards_schema_exclude_rewarded', 'akb_rewards_disclosure_enabled' ), true ) ? 'no' : $default_value
		);
		Functions\when( 'delete_transient' )->justReturn( true );

		\Brain\Monkey\Actions\expectDone( 'litespeed_purge_all' )->once();
		\Brain\Monkey\Actions\expectDone( 'litespeed_purge' )->never();
		$guardado = $this->page->save_fields(
			array(
				'akb_rewards_schema_exclude_rewarded' => 'yes',
				'akb_rewards_disclosure_enabled'      => 'yes',
			)
		);

		$this->assertSame( 'yes', $guardado['akb_rewards_schema_exclude_rewarded'] );
		$this->assertSame( 'yes', $guardado['akb_rewards_disclosure_enabled'] );
	}

	/**
	 * Reapertura S-3d03db68 (2026-09-19): la ayuda de «Sacar de Google el
	 * texto de las reseñas premiadas» abría con «las reseñas que ganaron
	 * puntos siempre se leen con el aviso de que los ganaron», que es falso
	 * con el interruptor de arriba apagado (fábrica y estado actual): la
	 * ficha entonces NO muestra ningún aviso. Esta prueba de catálogo evita
	 * que un futuro cierre reintroduzca la promesa incondicional.
	 *
	 * @testdox La ayuda del campo de Google no promete un aviso que el interruptor de arriba puede tener apagado.
	 */
	public function test_la_ayuda_del_campo_de_google_no_promete_el_aviso(): void {
		$fields = ( new \ReflectionClassConstant( SettingsPage::class, 'FIELDS' ) )->getValue();
		$ayuda  = (string) $fields['akb_rewards_schema_exclude_rewarded']['h'];

		$this->assertStringNotContainsString(
			'siempre se leen con el aviso',
			$ayuda,
			'El aviso depende de otro interruptor: esta ayuda no puede prometerlo incondicional.'
		);
	}

	/**
	 * La misma reapertura, por conducta: con la divulgación apagada (estado
	 * real de la tienda), el panel tiene que DECIR junto al interruptor de
	 * Google que hoy no hay aviso en la ficha; con la divulgación encendida,
	 * ese aviso vivo no debe aparecer.
	 *
	 * @testdox El panel avisa en vivo cuando la divulgación está apagada, y calla cuando está encendida.
	 */
	public function test_el_panel_avisa_en_vivo_segun_el_estado_de_la_divulgacion(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.test/wp-admin/' . $path );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'checked' )->alias( static fn( $a, $b, $echo = true ) => (string) $a === (string) $b ? ' checked="checked"' : '' );
		Functions\when( 'selected' )->alias( static fn( $a, $b, $echo = true ) => (string) $a === (string) $b ? ' selected="selected"' : '' );
		Functions\when( 'submit_button' )->justReturn( null );
		// email_choices() consulta function_exists('WC'): en la suite COMPLETA
		// otra prueba ya la declaró (contagio de function_exists, lección de
		// la casa), así que sin este stub WC() queda «no definida ni mockeada»
		// aquí. Sin WooCommerce instalado, is_object(WC()) debe dar false.
		Functions\when( 'WC' )->justReturn( false );

		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_disclosure_enabled' === $option ? 'no' : $default_value
		);
		ob_start();
		$this->page->render();
		$apagado = ob_get_clean();

		$this->assertStringContainsString(
			'SIN aviso de que ganaron puntos',
			$apagado,
			'Con la divulgación apagada, el panel tiene que decirlo junto al interruptor de Google.'
		);

		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_disclosure_enabled' === $option ? 'yes' : $default_value
		);
		ob_start();
		$this->page->render();
		$encendido = ob_get_clean();

		$this->assertStringNotContainsString(
			'SIN aviso de que ganaron puntos',
			$encendido,
			'Con la divulgación encendida, el aviso vivo no aplica: la ficha sí muestra el aviso.'
		);
	}

	/**
	 * «Listo para enviar» lo registra el plugin de Blue Express, no WooCommerce.
	 * Con ese plugin apagado, el estado desaparece de la lista y el guardado
	 * caía al default: bastaba con tocar cualquier otro ajuste para mover el
	 * momento de la acreditación sin que nadie lo viera (revisión independiente
	 * 2026-09-16).
	 *
	 * @testdox Guardar con el estado ausente de la lista conserva el guardado y avisa.
	 */
	public function test_un_estado_que_hoy_no_existe_se_conserva(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_award_status' === $option ? 'shipping-progress' : $default_value
		);
		// La tienda de la prueba no registra «shipping-progress»: la lista de
		// reserva de `order_status_choices()` sólo trae los del núcleo, que es
		// justo lo que ve una tienda con el plugin de despacho apagado.
		$guardado = $this->page->save_fields( array( 'akb_rewards_award_status' => 'shipping-progress' ) );

		$this->assertSame( 'shipping-progress', $guardado['akb_rewards_award_status'], 'No se mueve la acreditación de sitio a espaldas del dueño.' );
		$avisos = array_merge( ...array_map( static fn( array $t ): array => (array) $t['value'], $this->transients ) );
		// Con la forma que `render()` pinta: un texto suelto lo descartaba y el
		// aviso nunca llegaba a la pantalla.
		$this->assertNotSame(
			array(),
			array_filter( $avisos, static fn( $a ): bool => is_array( $a ) && 'warning' === ( $a['tipo'] ?? '' ) && str_contains( (string) ( $a['texto'] ?? '' ), 'no existe hoy en la tienda' ) ),
			'Y se dice en pantalla.'
		);
	}

	/**
	 * El aviso de arriba viajaba como texto suelto y `render()` sólo pinta
	 * `{tipo, texto}`: se descartaba. Y el desplegable no traía el estado
	 * guardado, así que el navegador enviaba la primera opción y el guardado
	 * nunca llegaba a la rama que conserva y avisa.
	 *
	 * @testdox Con el estado guardado ausente de la tienda, el desplegable lo ofrece marcado y el aviso sale en pantalla.
	 */
	public function test_el_estado_ausente_se_ve_y_su_aviso_sale_en_pantalla(): void {
		$guardadas = array( 'akb_rewards_award_status' => 'shipping-progress' );
		$avisos    = null;
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => $guardadas[ $option ] ?? $default_value
		);
		$this->page->save_fields( array( 'akb_rewards_award_status' => 'shipping-progress' ) );
		foreach ( $this->transients as $t ) {
			if ( SettingsPage::TRANSIENT_AVISOS . '0' === $t['key'] ) {
				$avisos = $t['value'];
			}
		}
		$this->assertIsArray( $avisos, 'El guardado dejó avisos para la pantalla.' );

		$html = $this->pintar_panel( $guardadas, $avisos );

		$this->assertMatchesRegularExpression(
			'#<select name="akb_rewards_award_status" id="akb_rewards_award_status">.*<option value="shipping-progress" selected="selected">shipping-progress \(este estado ya no está disponible\)</option>#s',
			$html,
			'El navegador vuelve a enviar lo guardado, no la primera opción.'
		);
		$this->assertStringContainsString( 'se quedó como estaba (shipping-progress)', $html, 'El aviso del guardado sale en pantalla.' );
	}

	/**
	 * Pinta la pantalla del panel con las options dadas y, si se pasan, los
	 * avisos del último guardado.
	 *
	 * @param array<string,mixed>     $guardadas Options guardadas (el resto, su default).
	 * @param array<int,mixed>|null   $avisos    Avisos del transient del usuario.
	 * @return string
	 */
	private function pintar_panel( array $guardadas, ?array $avisos = null ): string {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_transient' )->alias(
			static fn( string $key ) => SettingsPage::TRANSIENT_AVISOS . '0' === $key && null !== $avisos ? $avisos : false
		);
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.test/wp-admin/' . $path );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'checked' )->alias( static fn( $a, $b, $echo = true ) => (string) $a === (string) $b ? ' checked="checked"' : '' );
		Functions\when( 'selected' )->alias( static fn( $a, $b, $echo = true ) => (string) $a === (string) $b ? ' selected="selected"' : '' );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'WC' )->justReturn( false );
		Functions\when( 'wp_date' )->alias( static fn( string $formato, int $instante ): string => gmdate( 'Y-m-d', $instante ) );
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => $guardadas[ $option ] ?? $default_value
		);

		ob_start();
		$this->page->render();

		return (string) ob_get_clean();
	}

	/**
	 * QA-PANEL-5: 465 caracteres se guardaban enteros y salían como encabezado
	 * de reseñas de TODAS las fichas. Se recorta al tope de la fila y se avisa,
	 * como Akibara → Textos.
	 *
	 * @testdox Un título de reseñas más largo que el tope se guarda recortado y avisa.
	 */
	public function test_el_titulo_largo_se_recorta_y_avisa(): void {
		$largo  = self::tope_del_titulo();
		$parrafo = str_repeat( 'Opiniones de nuestros lectores ', 15 );

		$guardado = $this->page->save_fields( self::sin_tocar_la_ficha( array( 'akb_rewards_carousel_title' => $parrafo ) ) );

		$titulo = (string) $guardado['akb_rewards_carousel_title'];
		$this->assertLessThanOrEqual( $largo, mb_strlen( $titulo, 'UTF-8' ) );
		$this->assertStringStartsWith( 'Opiniones de nuestros lectores Opiniones', $titulo );
		$this->assertSame( rtrim( $titulo ), $titulo, 'Sin el espacio del corte al final.' );

		$avisos = array_merge( ...array_map( static fn( array $t ): array => (array) $t['value'], $this->transients ) );
		$this->assertNotSame(
			array(),
			array_filter( $avisos, static fn( $a ): bool => is_array( $a ) && 'warning' === $a['tipo'] && str_contains( $a['texto'], 'admite hasta ' . $largo . ' caracteres' ) && str_contains( $a['texto'], '«' . $titulo . '»' ) ),
			'El dueño ve que se recortó y cómo quedó.'
		);
	}

	/**
	 * @testdox Un título justo en el tope, con eñes y comillas, se guarda entero y sin aviso: se cuentan caracteres, no bytes.
	 */
	public function test_el_titulo_en_el_tope_se_guarda_entero(): void {
		$largo = self::tope_del_titulo();
		// En bytes pasa de largo (la eñe y las comillas son de dos); en
		// caracteres, justo en el tope.
		$justo = '«' . str_repeat( 'ñ', $largo - 2 ) . '»';

		$guardado = $this->page->save_fields( self::sin_tocar_la_ficha( array( 'akb_rewards_carousel_title' => $justo ) ) );

		$this->assertGreaterThan( $largo, strlen( $justo ) );
		$this->assertSame( $justo, $guardado['akb_rewards_carousel_title'] );
		$avisos = array_merge( ...array_map( static fn( array $t ): array => (array) $t['value'], $this->transients ) );
		$this->assertSame(
			array(),
			array_filter( $avisos, static fn( $a ): bool => is_array( $a ) && str_contains( (string) ( $a['texto'] ?? '' ), 'admite hasta' ) ),
			'Sin aviso: no se recortó nada.'
		);
	}

	/**
	 * REV-QF-RW-1: el tope se medía sobre la option, donde el «<» ya es
	 * «&lt;». 60 caracteres escritos con el teclado (maxlength los aceptaba)
	 * se guardaban como «…con amor &l», con aviso de recorte, y «&l» salía en
	 * el encabezado de reseñas de todas las fichas.
	 *
	 * @testdox Un título de 60 con «<3» al final se guarda entero, sin aviso y sin trozos de entidad.
	 */
	public function test_el_titulo_con_menor_que_en_el_tope_se_guarda_entero(): void {
		$largo   = self::tope_del_titulo();
		$escrito = 'Lo que opinan los lectores del Distrito Akibara, con amor <3';
		$this->assertSame( $largo, mb_strlen( $escrito, 'UTF-8' ), 'El caso del revisor: justo en el tope, «<3» en las posiciones 59-60.' );

		$guardado = (string) $this->page->save_fields( self::sin_tocar_la_ficha( array( 'akb_rewards_carousel_title' => $escrito ) ) )['akb_rewards_carousel_title'];

		$this->assertSame( 'Lo que opinan los lectores del Distrito Akibara, con amor &lt;3', $guardado, 'Se guarda saneado y ENTERO.' );
		$this->assertSame( $escrito, self::lo_que_ve_el_cliente( $guardado ), 'La ficha muestra lo escrito, no «&l».' );
		$this->assertSame( array(), $this->avisos_de_recorte(), 'Sin aviso: el dueño escribió 60, no más.' );
	}

	/**
	 * Desde un «<» hasta el siguiente, el núcleo también convierte «&» y
	 * comillas en entidades: un título de 56 medía 79 y perdía el final
	 * («…del Distrito, c»), con un aviso de recorte que no correspondía.
	 *
	 * @testdox Un título corto con comillas y apóstrofos después de un «<» se guarda entero y sin aviso.
	 */
	public function test_las_comillas_tras_un_menor_que_no_cuentan_de_mas(): void {
		$escrito = "Lo <3 que opinan 'los' lectores, con \"amor\" & más";
		$this->assertLessThan( self::tope_del_titulo(), mb_strlen( $escrito, 'UTF-8' ) );

		$guardado = (string) $this->page->save_fields( self::sin_tocar_la_ficha( array( 'akb_rewards_carousel_title' => $escrito ) ) )['akb_rewards_carousel_title'];

		$this->assertGreaterThan( self::tope_del_titulo(), mb_strlen( $guardado, 'UTF-8' ), 'La option sí pasa del tope: por eso no se puede medir ahí.' );
		$this->assertSame( $escrito, self::lo_que_ve_el_cliente( $guardado ) );
		$this->assertSame( array(), $this->avisos_de_recorte() );
	}

	/**
	 * Lo que llega sin el tope del navegador (devtools, curl) se sigue
	 * recortando, pero en letras visibles y sin partir una entidad, esté donde
	 * esté el «<» respecto del corte.
	 *
	 * @testdox Un título largo con «<» cerca del corte queda en 60 letras visibles, sin trozos de entidad, y el aviso muestra lo que se ve.
	 */
	public function test_el_recorte_nunca_parte_una_entidad(): void {
		$largo = self::tope_del_titulo();

		for ( $pos = $largo - 6; $pos <= $largo + 2; $pos++ ) {
			$this->transients = array();
			$escrito          = str_repeat( 'a', $pos ) . "<3 'y' & más texto de relleno";

			$guardado = (string) $this->page->save_fields( self::sin_tocar_la_ficha( array( 'akb_rewards_carousel_title' => $escrito ) ) )['akb_rewards_carousel_title'];
			$visto    = self::lo_que_ve_el_cliente( $guardado );

			$this->assertDoesNotMatchRegularExpression( '/&#?[a-z0-9]*$/i', $guardado, "Con el «<» en la posición {$pos}, la option no termina en media entidad." );
			$this->assertLessThanOrEqual( $largo, mb_strlen( $visto, 'UTF-8' ), "Posición {$pos}: el cliente ve como mucho el tope." );
			$this->assertStringStartsWith( $visto, $escrito, "Posición {$pos}: lo visible es el principio de lo escrito." );
			$this->assertSame( $guardado, self::sanear_como_wordpress( $visto ), "Posición {$pos}: guardar la pantalla sin tocarla no cambia el título." );

			$avisos = $this->avisos_de_recorte();
			$this->assertCount( 1, $avisos, "Posición {$pos}: se recortó y se avisa." );
			$this->assertStringContainsString( '«' . $visto . '»', self::lo_que_ve_el_cliente( $avisos[0]['texto'] ), 'El aviso (esc_html en pantalla) dice cómo quedó, en letras visibles.' );
		}
	}

	/**
	 * Lo que el cliente lee de un valor guardado: el tema y el carrusel lo
	 * pintan con esc_html() (sin doble escape) y el navegador descodifica.
	 *
	 * @param string $guardado Valor de la option.
	 * @return string
	 */
	private static function lo_que_ve_el_cliente( string $guardado ): string {
		return html_entity_decode( htmlspecialchars( $guardado, ENT_QUOTES, 'UTF-8', false ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Los avisos de recorte que dejó el último guardado.
	 *
	 * @return array<int,array{tipo:string,texto:string}>
	 */
	private function avisos_de_recorte(): array {
		$avisos = array_merge( ...array_map( static fn( array $t ): array => (array) $t['value'], $this->transients ) );

		return array_values( array_filter( $avisos, static fn( $a ): bool => is_array( $a ) && str_contains( (string) ( $a['texto'] ?? '' ), 'admite hasta' ) ) );
	}

	/**
	 * Copia de `_sanitize_text_fields()` de WordPress 7.1 (wp-includes/formatting.php),
	 * con `wp_pre_kses_less_than()` y `wp_strip_all_tags()` en línea. Contrastada
	 * con la función real del espejo para cada caso de esta clase.
	 *
	 * @param mixed $valor Lo que llega del formulario.
	 * @return string
	 */
	public static function sanear_como_wordpress( $valor ): string {
		if ( is_object( $valor ) || is_array( $valor ) ) {
			return '';
		}
		$texto = (string) $valor;
		if ( ! mb_check_encoding( $texto, 'UTF-8' ) ) {
			return '';
		}
		if ( str_contains( $texto, '<' ) ) {
			// wp_pre_kses_less_than(): el «<» que no abre una etiqueta pasa por esc_html().
			$texto = (string) preg_replace_callback(
				'%<[^>]*?((?=<)|>|$)%',
				static fn( array $m ): string => str_contains( $m[0], '>' ) ? $m[0] : htmlspecialchars( $m[0], ENT_QUOTES, 'UTF-8', false ),
				$texto
			);
			// wp_strip_all_tags( $texto, false ).
			$texto = trim( strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $texto ) ) );
			$texto = str_replace( "<\n", "&lt;\n", $texto );
		}
		$texto = trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $texto ) );
		$quito = false;
		while ( preg_match( '/%[a-f0-9]{2}/i', $texto, $m ) ) {
			$texto = str_replace( $m[0], '', $texto );
			$quito = true;
		}

		return $quito ? trim( (string) preg_replace( '/ +/', ' ', $texto ) ) : $texto;
	}

	/**
	 * @testdox El formulario pone el mismo tope que el guardado (maxlength) y la ayuda lo dice; la fecha de vigencia no lleva tope.
	 */
	public function test_el_formulario_dice_y_pone_el_tope_del_titulo(): void {
		$largo = self::tope_del_titulo();
		$html  = $this->pintar_panel( array() );

		$this->assertMatchesRegularExpression( '#<input type="text" name="akb_rewards_carousel_title" [^>]*maxlength="' . $largo . '"#', $html );
		$this->assertStringContainsString( 'Hasta ' . $largo . ' caracteres.', $html );
		$this->assertDoesNotMatchRegularExpression( '#name="akb_rewards_program_start" [^>]*maxlength#', $html );
	}

	/**
	 * El tope del título, del catálogo.
	 *
	 * @return int
	 */
	private static function tope_del_titulo(): int {
		$fields = ( new \ReflectionClassConstant( SettingsPage::class, 'FIELDS' ) )->getValue();
		$largo  = (int) ( $fields['akb_rewards_carousel_title']['largo'] ?? 0 );
		self::assertGreaterThan( 0, $largo, 'El título declara su tope en el catálogo.' );

		return $largo;
	}

	/**
	 * QA-PANEL-5: la etiqueta nombra la portada, pero el carrusel sólo sale con
	 * el mínimo de reseñas. Sin decirlo, el dueño buscaba ahí un título que no
	 * se ve.
	 *
	 * @testdox Bajo el título, el panel dice si hoy la portada muestra el carrusel.
	 */
	public function test_el_panel_dice_si_la_portada_muestra_el_carrusel(): void {
		$this->assertSame(
			'Hoy el carrusel no sale en la portada: sólo 3 reseñas cumplen las condiciones de esta sección y el mínimo es 8. Mientras tanto, este título sólo se ve en las fichas.',
			SettingsPage::carousel_evidence_text( true, 3, 8 )
		);
		$this->assertStringContainsString( 'sólo 1 reseña cumple las', SettingsPage::carousel_evidence_text( true, 1, 8 ) );
		$this->assertStringContainsString( 'ninguna reseña cumple las', SettingsPage::carousel_evidence_text( true, 0, 8 ) );
		$this->assertSame( '', SettingsPage::carousel_evidence_text( true, 8, 8 ), 'Con el mínimo, sale: nada que advertir.' );
		$this->assertStringContainsString( 'está apagado', SettingsPage::carousel_evidence_text( false, 20, 8 ), 'Apagado manda sobre las reseñas.' );

		// Y la pantalla lo pinta con el dato vivo: la base del doble no tiene
		// ninguna reseña elegible.
		$html = $this->pintar_panel( array() );
		$this->assertStringContainsString( 'Hoy el carrusel no sale en la portada: ninguna reseña cumple las condiciones de esta sección y el mínimo es 8.', $html );

		$apagado = $this->pintar_panel( array( 'akb_rewards_carousel_enabled' => 'no' ) );
		$this->assertStringContainsString( 'Hoy el carrusel de la portada está apagado', $apagado );
		$this->assertStringNotContainsString( 'no sale en la portada', $apagado );
	}

	/** @testdox Un estado inventado no rige: cae al guardado, y si tampoco vale, al de fábrica. */
	public function test_un_estado_inventado_no_rige(): void {
		$guardado = $this->page->save_fields( array( 'akb_rewards_award_status' => 'estado-inventado' ) );

		$this->assertSame( Options::DEFAULT_AWARD_STATUS, $guardado['akb_rewards_award_status'] );
	}

	public function test_an_unchecked_switch_is_saved_as_no_not_ignored(): void {
		// El checkbox apagado NO viaja en el POST: si el guardado recorriera
		// lo recibido en vez del catálogo, apagar un carril no se guardaría
		// jamás.
		$guardado = $this->page->save_fields( array( 'akb_rewards_earn_enabled' => 'yes' ) );

		$this->assertSame( 'yes', $guardado['akb_rewards_earn_enabled'] );
		$this->assertSame( 'no', $guardado['akb_rewards_welcome_enabled'], 'Ausente = apagado.' );
		$this->assertSame( 'no', $guardado['akb_rewards_reviews_enabled'] );
	}

	public function test_numbers_are_clamped_by_the_catalog_min_and_max(): void {
		$guardado = $this->page->save_fields(
			array(
				'akb_rewards_carousel_cards' => '999',   // max 20.
				'akb_rewards_carousel_min'   => '0',     // min 1.
				'akb_rewards_earn_points'    => '-40',   // min 0.
				'akb_rewards_earn_amount'    => '100',
			)
		);

		$this->assertSame( 20, $guardado['akb_rewards_carousel_cards'] );
		$this->assertSame( 1, $guardado['akb_rewards_carousel_min'] );
		$this->assertSame( 0, $guardado['akb_rewards_earn_points'] );
		$this->assertSame( 100, $guardado['akb_rewards_earn_amount'] );

		$avisos = array_merge( ...array_map( static fn( array $t ): array => (array) $t['value'], $this->transients ) );
		$textos = array_column( $avisos, 'texto' );
		$this->assertCount( 3, $avisos, 'Cada entero acotado avisa al dueño cómo quedó.' );
		$this->assertStringContainsString( 'admite entre 1 y 20: se guardó en 20.', implode( ' | ', $textos ) );
		$this->assertStringContainsString( 'admite entre 1 y 80: se guardó en 1.', implode( ' | ', $textos ) );
		$this->assertStringContainsString( 'admite entre 0 y 20: se guardó en 0.', implode( ' | ', $textos ) );
	}

	public function test_a_zero_point_value_falls_back_to_one_like_the_engine(): void {
		// El motor cae a 1 (Options::redeem_value): la pantalla no puede
		// quedar mostrando un 0 que no rige en ninguna parte.
		$guardado = $this->page->save_fields( array( 'akb_rewards_redeem_value' => '0' ) );

		$this->assertSame( 1, $guardado['akb_rewards_redeem_value'] );
	}

	public function test_the_point_value_is_an_integer_contract_end_to_end(): void {
		// El campo del panel es entero y el peso chileno no tiene centavos:
		// por eso el motor devuelve int y la etiqueta no puede inventarse una
		// forma con decimales que ningún valor guardable produce.
		$guardado = $this->page->save_fields( array( 'akb_rewards_redeem_value' => '2,5' ) );
		$this->assertSame( 2, $guardado['akb_rewards_redeem_value'] );

		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_redeem_value' === $option ? '3' : $default_value
		);

		$this->assertSame( 3, Options::redeem_value() );
		$this->assertSame( '$3', Options::redeem_label(), 'Sin decimales: la etiqueta habla el idioma del campo.' );
	}

	public function test_free_text_is_sanitized(): void {
		$guardado = $this->page->save_fields(
			array( 'akb_rewards_carousel_title' => '  <script>alert(1)</script>Voces del Distrito  ' )
		);

		// El núcleo quita el <script> CON su contenido (wp_strip_all_tags); el
		// doble anterior dejaba «alert(1)» y lo daba por bueno.
		$this->assertSame( 'Voces del Distrito', $guardado['akb_rewards_carousel_title'] );
	}

	public function test_every_setting_is_stored_autoloaded(): void {
		// 36 options con autoload apagado costaban 17 SELECT sueltos en un
		// render de Mi cuenta y 6 en cada ficha de producto.
		$this->page->save_fields( array() );

		$this->assertNotSame( array(), $this->saved );
		foreach ( $this->saved as $row ) {
			$this->assertTrue( $row['autoload'], $row['option'] . ' debe viajar en alloptions.' );
		}
	}

	public function test_the_catalog_is_the_single_source_of_the_options(): void {
		$nombres = SettingsPage::option_names();

		$this->page->save_fields( array() );

		$this->assertSame(
			$nombres,
			array_column( $this->saved, 'option' ),
			'El guardado recorre el catálogo entero, sin excepciones.'
		);
		$this->assertContains( 'akb_rewards_review_points', $nombres );

		// Y la migración de autoload cubre TODO el catálogo más las dos marcas
		// de arranque: una option nueva entra sola.
		$autoload = Schema::autoloaded_options();
		foreach ( $nombres as $nombre ) {
			$this->assertContains( $nombre, $autoload );
		}
		$this->assertContains( Schema::OPTION_KEY, $autoload );
		$this->assertContains( \Akibara\Rewards\Points\AccountRewards::FLUSH_OPTION, $autoload );
	}

	/**
	 * @testdox El estado disparador de la invitación solo acepta estados reales.
	 *
	 * Nació de la pregunta del dueño (2026-09-01): el motor leía
	 * akb_rewards_invite_status pero la pantalla no lo exponía — opción
	 * invisible. Un slug desconocido dejaría las invitaciones mudas para
	 * siempre, por eso el sanitizado cae al default ante cualquier cosa
	 * fuera del catálogo de estados.
	 */
	public function test_the_invite_trigger_status_only_accepts_real_statuses(): void {
		$this->page->save_fields( array( 'akb_rewards_invite_status' => 'processing' ) );
		$fila = array_values( array_filter( $this->saved, static fn( array $s ): bool => 'akb_rewards_invite_status' === $s['option'] ) );
		$this->assertSame( 'processing', $fila[0]['value'], 'Un estado real se guarda tal cual.' );

		$this->saved = array();
		$this->page->save_fields( array( 'akb_rewards_invite_status' => 'estado-fantasma' ) );
		$fila = array_values( array_filter( $this->saved, static fn( array $s ): bool => 'akb_rewards_invite_status' === $s['option'] ) );
		$this->assertSame( 'completed', $fila[0]['value'], 'Un slug desconocido cae al default, no se guarda.' );

		$this->saved = array();
		$this->page->save_fields( array( 'akb_rewards_invite_status' => 'cancelled' ) );
		$fila = array_values( array_filter( $this->saved, static fn( array $s ): bool => 'akb_rewards_invite_status' === $s['option'] ) );
		$this->assertSame( 'completed', $fila[0]['value'], 'Los estados terminales-negativos no son elegibles.' );
	}

	/** @testdox Sin WooCommerce, el selector de estados ofrece el trío estándar. */
	public function test_status_choices_fall_back_without_woocommerce(): void {
		$choices = SettingsPage::order_status_choices();

		$this->assertArrayHasKey( 'completed', $choices );
		$this->assertArrayHasKey( 'processing', $choices );
		$this->assertArrayNotHasKey( 'cancelled', $choices );
		$this->assertArrayNotHasKey( 'wc-completed', $choices, 'Las claves viajan SIN prefijo wc-, como las entrega woocommerce_order_status_changed.' );
	}

	/**
	 * Un caso por CAMPO ENTERO del catálogo, sacado del catálogo mismo por
	 * reflexión: una fila nueva sin tope hace fallar la suite sin que nadie
	 * tenga que acordarse de escribir su prueba.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>}>
	 */
	public static function campos_enteros(): array {
		/** @var array<string,array<string,mixed>> $fields */
		$fields = ( new \ReflectionClassConstant( SettingsPage::class, 'FIELDS' ) )->getValue();

		$casos = array();
		foreach ( $fields as $option => $campo ) {
			if ( 'int' === $campo['t'] ) {
				// La clave es el nombre de la option: el fallo dice CUÁL.
				$casos[ $option ] = array( (string) $option, $campo );
			}
		}

		return $casos;
	}

	/**
	 * @dataProvider campos_enteros
	 * @testdox Declara su tope el campo entero $_dataName
	 *
	 * El dato de la fila enciende las DOS capas de una vez: el atributo `max`
	 * del formulario (render) y el acotado del servidor (clamp_int). Sin la
	 * fila no hay ninguna de las dos.
	 *
	 * @param string               $option Option del catálogo.
	 * @param array<string,mixed>  $campo  Su fila.
	 */
	public function test_every_integer_field_declares_its_ceiling( string $option, array $campo ): void {
		$this->assertArrayHasKey( 'max', $campo, $option . ' es un campo de números sin techo: el panel acepta cualquier cifra.' );
		$this->assertIsInt( $campo['max'], $option . ' declara un max que no es entero.' );

		$min = (int) ( $campo['min'] ?? 0 );
		$this->assertGreaterThan( $min, $campo['max'], $option . ' declara un rango imposible.' );

		// Y el default tiene que caber en su propio rango, o el primer
		// guardado le cambiaría el número al dueño sin que él tocara nada.
		$this->assertGreaterThanOrEqual( $min, (int) $campo['d'], $option . ': el default queda bajo su propio min.' );
		$this->assertLessThanOrEqual( $campo['max'], (int) $campo['d'], $option . ': el default queda sobre su propio max.' );
	}

	/**
	 * @dataProvider campos_enteros
	 * @testdox Acota el recorrido de PHP el campo entero $_dataName
	 *
	 * Contra el RECORRIDO, no contra el HTML: el atributo `max` del navegador
	 * no defiende de un POST hecho con curl ni desde las devtools.
	 *
	 * OJO con la forma de la afirmación: para el trío de la razón
	 * (earn_points, earn_amount, redeem_value) el valor que vuelve puede NO
	 * ser el max sino el anterior, porque la baranda de la razón revierte
	 * antes de persistir. Por eso acá se afirma «dentro del rango», y la
	 * igualdad exacta con el tope se comprueba en los casos dirigidos de más
	 * abajo.
	 *
	 * @param string              $option Option del catálogo.
	 * @param array<string,mixed> $campo  Su fila.
	 */
	public function test_the_php_path_clamps_every_integer_field( string $option, array $campo ): void {
		$min   = (int) ( $campo['min'] ?? 0 );
		$max   = (int) $campo['max'];
		$otros = self::campos_enteros();
		unset( $otros[ $option ] );

		$envios = array(
			(string) ( $max + 1 ),
			(string) PHP_INT_MAX,
			'99999999999999999999',
			'-1',
			(string) $min,
			(string) $max,
		);

		foreach ( $envios as $enviado ) {
			$guardado = $this->page->save_fields( array( $option => $enviado ) );

			$this->assertIsInt( $guardado[ $option ], $option . ' con «' . $enviado . '» no se guardó como entero.' );
			$this->assertGreaterThanOrEqual( $min, $guardado[ $option ], $option . ' con «' . $enviado . '» quedó bajo su min.' );
			$this->assertLessThanOrEqual( $max, $guardado[ $option ], $option . ' con «' . $enviado . '» quedó sobre su max.' );

			// Y no arrastró a ningún otro campo del catálogo.
			foreach ( $otros as $otro => $fila ) {
				$this->assertSame(
					(int) $fila[1]['d'],
					$guardado[ $otro ],
					'Guardar ' . $option . ' movió ' . $otro . ', que nadie tocó.'
				);
			}
		}
	}

	/**
	 * @testdox El techo de la razón sale de fábrica en el 15 % de la tienda y manda el que el dueño dejó guardado.
	 *
	 * El techo NO tiene un accesor propio: lo lee el mismo catálogo que los
	 * demás campos, y por eso lo que hay que probar es que el valor GUARDADO
	 * —el que el panel pinta en el campo y vuelve en el envío— es el que
	 * decide, y no el literal de fábrica.
	 */
	public function test_the_giveback_ceiling_is_the_house_number_and_lives_in_the_panel(): void {
		$this->assertSame( 15, Options::DEFAULT_MAX_GIVEBACK_PCT );
		$this->assertContains( 'akb_rewards_max_giveback_pct', SettingsPage::option_names(), 'Regla de la casa: el techo se opera sin código.' );

		// 4 puntos por cada $100 a $1 es el 4 %: sobre el 15 de fábrica no, pero
		// con un techo guardado en 3 la baranda tiene que revertirlo.
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_max_giveback_pct' === $option ? '3' : $default_value
		);

		$guardado = $this->page->save_fields(
			array(
				'akb_rewards_earn_enabled'     => 'yes',
				'akb_rewards_earn_points'      => '4',
				'akb_rewards_earn_amount'      => '100',
				'akb_rewards_redeem_value'     => '1',
				'akb_rewards_max_giveback_pct' => '3',
			)
		);

		$this->assertSame( 3, $guardado['akb_rewards_max_giveback_pct'], 'El techo del dueño se conserva.' );
		$this->assertStringContainsString(
			'sobre el techo de 3%',
			$this->ultimo_aviso()['texto'],
			'Manda el techo GUARDADO, no el literal de fábrica.'
		);
	}

	/** @testdox La configuración vigente de producción pasa la baranda sin avisos. */
	public function test_the_live_production_ratio_passes_untouched(): void {
		$guardado = $this->page->save_fields(
			array(
				'akb_rewards_earn_points'  => '2',
				'akb_rewards_earn_amount'  => '100',
				'akb_rewards_redeem_value' => '1',
			)
		);

		$this->assertSame( 2, $guardado['akb_rewards_earn_points'] );
		$this->assertSame( 100, $guardado['akb_rewards_earn_amount'] );
		$this->assertSame( 1, $guardado['akb_rewards_redeem_value'] );
		$this->assertSame( array(), $this->transients, 'Un guardado sano no escribe aviso alguno.' );
	}

	/**
	 * @testdox El techo exacto pasa: la baranda rechaza lo que lo SUPERA.
	 * @dataProvider quince_por_ciento_clavado
	 *
	 * La segunda fila es la que en float daba 15,000000000000002 y se
	 * rechazaba con un aviso que decía que el 15 % superaba al 15 %
	 * (refutación 2026-09-12): la comparación es en enteros desde entonces.
	 */
	public function test_the_ceiling_itself_is_allowed( string $puntos, string $tramo, string $valor ): void {
		$guardado = $this->page->save_fields(
			array(
				'akb_rewards_earn_points'  => $puntos,
				'akb_rewards_earn_amount'  => $tramo,
				'akb_rewards_redeem_value' => $valor,
			)
		);

		$this->assertSame( (int) $puntos, $guardado['akb_rewards_earn_points'] );
		$this->assertSame( (int) $tramo, $guardado['akb_rewards_earn_amount'] );
		$this->assertSame( (int) $valor, $guardado['akb_rewards_redeem_value'] );
		$this->assertSame( array(), $this->transients );
	}

	/** @return array<string, array{0: string, 1: string, 2: string}> */
	public static function quince_por_ciento_clavado(): array {
		return array(
			'3 por cada $20 a $1'   => array( '3', '20', '1' ),
			'1 por cada $20 a $3'   => array( '1', '20', '3' ),
			'3 por cada $100 a $5'  => array( '3', '100', '5' ),
			'9 por cada $60 a $1'   => array( '9', '60', '1' ),
		);
	}

	/** @testdox Bajar el techo por debajo de la razón vigente conserva el techo que había. */
	public function test_lowering_the_ceiling_under_the_live_ratio_keeps_the_previous_ceiling(): void {
		// 2 por cada $100 a $1 es el 2 %; un techo del 1 % lo dejaría fuera.
		$guardado = $this->page->save_fields(
			array(
				'akb_rewards_earn_enabled'     => 'yes',
				'akb_rewards_earn_points'      => '2',
				'akb_rewards_earn_amount'      => '100',
				'akb_rewards_redeem_value'     => '1',
				'akb_rewards_max_giveback_pct' => '1',
			)
		);

		$this->assertSame( 15, $guardado['akb_rewards_max_giveback_pct'], 'Se conserva el techo que había.' );
		$this->assertSame( 2, $guardado['akb_rewards_earn_points'], 'La razón, que no cambió, no se toca.' );

		$aviso = $this->ultimo_aviso();
		$this->assertSame( 'error', $aviso['tipo'] );
		$this->assertStringContainsString( '«Techo de devolución del programa (%)»', $aviso['texto'] );
		$this->assertStringContainsString( 'sobre el techo de 1%', $aviso['texto'], 'El diagnóstico usa el techo que se intentó, no el guardado.' );
	}

	/** @testdox Subir el techo desde el panel desbloquea el máximo del campo de puntos. */
	public function test_raising_the_ceiling_unlocks_the_field_maximum(): void {
		// 20 por cada $100 a $1 es el 20 %: el max del campo (20) sólo es
		// alcanzable si el techo lo permite, y el techo se edita acá mismo.
		$guardado = $this->page->save_fields(
			array(
				'akb_rewards_earn_enabled'     => 'yes',
				'akb_rewards_earn_points'      => '20',
				'akb_rewards_earn_amount'      => '100',
				'akb_rewards_redeem_value'     => '1',
				'akb_rewards_max_giveback_pct' => '20',
			)
		);

		$this->assertSame( 20, $guardado['akb_rewards_earn_points'] );
		$this->assertSame( 20, $guardado['akb_rewards_max_giveback_pct'] );
		$this->assertSame( array(), $this->transients );
	}

	/**
	 * @testdox Con «Dar puntos por compra» apagado, una razón absurda se guarda y sólo se advierte.
	 *
	 * Apagado, Options::earn_points() devuelve 0 y nadie acumula: rechazar el
	 * guardado afirmaba una consecuencia que no ocurre. Se advierte que no
	 * rige y que hay que corregirlo antes de encender.
	 */
	public function test_with_the_accrual_lane_off_the_ratio_is_saved_and_only_warned(): void {
		$guardado = $this->page->save_fields(
			array(
				// El checkbox apagado no viaja en el POST: su ausencia es el «no».
				'akb_rewards_earn_points'  => '2',
				'akb_rewards_earn_amount'  => '1',
				'akb_rewards_redeem_value' => '1',
			)
		);

		$this->assertSame( 'no', $guardado['akb_rewards_earn_enabled'] );
		$this->assertSame( 1, $guardado['akb_rewards_earn_amount'], 'Con el carril apagado no se revierte.' );

		$aviso = $this->ultimo_aviso();
		$this->assertSame( 'warning', $aviso['tipo'] );
		$this->assertStringContainsString( 'Hoy no rige, porque «Dar puntos por compra» está apagado', $aviso['texto'] );
		$this->assertStringContainsString( 'antes de encenderlo', $aviso['texto'] );
		$this->assertStringContainsString( '200%', $aviso['texto'], 'La consecuencia se sigue explicando, en condicional.' );
	}

	/** @testdox Con el vencimiento apagado, la anticipación del aviso no se revierte: se advierte. */
	public function test_with_the_expiry_lane_off_the_warning_window_is_saved_and_only_warned(): void {
		$guardado = $this->page->save_fields(
			array(
				'akb_rewards_earn_enabled'     => 'yes',
				'akb_rewards_expiry_months'    => '12',
				'akb_rewards_expiry_warn_days' => '365',
			)
		);

		$this->assertSame( 'no', $guardado['akb_rewards_expiry_enabled'] );
		$this->assertSame( 365, $guardado['akb_rewards_expiry_warn_days'], 'Apagado, Expiry::months() es 0 y no sale ningún correo.' );

		$aviso = $this->ultimo_aviso();
		$this->assertSame( 'warning', $aviso['tipo'] );
		$this->assertStringContainsString( '«Hacer vencer los puntos» está apagado', $aviso['texto'] );
	}

	/**
	 * @testdox Un tramo de $1 no se guarda: se conserva el anterior y se explica en pesos.
	 *
	 * Es el caso que ningún `max` por campo puede cazar — «2 puntos» y «tramo
	 * de $1» son dos números perfectamente normales, y juntos devuelven el
	 * 200 % de cada compra.
	 */
	public function test_a_one_peso_bracket_is_rejected_and_the_previous_value_is_kept(): void {
		$guardado = $this->page->save_fields(
			array(
				'akb_rewards_earn_enabled'   => 'yes',
				'akb_rewards_earn_points'    => '2',
				'akb_rewards_earn_amount'    => '1',
				'akb_rewards_redeem_value'   => '1',
				'akb_rewards_carousel_title' => 'Voces del Distrito',
			)
		);

		$this->assertSame( 100, $guardado['akb_rewards_earn_amount'], 'Se conserva el tramo que había.' );
		$this->assertSame( 2, $guardado['akb_rewards_earn_points'], 'Lo que no cambió no se toca.' );
		$this->assertSame(
			'Voces del Distrito',
			$guardado['akb_rewards_carousel_title'],
			'Un rechazo del trío no puede impedir que se guarde el resto del formulario.'
		);

		$aviso = $this->ultimo_aviso();
		$this->assertSame( 'error', $aviso['tipo'] );
		$this->assertStringContainsString( '«Tamaño del tramo ($)»', $aviso['texto'], 'El aviso nombra el campo como se ve en pantalla.' );
		$this->assertStringContainsString( '200%', $aviso['texto'], 'Un sprintf habría muerto o mutilado este porcentaje.' );
		$this->assertStringContainsString( '15%', $aviso['texto'] );
		$this->assertStringContainsString( '$10.000', $aviso['texto'], 'La consecuencia va en pesos, con una compra de vara.' );
		$this->assertStringContainsString( '20.000 puntos', $aviso['texto'] );
		$this->assertStringContainsString( 'Se conservó lo que había: «Tamaño del tramo ($)» en 100.', $aviso['texto'] );
	}

	/** @testdox Subir los puntos por tramo por encima de la razón tampoco se guarda. */
	public function test_raising_the_points_per_bracket_over_the_ratio_is_rejected(): void {
		// 50 lo acota primero el tope del campo (20), y esos 20 con el tramo
		// de $100 ya devuelven el 20 %: la razón lo devuelve a lo que había.
		$guardado = $this->page->save_fields(
			array(
				'akb_rewards_earn_enabled'   => 'yes',
				'akb_rewards_earn_points'  => '50',
				'akb_rewards_earn_amount'  => '100',
				'akb_rewards_redeem_value' => '1',
			)
		);

		$this->assertSame( 2, $guardado['akb_rewards_earn_points'] );
		$this->assertSame( 'error', $this->ultimo_aviso()['tipo'] );
	}

	/** @testdox Un punto de diez pesos con la acumulación intacta se rechaza. */
	public function test_a_ten_peso_point_is_rejected_while_the_accrual_stays(): void {
		$guardado = $this->page->save_fields(
			array(
				'akb_rewards_earn_enabled'   => 'yes',
				'akb_rewards_earn_points'  => '2',
				'akb_rewards_earn_amount'  => '100',
				'akb_rewards_redeem_value' => '10',
			)
		);

		$this->assertSame( 1, $guardado['akb_rewards_redeem_value'], 'Se conserva el peso por punto que había.' );
		$this->assertStringContainsString( '«Pesos de descuento por punto canjeado»', $this->ultimo_aviso()['texto'] );
	}

	/** @testdox El tramo en 0 SÍ se guarda: es el apagado documentado del carril. */
	public function test_a_zero_bracket_is_saved_because_it_is_the_documented_off_switch(): void {
		$guardado = $this->page->save_fields(
			array(
				'akb_rewards_earn_points'  => '2',
				'akb_rewards_earn_amount'  => '0',
				'akb_rewards_redeem_value' => '1',
			)
		);

		$this->assertSame( 0, $guardado['akb_rewards_earn_amount'] );
		$this->assertSame( array(), $this->transients, 'Apagar la acumulación no es una violación de la razón.' );
	}

	/**
	 * @testdox Una razón HEREDADA fuera de techo no bloquea el resto del panel.
	 *
	 * Un valor escrito por WP-CLI o por SQL no puede dejar al dueño sin poder
	 * guardar nada más nunca: se guarda igual y se advierte.
	 */
	public function test_an_inherited_out_of_range_ratio_only_warns(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_earn_amount' === $option ? '1' : $default_value
		);

		$guardado = $this->page->save_fields(
			array(
				'akb_rewards_earn_enabled'   => 'yes',
				'akb_rewards_earn_points'    => '2',
				'akb_rewards_earn_amount'    => '1',
				'akb_rewards_redeem_value'   => '1',
				'akb_rewards_carousel_title' => 'Voces del Distrito',
			)
		);

		$this->assertSame( 1, $guardado['akb_rewards_earn_amount'], 'Nadie cambió el campo en este envío: queda como estaba.' );
		$this->assertSame( 'Voces del Distrito', $guardado['akb_rewards_carousel_title'] );

		$aviso = $this->ultimo_aviso();
		$this->assertSame( 'warning', $aviso['tipo'] );
		$this->assertStringContainsString( 'Este guardado no cambió esos campos', $aviso['texto'] );
		$this->assertStringContainsString( '200%', $aviso['texto'] );
	}

	/** @testdox El aviso viaja en un transient POR USUARIO, no en una marca global. */
	public function test_the_notice_is_stored_per_user(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		$this->page->save_fields(
			array(
				'akb_rewards_earn_enabled'   => 'yes',
				'akb_rewards_earn_amount' => '1',
			)
		);

		$this->assertNotSame( array(), $this->transients );
		$this->assertSame(
			SettingsPage::TRANSIENT_AVISOS . '7',
			$this->transients[0]['key'],
			'Con dos administradores guardando a la vez, uno se comía el aviso del otro.'
		);
	}

	/** @testdox Avisar del vencimiento antes de que empiece la vida de los puntos no se guarda. */
	public function test_an_expiry_warning_longer_than_the_points_life_is_rejected(): void {
		$guardado = $this->page->save_fields(
			array(
				'akb_rewards_expiry_enabled'   => 'yes',
				'akb_rewards_expiry_months'    => '12',
				'akb_rewards_expiry_warn_days' => '365',
			)
		);

		$this->assertSame( 30, $guardado['akb_rewards_expiry_warn_days'], 'Se conserva la anticipación que había.' );
		$this->assertSame( 12, $guardado['akb_rewards_expiry_months'] );

		$aviso = $this->ultimo_aviso();
		$this->assertSame( 'error', $aviso['tipo'] );
		$this->assertStringContainsString( '«Aviso previo al vencimiento (días)»', $aviso['texto'] );
		$this->assertStringContainsString( '360 días', $aviso['texto'] );
	}

	/** @testdox Con puntos que no vencen jamás, la anticipación del aviso no se juzga. */
	public function test_points_that_never_expire_are_not_judged_by_the_warning_window(): void {
		$guardado = $this->page->save_fields(
			array(
				'akb_rewards_expiry_months'    => '0',
				'akb_rewards_expiry_warn_days' => '365',
			)
		);

		$this->assertSame( 0, $guardado['akb_rewards_expiry_months'] );
		$this->assertSame( 365, $guardado['akb_rewards_expiry_warn_days'] );
		$this->assertSame( array(), $this->transients );
	}

	/**
	 * El último aviso escrito para el dueño.
	 *
	 * @return array{tipo:string,texto:string}
	 */
	private function ultimo_aviso(): array {
		$this->assertNotSame( array(), $this->transients, 'No se escribió ningún aviso.' );
		$payload = end( $this->transients )['value'];
		$this->assertIsArray( $payload );

		return (array) end( $payload );
	}

	/**
	 * El interruptor decía «Activado» con la lista de correos vacía: el
	 * párrafo no salía en ninguno y la pantalla no protestaba. Es la clase de
	 * palanca que miente que el dueño prohíbe (hallazgo S-b2d528bc,
	 * 2026-09-18).
	 *
	 * El aviso se afirma sobre la FUENTE porque pintar la pantalla entera
	 * exige media docena de funciones de admin; que el texto salga de verdad
	 * se mide en el espejo con la pantalla abierta.
	 *
	 * @testdox La pantalla avisa cuando el empujón está activado sin ningún correo marcado.
	 */
	public function test_the_screen_warns_about_the_contradictory_nudge(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Support/SettingsPage.php' );

		self::assertStringContainsString(
			'no hay ningún correo marcado abajo',
			$fuente,
			'Una palanca encendida que no hace nada tiene que decirlo.'
		);

		// En los DOS campos: el dueño puede estar mirando cualquiera.
		self::assertStringContainsString(
			"array( 'akb_rewards_review_nudge_enabled', 'akb_rewards_nudge_email_ids' )",
			$fuente
		);
	}

	/**
	 * El guardado vigilaba DOS interruptores y sólo tiraba el transient del
	 * carrusel. Cambiar el título, el número de tarjetas o el largo mínimo no
	 * tiraba nada, y aun cuando tiraba el transient la PORTADA cacheada seguía
	 * enseñando el carrusel anterior: «el interruptor no hace nada», que es el
	 * síntoma que ese bloque vino a curar (revisión de Fable 5.1 sobre la
	 * reapertura S-80f7d8ac, 2026-09-19).
	 *
	 * @testdox Cambiar CUALQUIER ajuste del carrusel lo refresca entero: transient y portada.
	 */
	public function test_cualquier_ajuste_del_carrusel_lo_refresca_entero(): void {
		$guardadas = array( 'akb_rewards_carousel_title' => 'Voces del Distrito' );
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => $guardadas[ $option ] ?? $default_value
		);

		$tirados = array();
		Functions\when( 'delete_transient' )->alias(
			static function ( string $clave ) use ( &$tirados ): bool {
				$tirados[] = $clave;
				return true;
			}
		);
		\Brain\Monkey\Actions\expectDone( 'litespeed_purge_url' )->once()->with( 'https://akibara.test/' );

		$this->page->save_fields(
			array(
				'akb_rewards_carousel_enabled' => 'yes',
				'akb_rewards_carousel_title'   => 'Lo que dice la comunidad',
			)
		);

		$this->assertContains( \Akibara\Rewards\Reviews\Carousel::TRANSIENT, $tirados );
	}
	/**
	 * REV-T6-2-4 (2026-09-23): el tema usa este título también como encabezado
	 * de las reseñas de CADA ficha. Cambiarlo tiraba sólo el carrusel y la
	 * portada; la copia de invitado de las fichas seguía con el título viejo.
	 *
	 * REV-R-R-1-P1 (2026-09-24): pero un encabezado no justifica la purga
	 * total, que en producción vacía también el CSS/JS minificado, la caché de
	 * objetos, OPcache y el borde entero de Cloudflare. Basta con el origen.
	 *
	 * @testdox Cambiar el título de las reseñas purga el origen de las fichas y la portada, sin la purga total.
	 */
	public function test_el_titulo_de_las_resenas_purga_las_fichas(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_carousel_title' === $option ? 'Voces del Distrito' : $default_value
		);
		Functions\when( 'delete_transient' )->justReturn( true );

		\Brain\Monkey\Actions\expectDone( 'litespeed_purge' )->once()->with( '*' );
		\Brain\Monkey\Actions\expectDone( 'litespeed_purge_all' )->never();
		\Brain\Monkey\Actions\expectDone( 'litespeed_purge_url' )->once()->with( 'https://akibara.test/' );

		$guardado = $this->page->save_fields( self::sin_tocar_la_ficha( array( 'akb_rewards_carousel_title' => 'Lo que dice la comunidad' ) ) );

		$this->assertSame( 'Lo que dice la comunidad', $guardado['akb_rewards_carousel_title'] );
	}

	/**
	 * Si en el mismo guardado cambian el título y la tasa, manda la promesa:
	 * la purga total es la única que llega al borde y ya incluye el origen.
	 *
	 * @testdox Cambiar el título y la tasa a la vez lanza UNA purga, la total.
	 */
	public function test_titulo_y_tasa_a_la_vez_purgan_una_vez_la_total(): void {
		$guardadas = array(
			'akb_rewards_carousel_title' => 'Voces del Distrito',
			'akb_rewards_earn_points'    => 2,
		);
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => $guardadas[ $option ] ?? $default_value
		);
		Functions\when( 'delete_transient' )->justReturn( true );

		\Brain\Monkey\Actions\expectDone( 'litespeed_purge_all' )->once();
		\Brain\Monkey\Actions\expectDone( 'litespeed_purge' )->never();

		$guardado = $this->page->save_fields(
			self::sin_tocar_la_ficha(
				array(
					'akb_rewards_carousel_title' => 'Lo que dice la comunidad',
					'akb_rewards_earn_points'    => '3',
				)
			)
		);

		$this->assertSame( 'Lo que dice la comunidad', $guardado['akb_rewards_carousel_title'] );
		$this->assertSame( 3, $guardado['akb_rewards_earn_points'] );
	}

	/**
	 * El interruptor del aviso «Gana N puntos» cambia el HTML de cada ficha:
	 * apagarlo sin purgar dejaba el aviso en la copia de invitado, y
	 * encenderlo no lo mostraba, hasta que la caché caducara sola.
	 *
	 * @testdox Apagar o encender el aviso de puntos de la ficha purga la caché; guardar sin cambiarlo, no.
	 */
	public function test_el_interruptor_del_aviso_de_la_ficha_purga(): void {
		$guardado_antes = 'yes';
		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default_value = false ) use ( &$guardado_antes ) {
				return 'akb_rewards_pdp_notice_enabled' === $option ? $guardado_antes : $default_value;
			}
		);

		\Brain\Monkey\Actions\expectDone( 'litespeed_purge_all' )->twice();

		$apagado        = $this->page->save_fields( self::sin_la_casilla( 'akb_rewards_pdp_notice_enabled' ) );
		$guardado_antes = 'no';
		$igual          = $this->page->save_fields( self::sin_la_casilla( 'akb_rewards_pdp_notice_enabled' ) ); // Sigue apagado: no purga.
		$encendido      = $this->page->save_fields( self::sin_tocar_la_ficha() );

		$this->assertSame( 'no', $apagado['akb_rewards_pdp_notice_enabled'] );
		$this->assertSame( 'no', $igual['akb_rewards_pdp_notice_enabled'] );
		$this->assertSame( 'yes', $encendido['akb_rewards_pdp_notice_enabled'] );
	}

	/**
	 * R-R-1-1: con el carril apagado, `Options::earn_points()` devuelve 0 y la
	 * ficha no pinta el aviso. El interruptor no purgaba, así que la copia de
	 * invitado seguía diciendo «Gana N Puntos Akibara» con la acumulación
	 * apagada, y seguía sin el aviso al encenderla, hasta caducar sola.
	 *
	 * @testdox Apagar o encender «Dar puntos por compra» purga la caché de las fichas; guardar sin cambiarlo, no.
	 */
	public function test_apagar_o_encender_la_acumulacion_purga_las_fichas(): void {
		$guardado_antes = 'yes';
		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default_value = false ) use ( &$guardado_antes ) {
				return 'akb_rewards_earn_enabled' === $option ? $guardado_antes : $default_value;
			}
		);

		\Brain\Monkey\Actions\expectDone( 'litespeed_purge_all' )->twice();

		$apagado        = $this->page->save_fields( self::sin_la_casilla( 'akb_rewards_earn_enabled' ) );
		$guardado_antes = 'no';
		$igual          = $this->page->save_fields( self::sin_la_casilla( 'akb_rewards_earn_enabled' ) );
		$encendido      = $this->page->save_fields( self::sin_tocar_la_ficha() );

		$this->assertSame( 'no', $apagado['akb_rewards_earn_enabled'], 'El dueño la desmarcó: se apaga.' );
		$this->assertSame( 'no', $igual['akb_rewards_earn_enabled'], 'Sigue apagada: no vuelve a purgar.' );
		$this->assertSame( 'yes', $encendido['akb_rewards_earn_enabled'], 'Encendida de nuevo: purga.' );
	}

	/**
	 * La lista se escribía a mano y se le quedó fuera el interruptor de la
	 * acumulación (R-R-1-1): la prueba sólo miraba que fuera un subconjunto del
	 * panel. Ahora se pinta el aviso de la ficha de verdad, como invitado (la
	 * rama que más lee: tasa, bienvenida y compra mínima), se anota cada ajuste
	 * del panel que consulta y se exige que cambiarlo purgue.
	 *
	 * Y en la lista de la purga TOTAL: es una promesa, y la única purga que
	 * llega a la copia del borde de Cloudflare (REV-R-R-1-P1).
	 *
	 * @testdox Todo ajuste del panel que el aviso de puntos de la ficha lee está en la lista que purga las fichas hasta el borde.
	 */
	public function test_todo_lo_que_lee_el_aviso_de_la_ficha_purga(): void {
		$fields = ( new \ReflectionClassConstant( SettingsPage::class, 'FIELDS' ) )->getValue();
		$ficha  = ( new \ReflectionClassConstant( SettingsPage::class, 'PROMESA_EN_CADA_FICHA' ) )->getValue();

		$leidos = array();
		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default_value = false ) use ( &$leidos ) {
				$leidos[ $option ] = true;
				return $default_value;
			}
		);
		Functions\when( 'wc_get_product' )->justReturn(
			new class() extends \WC_Product {
				/**
				 * Un tomo que se puede comprar hoy.
				 *
				 * @return bool
				 */
				public function is_purchasable(): bool {
					return true;
				}
			}
		);
		Functions\when( 'wc_get_price_including_tax' )->justReturn( 10000.0 );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( 'wp_kses_post' )->returnArg();

		ob_start();
		( new \Akibara\Rewards\Points\PdpNotice() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'de bienvenida', $html, 'Se pintó la rama del invitado, la que más ajustes lee.' );

		$del_panel = array_values( array_intersect( array_keys( $leidos ), array_keys( $fields ) ) );
		$this->assertContains( 'akb_rewards_earn_enabled', $del_panel, 'El interruptor del carril se lee en la ficha.' );
		$this->assertSame(
			array(),
			array_values( array_diff( $del_panel, $ficha ) ),
			'La ficha lee estos ajustes del panel y cambiarlos no purgaría su copia de invitado hasta el borde.'
		);
	}

	/**
	 * La promesa de la ficha es la matemática del programa: con la tasa
	 * cambiada y la ficha sin purgar, la ficha prometía una cifra y la
	 * acreditación daba otra.
	 *
	 * @testdox Cambiar la tasa de puntos purga la caché de las fichas UNA vez; guardar la misma, no.
	 */
	public function test_cambiar_la_tasa_purga_las_fichas(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_earn_points' === $option ? 2 : $default_value
		);

		\Brain\Monkey\Actions\expectDone( 'litespeed_purge_all' )->once();

		$nueva = $this->page->save_fields( self::sin_tocar_la_ficha( array( 'akb_rewards_earn_points' => '3' ) ) );
		$misma = $this->page->save_fields( self::sin_tocar_la_ficha( array( 'akb_rewards_earn_points' => '2' ) ) );

		$this->assertSame( 3, $nueva['akb_rewards_earn_points'] );
		$this->assertSame( 2, $misma['akb_rewards_earn_points'] );
	}

	/**
	 * Cada ajuste de la lista de «se lee en la ficha» tiene que ser un campo
	 * real del panel: uno que no lo fuera rompería el guardado entero al
	 * buscar su valor de fábrica.
	 *
	 * Y ninguno está en las dos listas: la purga que le toca a cada uno es
	 * una sola.
	 *
	 * @testdox Todo ajuste que purga las fichas es un campo del panel, y sólo de una de las dos listas.
	 */
	public function test_lo_que_purga_las_fichas_son_campos_del_panel(): void {
		$fields  = ( new \ReflectionClassConstant( SettingsPage::class, 'FIELDS' ) )->getValue();
		$promesa = ( new \ReflectionClassConstant( SettingsPage::class, 'PROMESA_EN_CADA_FICHA' ) )->getValue();
		$forma   = ( new \ReflectionClassConstant( SettingsPage::class, 'FORMA_DE_CADA_FICHA' ) )->getValue();

		$this->assertSame( array(), array_values( array_diff( array_merge( $promesa, $forma ), array_keys( $fields ) ) ) );
		$this->assertSame( array(), array_values( array_intersect( $promesa, $forma ) ) );
		$this->assertContains( 'akb_rewards_carousel_title', $forma, 'El tema lo lee en cada ficha, y es un encabezado.' );
		$this->assertContains( 'akb_rewards_pdp_notice_enabled', $promesa );
		$this->assertContains( 'akb_rewards_disclosure_enabled', $promesa, 'Le dice al comprador que la reseña ganó puntos.' );
	}

	/**
	 * REV-T6-2-4: el dueño tiene que saber, desde el panel, que el título del
	 * carrusel también titula las reseñas de cada ficha.
	 *
	 * @testdox El rótulo y la ayuda del título dicen que se usa en la portada y en las fichas.
	 */
	public function test_el_titulo_dice_donde_se_ve(): void {
		$fields = ( new \ReflectionClassConstant( SettingsPage::class, 'FIELDS' ) )->getValue();
		$campo  = $fields['akb_rewards_carousel_title'];

		$this->assertStringContainsString( 'fichas', $campo['l'] );
		$this->assertStringContainsString( 'portada', $campo['l'] );
		$this->assertStringContainsString( 'cada ficha de producto', $campo['h'] );
		$this->assertStringContainsString( 'Voces del Distrito', $campo['h'], 'Y a qué vuelve si se deja vacío.' );
	}

	/**
	 * La ayuda del interruptor dice dónde sale el aviso. Desde UC-4b va bajo el
	 * botón de compra; pero un tomo agotado no tiene botón, y el respaldo del
	 * resumen (prioridad 32) lo pinta justo bajo el precio. La ayuda decía sólo
	 * la mitad de los casos (QA-RW-1, medido en la ficha 30561 del espejo).
	 *
	 * @testdox La ayuda del aviso de la ficha dice los dos sitios: bajo el botón de compra y, en un agotado, bajo el precio.
	 */
	public function test_la_ayuda_del_aviso_dice_donde_sale(): void {
		$fields = ( new \ReflectionClassConstant( SettingsPage::class, 'FIELDS' ) )->getValue();
		$ayuda  = (string) $fields['akb_rewards_pdp_notice_enabled']['h'];

		$this->assertStringContainsString( 'Bajo el botón de compra', $ayuda );
		$this->assertStringContainsString( 'agotado', $ayuda );
		$this->assertStringContainsString( 'bajo el precio', $ayuda );
	}

	/**
	 * R2-2c367bf6 (2026-09-19): mientras el programa no rige, la tienda deja de
	 * prometer puntos. Sin decirlo junto a la fecha, el dueño vería desaparecer
	 * el «Gana N puntos» de la ficha sin saber por qué.
	 *
	 * @testdox El panel dice junto a la fecha de vigencia cuándo el programa aún no empieza o su fecha no se puede leer.
	 */
	public function test_el_panel_avisa_el_estado_de_la_vigencia(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.test/wp-admin/' . $path );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'checked' )->alias( static fn( $a, $b, $echo = true ) => (string) $a === (string) $b ? ' checked="checked"' : '' );
		Functions\when( 'selected' )->alias( static fn( $a, $b, $echo = true ) => (string) $a === (string) $b ? ' selected="selected"' : '' );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'WC' )->justReturn( false );
		Functions\when( 'wp_date' )->alias( static fn( string $formato, int $instante ): string => gmdate( 'Y-m-d', $instante ) );

		$pintar = function ( string $inicio ): string {
			Functions\when( 'get_option' )->alias(
				static fn( string $option, $default_value = false ) => 'akb_rewards_program_start' === $option ? $inicio : $default_value
			);
			ob_start();
			$this->page->render();

			return (string) ob_get_clean();
		};

		$manana = ( new \DateTimeImmutable( '@' . ( time() + 86400 ) ) )->format( 'Y-m-d H:i:s' );
		$this->assertStringContainsString( 'Ojo: el programa empieza el ', $pintar( $manana ) );
		$this->assertStringContainsString( 'La fecha guardada no se puede leer', $pintar( '17/08/2026' ) );

		$vigente = $pintar( '2026-08-17 00:00:00' );
		$this->assertStringNotContainsString( 'el programa empieza el', $vigente );
		$this->assertStringNotContainsString( 'no se puede leer', $vigente );
	}
	/** @testdox El selector del carrusel guarda sus tres modos y rechaza entradas ajenas al catálogo. */
	public function test_carousel_media_selection_is_persisted_and_validated(): void {
		Functions\when( 'get_option' )->alias( static fn( string $option, $default = false ) => 'akb_rewards_carousel_media' === $option ? 'photos' : $default );
		Functions\when( 'delete_transient' )->justReturn( true );
		foreach ( array( 'mixed', 'photos', 'text' ) as $mode ) {
			$result = $this->page->save_fields( self::sin_tocar_la_ficha( array( 'akb_rewards_carousel_media' => $mode ) ) );
			$this->assertSame( $mode, $result['akb_rewards_carousel_media'] );
		}
		foreach ( array( 'not-a-mode', array( 'text' ) ) as $invalid ) {
			$result = $this->page->save_fields( self::sin_tocar_la_ficha( array( 'akb_rewards_carousel_media' => $invalid ) ) );
			$this->assertSame( 'photos', $result['akb_rewards_carousel_media'] );
		}
		$result = $this->page->save_fields( self::sin_tocar_la_ficha() );
		$this->assertSame( 'photos', $result['akb_rewards_carousel_media'], 'Un formulario anterior sin el campo conserva lo guardado.' );
	}

	/** @testdox La casilla del aviso de puntos del home es independiente y aparece desmarcada por defecto. */
	public function test_home_disclosure_checkbox_is_clear_and_defaults_to_off(): void {
		$html = $this->pintar_panel( array( 'akb_rewards_disclosure_enabled' => 'yes' ) );
		self::assertStringContainsString( 'Mostrar también en el carrusel el aviso de puntos', $html );
		self::assertMatchesRegularExpression( '/<input[^>]+name="akb_rewards_carousel_disclosure_enabled"[^>]*>/', $html );
		self::assertDoesNotMatchRegularExpression( '/<input[^>]+name="akb_rewards_carousel_disclosure_enabled"[^>]+checked="checked"/', $html );
		self::assertStringContainsString( 'conserva el aviso en las fichas y las etiquetas de reseñas transcritas', $html );
		self::assertContains( 'akb_rewards_carousel_disclosure_enabled', SettingsPage::option_names(), 'El campo entra en registro, guardado y autoload habituales.' );
	}

	/** @testdox Guardar ON/OFF del aviso del home conserva el aviso de la ficha y purga sólo el carrusel al cambiar. */
	public function test_home_disclosure_saves_both_states_without_changing_pdp_setting(): void {
		$store = array( 'akb_rewards_disclosure_enabled' => 'yes', 'akb_rewards_carousel_disclosure_enabled' => 'yes' );
		Functions\when( 'get_option' )->alias( static function( string $key, $default = false ) use ( &$store ) { return $store[ $key ] ?? $default; } );
		Functions\when( 'update_option' )->alias( static function( string $key, $value, $autoload = null ) use ( &$store ) { $store[ $key ] = $value; return true; } );
		$deleted = array();
		Functions\when( 'delete_transient' )->alias( static function( string $key ) use ( &$deleted ) { $deleted[] = $key; return true; } );
		\Brain\Monkey\Actions\expectDone( 'litespeed_purge_all' )->never();
		\Brain\Monkey\Actions\expectDone( 'litespeed_purge' )->never();
		$base = self::sin_tocar_la_ficha( array( 'akb_rewards_disclosure_enabled' => 'yes', 'akb_rewards_carousel_enabled' => 'yes' ) );
		$on = $this->page->save_fields( $base + array( 'akb_rewards_carousel_disclosure_enabled' => 'yes' ) );
		self::assertSame( 'yes', $on['akb_rewards_carousel_disclosure_enabled'] );
		$off = $this->page->save_fields( $base );
		self::assertSame( 'no', $off['akb_rewards_carousel_disclosure_enabled'], 'Una casilla desmarcada llega ausente y se guarda como no.' );
		self::assertSame( 'yes', $off['akb_rewards_disclosure_enabled'], 'El aviso de la ficha sigue encendido.' );
		$on_again = $this->page->save_fields( $base + array( 'akb_rewards_carousel_disclosure_enabled' => 'yes' ) );
		self::assertSame( 'yes', $on_again['akb_rewards_carousel_disclosure_enabled'] );
		self::assertSame( 'yes', $on_again['akb_rewards_disclosure_enabled'] );
		self::assertSame( array( \Akibara\Rewards\Reviews\Carousel::TRANSIENT, \Akibara\Rewards\Reviews\Carousel::TRANSIENT ), $deleted, 'Sólo OFF y el posterior ON invalidan las tarjetas.' );
	}

}
