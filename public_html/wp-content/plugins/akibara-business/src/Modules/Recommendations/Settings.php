<?php
/**
 * Ajustes de las recomendaciones de producto (pestaña «Recomendaciones»).
 *
 * Las reglas que deciden qué tomos se ofrecen fuera de la compra principal:
 *
 * - «Productos relacionados» de la ficha: una serie por hueco, empezando por su
 *   primer tomo que se pueda comprar (UC-6), o el comportamiento anterior.
 * - «También te recomendamos…» cuando TODOS los siguientes tomos guardados están
 *   agotados: mostrarlos con «Avísame cuando vuelva», cambiarlos por los
 *   siguientes que sí se pueden comprar, u ocultar el bloque (UC-7).
 * - «Más vendidos» de los estados vacíos (carrito vacío, 404, sin resultados):
 *   una tarjeta por serie o la lista tal cual (UC-13), y si la búsqueda sin
 *   resultados los muestra (UC-1).
 *
 * Una sola noción de valor válido por ajuste ({@see self::parse()}): la usan el
 * guardado del panel y el lector del front, así que el guardado no puede ser
 * más permisivo que la lectura. Un valor raro escrito a mano cae al de fábrica,
 * que nunca deja una ficha sin recomendaciones.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Recommendations;

defined( 'ABSPATH' ) || exit;

final class Settings {

	/** Pestaña del panel donde viven (la de «Recomendaciones» de siempre). */
	public const PANEL_TAB_ID = 'upsells_autofill';

	/** «Productos relacionados» de la ficha. */
	public const OPTION_RELATED = 'akb_reco_relacionados';

	/** Una serie por hueco, desde su primer tomo comprable. */
	public const RELATED_OTRA_SERIE = 'otra_serie';

	/** Como hasta 2026-09: los comprables primero, sin mirar la serie. */
	public const RELATED_COMPRABLES = 'comprables';

	/** Qué hacer si todos los siguientes tomos guardados están agotados. */
	public const OPTION_SERIE_AGOTADA = 'akb_reco_serie_agotada';

	/** Mostrarlos, ordenados, con «Avísame cuando vuelva» (lo de siempre). */
	public const AGOTADA_MOSTRAR = 'mostrar';

	/** Cambiarlos por los siguientes tomos de la serie que sí se pueden comprar. */
	public const AGOTADA_COMPLETAR = 'completar';

	/** No pintar el bloque. */
	public const AGOTADA_OCULTAR = 'ocultar';

	/** «Más vendidos» de los estados vacíos. */
	public const OPTION_MAS_VENDIDOS = 'akb_reco_mas_vendidos';

	/** Una tarjeta por serie. */
	public const MAS_VENDIDOS_UNA_POR_SERIE = 'una_por_serie';

	/** Los más vendidos tal cual, aunque se repita la serie. */
	public const MAS_VENDIDOS_TAL_CUAL = 'tal_cual';

	/**
	 * ¿La búsqueda sin resultados muestra «Lo más vendido»? Casilla: 'yes' o 'no'.
	 * La pinta el tema dentro del aviso del buscador y pregunta aquí con el
	 * filtro {@see self::FILTER_EN_BUSQUEDA}.
	 */
	public const OPTION_MAS_VENDIDOS_BUSQUEDA = 'akb_reco_mas_vendidos_busqueda';

	/**
	 * Filtro con el que el tema pregunta si pinta «Lo más vendido» en una
	 * búsqueda sin resultados (themes/akibara-gp/inc/woocommerce.php). El nombre
	 * es el contrato entre los dos: no cambia.
	 */
	public const FILTER_EN_BUSQUEDA = 'akibara_show_popular_in_empty_search';

	/**
	 * Valores válidos de cada ajuste. El de fábrica está en {@see self::DEFAULTS}.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const CHOICES = array(
		self::OPTION_RELATED               => array( self::RELATED_OTRA_SERIE, self::RELATED_COMPRABLES ),
		self::OPTION_SERIE_AGOTADA         => array( self::AGOTADA_MOSTRAR, self::AGOTADA_COMPLETAR, self::AGOTADA_OCULTAR ),
		self::OPTION_MAS_VENDIDOS          => array( self::MAS_VENDIDOS_UNA_POR_SERIE, self::MAS_VENDIDOS_TAL_CUAL ),
		self::OPTION_MAS_VENDIDOS_BUSQUEDA => array( 'yes', 'no' ),
	);

	/**
	 * Valores predeterminados de las recomendaciones.
	 *
	 * Relacionados y más vendidos priorizan diversidad de series. El bloque de
	 * continuidad agotada se muestra por defecto; ocultarlo o sustituir tomos es una
	 * configuración comercial explícita. La búsqueda sin resultados ofrece más
	 * vendidos para conservar una salida de navegación.
	 *
	 * @var array<string, string>
	 */
	public const DEFAULTS = array(
		self::OPTION_RELATED               => self::RELATED_OTRA_SERIE,
		self::OPTION_SERIE_AGOTADA         => self::AGOTADA_MOSTRAR,
		self::OPTION_MAS_VENDIDOS          => self::MAS_VENDIDOS_UNA_POR_SERIE,
		self::OPTION_MAS_VENDIDOS_BUSQUEDA => 'yes',
	);

	public function register(): void {
		add_filter( 'akibara_panel_save_errors', array( self::class, 'validate_save' ), 10, 2 );
		foreach ( array_keys( self::CHOICES ) as $option ) {
			add_filter( 'woocommerce_admin_settings_sanitize_option_' . $option, array( self::class, 'sanitize_for_save' ), 10, 2 );
		}
		add_filter( self::FILTER_EN_BUSQUEDA, array( self::class, 'mas_vendidos_en_busqueda' ) );
	}

	/**
	 * ¿La búsqueda sin resultados muestra «Lo más vendido»? Lo decide la
	 * casilla del panel; sin fila o con un valor raro, encendido (el de
	 * fábrica), con la misma lectura que el resto de estos ajustes.
	 *
	 * @internal Callback de {@see self::FILTER_EN_BUSQUEDA}, que aplica el tema.
	 *
	 * @param mixed $propuesto Lo que propone el tema (se ignora: decide el ajuste).
	 */
	public static function mas_vendidos_en_busqueda( $propuesto = true ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- firma del filtro del tema.
		return 'yes' === self::current( self::OPTION_MAS_VENDIDOS_BUSQUEDA );
	}

	/**
	 * El valor si es uno de los válidos de `$option`; null si no.
	 *
	 * @param string $option Nombre del ajuste.
	 * @param mixed  $value  Valor a interpretar.
	 */
	public static function parse( string $option, $value ): ?string {
		$choices = self::CHOICES[ $option ] ?? array();

		return in_array( $value, $choices, true ) ? $value : null;
	}

	/**
	 * Valor vigente. Fila ausente o con un valor desconocido = el de fábrica.
	 *
	 * @param string $option Uno de los OPTION_*.
	 */
	public static function current( string $option ): string {
		$default = self::DEFAULTS[ $option ] ?? '';

		return self::parse( $option, get_option( $option, $default ) ) ?? $default;
	}

	/**
	 * Lo que WooCommerce escribe: el valor enviado si es válido; si no, el
	 * vigente. WooCommerce sólo pasa los `radio` por `wc_clean()`.
	 *
	 * @internal Callback de `woocommerce_admin_settings_sanitize_option_{option}`.
	 *
	 * @param mixed               $value  Valor enviado.
	 * @param array<string,mixed> $field  Campo del panel.
	 */
	public static function sanitize_for_save( $value, $field = array() ): string {
		$option = is_array( $field ) ? (string) ( $field['id'] ?? '' ) : '';
		if ( ! isset( self::CHOICES[ $option ] ) ) {
			return is_scalar( $value ) ? (string) $value : '';
		}

		return self::parse( $option, $value ) ?? self::current( $option );
	}

	/**
	 * Rechaza la pestaña entera si llega un valor que no existe (envío
	 * fabricado a mano), y lo dice en vez de guardar otra cosa en silencio.
	 *
	 * @internal Callback de `akibara_panel_save_errors`.
	 *
	 * @param mixed  $errors Mensajes acumulados.
	 * @param string $tab    Pestaña que se guarda.
	 * @return mixed
	 */
	public static function validate_save( $errors, $tab ) {
		if ( self::PANEL_TAB_ID !== $tab || ! is_array( $errors ) ) {
			return $errors;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- `AdminPanel::handle_save()` verifica el nonce antes de aplicar este filtro.
		foreach ( array_keys( self::CHOICES ) as $option ) {
			// Una casilla no trae un valor que no exista: el navegador manda '1'
			// y WooCommerce guarda 'yes' con '1' o 'yes' y 'no' con cualquier
			// otra cosa, antes de pasar por `sanitize_for_save()`.
			if ( ! isset( $_POST[ $option ] ) || self::OPTION_MAS_VENDIDOS_BUSQUEDA === $option ) {
				continue;
			}
			$enviado = wp_unslash( $_POST[ $option ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se valida con parse() y no se guarda desde aquí.
			if ( null === self::parse( $option, $enviado ) ) {
				$errors[] = 'Una de las opciones de recomendaciones no existe. No se guardó nada: elige una de las que ofrece la pantalla.';
				break;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $errors;
	}

	/**
	 * Encabezado de estos ajustes, entre la herramienta de relleno y el
	 * formulario.
	 *
	 * La pestaña abre con «Recomendaciones automáticas» (cobertura, salud,
	 * lote), que es larga; estos ajustes quedaban al final sin título
	 * propio, empezando por una frase suelta, y al dueño le costaba encontrar
	 * dónde se decide qué ve el cliente. El
	 * título de la primera sección no sirve: el panel lo borra para no repetir
	 * el suyo (ver `get_settings()`). Mismo patrón que la Vitrina («Qué ve
	 * primero el cliente», `CatalogOrder::render_admin_section()`).
	 *
	 * @internal Se pinta al final del encabezado de la pestaña (`Plugin`).
	 */
	public static function render_heading(): void {
		echo '<h3 class="akb-reco-settings-heading">Qué le recomienda la tienda al cliente</h3>';
	}

	/**
	 * Campos del panel.
	 *
	 * Con autoload (el de WooCommerce por defecto): la ficha los lee en cada
	 * render. Mientras no se guarden, `Plugin::FRONT_READ_OPTIONS` los calienta
	 * en el lote único del front.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_settings(): array {
		return array(
			array(
				// Vacío a propósito: el panel borra el título de la primera sección
				// para no repetir el suyo (`AdminPanel::prepare_settings_for_display()`).
				'title' => '',
				'type'  => 'title',
				'id'    => 'akb_reco_settings',
				'desc'  => 'Qué tomos se ofrecen además del que el cliente está mirando. Al guardar se limpia la caché para que la ficha cambie en el acto. Los títulos de los dos bloques se cambian en Textos → «Recomendaciones de la ficha».',
			),
			array(
				'title'   => '«Productos relacionados» de la ficha',
				'id'      => self::OPTION_RELATED,
				'type'    => 'radio',
				'default' => self::DEFAULTS[ self::OPTION_RELATED ],
				'options' => array(
					self::RELATED_OTRA_SERIE => 'Empezar otra serie (recomendado): una serie distinta en cada hueco, cada una con su primer tomo que se pueda comprar. No repite la serie de la ficha, que ya tiene su propio bloque, ni otra edición de la misma obra (las que se enlazan en «Ediciones relacionadas» de cada serie).',
					self::RELATED_COMPRABLES => 'Como antes: tomos al azar de la misma categoría, primero los que se pueden comprar, aunque se repita la serie o sean de la mitad.',
				),
			),
			array(
				'title'   => '«También te recomendamos…» con todo agotado',
				'id'      => self::OPTION_SERIE_AGOTADA,
				'type'    => 'radio',
				'default' => self::DEFAULTS[ self::OPTION_SERIE_AGOTADA ],
				'desc'    => 'Siempre van en orden de lectura (tomo 1 → N) y los que se pueden comprar primero. Esto decide qué pasa cuando NINGUNO de los siguientes tomos se puede comprar.',
				'options' => array(
					self::AGOTADA_MOSTRAR   => 'Mostrarlos con «Avísame cuando vuelva» (como hasta ahora).',
					self::AGOTADA_COMPLETAR => 'Cambiarlos por los siguientes tomos de la serie que sí se pueden comprar. Si no hay ninguno, se muestran los agotados.',
					self::AGOTADA_OCULTAR   => 'Ocultar el bloque.',
				),
			),
			array(
				'title'   => '«Más vendidos» (carrito vacío, página no encontrada, búsqueda sin resultados)',
				'id'      => self::OPTION_MAS_VENDIDOS,
				'type'    => 'radio',
				'default' => self::DEFAULTS[ self::OPTION_MAS_VENDIDOS ],
				'options' => array(
					self::MAS_VENDIDOS_UNA_POR_SERIE => 'Una tarjeta por serie (recomendado): cuatro puertas distintas en vez de tres tomos de la misma serie.',
					self::MAS_VENDIDOS_TAL_CUAL      => 'Los más vendidos tal cual, aunque se repita la serie.',
				),
			),
			array(
				'title'    => '«Lo más vendido» en la búsqueda sin resultados',
				'desc'     => 'Mostrar «Lo más vendido» cuando una búsqueda no encuentra nada',
				'desc_tip' => 'Encendida, bajo el aviso de «Sin resultados» aparecen cuatro de los más vendidos (elegidos como diga la opción de arriba), para que el cliente no se quede sin salida. Apagada, el aviso queda con su mensaje y las sugerencias del buscador. El título de la fila se cambia en Textos → «Blog / Buscador».',
				'id'       => self::OPTION_MAS_VENDIDOS_BUSQUEDA,
				'type'     => 'checkbox',
				'default'  => self::DEFAULTS[ self::OPTION_MAS_VENDIDOS_BUSQUEDA ],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_reco_settings',
			),
		);
	}
}
