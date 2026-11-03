<?php
/**
 * Contratos de layout de la vista rápida (release 1.108.31).
 *
 * Los dos defectos que fija se veían idénticos desde el CSS —cada regla, por
 * separado, se lee perfecta— y sólo aparecían al medir el DOM:
 *
 *   1) El badge se CLONA desde la card, donde `loop.css` lo declara
 *      `position: absolute; top/left .5rem`, porque allí flota sobre la portada.
 *      Dentro del modal no hay portada debajo y `.akb-qv__meta` era `static`, así
 *      que el ancestro posicionado más cercano era el propio diálogo: el badge
 *      aterrizaba en la esquina y tapaba el `<h2>` del título.
 *
 *   2) `.akb-qv__media` combinaba `aspect-ratio: 2/3` con `min-height: 198px` y
 *      SIN ancho declarado. Con esa combinación manda el alto y el ancho sale
 *      derivado (198 × 2/3 = 132px). En escritorio la pista mide justo 132px y
 *      encajaba por casualidad; en móvil la pista baja a 104px y la portada se
 *      desbordaba 28px sobre la sinopsis.
 *
 * Ojo al escribir estas pruebas: los comentarios del propio CSS mencionan
 * `min-height: 198px` para explicar el bug. Por eso se comprueban DECLARACIONES,
 * no apariciones del literal — un grep a secas se pondría verde o rojo por un
 * comentario.
 *
 * @package Akibara\Business\Tests
 */

declare(strict_types=1);

namespace Akibara\Business\Tests\Modules\QuickView;

use PHPUnit\Framework\TestCase;

final class QuickViewLayoutContractTest extends TestCase {

	/**
	 * CSS con los comentarios eliminados, para que ninguna aserción se apoye en
	 * texto explicativo.
	 */
	private function css_sin_comentarios(): string {
		$ruta = dirname( __DIR__, 3 ) . '/assets/css/quick-view.css';
		$css  = file_get_contents( $ruta );
		$this->assertIsString( $css, 'No se pudo leer quick-view.css' );

		return (string) preg_replace( '#/\*.*?\*/#s', '', $css );
	}

	/**
	 * Devuelve el cuerpo de un selector, ya sin comentarios.
	 */
	private function bloque( string $selector ): string {
		$css = $this->css_sin_comentarios();
		$ok  = preg_match(
			'/(?:^|\})\s*' . preg_quote( $selector, '/' ) . '\s*\{([^}]*)\}/m',
			$css,
			$m
		);
		$this->assertSame( 1, $ok, "No encontré el bloque «{$selector}» en quick-view.css" );

		return $m[1];
	}

	public function test_la_fila_de_meta_contiene_a_sus_clones(): void {
		$this->assertMatchesRegularExpression(
			'/position:\s*relative/',
			$this->bloque( '.akb-qv__meta' ),
			'Sin `position: relative`, un clon con position:absolute se ancla al diálogo y tapa el título.'
		);
	}

	public function test_el_badge_vuelve_al_flujo_dentro_del_modal(): void {
		$this->assertMatchesRegularExpression(
			'/position:\s*static/',
			$this->bloque( '.akb-qv .akb-badge' ),
			'El badge llega absoluto desde loop.css; en el modal tiene que ser un item más de la fila.'
		);
	}

	public function test_la_portada_ata_su_ancho_a_la_pista_de_la_rejilla(): void {
		$media = $this->bloque( '.akb-qv__media' );

		$this->assertMatchesRegularExpression(
			'/width:\s*100%/',
			$media,
			'Sin ancho, el aspect-ratio deriva el ancho del alto y la portada ignora su columna.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/(?<!-)\bmin-height\s*:/',
			$media,
			'`min-height` junto a `aspect-ratio` invierte quién manda: el ancho pasa a derivarse del alto.'
		);
	}

	public function test_el_precio_domina_la_fila_de_meta(): void {
		// Antes del rediseño el precio era Inter 14px/400 blanco: el MISMO peso
		// que la sinopsis, y menos que el nombre de la editorial (700). El dato
		// que decide la compra pesaba menos que el sello del sello editorial.
		$precio = $this->bloque( '.akb-qv__meta .price' );

		$this->assertMatchesRegularExpression( '/font-size:\s*38px/', $precio, 'El precio tiene que dominar la fila.' );
		$this->assertMatchesRegularExpression( '/--aki-white/', $precio, 'El precio va en blanco editorial de alto contraste.' );
		$this->assertDoesNotMatchRegularExpression(
			'/--aki-red|#e60012/i',
			$precio,
			'El rojo sólido está reservado a comprar; en el precio confunde «pagar» con «esperar».'
		);

		$editorial = $this->bloque( '.akb-qv__meta .akb-editorial-eyebrow' );
		$this->assertMatchesRegularExpression(
			'/font-size:\s*11px/',
			$editorial,
			'La editorial es metadato: nunca puede competir con el precio.'
		);
	}

	public function test_el_chip_de_llegada_no_desborda_su_columna(): void {
		// `flex-basis: 100%` + padding + border sin `box-sizing` sumaba 23px al
		// ancho del contenedor y sacaba el chip fuera del modal en móvil.
		$eta = $this->bloque( '.akb-qv__meta .akb-eta-loop' );

		$this->assertMatchesRegularExpression( '/box-sizing:\s*border-box/', $eta );
		$this->assertMatchesRegularExpression( '/flex:\s*0 0 100%/', $eta );
	}

	public function test_el_enlace_no_compite_con_el_boton_de_comprar(): void {
		// Los dos eran rojos y vivían a dos centímetros. El rojo sólido es del CTA.
		$enlace = $this->bloque( '.akb-qv__link' );

		// Anclado a inicio de línea A PROPÓSITO: `\bcolor:` casa también con
		// `text-decoration-color:`, porque el guion es frontera de palabra. Con
		// esa versión el test marcaba en rojo el subrayado, que sí puede serlo.
		$this->assertDoesNotMatchRegularExpression(
			'/^\s*color:\s*var\(\s*--aki-red/m',
			$enlace,
			'El texto del enlace no puede ir en rojo: compite con «Reservar en preventa».'
		);
		$this->assertMatchesRegularExpression(
			'/^\s*color:\s*var\(\s*--aki-gray/m',
			$enlace,
			'El enlace es secundario: va en gris.'
		);
	}

	/**
	 * D5b (lote de integración): la vista rápida existe sólo desde 769 px. La
	 * hoja se encola con ese `media` y el JS no revela el botón por debajo, así
	 * que una regla para el teléfono en esta hoja no se aplicaría nunca.
	 */
	public function test_la_hoja_es_solo_de_escritorio_y_no_guarda_reglas_de_telefono(): void {
		$css = $this->css_sin_comentarios();

		$this->assertSame( 0, preg_match( '/@media\s*\(max-width/', $css ), 'Una regla para ≤768 aquí es código muerto.' );
		$this->assertSame( 1, preg_match_all( '/grid-template-columns:\s*(\d+)px\s+1fr/', $css ), 'Una sola pista: la del modal.' );

		$php = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/QuickView/QuickView.php' );
		$this->assertStringContainsString( "public const MEDIA = '(min-width: 769px)';", $php );
		$this->assertMatchesRegularExpression( "/wp_enqueue_style\(\s*'akb-quick-view',[^;]*self::MEDIA\s*\);/s", $php );

		$js = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/js/quick-view.js' );
		$this->assertStringContainsString( "window.matchMedia( '(min-width: 769px)' )", $js, 'El JS usa el mismo corte que la hoja.' );
	}

	/**
	 * QA-BIZ-DIFF-1 (QA integral 2026-09-24): el tema de producción 2.1.142 no
	 * tiene reglas para el botón. Con la hoja «sólo funcional» de D5b, business
	 * antes que el tema dejaba en cada tarjeta un botón nativo de 22 px con borde
	 * en relieve. Vuelve el aspecto propio, con especificidad de CLASE: con cero
	 * (`:where`) las reglas genéricas de `button` del tema le ganaban el fondo y
	 * el color; y el tema nuevo, más específico, lo sigue pisando entero.
	 *
	 * @testdox Sin el tema, el botón conserva su contorno de 44 px con especificidad de clase.
	 */
	public function test_sin_el_tema_el_boton_conserva_su_aspecto_de_44_px(): void {
		$propio = $this->bloque( '.akb-qv-trigger' );

		$this->assertMatchesRegularExpression( '/display:\s*inline-flex/', $propio );
		$this->assertMatchesRegularExpression( '/min-height:\s*44px/', $propio, 'El mínimo táctil de la casa (y sobre los 24 px de WCAG 2.5.8).' );
		foreach ( array( 'padding', 'border', 'color', 'font-size', 'background', 'box-sizing' ) as $propiedad ) {
			$this->assertMatchesRegularExpression( '/(^|;|\s)' . preg_quote( $propiedad, '/' ) . '\s*:/', $propio, "Sin «{$propiedad}» el botón vuelve al del navegador." );
		}
		$this->assertSame( 0, preg_match( '/!important/', $propio ), 'Sin !important: la regla del tema, más específica, siempre gana.' );
		$this->assertSame( 0, preg_match( '/:where\(\s*\.akb-qv-trigger/', $this->css_sin_comentarios() ), 'Con especificidad cero, el `button` genérico del tema le quita el contorno.' );
		$this->assertMatchesRegularExpression( '/(?:^|\})\s*\.akb-qv-trigger:hover\s*\{/m', $this->css_sin_comentarios() );
	}

	/**
	 * VERIF-Z-3 (verificación del lote de UX del tema, 2026-09-26): la cabecera
	 * pegada arriba es la primera hija posicionada del diálogo. Sin `z-index`,
	 * la portada y la fila del precio (`position: relative`) se pintaban encima
	 * al deslizarlo, y a 932x430 la portada caía sobre la «×». El aviso de red
	 * caída del tema (loop.css, `.akb-qv__actions > .akb-tarjeta-motivo`) lleva
	 * `z-index` propio: la cabecera tiene que quedar por encima también de él.
	 * La prueba de navegador es `negocio-vista-rapida-cabecera.spec.mjs`.
	 *
	 * @testdox La cabecera pegada del diálogo queda encima de la portada, el precio y el aviso de red caída del tema.
	 */
	public function test_la_cabecera_pegada_queda_encima_de_lo_que_pasa_por_debajo(): void {
		$cabecera = $this->bloque( '.akb-qv__head' );

		$this->assertMatchesRegularExpression( '/position:\s*sticky/', $cabecera, 'Fixture: la cabecera sigue pegada arriba.' );
		$this->assertSame( 1, preg_match( '/(?:^|;|\s)z-index:\s*(\d+)\s*;/', $cabecera, $propio ), 'Sin z-index lo posicionado que viene después se pinta encima.' );

		$loop = (string) file_get_contents( dirname( __DIR__, 5 ) . '/themes/akibara-gp/assets/css/loop.css' );
		$loop = (string) preg_replace( '#/\*.*?\*/#s', '', $loop );
		// El tema puede declararlo en más de un bloque (con y sin corte de ancho):
		// manda el z-index más alto de todos.
		preg_match_all( '/\.akb-qv__actions\s*>\s*\.akb-tarjeta-motivo\s*\{([^}]*)\}/', $loop, $bloques );
		$this->assertNotEmpty( $bloques[1], 'Fixture: el tema declara el aviso de la vista rápida.' );
		$z_aviso = array();
		foreach ( $bloques[1] as $cuerpo ) {
			if ( preg_match( '/z-index:\s*(\d+)/', $cuerpo, $z ) ) {
				$z_aviso[] = (int) $z[1];
			}
		}
		$this->assertNotEmpty( $z_aviso, 'Fixture: el aviso lleva z-index propio.' );

		$this->assertGreaterThan( max( $z_aviso ), (int) $propio[1], 'La cabecera tiene que quedar encima del aviso de red caída.' );
	}
}
