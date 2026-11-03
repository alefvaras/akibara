<?php
/**
 * El correo del digest.
 *
 * `get_content_html()` / `get_content_plain()` / `template_args()` no son
 * ceremonia: `WC_Email::get_content()` delega en ellos, así que sin los tres el
 * correo sale con asunto y SIN cuerpo.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFollow;

use Akibara\Business\Modules\SeriesFollow\SeriesFollowEmail;
use Akibara\Business\Modules\SeriesFollow\VolumePhrase;
use Akibara\Business\Modules\SeriesFollow\UnfollowHandler;
use Akibara\Business\Services\AkibaraEmail;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) . '/' );

/**
 * Expone el estado que WooCommerce sustituye en asunto y encabezado.
 *
 * Usa el constructor REAL: las rutas de plantilla que fija ahí son justamente lo
 * que `get_content()` necesita, y anularlo dejaría verde una prueba de cuerpo
 * sobre un correo sin plantillas.
 */
final class SeriesFollowEmailProbe extends SeriesFollowEmail {

	/** @return array<string, string> */
	public function akb_placeholders(): array {
		return $this->placeholders;
	}

	public function akb_unsubscribe_url(): string {
		return $this->unsubscribe_url;
	}
}

final class SeriesFollowEmailTest extends TestCase {

	/** @var array<int, array{plantilla:string, args:array<string,mixed>}> */
	private array $renderizados = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->renderizados = array();

		Functions\when( 'trailingslashit' )->alias(
			static fn ( string $value ): string => rtrim( $value, '/\\' ) . '/'
		);
		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ): string => 'https://akibara.test' . $path );
		Functions\when( 'wp_salt' )->justReturn( 'salt-de-prueba' );
		Functions\when( 'add_query_arg' )->alias(
			static fn ( array $args, string $url ): string => $url . '?' . http_build_query( $args )
		);
		// Doble FIEL a producción: sin `$permalink` y sin request (el digest
		// corre en Action Scheduler) wc_get_endpoint_url devuelve RELATIVA.
		// El stub amable que siempre daba absoluta tapó el enlace muerto
		// «/coleccion/» de los correos (hallazgo del QA vivo 2026-08-31).
		Functions\when( 'wc_get_endpoint_url' )->alias(
			static function ( string $slug, string $value = '', string $permalink = '' ): string {
				return ( '' !== $permalink ? rtrim( $permalink, '/' ) . '/' : '/' ) . $slug . '/';
			}
		);
		Functions\when( 'wc_get_page_permalink' )->alias(
			static fn ( string $page ): string => 'https://akibara.test/mi-cuenta/'
		);
		Functions\when( 'wc_get_template_html' )->alias(
			function ( string $plantilla, array $args ): string {
				$this->renderizados[] = compact( 'plantilla', 'args' );
				return '<p>' . $plantilla . '</p>';
			}
		);
		Functions\when( 'get_userdata' )->alias(
			static fn ( int $user_id ) => (object) array( 'user_email' => 'cliente' . $user_id . '@akibara.cl' )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Una serie con UN solo tomo conserva el asunto de hoy, palabra por palabra.
	 *
	 * El candado del caso que no debe moverse: la inmensa mayoría de los avisos
	 * son de un tomo, y ésos no pueden cambiar por añadir el caso de la tanda.
	 */
	public function test_una_serie_con_un_solo_tomo_conserva_el_asunto_de_hoy(): void {
		$email = $this->probe();
		$email->trigger( 10, array( $this->bloque( 42, 74, 'Kingdom' ) ) );

		self::assertSame( 'Salió el tomo 74 de Kingdom', $email->get_subject() );
	}

	/** @testdox Con varios tomos de la misma serie, el asunto lo dice en plural y con el número. */
	public function test_una_serie_con_varios_tomos_usa_el_asunto_de_tanda(): void {
		$email = $this->probe();
		$email->trigger( 10, array( $this->bloque( 42, 74, 'Kingdom', array( 71, 72, 73, 74 ) ) ) );

		$asunto = $email->get_subject();

		self::assertSame( 'Salieron 4 tomos de Kingdom', $asunto );
		self::assertStringNotContainsString( 'varios', mb_strtolower( $asunto ), 'El número exacto, nunca «varios».' );
	}

	/**
	 * @testdox Dos clientes seguidos en el MISMO lote no comparten asunto.
	 *
	 * LA PRUEBA DEL FALLO CARO. `DigestRunner::run()` reutiliza UNA instancia del
	 * correo para los 50 clientes del lote, y `WC_Settings_API::get_option()`
	 * ESCRIBE el default dentro de `$this->settings` la primera vez y ya no
	 * vuelve a preguntar. Sin restaurar el valor previo, el primer cliente
	 * decidiría el asunto de los otros 49.
	 */
	public function test_dos_clientes_seguidos_en_el_mismo_lote_no_comparten_asunto(): void {
		$email = $this->probe();

		$email->trigger( 10, array( $this->bloque( 42, 74, 'Kingdom', array( 71, 72, 73, 74 ) ) ) );
		$primero = $email->get_subject();

		$email->trigger( 11, array( $this->bloque( 43, 107, 'One Piece' ) ) );
		$segundo = $email->get_subject();

		self::assertSame( 'Salieron 4 tomos de Kingdom', $primero );
		self::assertSame( 'Salió el tomo 107 de One Piece', $segundo, 'El asunto del primero NO puede pegarse al segundo.' );
	}

	/** @testdox El default del asunto no depende de lo que se acabe de enviar. */
	public function test_el_default_del_asunto_no_depende_del_envio(): void {
		$email = $this->probe();
		$antes = $email->get_default_subject();

		$email->trigger( 10, array( $this->bloque( 42, 74, 'Kingdom', array( 71, 72, 73, 74 ) ) ) );

		self::assertSame( $antes, $email->get_default_subject() );
	}

	/**
	 * @testdox El encabezado ya no dice «el siguiente tomo», en ningún caso.
	 *
	 * Era falso en dos situaciones vivas: con una tanda se enseña el más NUEVO,
	 * que no es el siguiente que le toca; y una fila rezagada anuncia un tomo
	 * ANTERIOR al ya avisado — que es exactamente lo que va a pasar el
	 * 2026-09-12 con los clientes 228 y 243 y el tomo 8 de Kagurabachi.
	 */
	public function test_el_encabezado_ya_no_dice_el_siguiente_tomo(): void {
		foreach (
			array(
				'un tomo'      => array( $this->bloque( 42, 74, 'Kingdom' ) ),
				'cuatro tomos' => array( $this->bloque( 42, 74, 'Kingdom', array( 71, 72, 73, 74 ) ) ),
				'tres series'  => array(
					$this->bloque( 42, 107, 'One Piece' ),
					$this->bloque( 43, 42, 'Berserk' ),
					$this->bloque( 44, 18, 'Monster' ),
				),
			) as $caso => $grupos
		) {
			$email = $this->probe();
			$email->trigger( 10, $grupos );

			self::assertStringNotContainsString(
				'siguiente',
				mb_strtolower( $email->get_heading() ),
				"El encabezado sigue diciendo «siguiente» con {$caso}."
			);
		}
	}

	/**
	 * @testdox Un texto del dueño con «%» sale literal y no rompe el asunto.
	 *
	 * La regla de la casa que ya costó un fatal en /mi-cuenta: los copys del
	 * dueño NUNCA como formato de sprintf. WooCommerce sustituye con
	 * `str_replace`, y el asunto de tanda tiene que pasar por el mismo camino.
	 */
	public function test_un_copy_con_porcentaje_no_rompe_el_asunto(): void {
		$email = $this->probe();
		$email->init_form_fields();
		$email->settings['subject_tanda'] = 'Salieron {n_tomos} tomos de {serie} con 50% de descuento';
		$email->settings['subject']       = 'Salió el tomo {tomo} de {serie} con 50% de descuento';

		$email->trigger( 10, array( $this->bloque( 42, 74, 'Kingdom', array( 71, 72, 73, 74 ) ) ) );
		self::assertSame( 'Salieron 4 tomos de Kingdom con 50% de descuento', $email->get_subject() );

		$email->trigger( 11, array( $this->bloque( 42, 74, 'Kingdom' ) ) );
		self::assertSame( 'Salió el tomo 74 de Kingdom con 50% de descuento', $email->get_subject() );
	}

	/**
	 * @testdox Los dos asuntos están en la pantalla, en orden de lectura, y email_type sigue el último.
	 */
	public function test_los_dos_asuntos_estan_en_la_pantalla_y_en_ese_orden(): void {
		$email = $this->probe();
		$email->init_form_fields();
		$claves = array_keys( $email->form_fields );

		self::assertSame( 'subject', $claves[1] ?? '' );
		self::assertSame( 'subject_tanda', $claves[2] ?? '', 'El de tanda va justo debajo del normal: se leen juntos.' );
		self::assertSame( 'heading', $claves[3] ?? '' );
		self::assertSame( 'email_type', end( $claves ), 'email_type sigue siendo el último.' );
		self::assertSame( 'multipart', $email->form_fields['email_type']['default'] ?? '' );
	}

	/** @testdox La ayuda del asunto de tanda nombra todos los marcadores que de verdad funcionan. */
	public function test_la_ayuda_del_asunto_de_tanda_declara_los_marcadores(): void {
		$email = $this->probe();
		$email->init_form_fields();
		$ayuda = (string) ( $email->form_fields['subject_tanda']['description'] ?? '' );

		foreach ( array( '{serie}', '{y_mas}', '{n_tomos}', '{tomos}', '{tomo}', '{site_title}' ) as $marcador ) {
			self::assertStringContainsString( $marcador, $ayuda, "La ayuda no nombra {$marcador}." );
		}
	}

	/**
	 * @testdox Sin bloques —la vista previa de WooCommerce— el asunto no revienta.
	 */
	public function test_el_asunto_de_tanda_no_se_usa_sin_bloques(): void {
		$email = $this->probe();

		self::assertIsString( $email->get_subject() );
	}

	/**
	 * @param int $volume  Tomo.
	 * @param int $term_id Serie.
	 * @return array<string, mixed>
	 */
	private function bloque( int $term_id, int $volume, string $serie, array $tomos = array() ): array {
		$tomos = VolumePhrase::normalize( array() === $tomos ? array( $volume ) : $tomos );

		return array(
			'term_id'        => $term_id,
			'series_name'    => $serie,
			'volume'         => $volume,
			'product_id'     => 5000 + $volume,
			'title'          => $serie . ' ' . $volume,
			'url'            => 'https://akibara.test/p/' . ( 5000 + $volume ),
			'price_html'     => '$12.990',
			'image_url'      => '',
			'cutoff'         => '',
			'availability'   => 'Disponible',
			'volumes'        => $tomos,
			'volumes_count'  => count( $tomos ),
			'volumes_phrase' => VolumePhrase::of( $tomos ),
		);
	}

	private function probe(): SeriesFollowEmailProbe {
		return new SeriesFollowEmailProbe();
	}

	/**
	 * Sin `email_type` en el form, `get_content_type()` cae a `text/plain` y el
	 * correo sale con el HTML dentro. La base lo declara para los cuatro.
	 *
	 * El default es `multipart` y no `html` desde el 2026-09-01: con `html` el
	 * correo viajaba SIN parte `text/plain` y `get_content_plain()` —o sea la
	 * plantilla `templates/emails/plain/customer-series-follow.php`— no se
	 * ejecutaba nunca. Este candado es lo que impide que vuelva a serlo.
	 *
	 * @testdox Extiende AkibaraEmail y hereda el campo email_type en multipart.
	 */
	public function test_extiende_akibara_email_y_declara_email_type_multipart(): void {
		$email = $this->probe();
		$email->init_form_fields();

		self::assertInstanceOf( AkibaraEmail::class, $email );
		self::assertArrayHasKey( 'email_type', $email->form_fields );
		self::assertSame( 'multipart', $email->form_fields['email_type']['default'] );
		self::assertArrayHasKey(
			'multipart',
			$email->form_fields['email_type']['options'],
			'El default tiene que ser una opción ofrecida; si no, el select se dibuja vacío.'
		);
	}

	/**
	 * La clase NO puede ser `final`: WooCommerce clona y extiende los emails en
	 * su mailer, y Mockery no mockea clases finales.
	 *
	 * @testdox El correo no es final: WooCommerce lo extiende en su mailer.
	 */
	public function test_el_email_no_es_final(): void {
		self::assertFalse( ( new ReflectionClass( SeriesFollowEmail::class ) )->isFinal() );
	}

	/** @testdox get_content devuelve los bloques de TODAS las series. */
	public function test_get_content_devuelve_los_bloques_de_todas_las_series(): void {
		$email = $this->probe();
		$email->trigger(
			10,
			array(
				$this->bloque( 42, 107, 'One Piece' ),
				$this->bloque( 43, 42, 'Berserk' ),
			)
		);

		$html = array_values(
			array_filter(
				$this->renderizados,
				static fn ( array $r ): bool => false === strpos( $r['plantilla'], 'plain/' )
			)
		);

		self::assertNotSame( array(), $html, 'Sin get_content_html() el cuerpo sale vacío.' );
		self::assertCount( 2, $html[0]['args']['groups'] );
		self::assertSame( 'One Piece', $html[0]['args']['groups'][0]['series_name'] );
		self::assertSame( 'Berserk', $html[0]['args']['groups'][1]['series_name'] );
	}

	/** @testdox Existe la versión plain además de la HTML. */
	public function test_existe_la_version_plain(): void {
		$email = $this->probe();
		$email->trigger( 10, array( $this->bloque( 42, 107, 'One Piece' ) ) );

		$plantillas = array_column( $this->renderizados, 'plantilla' );

		self::assertContains( 'emails/customer-series-follow.php', $plantillas );
		self::assertSame(
			array( 'emails/customer-series-follow.php' ),
			array_values( array_unique( $plantillas ) ),
			'get_content() sólo pide la HTML; la plain la pide WooCommerce en multipart.'
		);
		self::assertStringContainsString( 'plain/', $email->get_content_plain() );
	}

	/** @testdox Los placeholders del asunto se sustituyen con la serie más nueva. */
	public function test_placeholders_del_asunto_se_sustituyen(): void {
		$email = $this->probe();
		$email->trigger( 10, array( $this->bloque( 42, 107, 'One Piece' ) ) );

		self::assertSame( 'One Piece', $email->akb_placeholders()['{serie}'] );
		self::assertSame( '107', $email->akb_placeholders()['{tomo}'] );
	}

	/**
	 * Sin decir cuántas más viajan, el cliente cree que el correo habla de una
	 * sola serie y no abre los otros bloques. Eso no cambia.
	 *
	 * LO QUE SÍ CAMBIÓ: el sufijo sale FUERA de `{serie}`, en su propio marcador.
	 * Pegado dentro, un dueño que escribiera «Novedades de {serie}» recibía
	 * «Novedades de One Piece (+2 series más)» y no podía ni verlo ni moverlo:
	 * una option invisible escondida dentro de una que sí lo es. Y el paréntesis
	 * con signo más era notación de programador, que en la vista previa del móvil
	 * compite con el nombre de la serie.
	 *
	 * @testdox El sufijo de varias series viaja en {y_mas}, no pegado a {serie}.
	 */
	public function test_el_multi_serie_anade_el_sufijo(): void {
		$email = $this->probe();
		$email->trigger(
			10,
			array(
				$this->bloque( 42, 107, 'One Piece' ),
				$this->bloque( 43, 42, 'Berserk' ),
				$this->bloque( 44, 18, 'Monster' ),
			)
		);

		self::assertSame( 'One Piece', $email->akb_placeholders()['{serie}'], '{serie} es el NOMBRE, y sólo eso.' );
		self::assertSame( ' y 2 series más', $email->akb_placeholders()['{y_mas}'] );

		$dos = $this->probe();
		$dos->trigger(
			10,
			array(
				$this->bloque( 42, 107, 'One Piece' ),
				$this->bloque( 43, 42, 'Berserk' ),
			)
		);

		self::assertSame( ' y 1 serie más', $dos->akb_placeholders()['{y_mas}'], 'Singular con una sola.' );

		$sola = $this->probe();
		$sola->trigger( 10, array( $this->bloque( 42, 107, 'One Piece' ) ) );

		self::assertSame( '', $sola->akb_placeholders()['{y_mas}'], 'Con una serie no sobra nada al final del asunto.' );
	}

	/**
	 * UN ENLACE POR SERIE: dar de baja de una no puede callar las otras dos.
	 *
	 * @testdox Cada bloque lleva SU propio enlace de baja.
	 */
	public function test_cada_bloque_lleva_su_propio_enlace_de_baja_por_serie(): void {
		$email = $this->probe();
		$email->trigger(
			10,
			array(
				$this->bloque( 42, 107, 'One Piece' ),
				$this->bloque( 43, 42, 'Berserk' ),
			)
		);

		$groups = $this->renderizados[0]['args']['groups'];

		self::assertSame( UnfollowHandler::url_for( 10, 42 ), $groups[0]['unfollow_url'] );
		self::assertSame( UnfollowHandler::url_for( 10, 43 ), $groups[1]['unfollow_url'] );
		self::assertNotSame( $groups[0]['unfollow_url'], $groups[1]['unfollow_url'] );
	}

	/** @testdox El enlace de la cabecera es el GLOBAL, no el de una serie. */
	public function test_el_enlace_de_cabecera_es_el_global(): void {
		$email = $this->probe();
		$email->trigger( 10, array( $this->bloque( 42, 107, 'One Piece' ) ) );

		self::assertSame( UnfollowHandler::url_for_all( 10 ), $email->akb_unsubscribe_url() );
		self::assertNotSame( UnfollowHandler::url_for( 10, 42 ), $email->akb_unsubscribe_url() );
	}

	/** @testdox Los headers llevan List-Unsubscribe y One-Click (RFC 8058). */
	public function test_headers_llevan_list_unsubscribe_y_one_click(): void {
		$email = $this->probe();
		$email->trigger( 10, array( $this->bloque( 42, 107, 'One Piece' ) ) );

		$headers = $email->get_headers();

		self::assertStringContainsString( 'List-Unsubscribe: <' . UnfollowHandler::url_for_all( 10 ) . '>', $headers );
		self::assertStringContainsString( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', $headers );
	}

	/** @testdox [NEG] Sin destinatario o sin bloques no se envía nada. */
	public function test_sin_destinatario_o_sin_bloques_no_envia(): void {
		self::assertFalse( $this->probe()->trigger( 10, array() ) );
		self::assertFalse( $this->probe()->trigger( 0, array( $this->bloque( 42, 107, 'One Piece' ) ) ) );

		Functions\when( 'get_userdata' )->justReturn( false );
		self::assertFalse( $this->probe()->trigger( 10, array( $this->bloque( 42, 107, 'One Piece' ) ) ) );
	}

	/**
	 * El locale del cliente se abre y se cierra SIEMPRE: uno que queda abierto
	 * traduce el resto del request al idioma del último destinatario.
	 *
	 * @testdox El locale queda balanceado tras el envío.
	 */
	public function test_el_locale_queda_balanceado(): void {
		\WC_Email::akb_reset_locale_counters();

		$this->probe()->trigger( 10, array( $this->bloque( 42, 107, 'One Piece' ) ) );

		self::assertTrue( \WC_Email::akb_locale_is_balanced() );
	}

	/** @testdox La descripción del asunto declara qué placeholders funcionan y cuáles no. */
	public function test_la_ayuda_del_asunto_declara_los_placeholders_reales(): void {
		$email = $this->probe();
		$email->init_form_fields();

		$descripcion = (string) $email->form_fields['subject']['description'];

		self::assertStringContainsString( '{serie}', $descripcion );
		self::assertStringContainsString( '{tomo}', $descripcion );
		self::assertStringContainsString(
			'cuerpo',
			$descripcion,
			'Un corte y un enlace de baja en la línea de asunto no significan nada; hay que decirlo.'
		);
	}

	/**
	 * @testdox Un bloque con is_gap usa el asunto de hueco y no contamina al cliente siguiente del lote.
	 */
	public function test_un_bloque_de_hueco_usa_el_asunto_de_hueco_y_no_contamina_al_siguiente(): void {
		$email = $this->probe();

		$normal = $this->bloque( 42, 11, 'Nana' );
		$email->trigger( 10, array( $normal ) );
		self::assertSame( 'Salió el tomo 11 de Nana', $email->get_subject() );

		$hueco           = $this->bloque( 42, 5, 'Nana' );
		$hueco['is_gap'] = true;
		$email->trigger( 11, array( $hueco ) );
		self::assertSame( 'Cierra el hueco de tu colección: tomo 5 de Nana', $email->get_subject() );

		$email->trigger( 12, array( $normal ) );
		self::assertSame( 'Salió el tomo 11 de Nana', $email->get_subject() );
	}
}
