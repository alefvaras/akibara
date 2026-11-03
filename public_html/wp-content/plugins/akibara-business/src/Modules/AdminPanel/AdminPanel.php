<?php
/**
 * AdminPanel — panel de administración centralizado de Akibara Business.
 *
 * Registra un ítem de menú "Akibara" en el sidebar de WP admin con tabs
 * por cada módulo que tenga ajustes configurables.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\AdminPanel;

use Akibara\Business\Utilities\PluginAssetVersion;

defined( 'ABSPATH' ) || exit;

final class AdminPanel {

	/** Capability del panel completo. Cada sección puede exigir MÁS, nunca menos. */
	public const PANEL_CAP = 'manage_woocommerce';

	/**
	 * Errores de validación previa: significan que NO se escribió nada.
	 */
	private const ERROR_TRANSIENT_PREFIX = 'akibara_eda_save_errors_';

	/**
	 * Escrituras rechazadas una a una: entró casi todo. Canal aparte a propósito,
	 * porque el de arriba afirma lo contrario y el panel llegó a decir las dos
	 * cosas en la misma pantalla.
	 */
	private const PARTIAL_TRANSIENT_PREFIX = 'akibara_eda_save_partial_';

	/**
	 * Secciones cuyo guardado NO dispara el purge global: sus ajustes no
	 * alimentan HTML cacheado, o el módulo purga por su cuenta exactamente lo
	 * que cambia (`catalog_order`, `home_series`).
	 *
	 * Guardar una de éstas no dispara el purge global (ver `handle_save()`). La
	 * lista es deliberadamente corta y opt-out: agregar una sección acá exige
	 * comprobar que TODAS sus superficies quedan fuera de la caché de página, o
	 * que el módulo purga por su cuenta exactamente las que cambian
	 * (`catalog_order`, `home_series`).
	 *
	 * - `address`: sólo el checkout, que nunca se cachea.
	 * - `native_cart`: el drawer se hidrata por AJAX y la página de carrito es
	 *   dinámica; ninguna de las dos entra a la caché.
	 * - `free_shipping_progress`: la barra vive en drawer, carrito y checkout.
	 *   OJO: el mensaje del umbral que sí aparece en TODAS las páginas es el de
	 *   la barra de anuncios, que es otra sección (`announcement`) y sí purga.
	 *
	 * Quedan FUERA a propósito, aunque tienten: `preventa` y `back_in_stock`
	 * (sus banners y formularios se imprimen en fichas y listados cacheados),
	 * `taxonomy_discount` y `series_bundles` (mueven precios), `announcement`,
	 * `branding` y `copys` (HTML de todo el sitio).
	 *
	 * Mapeo de secciones administrativas a la explicación contextual que justifica
	 * omitir la purga global de caché tras guardar.
	 *
	 * @var array<string, string>
	 */
	private const SECTIONS_WITHOUT_CACHED_SURFACE = array(
		'address'                 => 'Estos ajustes se ven en el checkout, que no se cachea: el cambio ya está visible sin limpiar la caché del sitio.',
		'native_cart'             => 'Estos ajustes se ven en el carrito y su cajón, que no se cachean: el cambio ya está visible sin limpiar la caché del sitio.',
		'free_shipping_progress'  => 'Esta barra se ve en el cajón, el carrito y el checkout, que no se cachean: el cambio ya está visible sin limpiar la caché del sitio.',
		// La calidad AVIF sólo actúa al crear archivos de imagen; ningún HTML
		// cambia al guardarla. La recompresión purga por su cuenta al terminar.
		'imagenes'                => 'Esta calidad se aplica a las imágenes que subas de ahora en adelante; las ya subidas no cambian hasta que uses «Recomprimir». Ningún HTML cacheado depende de ella.',
		// El interruptor del recalentado no cambia HTML (y purgar tiraría lo que
		// recalentó). La etiqueta de Google Ads y la carga diferida de GA4 y
		// Clarity sí viven en el HTML: sus options purgan por su cuenta, y sólo
		// cuando cambian de valor (`GoogleAdsTag`, `AnalyticsAfterLoad`).
		'velocidad'               => 'El recalentado no cambia el HTML del sitio, así que el guardado no limpió la caché. Si moviste la etiqueta de Google Ads o la carga de Analytics y Clarity, esa parte limpió la caché por su cuenta.',
		// Guardar la vitrina no reordena nada: encola el recálculo, y ése purga
		// al terminar (sólo si reescribió algún menu_order). Purgar aquí vaciaba
		// el borde minutos ANTES de que cambiara el orden —la tienda fría para
		// nada— y el aviso «el cambio ya está visible» mentía.
		'home_series'             => 'Si cambiaste la vitrina, se limpió de la caché sólo la portada, no el sitio entero. Cloudflare puede tardar hasta 10 minutos en mostrar el cambio.',
		'catalog_order'           => 'Si cambiaste el orden de la vitrina, lo aplica un recálculo que el cron del servidor ejecuta en unos minutos y que limpia la caché del sitio al terminar; hasta entonces el cliente sigue viendo el orden anterior. Si cambiaste los tomos por página de las series, esas páginas ya se limpiaron de la caché: el cambio se ve en uno o dos minutos.',
	);

	/**
	 * Hooks que detectan un cambio real durante el guardado.
	 *
	 * Incluye opciones y term-meta para asegurar detección de modificaciones
	 * en parámetros de configuración y descuentos editoriales.
	 *
	 * @var array<int, string>
	 */
	private const CHANGE_HOOKS = array(
		'updated_option',
		'added_option',
		'updated_term_meta',
		'added_term_meta',
		'deleted_term_meta',
	);

	/**
	 * Grupos del nav, en el orden en que se pintan.
	 *
	 * Son SEPARADORES visuales, no niveles: la clave vacía es «sin grupo» y va
	 * primera. Una sección registrada con un grupo que no está acá cae en el
	 * bucket sin grupo.
	 *
	 * @var array<string, string>
	 */
	private const NAV_GROUPS = array(
		''             => '',
		'venta'        => 'Operaciones & Pedidos',
		'marketing'    => 'Marketing & Promociones',
		'catalogo'     => 'Catálogo & Portada',
		'fidelizacion' => 'Fidelización & Avisos',
		'sitio'        => 'Sitio & Experiencia',
		'sistema'      => 'Sistema',
	);

	/** @var array<string, array{label: string, settings_fn: callable, header_fn: ?callable, is_action: bool, cap: string, group: string}> */
	private array $sections = array();

	/**
	 * @param string        $id          Slug de la pestaña.
	 * @param string        $label       Texto visible en el nav.
	 * @param callable      $settings_fn Callable que devuelve el array de WC settings.
	 *                                    En action sections puede devolver array() vacío.
	 * @param callable|null $header_fn   Callable opcional que renderiza HTML antes del form
	 *                                    (útil para callouts/banners que no caben en la
	 *                                    columna `<th>` del form-table). En action sections
	 *                                    renderiza todo el UI (la sección no tiene form WC).
	 * @param bool          $is_action   true = sección sin form de settings, solo header_fn.
	 *                                    Útil para tabs tipo "ejecutar comando" sin guardar nada.
	 * @param string        $cap         Capability adicional de ESTA sección. Vacío = la del
	 *                                    panel. Una sección cuya superficie excede la tienda
	 *                                    (p. ej. una operación global del sitio) declara acá
	 *                                    su cap real y deja de aparecer en el nav para quien
	 *                                    no la tiene: sin
	 *                                    esto el shop manager veía la pestaña y solo al
	 *                                    entrar descubría que no podía usarla.
	 * @param string        $group       Separador visual del nav bajo el que se agrupa
	 *                                    la sección. Una de las claves de
	 *                                    `NAV_GROUPS` ('venta', 'sitio', 'sistema');
	 *                                    vacío o desconocido = sin grupo, que se
	 *                                    pinta primero.
	 */
	public function add_section( string $id, string $label, callable $settings_fn, ?callable $header_fn = null, bool $is_action = false, string $cap = '', string $group = '' ): void {
		$this->sections[ $id ] = array(
			'label'       => $label,
			'settings_fn' => $settings_fn,
			'header_fn'   => $header_fn,
			'is_action'   => $is_action,
			'cap'         => '' !== $cap ? $cap : self::PANEL_CAP,
			'group'       => $group,
		);
	}

	/**
	 * Secciones que el usuario actual puede ver. Es la ÚNICA fuente del nav y
	 * del fallback de tab: derivar el nav de `$this->sections` a secas volvía a
	 * mostrar pestañas inalcanzables.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function visible_sections(): array {
		return array_filter(
			$this->sections,
			static fn( array $section ): bool => current_user_can( $section['cap'] )
		);
	}

	/**
	 * Secciones visibles en el ORDEN del nav: por grupo declarado y, dentro de
	 * cada grupo, por orden de registro.
	 *
	 * Es la fuente ÚNICA de los dos navegadores del panel (el `<select>` móvil y
	 * la lista de escritorio). Antes cada uno derivaba de un array distinto: el
	 * de escritorio recorría sólo los grupos declarados, así que una sección con
	 * el grupo mal escrito desaparecía de él y seguía apareciendo en el móvil.
	 *
	 * @param array<string, array<string, mixed>> $sections Secciones visibles.
	 * @return array<string, array<string, mixed>>
	 */
	private function sections_in_nav_order( array $sections ): array {
		$ordenadas = array();

		foreach ( array_keys( self::NAV_GROUPS ) as $grupo ) {
			foreach ( $sections as $id => $section ) {
				if ( $this->section_group( $section ) === $grupo ) {
					$ordenadas[ $id ] = $section;
				}
			}
		}

		return $ordenadas;
	}

	/**
	 * Grupo efectivo de una sección: el declarado si existe en `NAV_GROUPS`, y
	 * el bucket sin grupo si no. Ninguna sección puede quedarse fuera del nav
	 * por un tipeo.
	 *
	 * @param array<string, mixed> $section Sección registrada.
	 */
	private function section_group( array $section ): string {
		$grupo = (string) ( $section['group'] ?? '' );

		return isset( self::NAV_GROUPS[ $grupo ] ) ? $grupo : '';
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_print_styles-edit.php', array( $this, 'print_product_list_styles' ) );
	}

	public function register_menu(): void {
		add_menu_page(
			'Akibara',
			'Akibara',
			'manage_woocommerce',
			'akibara-panel',
			array( $this, 'render_page' ),
			$this->get_menu_icon(),
			56
		);

		// El primer submenú hereda el nombre del menú («Akibara» dentro de
		// «Akibara»): se renombra a «Panel» (patrón fix_admin_menu de Rank
		// Math). Y «Puntos y Reseñas» aparece como ENLACE saliente a su
		// pantalla real bajo WooCommerce — el hub enlaza, no absorbe.
		add_submenu_page(
			'akibara-panel',
			'Panel Akibara',
			'Panel',
			'manage_woocommerce',
			'akibara-panel'
		);
		if ( class_exists( '\\Akibara\\Rewards\\Plugin' ) ) {
			add_submenu_page(
				'akibara-panel',
				'Puntos y Reseñas',
				'Puntos y Reseñas',
				'manage_woocommerce',
				'admin.php?page=akb-rewards-settings'
			);
		}
	}

	/**
	 * option => valor por defecto declarado, sólo mientras se guarda una pestaña.
	 *
	 * @var array<string, string>
	 */
	private array $untouched_defaults = array();

	/**
	 * option => valor que se INTENTÓ escribir en este guardado.
	 *
	 * Se llena desde el filtro, no desde `$_POST`: lo que importa comparar es lo
	 * que llegó a `update_option()` tras el saneado, no lo que tecleó la persona.
	 *
	 * @var array<string, mixed>
	 */
	private array $intentos = array();

	/**
	 * Extrae el mapa `option => default` de la definición de una pestaña.
	 *
	 * Se ignoran los campos sin `id` (títulos y cierres de sección) y los que no
	 * declaran `default`: de esos no se puede saber si lo enviado es «lo que se
	 * pintó», así que se guardan como siempre.
	 *
	 * @param array<int, array<string, mixed>> $settings Definición de la pestaña.
	 * @return array<string, string>
	 */
	private static function defaults_by_option( array $settings ): array {
		$mapa = array();
		foreach ( $settings as $campo ) {
			if ( ! is_array( $campo ) || ! isset( $campo['id'], $campo['default'] ) ) {
				continue;
			}
			if ( is_scalar( $campo['default'] ) ) {
				$mapa[ (string) $campo['id'] ] = (string) $campo['default'];
			}
		}

		return $mapa;
	}

	/**
	 * Extrae el mapa `option => etiqueta visible` de la definición de una pestaña.
	 *
	 * El aviso de escritura rechazada lo lee el dueño, no un programador:
	 * `akibara_taxonomy_discount_to` no le dice cuál de los campos de la pantalla
	 * se quedó atrás, y «Vigencia hasta» sí.
	 *
	 * @param array<int, array<string, mixed>> $settings Definición de la pestaña.
	 * @return array<string, string>
	 */
	private static function labels_by_option( array $settings ): array {
		$mapa = array();
		foreach ( $settings as $campo ) {
			if ( ! is_array( $campo ) || ! isset( $campo['id'] ) || ! isset( $campo['title'] ) ) {
				continue;
			}
			if ( is_scalar( $campo['title'] ) ) {
				$mapa[ (string) $campo['id'] ] = (string) $campo['title'];
			}
		}

		return $mapa;
	}

	/**
	 * Determina si debe emitirse una purga de caché tras guardar la pestaña.
	 *
	 * Requiere que se hayan modificado valores efectivamente y que la sección
	 * impacte superficies públicas cacheadas (opt-out en SECTIONS_WITHOUT_CACHED_SURFACE).
	 *
	 * @param string $tab     Pestaña guardada.
	 * @param int    $cambios Cuántas opciones cambiaron de valor en este guardado.
	 */
	private static function should_purge_after_save( string $tab, int $cambios ): bool {
		if ( $cambios < 1 ) {
			return false;
		}

		return ! array_key_exists( $tab, self::SECTIONS_WITHOUT_CACHED_SURFACE );
	}

	/**
	 * Devuelve la explicación visible de por qué la sección no requiere purga global, o '' si purga.
	 */
	private static function uncached_surface_reason( string $tab ): string {
		return self::SECTIONS_WITHOUT_CACHED_SURFACE[ $tab ] ?? '';
	}

	/**
	 * Los avisos que toca pintar tras un guardado, en orden y sin contradicciones.
	 *
	 * Son tres situaciones que se estaban mezclando en dos canales:
	 *
	 * - **Validación previa**: `handle_save()` redirige ANTES de escribir nada,
	 *   así que ahí «no se guardó ningún ajuste» es literalmente cierto.
	 * - **Rechazo parcial**: entró casi todo y algún campo se quedó con su valor
	 *   anterior. Decir «ningún ajuste» aquí es falso y hace desconfiar de lo que
	 *   sí quedó escrito; felicitar con el verde, también.
	 * - **Guardado limpio**: el verde de siempre.
	 *
	 * Y un cuarto que viaja por el canal parcial pero NO es un rechazo: un campo
	 * que se guardó (entero o recortado) y del que el dueño tiene que saber algo
	 * (`'guardado' => true`, filtro `akibara_panel_options_saved_with_warning`).
	 * Meterlo en «Se guardó todo menos esto» hacía que el panel dijera que no se
	 * guardó y, en la frase siguiente, que sí (R-I1-5). Va en su propio aviso,
	 * junto al verde o al de los que no entraron, y no dice ni una cosa ni la
	 * otra: nombra el campo y lo que hay que revisar.
	 *
	 * @param bool                  $saved     Si venimos de un guardado.
	 * @param array<int, string>    $errores   Mensajes de validación previa.
	 * @param array<string, mixed>  $parciales option => etiqueta de lo que no quedó,
	 *        o `['etiqueta'=>…, 'motivo'=>…, 'guardado'=>bool]`.
	 * @param bool                  $purged    Si este guardado purgó la caché.
	 * @param bool                  $cambios   Si el guardado cambió algo de verdad.
	 * @param string                $motivo_sin_purga Motivo visible de por qué la pestaña no purga; '' cuando no aplica.
	 * @return array<int, array<string, string>> Avisos `tipo` + `texto`.
	 */
	private static function post_save_notices( bool $saved, array $errores, array $parciales, bool $purged, bool $cambios = true, string $motivo_sin_purga = '' ): array {
		$avisos = array();

		foreach ( $errores as $error ) {
			$avisos[] = array(
				'tipo'  => 'error',
				'texto' => (string) $error,
			);
		}

		if ( ! $saved ) {
			return $avisos;
		}

		if ( array() !== $errores ) {
			$avisos[] = array(
				'tipo'  => 'warning',
				'texto' => 'No se guardó ningún ajuste de esta pestaña — corrige los errores de arriba y vuelve a guardar.',
			);

			return $avisos;
		}

		$con_aviso   = array();
		$no_entraron = array();
		foreach ( $parciales as $option => $info ) {
			if ( is_array( $info ) && ! empty( $info['guardado'] ) ) {
				$con_aviso[ $option ] = $info;
			} else {
				$no_entraron[ $option ] = $info;
			}
		}

		if ( array() !== $no_entraron ) {
			// Agrupa las opciones rechazadas según su motivo descriptivo.
			$grupos = array();
			foreach ( $no_entraron as $option => $info ) {
				if ( is_array( $info ) ) {
					$etiqueta = (string) ( $info['etiqueta'] ?? '' );
					$motivo   = (string) ( $info['motivo'] ?? '' );
				} else {
					$etiqueta = (string) $info;
					$motivo   = '';
				}

				$nombre             = '' === $etiqueta ? (string) $option : $etiqueta;
				$grupos[ $motivo ][] = $nombre;
			}

			foreach ( $grupos as $motivo => $nombres ) {
				$texto = 'Se guardó todo menos esto: ' . implode( ', ', $nombres ) . '.';
				if ( '' !== $motivo ) {
					$texto .= ' ' . $motivo;
				}

				$avisos[] = array(
					'tipo'  => 'warning',
					'texto' => $texto,
				);
			}

			return array_merge( $avisos, self::saved_with_warning_notices( $con_aviso ) );
		}

		if ( $purged ) {
			$texto = 'Ajustes guardados correctamente. Se limpió la caché del sitio — el cambio ya está visible.';
		} elseif ( ! $cambios ) {
			$texto = 'No había cambios que guardar: todo sigue como estaba.';
		} else {
			// Informa el motivo específico por el cual no se requirió purga de caché.
			$texto = 'Ajustes guardados correctamente. ' . (
				'' !== $motivo_sin_purga
					? $motivo_sin_purga
					: 'Esta sección no alimenta páginas cacheadas, así que no hizo falta limpiar la caché del sitio.'
			);
		}

		$avisos[] = array(
			'tipo'  => 'success',
			'texto' => $texto,
		);

		return array_merge( $avisos, self::saved_with_warning_notices( $con_aviso ) );
	}

	/**
	 * Avisos de los campos que SÍ se guardaron pero tienen algo que revisar,
	 * agrupados por motivo como los rechazos. No dicen «se guardó» ni «no se
	 * guardó»: con «No había cambios que guardar» al lado, cualquiera de las dos
	 * sería falsa.
	 *
	 * @param array<string, array<string, mixed>> $con_aviso option => `['etiqueta'=>…, 'motivo'=>…]`.
	 * @return array<int, array<string, string>>
	 */
	private static function saved_with_warning_notices( array $con_aviso ): array {
		$grupos = array();
		foreach ( $con_aviso as $option => $info ) {
			$etiqueta                                      = (string) ( $info['etiqueta'] ?? '' );
			$grupos[ (string) ( $info['motivo'] ?? '' ) ][] = '' === $etiqueta ? (string) $option : $etiqueta;
		}

		$avisos = array();
		foreach ( $grupos as $motivo => $nombres ) {
			$texto = ( 1 === count( $nombres ) ? 'Revisa este campo: ' : 'Revisa estos campos: ' ) . implode( ', ', $nombres ) . '.';
			if ( '' !== $motivo ) {
				$texto .= ' ' . $motivo;
			}

			$avisos[] = array(
				'tipo'  => 'warning',
				'texto' => $texto,
			);
		}

		return $avisos;
	}

	/**
	 * Impide crear una fila cuyo valor enviado es el que ya se pintó.
	 *
	 * Devolver `$old_value` es lo que hace que `update_option()` salga sin
	 * escribir. Con la fila ausente ese valor es `false`, así que la comparación
	 * de Core corta antes de llegar a `add_option()`.
	 *
	 * @param mixed  $value     Valor entrante, ya saneado.
	 * @param string $option    Nombre de la option.
	 * @param mixed  $old_value Valor previo según WordPress.
	 * @return mixed
	 */
	public function skip_untouched_default( $value, $option, $old_value ) {
		/*
		 * Regla de Core, antes que cualquier cosa nuestra: con el valor entrante
		 * idéntico al viejo, `update_option()` sale sin escribir. Eso no es un
		 * fallo y no puede entrar en la lista de intentos, lo haya decidido una
		 * guarda de esta casa o un filtro de un tercero enganchado antes.
		 */
		if ( $value === $old_value ) {
			return $value;
		}

		if ( ! isset( $this->untouched_defaults[ $option ] ) ) {
			$this->intentos[ (string) $option ] = $value;

			return $value;
		}

		$centinela = '__akb_fila_ausente__';
		if ( $centinela !== get_option( $option, $centinela ) ) {
			$this->intentos[ (string) $option ] = $value; // La fila ya existe: guardar es lo normal.

			return $value;
		}

		if ( is_scalar( $value ) && (string) $value === $this->untouched_defaults[ $option ] ) {
			// Saltada A PROPÓSITO: no es un fallo y no puede salir en el aviso.
			return $old_value;
		}

		$this->intentos[ (string) $option ] = $value;

		return $value;
	}

	/**
	 * Detecta las opciones que se intentaron persistir pero cuyo valor final en base de datos difiere.
	 *
	 * Permite informar al usuario cuando una opción no pudo ser persistida (por ejemplo, debido
	 * a validaciones de base de datos o filtros de negocio).
	 *
	 * @return array<int, string> Nombres de opciones rechazadas.
	 */
	private function options_that_did_not_stick(): array {
		$fallidos = array();

		foreach ( $this->intentos as $option => $enviado ) {
			if ( ! is_scalar( $enviado ) ) {
				continue; // Sin forma de comparar sin arriesgar un falso positivo.
			}

			$actual = get_option( $option, null );
			if ( null === $actual ) {
				$fallidos[] = (string) $option;
				continue;
			}
			if ( is_scalar( $actual ) && (string) $actual !== (string) $enviado ) {
				$fallidos[] = (string) $option;
			}
		}

		return $fallidos;
	}

	public function handle_save(): void {
		if ( ! isset( $_POST['akibara_panel_save'] ) ) {
			return;
		}

		check_admin_referer( 'akibara_panel_save' );

		if ( ! current_user_can( self::PANEL_CAP ) ) {
			wp_die( 'Sin permisos.' );
		}

		$tab = sanitize_key( $_POST['akibara_current_tab'] ?? '' );

		if ( ! isset( $this->sections[ $tab ] ) ) {
			return;
		}

		// La cap del panel no basta: una sección puede exigir más. El
		// POST se rechaza acá, no solo se oculta la pestaña en el nav.
		if ( ! current_user_can( $this->sections[ $tab ]['cap'] ) ) {
			wp_die( 'Sin permisos.' );
		}

		// Action sections no guardan settings — los módulos manejan su propio POST.
		if ( ! empty( $this->sections[ $tab ]['is_action'] ) ) {
			return;
		}

		/**
		 * Filters validation errors before a panel tab persists any option.
		 *
		 * @param array<int, string> $errors Validation messages.
		 * @param string             $tab    Active tab slug.
		 *
		 * @since 1.88.8
		 */
		$validation_errors = apply_filters( 'akibara_panel_save_errors', array(), $tab );
		if ( is_array( $validation_errors ) && ! empty( $validation_errors ) ) {
			set_transient(
				self::ERROR_TRANSIENT_PREFIX . get_current_user_id(),
				array_values( array_map( 'strval', $validation_errors ) ),
				MINUTE_IN_SECONDS
			);
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'  => 'akibara-panel',
						'tab'   => $tab,
						'saved' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$settings = ( $this->sections[ $tab ]['settings_fn'] )();

		/*
		 * Omite escribir opciones si la fila no existe en base de datos y el valor
		 * enviado es idéntico al valor por defecto, previniendo mutaciones superfluas
		 * de estado y eventos redundantes de indexación.
		 */
		$this->untouched_defaults = self::defaults_by_option( $settings );
		$this->intentos           = array();

		// Cuántas options cambian DE VERDAD. WordPress dispara estos dos sólo
		// cuando la escritura ocurre, así que es el recuento honesto: ni los
		// defaults que la guarda salta, ni los valores idénticos al anterior.
		$cambios  = 0;
		$contador = static function () use ( &$cambios ): void {
			++$cambios;
		};
		foreach ( self::CHANGE_HOOKS as $gancho ) {
			add_action( $gancho, $contador, 10, 0 );
		}

		add_filter( 'pre_update_option', array( $this, 'skip_untouched_default' ), 10, 3 );
		woocommerce_update_options( $settings );
		remove_filter( 'pre_update_option', array( $this, 'skip_untouched_default' ), 10 );

		foreach ( self::CHANGE_HOOKS as $gancho ) {
			remove_action( $gancho, $contador, 10 );
		}

		/**
		 * Options de la pestaña que el módulo sabe que NO entraron como las
		 * escribió el dueño, aunque el saneador las dejara «iguales» a lo
		 * guardado (y por eso `options_that_did_not_stick()` no las ve).
		 *
		 * Es el canal PARCIAL: se guarda todo lo demás y el aviso nombra sólo el
		 * campo que no entró. El de validación (`akibara_panel_save_errors`)
		 * tira la pestaña entera y no sirve para un campo independiente.
		 *
		 * @since 1.116.9
		 *
		 * @param array<int, string> $no_guardados Options que no quedaron.
		 * @param string             $tab          Pestaña guardada.
		 */
		$no_guardados             = array_values( array_unique( (array) apply_filters( 'akibara_panel_options_not_saved', $this->options_that_did_not_stick(), $tab ) ) );
		$this->untouched_defaults = array();
		$this->intentos           = array();


		$parciales = array();
		$etiquetas = null;
		if ( array() !== $no_guardados ) {
			// Canal de opciones no guardadas.
			$etiquetas = self::labels_by_option( $settings );
			foreach ( $no_guardados as $option ) {
				/**
				 * Motivo específico por el cual la opción no fue persistida.
				 *
				 * @param string $motivo_default Motivo por defecto.
				 * @param string $option         Option que no quedó escrita.
				 * @param string $tab            Pestaña guardada.
				 */
				$motivo = (string) apply_filters(
					'akibara_panel_option_not_saved_reason',
					'El buscador no acepta cambios de precio mientras está poniéndose al día con el catálogo. Espera a que termine y vuelve a guardar sólo eso.',
					$option,
					$tab
				);

				$parciales[ $option ] = array(
					'etiqueta' => (string) ( $etiquetas[ $option ] ?? '' ),
					'motivo'   => $motivo,
				);
			}
		}

		/**
		 * Campos que SÍ se guardaron (enteros o recortados) pero sobre los que el
		 * dueño tiene que saber algo: option => qué revisar.
		 *
		 * No es el canal de `akibara_panel_options_not_saved`: ése dice «Se guardó
		 * todo menos esto», y para un valor que sí quedó escrito esa frase es
		 * falsa (R-I1-5, revisión del lote I1: «Se guardó todo menos esto: Guía:
		 * botón a la serie. Se guardó, pero sin {serie}…»). Un campo que además
		 * se rechazó se queda con el aviso del rechazo.
		 *
		 * @since 1.116.41
		 *
		 * @param array<string, string> $avisos option => qué revisar.
		 * @param string                $tab    Pestaña guardada.
		 */
		$con_aviso = (array) apply_filters( 'akibara_panel_options_saved_with_warning', array(), $tab );
		if ( array() !== $con_aviso ) {
			if ( null === $etiquetas ) {
				$etiquetas = self::labels_by_option( $settings );
			}
			foreach ( $con_aviso as $option => $aviso ) {
				if ( ! is_string( $option ) || isset( $parciales[ $option ] ) ) {
					continue;
				}
				$parciales[ $option ] = array(
					'etiqueta' => (string) ( $etiquetas[ $option ] ?? '' ),
					'motivo'   => (string) $aviso,
					'guardado' => true,
				);
			}
		}

		if ( array() !== $parciales ) {
			set_transient(
				self::PARTIAL_TRANSIENT_PREFIX . get_current_user_id(),
				$parciales,
				MINUTE_IN_SECONDS
			);
		}

		// La purga central de LiteSpeed y CDN se difiere a shutdown cuando cambia
		// un valor de una pestaña que alimenta superficies cacheadas.
		$purga = self::should_purge_after_save( $tab, $cambios );
		if ( $purga ) {
			add_action(
				'shutdown',
				static function (): void {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de LiteSpeed.
			do_action( 'litespeed_purge_all' );
				},
				99
			);
		}

		// Notificación de estado preciso post-guardado.
		$redirect_args = array(
			'page'    => 'akibara-panel',
			'tab'     => $tab,
			'saved'   => '1',
			'purged'  => $purga ? '1' : '0',
			'cambios' => $cambios > 0 ? '1' : '0',
		);

		// Soporte para sub-tabs internos: módulos que renderean su propio
		// sub-nav (ej. TaxonomyDiscount) pueden inyectar un hidden input
		// `akibara_current_subtab` en el form para preservar el sub-tab
		// activo tras el redirect post-save.
		$subtab = isset( $_POST['akibara_current_subtab'] ) ? sanitize_key( wp_unslash( (string) $_POST['akibara_current_subtab'] ) ) : '';
		if ( '' !== $subtab ) {
			$redirect_args['subtab'] = $subtab;
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_page(): void {
		// El nav y el fallback de tab se derivan de las secciones VISIBLES: una
		// sección con cap propia no aparece, y llegar a ella por URL cae
		// en la primera visible en vez de renderizar un aviso de permisos.
		$sections = $this->sections_in_nav_order( $this->visible_sections() );
		$tab_ids  = array_keys( $sections );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sólo decide qué pestaña se PINTA; guardar exige nonce en handle_save().
		$current  = sanitize_key( wp_unslash( $_GET['tab'] ?? ( $tab_ids[0] ?? 'general' ) ) );

		if ( ! isset( $sections[ $current ] ) ) {
			$current = $tab_ids[0] ?? '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sólo decide si se muestra el aviso de «guardado».
		$saved    = isset( $_GET['saved'] );
		$settings = isset( $sections[ $current ] )
			? ( $sections[ $current ]['settings_fn'] )()
			: array();
		$display_settings = $this->prepare_settings_for_display( $settings );
		$is_action = ! empty( $sections[ $current ]['is_action'] );

		/**
		 * Permite a una sección volverse «acción» según su propio estado
		 * (p. ej. un sub-tab que trae form propio hacia admin-post y no debe
		 * anidarse dentro del form de settings del panel).
		 *
		 * @since 1.110.0
		 *
		 * @param bool   $is_action Valor registrado de la sección.
		 * @param string $current   Tab activo.
		 */
		$is_action = (bool) apply_filters( 'akibara_panel_section_is_action', $is_action, $current );

		// Los dos canales del guardado, cada uno con su significado. Viven en
		// transient para sobrevivir el redirect post-save; se consumen aquí.
		//   errores  → validación cross-field (ej. akb_range_pair de
		//              EstimatedDelivery): no se escribió NADA.
		//   parcial  → escrituras rechazadas una a una: entró casi todo.
		$error_transient_key   = self::ERROR_TRANSIENT_PREFIX . get_current_user_id();
		$partial_transient_key = self::PARTIAL_TRANSIENT_PREFIX . get_current_user_id();

		$save_errors = get_transient( $error_transient_key );
		if ( is_array( $save_errors ) && ! empty( $save_errors ) ) {
			delete_transient( $error_transient_key );
		} else {
			$save_errors = array();
		}

		$save_partial = get_transient( $partial_transient_key );
		if ( is_array( $save_partial ) && ! empty( $save_partial ) ) {
			delete_transient( $partial_transient_key );
		} else {
			$save_partial = array();
		}

		// El texto tiene que decir la VERDAD de lo que pasó: desde 1.108.9 las
		// secciones sin superficie cacheada ya no purgan el sitio entero, y
		// prometer «se limpió la caché» ahí sería mentira.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- sólo deciden el texto del aviso.
		$notices = self::post_save_notices(
			$saved,
			array_values( array_map( 'strval', $save_errors ) ),
			$save_partial,
			'1' === sanitize_key( wp_unslash( (string) ( $_GET['purged'] ?? '0' ) ) ),
			'0' !== sanitize_key( wp_unslash( (string) ( $_GET['cambios'] ?? '1' ) ) ),
			self::uncached_surface_reason( $current )
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		?>
		<div class="wrap woocommerce akb-panel">

			<div class="akb-panel-header">
				<h1>Akibara</h1>
				<span class="akb-panel-version">v<?php echo esc_html( AKIBARA_BUSINESS_VERSION ); ?></span>
			</div>
			<?php // Marcador estándar para inserción de avisos administrativos de WordPress. ?>
			<hr class="wp-header-end">

			<?php foreach ( $notices as $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['tipo'] ); ?> is-dismissible"><p><?php echo wp_kses_post( $notice['texto'] ); ?></p></div>
			<?php endforeach; ?>

			<div class="akb-panel-shell">
				<aside class="akb-panel-sidebar">
					<div class="akb-panel-search">
						<input type="search" id="akb-panel-search-input" class="akb-panel-search__input" placeholder="🔍 Buscar ajuste..." aria-label="Buscar pestaña de ajuste">
					</div>
					<label class="screen-reader-text" for="akb-panel-section-select">Sección Akibara</label>
					<select id="akb-panel-section-select" class="akb-panel-section-select">
						<?php foreach ( $sections as $id => $section ) : ?>
							<?php $url = add_query_arg( array( 'page' => 'akibara-panel', 'tab' => $id ), admin_url( 'admin.php' ) ); ?>
							<option value="<?php echo esc_url( $url ); ?>" <?php selected( $current, $id ); ?>>
								<?php echo esc_html( $section['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<nav class="akb-panel-nav" aria-label="Secciones Akibara">
						<?php
						$pintado = '';
						foreach ( $sections as $id => $section ) :
							$g = $this->section_group( $section );
							if ( $g !== $pintado && '' !== self::NAV_GROUPS[ $g ] ) :
								$pintado = $g;
								?>
								<span class="akb-panel-nav__group" data-nav-group="<?php echo esc_attr( $g ); ?>" role="presentation">
									<?php echo esc_html( self::NAV_GROUPS[ $g ] ); ?>
								</span>
							<?php endif; ?>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'akibara-panel', 'tab' => $id ), admin_url( 'admin.php' ) ) ); ?>"
							   class="akb-panel-nav__link <?php echo $current === $id ? 'is-active' : ''; ?>"
							   data-tab="<?php echo esc_attr( $id ); ?>"
							   data-nav-group="<?php echo esc_attr( $g ); ?>"
							   <?php echo $current === $id ? 'aria-current="page"' : ''; ?>>
								<?php echo esc_html( $section['label'] ); ?>
							</a>
						<?php endforeach; ?>
					</nav>
				</aside>

				<section class="akb-panel-content" aria-labelledby="akb-panel-title">
					<h2 id="akb-panel-title" class="akb-panel-current-title<?php echo $is_action ? ' screen-reader-text' : ''; ?>">
						<?php echo esc_html( $sections[ $current ]['label'] ?? 'Akibara' ); ?>
					</h2>
					<?php
					if ( isset( $sections[ $current ]['header_fn'] ) && is_callable( $sections[ $current ]['header_fn'] ) ) {
						call_user_func( $sections[ $current ]['header_fn'] );
					}
					?>

					<?php if ( ! $is_action ) : ?>
						<form method="post" action="" class="akb-panel-form">
							<?php wp_nonce_field( 'akibara_panel_save' ); ?>
							<?php woocommerce_admin_fields( $display_settings ); ?>
							<input type="hidden" name="akibara_current_tab" value="<?php echo esc_attr( $current ); ?>">
							<?php
							/**
							 * Permite a un módulo inyectar `<input type="hidden">` adicionales
							 * en el form del panel. Útil para preservar estado de sub-navegación
							 * interna tras el redirect post-save (ej. TaxonomyDiscount sub-tab).
							 *
							 * @since 1.16.1
							 *
							 * @param string $current Slug del tab activo.
							 */
							do_action( 'akibara_panel_form_hidden_inputs', $current );
							?>
							<p class="submit">
								<button type="submit" name="akibara_panel_save" class="button button-primary">
									Guardar ajustes
								</button>
							</p>
						</form>
					<?php endif; ?>
				</section>
			</div>

		</div>
		<?php
	}

	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_akibara-panel' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'akibara-admin-panel',
			AKIBARA_BUSINESS_URL . 'assets/css/admin-panel.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/admin-panel.css' )
		);

		wp_enqueue_script(
			'akibara-admin-panel',
			AKIBARA_BUSINESS_URL . 'assets/js/admin-panel.js',
			array(),
			PluginAssetVersion::for_path( 'assets/js/admin-panel.js' ),
			true
		);
	}

	/**
	 * Lista de productos: la columna SKU de WooCommerce parte los ISBN-13 en
	 * dos líneas en cuanto la tabla se estrecha. Regla mínima por hook (la
	 * columna es de un tercero); solo en `edit-product`.
	 *
	 * @internal Callback de `admin_print_styles-edit.php`.
	 */
	public function print_product_list_styles(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}
		echo '<style id="akb-product-list-sku">.wp-list-table .column-sku{white-space:nowrap}</style>';
	}

	private function get_menu_icon(): string {
		// SVG inline — libro abierto, color blanco, base64
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="white">'
			. '<path d="M10 2C8 2 5.5 2.8 4 4V17c1.5-1 4-1.7 6-1.7s4.5.7 6 1.7V4C14.5 2.8 12 2 10 2z"'
			. ' opacity=".3"/>'
			. '<path d="M10 2C8 2 5.5 2.8 4 4v13c1.5-1 4-1.7 6-1.7V2z"/>'
			. '<path d="M10 2c2 0 4.5.8 6 2v13c-1.5-1-4-1.7-6-1.7V2z" opacity=".6"/>'
			. '</svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Keeps the panel title as the single visible section heading.
	 *
	 * WooCommerce title fields still render their description and open the
	 * settings table when their title is empty, so saving receives the original
	 * field definitions while the form avoids a duplicate H2.
	 *
	 * @param array<int, array<string, mixed>> $settings Settings for one tab.
	 * @return array<int, array<string, mixed>>
	 */
	private function prepare_settings_for_display( array $settings ): array {
		foreach ( $settings as $index => $setting ) {
			if ( 'title' !== ( $setting['type'] ?? '' ) ) {
				continue;
			}

			$settings[ $index ]['title'] = '';
			break;
		}

		return $settings;
	}
}
