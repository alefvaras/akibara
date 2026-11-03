<?php
/**
 * Controla la calidad AVIF y la recompresión de portadas desde el panel.
 * La política inicial elegida para el catálogo es 60. La calidad se aplica
 * mediante wp_editor_set_quality: el editor puede reemplazar set_quality
 * al guardar y el codificador AVIF usa setCompressionQuality, no
 * setImageCompressionQuality.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\ImageQuality;

use Akibara\Business\Modules\AdminPanel\AdminPanel;

defined( 'ABSPATH' ) || exit;

final class ImageQuality {

	/** Pestaña propia del panel. */
	public const PANEL_TAB_ID = 'imagenes';

	/** Calidad AVIF guardada (entero como texto, igual que el resto del panel). */
	public const OPTION_QUALITY = 'akb_image_avif_quality';

	/**
 * Calidad AVIF inicial elegida para el catálogo.
 */
	public const DEFAULT_QUALITY = 60;

	/** Por debajo de 40 la trama y los trazos finos se pierden a simple vista. */
	public const MIN_QUALITY = 40;

	/** Por encima de 90 el archivo crece mucho sin que se vea distinto. */
	public const MAX_QUALITY = 90;

	/** Acción de `admin-post.php` que arranca la recompresión. */
	private const ADMIN_ACTION = 'akibara_image_recompress';

	private AdminPanel $panel;

	private Recompressor $recompressor;

	public function __construct( AdminPanel $panel, Recompressor $recompressor ) {
		$this->panel        = $panel;
		$this->recompressor = $recompressor;
	}

	public function register(): void {
		add_filter( 'wp_editor_set_quality', array( $this, 'filter_quality' ), 20, 2 );
		add_filter( 'akibara_panel_save_errors', array( $this, 'validate_save' ), 10, 2 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::OPTION_QUALITY, array( self::class, 'sanitize_for_save' ) );
		add_action( 'admin_post_' . self::ADMIN_ACTION, array( $this, 'handle_recompress' ) );
		add_action( 'init', array( $this, 'register_admin_tab' ) );
		$this->recompressor->register();
	}

	/**
	 * La única noción de calidad válida: la usan el guardado y el lector.
	 *
	 * Un entero de 40 a 90, como número o como texto de dígitos. Cualquier otra
	 * cosa —vacío, decimales, «60%», fuera de rango— no es una calidad.
	 *
	 * @param mixed $value Valor a interpretar.
	 */
	public static function parse_quality( $value ): ?int {
		if ( is_int( $value ) ) {
			$calidad = $value;
		} elseif ( is_string( $value ) && 1 === preg_match( '/^\s*\d{1,3}\s*$/', $value ) ) {
			$calidad = (int) trim( $value );
		} else {
			return null;
		}

		return ( $calidad >= self::MIN_QUALITY && $calidad <= self::MAX_QUALITY ) ? $calidad : null;
	}

	/**
	 * La calidad vigente. Una fila ausente o inválida vale el valor por defecto.
	 */
	public static function current_quality(): int {
		return self::parse_quality( get_option( self::OPTION_QUALITY, self::DEFAULT_QUALITY ) ) ?? self::DEFAULT_QUALITY;
	}

	/**
	 * Calidad con que WordPress guarda cada tamaño nuevo. Sólo AVIF: JPEG y WebP
	 * siguen con el valor de core.
	 *
	 * @internal Callback de `wp_editor_set_quality`.
	 *
	 * @param mixed $quality   Calidad propuesta.
	 * @param mixed $mime_type Tipo MIME de la imagen de salida.
	 * @return mixed
	 */
	public function filter_quality( $quality, $mime_type = '' ) {
		return 'image/avif' === $mime_type ? self::current_quality() : $quality;
	}

	/**
	 * Rechaza la pestaña entera si la calidad escrita no es válida.
	 *
	 * Mejor que guardar otra cosa en silencio: el dueño escribió un número y
	 * tiene que saber que no entró.
	 *
	 * @internal Callback de `akibara_panel_save_errors`.
	 *
	 * @param mixed  $errors Mensajes acumulados.
	 * @param string $tab    Pestaña que se guarda.
	 * @return mixed
	 */
	public function validate_save( $errors, $tab ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- `AdminPanel::handle_save()` verifica el nonce antes de aplicar este filtro.
		if ( self::PANEL_TAB_ID !== $tab || ! is_array( $errors ) || ! isset( $_POST[ self::OPTION_QUALITY ] ) ) {
			return $errors;
		}

		$enviado = wp_unslash( $_POST[ self::OPTION_QUALITY ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se valida con parse_quality() y no se guarda.
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( null === self::parse_quality( is_string( $enviado ) ? $enviado : null ) ) {
			$errors[] = sprintf( 'La calidad tiene que ser un número entero entre %d y %d. No se guardó nada.', self::MIN_QUALITY, self::MAX_QUALITY );
		}

		return $errors;
	}

	/**
	 * Lo que WooCommerce escribe: la calidad normalizada, o la vigente si lo
	 * enviado no es válido (la validación previa ya lo habría detenido).
	 *
	 * @internal Callback de `woocommerce_admin_settings_sanitize_option_akb_image_avif_quality`.
	 *
	 * @param mixed $value Valor enviado.
	 */
	public static function sanitize_for_save( $value ): string {
		return (string) ( self::parse_quality( $value ) ?? self::current_quality() );
	}

	/**
	 * @internal Callback de `init`.
	 */
	public function register_admin_tab(): void {
		$this->panel->add_section(
			self::PANEL_TAB_ID,
			'Imágenes',
			array( $this, 'get_settings' ),
			array( $this, 'render_admin_section' ),
			false,
			'',
			'sitio'
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return array(
			array(
				'title' => 'Calidad de las imágenes nuevas',
				'type'  => 'title',
				'id'    => 'akb_image_quality_settings',
				'desc'  => 'Al subir una portada, WordPress guarda varios tamaños de ella para que cada pantalla baje el que necesita. Esta calidad decide cuánto pesan esos tamaños en toda imagen AVIF que subas (portadas, y también un logo o un banner si los subes en AVIF). Más bajo pesa menos y se ve un poco más suave. El archivo que subes no se recomprime; si WordPress lo achica por ser muy grande, esa copia sí usa esta calidad.',
			),
			array(
				'title'             => 'Calidad AVIF (de 40 a 90)',
				'id'                => self::OPTION_QUALITY,
				'type'              => 'number',
				'default'           => (string) self::DEFAULT_QUALITY,
				'custom_attributes' => array(
					'min'      => self::MIN_QUALITY,
					'max'      => self::MAX_QUALITY,
					'step'     => 1,
					'required' => 'required',
				),
				'desc'              => 'Medido en la tienda el 14 de septiembre de 2026 con las portadas que se cargan por el servidor: con 60 pesan un 37-39 % menos que con 82 (lo que usa WordPress de fábrica) y a tamaño real no se distinguen. Con 50 pesan la mitad y, con lupa, se suavizan los trazos finos.',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_image_quality_settings',
			),
		);
	}

	/**
	 * Arranca la recompresión de lo ya subido con la calidad guardada.
	 *
	 * ENCOLA: recorrer 1.600 portadas con nueve tamaños cada una es cerca de una
	 * hora de CPU; dentro del request del admin sería una pantalla en blanco.
	 *
	 * @internal Callback de `admin_post_akibara_image_recompress`.
	 */
	public function handle_recompress(): void {
		if ( ! current_user_can( AdminPanel::PANEL_CAP ) ) {
			wp_die( 'Sin permisos.', 'akibara', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ADMIN_ACTION );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => 'akibara-panel',
					'tab'               => self::PANEL_TAB_ID,
					'akb_recompresion' => $this->recompressor->start( self::current_quality() ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Estado de la recompresión y su botón.
	 *
	 * @internal Callback de `AdminPanel::add_section($header_fn)`.
	 */
	public function render_admin_section(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- bandera de sólo lectura puesta por nuestro propio wp_safe_redirect.
		$resultado = isset( $_GET['akb_recompresion'] ) ? sanitize_key( wp_unslash( (string) $_GET['akb_recompresion'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$aviso = match ( $resultado ) {
			'encolada'    => array( 'success', 'Recompresión encolada. La hace el servidor en segundo plano, de a poco, para no ralentizar la tienda: vuelve a esta pantalla para ver cuánto lleva.' ),
			'ya-en-curso' => array( 'info', 'Ya hay una recompresión en curso; no se empezó otra.' ),
			'sin-cola'    => array( 'error', 'No se pudo encolar: falta la cola de tareas de WooCommerce (Action Scheduler).' ),
			default       => null,
		};
		$estado  = Recompressor::state();
		$calidad = self::current_quality();
		?>
		<h2>Portadas ya subidas</h2>

		<?php if ( null !== $aviso ) : ?>
			<div class="notice notice-<?php echo esc_attr( $aviso[0] ); ?> is-dismissible"><p><?php echo esc_html( $aviso[1] ); ?></p></div>
		<?php endif; ?>

		<p>
			La calidad de abajo se aplica a lo que subas desde que la guardas. Las portadas que ya están en la
			tienda conservan la compresión con que se hicieron hasta que las recomprimes. Recomprimir reemplaza
			cada tamaño <strong>sólo si el archivo nuevo pesa al menos un 10 % menos</strong>: nunca deja una
			portada más pesada ni toca el original. Por eso subir la calidad no mejora lo ya subido. El logo y
			los banners no se recomprimen.
		</p>

		<p><?php echo esc_html( self::describe_state( $estado, Recompressor::is_stalled( $estado ) ) ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::ADMIN_ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ADMIN_ACTION ); ?>">
			<button type="submit" class="button"><?php echo esc_html( sprintf( 'Recomprimir las portadas ya subidas con calidad %d', $calidad ) ); ?></button>
		</form>
		<p class="description">Usa la calidad guardada: si la cambias, guárdala primero.</p>
		<?php
	}

	/**
	 * El estado en una frase para el dueño.
	 *
	 * @param array<string, mixed> $estado   Estado normalizado de {@see Recompressor::state()}.
	 * @param bool                 $detenida Si la pasada quedó a medias sin nada que la mueva.
	 */
	public static function describe_state( array $estado, bool $detenida = false ): string {
		if ( 0 === (int) $estado['started'] ) {
			return 'Todavía no se ha recomprimido nada.';
		}

		$ahorro = max( 0, (int) $estado['bytes_antes'] - (int) $estado['bytes_despues'] );
		$cuenta = sprintf(
			'%s de %s portadas revisadas · %s tamaños reemplazados por uno más liviano · %s MB menos',
			number_format_i18n( (int) $estado['revisadas'] ),
			// El total se cuenta al empezar; una pasada larga puede cruzarse con portadas
			// nuevas, y «1.610 de 1.604» sólo confunde.
			number_format_i18n( max( (int) $estado['total'], (int) $estado['revisadas'] ) ),
			number_format_i18n( (int) $estado['reemplazados'] ),
			number_format_i18n( $ahorro / 1048576, 1 )
		);
		if ( (int) $estado['omitidas'] > 0 ) {
			$cuenta .= sprintf( ' · %s portadas se dejaron como estaban (original enorme o ausente, o una que cortó el proceso)', number_format_i18n( (int) $estado['omitidas'] ) );
		}
		if ( (int) $estado['errores'] > 0 ) {
			$cuenta .= sprintf( ' · %s tamaños no se pudieron revisar (se reintentan en la próxima recompresión)', number_format_i18n( (int) $estado['errores'] ) );
		}
		// Expone las portadas omitidas y su motivo, no sólo el conteo.
		if ( '' !== (string) $estado['ultimo_error'] && ( (int) $estado['omitidas'] > 0 || (int) $estado['errores'] > 0 ) ) {
			$cuenta .= sprintf( '. Último aviso: %s', rtrim( (string) $estado['ultimo_error'], ' .' ) );
		}

		if ( 0 === (int) $estado['finished'] ) {
			if ( $detenida ) {
				return sprintf( 'La recompresión con calidad %d se detuvo sin terminar: %s. Pulsa el botón para retomarla: con la misma calidad sigue donde quedó.', (int) $estado['quality'], $cuenta );
			}

			return sprintf( 'Recompresión en curso con calidad %d: %s.', (int) $estado['quality'], $cuenta );
		}

		$texto = sprintf( 'Última recompresión, terminada el %s con calidad %d: %s.', wp_date( 'j/m/Y \\a \\l\\a\\s H:i', (int) $estado['finished'] ), (int) $estado['quality'], $cuenta );
		if ( (int) $estado['purga_agendada'] > 0 ) {
			$texto .= sprintf( ' Las copias pesadas que guarda Cloudflare se vacían el %s, de madrugada, para no enfriar la tienda en horario de compra.', wp_date( 'j/m/Y \\a \\l\\a\\s H:i', (int) $estado['purga_agendada'] ) );
		} elseif ( (int) $estado['purgada'] > 0 ) {
			$texto .= sprintf( ' Las copias de Cloudflare se vaciaron el %s.', wp_date( 'j/m/Y \\a \\l\\a\\s H:i', (int) $estado['purgada'] ) );
		}

		return $texto;
	}
}
