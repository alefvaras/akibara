<?php
/**
 * Contrato del carrusel del home.
 *
 * Decisión del dueño: aparece cuando las reseñas elegibles SOBREPASAN las 7
 * (≥8, configurable). Elegible hereda el criterio del mu-plugin: aprobada,
 * producto publicado, ≥40 caracteres, NO incentivada. Y la regla de UI:
 * LIVIANO — cero JavaScript, scroll-snap CSS, imágenes lazy.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Reviews\Carousel;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.2.0
 */
final class CarouselTest extends RewardsTestCase {

	private Carousel $carousel;

	protected function setUp(): void {
		parent::setUp();

		$this->carousel = new Carousel();
		Functions\when( 'update_meta_cache' )->justReturn( array() );

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_permalink' )->alias( static fn( int $id ): string => "https://akibara.cl/p/{$id}/" );
		Functions\when( 'get_the_title' )->alias( static fn( int $id ): string => "Tomo {$id}" );
		Functions\when( 'wp_attachment_is' )->justReturn( true );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'wp_script_is' )->justReturn( false );
		Functions\when( 'wp_get_attachment_image' )->alias(
			static fn( int $id, $size, $icon, array $attr ): string => '<img src="foto-' . $id . '.jpg" loading="' . ( $attr['loading'] ?? '' ) . '" class="' . ( $attr['class'] ?? '' ) . '" alt="' . ( $attr['alt'] ?? '' ) . '">'
		);
	}

	/**
	 * Puebla las respuestas del wpdb falso.
	 *
	 * Una SOLA consulta responde ahora las dos preguntas (umbral y tarjetas),
	 * así que el doble ya no puede hacer divergir el conteo de las filas: las
	 * $rows dadas se completan con relleno hasta los $eligible elegibles, tal
	 * como devolvería MySQL.
	 *
	 * @param int                            $eligible Elegibles en la tienda.
	 * @param array<int,array<string,mixed>> $rows     Filas concretas del test.
	 */
	private function seed( int $eligible, array $rows = array() ): void {
		$objects = array();
		foreach ( $rows as $row ) {
			$objects[] = (object) $row;
		}

		for ( $i = count( $objects ); $i < $eligible; $i++ ) {
			$objects[] = (object) array(
				'comment_ID'      => 8000 + $i,
				'comment_post_ID' => 9000 + $i,
				'comment_author'  => 'Relleno ' . $i,
				'comment_content' => str_repeat( 'Reseña de relleno con largo suficiente. ', 2 ),
				'comment_date'    => '2026-08-29 11:00:00',
			);
		}

		$this->wpdb->results_answers['ORDER BY c.comment_date_gmt DESC'] = $objects;
	}

	public function test_below_the_threshold_renders_nothing(): void {
		// «Cuando sobrepase las 7»: con 7 elegibles aún NO aparece.
		$this->seed( 7 );

		$this->assertSame( '', $this->carousel->render() );
	}

	public function test_above_the_threshold_renders_snap_cards_without_js(): void {
		$this->seed(
			8,
			array(
				array(
					'comment_ID'      => 10,
					'comment_post_ID' => 777,
					'comment_author'  => 'Camila R.',
					'comment_content' => str_repeat( 'Muy buena edición. ', 5 ),
					'comment_date'    => '2026-08-30 11:00:00',
				),
			)
		);
		$this->wpdb->comment_meta[10]['rating']                                             = array( '5' );
		$this->wpdb->comment_meta[10][ \Akibara\Rewards\Reviews\Media::META_LOCAL_IMAGE ] = array( 901 );

		$html = $this->carousel->render();

		$this->assertStringContainsString( 'Voces del Distrito', $html );
		$this->assertStringContainsString( 'scroll-snap-type:x mandatory', $html );
		$this->assertStringContainsString( 'Camila R.', $html );
		$this->assertStringContainsString( '★★★★★', $html );
		$this->assertStringContainsString( 'loading="lazy"', $html );
		$this->assertStringContainsString( 'https://akibara.cl/p/777/', $html );
		$this->assertStringNotContainsString( '<script', $html, 'LIVIANO: cero JavaScript.' );
	}

	/**
	 * Revisión independiente 2026-09-13 (MEDIA): el carrusel es la superficie
	 * más visible y mostraba reseñas premiadas sin decirlo (S-66cc662a).
	 *
	 * @testdox La tarjeta de una reseña premiada lleva el mismo aviso que la ficha; la otra, no.
	 */
	public function test_a_rewarded_card_carries_the_incentive_notice(): void {
		$this->seed(
			8,
			array(
				array(
					'comment_ID'      => 10,
					'comment_post_ID' => 777,
					'comment_author'  => 'Camila R.',
					'comment_content' => str_repeat( 'Muy buena edición. ', 5 ),
					'comment_date'    => '2026-08-30 11:00:00',
				),
			)
		);
		$this->wpdb->comment_meta[10]['rating'] = array( '5' );
		$this->wpdb->comment_meta[10][ \Akibara\Rewards\Reviews\ReviewPoints::META_POINTS_PAID ] = array( '500' );
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => in_array( $option, array( 'akb_rewards_disclosure_enabled', 'akb_rewards_carousel_disclosure_enabled' ), true ) ? 'yes' : $default_value
		);

		$html = $this->carousel->render();

		$this->assertSame( 1, substr_count( $html, 'class="akb-rw-disclosure"' ), 'Sólo la premiada: el relleno no cobró.' );
		$this->assertStringContainsString( \Akibara\Rewards\Reviews\Disclosure::TEXTO_PREMIADA, $html );
		// Cambiar la FORMA de la tarjeta obliga a cambiar la clave: si no, las
		// tarjetas cacheadas con la forma vieja —sin aviso— se siguen sirviendo
		// hasta que caduque el transient. Se fija contra las versiones ya
		// retiradas, no contra la vigente: clavar la vigente convierte cada
		// cambio legítimo en un rojo que se arregla editando el test.
		foreach ( array( 'akb_rewards_carousel', 'akb_rewards_carousel_v2' ) as $retirada ) {
			$this->assertNotSame( $retirada, \Akibara\Rewards\Reviews\Carousel::TRANSIENT, 'Las tarjetas cacheadas con la forma vieja no pueden servirse.' );
		}
		$this->assertMatchesRegularExpression( '/^akb_rewards_carousel_v\d+$/', \Akibara\Rewards\Reviews\Carousel::TRANSIENT );
	}

	public function test_the_owner_can_switch_it_off(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_carousel_enabled' === $option ? 'no' : $default_value
		);
		$this->seed( 20 );

		$this->assertSame( '', $this->carousel->render() );
	}

	public function test_the_eligibility_sql_inherits_the_quality_gates(): void {
		// Contra el SQL EJECUTADO (var_log del doble), no contra el texto del
		// fichero: la célula desenmascaró la versión anterior como un test que
		// pasaba siempre (2026-08-31).
		$this->seed( 8 );
		$this->carousel->render();

		$count_sql = $this->executed_count_sql();

		$this->assertNotSame( '', $count_sql, 'La consulta de elegibilidad se ejecutó.' );
		$this->assertStringContainsString( "comment_type = 'review'", $count_sql );
		$this->assertStringContainsString( 'CHAR_LENGTH(c.comment_content) >= 40', $count_sql );
		$this->assertStringContainsString( "meta_key = 'cr_coupon_code'", $count_sql );
		$this->assertStringContainsString( "post_status = 'publish'", $count_sql );
		$this->assertStringContainsString( 'comment_parent = 0', $count_sql );
		$this->assertStringContainsString( 'BETWEEN 1 AND 5', $count_sql );
		$this->assertStringNotContainsString( '_akb_rewards_review_points', $count_sql, 'Por defecto las premiadas ENTRAN.' );
	}

	/**
	 * La consulta de elegibilidad realmente enviada al wpdb.
	 */
	private function executed_count_sql(): string {
		foreach ( $this->wpdb->results_log as $sql ) {
			if ( false !== strpos( $sql, 'comment_type' ) ) {
				return $sql;
			}
		}
		return '';
	}

	public function test_hostile_author_and_title_are_escaped(): void {
		$this->seed(
			8,
			array(
				array(
					'comment_ID'      => 10,
					'comment_post_ID' => 777,
					'comment_author'  => '<script>alert(1)</script>',
					'comment_content' => str_repeat( 'Texto suficientemente largo de reseña. ', 3 ),
					'comment_date'    => '2026-08-30 11:00:00',
				),
			)
		);
		$this->wpdb->comment_meta[10]['rating']       = array( '5' );
		$this->wpdb->comment_meta[10]['cr_rev_title'] = array( '"><img src=x onerror=alert(2)>' );

		$html = $this->carousel->render();

		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringNotContainsString( 'onerror=alert(2)>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html, 'El autor hostil sale ESCAPADO, no suprimido.' );
	}

	public function test_rewarded_reviews_can_be_excluded_by_option(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_carousel_exclude_rewarded' === $option ? 'yes' : $default_value
		);
		$this->seed( 8 );

		$this->carousel->render();

		$this->assertStringContainsString(
			'_akb_rewards_review_points',
			$this->executed_count_sql(),
			'El interruptor inyecta la exclusión en el SQL EJECUTADO.'
		);
		$this->assertStringContainsString( '_akb_rewards_review_counted', $this->executed_count_sql(), 'Con «premiar cada N», la que sumó para el premio también es incentivada.' );
	}

	public function test_excerpt_cuts_at_word_boundaries_never_mid_word(): void {
		$largo = trim( str_repeat( 'palabra ', 40 ) );
		$out   = Carousel::excerpt( $largo );
		$this->assertStringEndsWith( 'palabra…', $out, 'Corta en el espacio, jamás a media palabra.' );
		$this->assertLessThanOrEqual( 221, mb_strlen( $out, 'UTF-8' ) );

		$sin_espacios = str_repeat( 'X', 300 );
		$this->assertSame( str_repeat( 'X', 220 ) . '…', Carousel::excerpt( $sin_espacios ), 'Sin espacios se recorta duro: mejor que desbordar la card.' );

		$corto = 'Se queda igual.';
		$this->assertSame( $corto, Carousel::excerpt( $corto ) );
	}

	public function test_the_style_block_is_emitted_once_per_request(): void {
		$this->seed(
			8,
			array(
				array(
					'comment_ID'      => 10,
					'comment_post_ID' => 777,
					'comment_author'  => 'Camila R.',
					'comment_content' => str_repeat( 'Muy buena edición. ', 5 ),
					'comment_date'    => '2026-08-30 11:00:00',
				),
			)
		);
		$this->wpdb->comment_meta[10]['rating'] = array( '5' );

		$first  = $this->carousel->render();
		$second = $this->carousel->render();

		$this->assertStringContainsString( '<style>', $first );
		$this->assertStringNotContainsString( '<style>', $second, 'El shortcode pegado dos veces no duplica el CSS.' );
		$this->assertStringContainsString( 'akb-rw-card', $second, 'Las cards sí salen las dos veces.' );
	}

	public function test_an_orphan_photo_meta_falls_back_to_the_product_cover(): void {
		Functions\when( 'wp_attachment_is' )->alias( static fn( string $type, int $id ): bool => 555 === $id );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 555 );
		$this->seed(
			8,
			array(
				array(
					'comment_ID'      => 10,
					'comment_post_ID' => 777,
					'comment_author'  => 'Camila R.',
					'comment_content' => str_repeat( 'Muy buena edición. ', 5 ),
					'comment_date'    => '2026-08-30 11:00:00',
				),
			)
		);
		$this->wpdb->comment_meta[10]['rating']                                           = array( '5' );
		$this->wpdb->comment_meta[10][ \Akibara\Rewards\Reviews\Media::META_LOCAL_IMAGE ] = array( 999 );

		$html = $this->carousel->render();

		$this->assertStringContainsString( 'foto-555.jpg', $html, 'Meta huérfana → carátula del tomo, no un hueco.' );
		$this->assertStringContainsString( 'akb-rw-card', $html );
	}

	public function test_cards_without_review_photo_wear_the_product_cover(): void {
		// Orden del dueño 2026-08-31: TODA card del home lleva imagen.
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 640 );
		$this->seed(
			8,
			array(
				array(
					'comment_ID'      => 10,
					'comment_post_ID' => 777,
					'comment_author'  => 'Camila R.',
					'comment_content' => str_repeat( 'Muy buena edición. ', 5 ),
					'comment_date'    => '2026-08-30 11:00:00',
				),
			)
		);
		$this->wpdb->comment_meta[10]['rating'] = array( '5' );

		$html = $this->carousel->render();

		$this->assertStringContainsString( 'foto-640.jpg', $html );
		$this->assertStringContainsString( 'Portada de Tomo 777', $html, 'El alt dice la verdad: es la carátula, no una foto del cliente.' );
	}

	public function test_review_photo_beats_the_cover_and_keeps_its_alt(): void {
		// Solo el producto del test tiene carátula: así el «no contiene» de
		// abajo habla de ESTA tarjeta y no del relleno del umbral.
		Functions\when( 'get_post_thumbnail_id' )->alias( static fn( int $id ): int => 777 === $id ? 640 : 0 );
		$this->seed(
			8,
			array(
				array(
					'comment_ID'      => 10,
					'comment_post_ID' => 777,
					'comment_author'  => 'Camila R.',
					'comment_content' => str_repeat( 'Muy buena edición. ', 5 ),
					'comment_date'    => '2026-08-30 11:00:00',
				),
			)
		);
		$this->wpdb->comment_meta[10]['rating']                                           = array( '5' );
		$this->wpdb->comment_meta[10][ \Akibara\Rewards\Reviews\Media::META_LOCAL_IMAGE ] = array( 901 );

		$html = $this->carousel->render();

		$this->assertStringContainsString( 'foto-901.jpg', $html );
		$this->assertStringContainsString( 'class="akb-rw-cover" alt="Portada de Tomo 777"', $html, 'La portada pequeña identifica el producto, separada de la foto real.' );
		$this->assertStringContainsString( 'Foto de la reseña de Camila R.', $html );
	}

	public function test_gsap_animation_only_enqueues_when_the_theme_registered_it(): void {
		$enqueued = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( string $handle ) use ( &$enqueued ): void {
				$enqueued[] = $handle;
			}
		);
		$fila = array(
			array(
				'comment_ID'      => 10,
				'comment_post_ID' => 777,
				'comment_author'  => 'Camila R.',
				'comment_content' => str_repeat( 'Muy buena edición. ', 5 ),
				'comment_date'    => '2026-08-30 11:00:00',
			),
		);
		$this->wpdb->comment_meta[10]['rating'] = array( '5' );

		// Tema SIN gsap: cero encolado (el carrusel jamás trae la librería).
		$this->seed( 8, $fila );
		$this->carousel->render();
		$this->assertSame( array(), $enqueued );

		// Tema CON gsap registrado: el reveal viaja declarando la dependencia.
		Functions\when( 'wp_script_is' )->justReturn( true );
		$this->seed( 8, $fila );
		( new Carousel() )->render();
		$this->assertSame( array( 'akb-rewards-carousel' ), $enqueued );
	}

	public function test_the_css_carries_the_page_scroll_and_layout_guards(): void {
		// Regresión del QA vivo 2026-08-31: sr-text absolutos empujaban el
		// scrollWidth de la página 2.300px; cards estiradas 197px; cadena sin
		// espacios ensanchaba la card; pista inoperable por teclado.
		$this->seed(
			8,
			array(
				array(
					'comment_ID'      => 10,
					'comment_post_ID' => 777,
					'comment_author'  => 'Camila R.',
					'comment_content' => str_repeat( 'Muy buena edición. ', 5 ),
					'comment_date'    => '2026-08-30 11:00:00',
				),
			)
		);
		$this->wpdb->comment_meta[10]['rating'] = array( '5' );

		$html = $this->carousel->render();

		$this->assertStringContainsString( 'position:relative', $html );
		$this->assertStringContainsString( 'align-items:stretch', $html );
		$this->assertStringContainsString( 'overflow-wrap:anywhere', $html );
		$this->assertStringContainsString( 'max-width:min(360px,86vw)', $html, '80vw + border-box: en móvil asoma la card siguiente (señal de que hay más — escéptico 2026-08-31).' );
		$this->assertStringContainsString( 'tabindex="0"', $html );
		$this->assertMatchesRegularExpression( '/\.akb-rw-carousel h2\{[^}]*width:fit-content[;}]/', $html, 'El skew global del tema proyecta el h2 1px fuera: fit-content lo contiene.' );
		$this->assertStringContainsString( 'box-sizing:border-box', $html );
		$this->assertStringContainsString( '-webkit-line-clamp:5', $html );
		$this->assertStringContainsString( 'outline-offset:-2px', $html, 'La pista es full-bleed: el anillo de foco vive DENTRO.' );
	}

	public function test_flush_drops_the_transient_and_purges_the_home(): void {
		$dropped = array();
		Functions\when( 'delete_transient' )->alias(
			static function ( string $key ) use ( &$dropped ): bool {
				$dropped[] = $key;
				return true;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://akibara.cl/' );
		\Brain\Monkey\Actions\expectDone( 'litespeed_purge_url' )
			->once()
			->with( 'https://akibara.cl/' );

		$this->carousel->flush();

		$this->assertSame( array( Carousel::TRANSIENT ), $dropped );
	}

	public function test_post_status_changes_only_flush_for_products(): void {
		$dropped = 0;
		Functions\when( 'delete_transient' )->alias(
			static function () use ( &$dropped ): bool {
				++$dropped;
				return true;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://akibara.cl/' );

		$post            = new \WP_Post( 1 );
		$post->post_type = 'post';
		$this->carousel->flush_for_product( 'publish', 'draft', $post );
		$this->assertSame( 0, $dropped, 'Un post del blog no purga el carrusel.' );

		$producto            = new \WP_Post( 2 );
		$producto->post_type = 'product';
		$this->carousel->flush_for_product( 'publish', 'draft', $producto );
		$this->assertSame( 1, $dropped );

		// Guardar un producto publicado sin moverlo de estado NO es un cambio:
		// purgar LiteSpeed y reconstruir por editar un precio era trabajo puro.
		$this->carousel->flush_for_product( 'publish', 'publish', $producto );
		$this->assertSame( 1, $dropped, 'publish→publish no purga.' );
	}

	public function test_a_hard_delete_of_a_product_flushes_the_carousel(): void {
		$dropped = 0;
		Functions\when( 'delete_transient' )->alias(
			static function () use ( &$dropped ): bool {
				++$dropped;
				return true;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://akibara.cl/' );

		// Borrar EN FIRME no pasa por transition_post_status: sin este gancho
		// la portada servía hasta una hora una tarjeta hacia la nada.
		$entrada            = new \WP_Post( 1 );
		$entrada->post_type = 'post';
		$this->carousel->flush_on_delete( 1, $entrada );
		$this->assertSame( 0, $dropped );

		$producto            = new \WP_Post( 2 );
		$producto->post_type = 'product';
		$this->carousel->flush_on_delete( 2, $producto );
		$this->assertSame( 1, $dropped );

		// WP puede no pasar el objeto: se resuelve por id.
		Functions\when( 'get_post' )->justReturn( $producto );
		$this->carousel->flush_on_delete( 2 );
		$this->assertSame( 2, $dropped );
	}

	public function test_the_threshold_and_the_cards_come_from_a_single_query(): void {
		$this->seed( 12 );

		$html = $this->carousel->render();

		$this->assertNotSame( '', $html, 'Con 12 elegibles la sección se pinta.' );
		$this->assertCount(
			1,
			array_filter( $this->wpdb->results_log, static fn( string $sql ): bool => false !== strpos( $sql, 'comment_type' ) ),
			'Una sola consulta responde el umbral Y las tarjetas.'
		);
		$this->assertSame( 8, substr_count( $html, '<li class="akb-rw-card">' ), 'Se piden 12 filas para el umbral pero se pintan las 8 configuradas.' );
	}

	/**
	 * El panel dice bajo el título si la portada muestra hoy el carrusel
	 * (QA-PANEL-5). Lo sabe por el mismo dato con que la portada decide.
	 *
	 * @testdox Las elegibles que ve el panel son las mismas con que la portada decide: exactas bajo el mínimo.
	 */
	public function test_el_panel_ve_las_mismas_elegibles_que_la_portada(): void {
		$this->seed( 3 );

		$this->assertSame( 3, $this->carousel->eligible_seen() );
		$this->assertSame( '', $this->carousel->render(), 'Y con esas 3 la portada no pinta nada.' );
	}

	/**
	 * @testdox Con el transient caliente, las elegibles del panel salen de él y no consultan la base.
	 */
	public function test_el_panel_lee_el_mismo_cache_que_la_portada(): void {
		Functions\when( 'get_transient' )->justReturn(
			array(
				'eligible' => 11,
				'cards'    => array(),
			)
		);

		$this->assertSame( 11, $this->carousel->eligible_seen() );
		$this->assertSame( array(), $this->wpdb->results_log, 'Sin consulta: el panel no paga una portada fría si ya hay caché.' );
	}
	/** @testdox Los tres modos filtran antes del umbral sin confundir portadas ni adjuntos borrados. */
	public function test_media_modes_count_only_the_selected_reviews(): void {
		Functions\when( 'wp_attachment_is' )->alias( static fn( string $type, int $id ): bool => 901 === $id );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 640 );
		$this->seed( 3 );
		$this->wpdb->comment_meta[8000][ \Akibara\Rewards\Reviews\Media::META_LOCAL_IMAGE ] = array( 901 );
		$this->wpdb->comment_meta[8001][ \Akibara\Rewards\Reviews\Media::META_LOCAL_IMAGE ] = array( 999 );
		foreach ( array( 'mixed' => 3, 'photos' => 1, 'text' => 2 ) as $mode => $count ) {
			Functions\when( 'get_option' )->alias( static function ( string $option, $default = false ) use ( $mode ) {
				return array( 'akb_rewards_carousel_media' => $mode, 'akb_rewards_carousel_min' => 1 )[ $option ] ?? $default;
			} );
			$carousel = new Carousel();
			$this->assertSame( $count, $carousel->eligible_seen(), $mode );
			$html = $carousel->render();
			$this->assertSame( $count, substr_count( $html, '<li class="akb-rw-card">' ), $mode );
			$this->assertSame( 'text' === $mode ? 0 : 1, substr_count( $html, '<figure class="akb-rw-media">' ), $mode );
		}
	}

	/** @testdox El filtro de fotos encuentra una foto válida más allá de la primera página. */
	public function test_photo_filter_does_not_stop_at_a_page_without_photos(): void {
		Functions\when( 'get_option' )->alias( static function ( string $option, $default = false ) {
			return array( 'akb_rewards_carousel_media' => 'photos', 'akb_rewards_carousel_min' => 1, 'akb_rewards_carousel_cards' => 1 )[ $option ] ?? $default;
		} );
		$this->seed( 50 );
		$first = $this->wpdb->results_answers['ORDER BY c.comment_date_gmt DESC'];
		$older = clone $first[0];
		$older->comment_ID = 9001;
		$older->comment_author = 'Foto anterior';
		$this->wpdb->results_answers = array( 'OFFSET 0' => $first, 'OFFSET 50' => array( $older ) );
		$this->wpdb->comment_meta[9001][ \Akibara\Rewards\Reviews\Media::META_LOCAL_IMAGE ] = array( 901 );
		$html = $this->carousel->render();
		$this->assertStringContainsString( 'Foto anterior', $html );
		$this->assertStringNotContainsString( 'Relleno 1', $html );
		$this->assertCount( 2, $this->wpdb->results_log );
	}

	/** @testdox El mínimo sigue siendo configurable y se aplica tras seleccionar el tipo. */
	public function test_photo_mode_respects_the_configured_minimum(): void {
		Functions\when( 'get_option' )->alias( static fn( string $option, $default = false ) => array( 'akb_rewards_carousel_media' => 'photos', 'akb_rewards_carousel_min' => 2 )[ $option ] ?? $default );
		$this->seed( 8 );
		$this->wpdb->comment_meta[8000][ \Akibara\Rewards\Reviews\Media::META_LOCAL_IMAGE ] = array( 901 );
		$this->assertSame( 1, $this->carousel->eligible_seen() );
		$this->assertSame( '', $this->carousel->render() );
	}

	/** @testdox Las fotos remotas heredadas conservan su identidad sin usar la portada como prueba. */
	public function test_legacy_remote_photo_is_rendered_separately_from_product(): void {
		Functions\when( 'get_option' )->alias( static fn( string $option, $default = false ) => array( 'akb_rewards_carousel_media' => 'photos', 'akb_rewards_carousel_min' => 1 )[ $option ] ?? $default );
		$this->seed( 1 );
		$this->wpdb->comment_meta[8000][ \Akibara\Rewards\Reviews\Media::META_REMOTE_IMAGE ] = array( array( 'url' => 'https://example.com/customer.jpg' ) );
		$html = $this->carousel->render();
		$this->assertStringContainsString( 'https://example.com/customer.jpg', $html );
		$this->assertStringContainsString( 'class="akb-rw-media"', $html );
		$this->assertStringContainsString( 'akb-rw-photo', $html );
		$this->assertStringNotContainsString( 'Foto compartida por quien escribió la reseña', $html, 'La leyenda redundante fue retirada por orden de diseño.' );
		$this->assertStringContainsString( '#comment-8000', $html );
	}

	/** @testdox Adjuntar o retirar foto invalida el carrusel, pero una meta ajena no purga. */
	public function test_review_photo_changes_flush_the_cached_selection(): void {
		$count = 0;
		Functions\when( 'delete_transient' )->alias( static function () use ( &$count ): bool { ++$count; return true; } );
		$review = new \WP_Comment( array( 'comment_ID' => 15 ) );
		$review->comment_type = 'review';
		$review->comment_post_ID = 99;
		Functions\when( 'get_comment' )->justReturn( $review );
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		$this->carousel->flush_on_media( 1, 15, 'unrelated_meta' );
		$this->assertSame( 0, $count );
		$this->carousel->flush_on_media( 2, 15, \Akibara\Rewards\Reviews\Media::META_LOCAL_IMAGE );
		$this->carousel->flush_on_media( array( 3 ), 15, \Akibara\Rewards\Reviews\Media::META_REMOTE_IMAGE );
		$this->assertSame( 2, $count );
	}

}
