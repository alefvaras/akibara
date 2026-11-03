<?php
/**
 * HomeHero\Migration — seed default no destructivo.
 *
 * Al activar el plugin (o al primer boot tras instalación) corre `maybe_seed()`.
 * Si el option `akibara_hero_settings` está vacío Y existen los archivos
 * `hero-desktop.webp` / `hero-movile.webp` en uploads/2026/05/, busca sus
 * attachment IDs y siembra 1 slide replicando el hero histórico:
 *
 *   - desktop_id + mobile_id apuntando a los attachments encontrados
 *   - imagen decorativa (alt vacío porque el texto visible ya comunica)
 *   - title = "Encuentra tu próxima historia"
 *   - lede  = "Manga, cómics, preventas y novedades en un solo lugar."
 *   - 3 CTAs internos con rutas relativas y portables entre ambientes
 *
 * Si no encuentra los attachments, persiste un settings vacío (no falla — el
 * shortcode `[akb_hero]` simplemente no renderea nada hasta que el owner suba
 * imágenes). Esto es seguro: ningún site rompe.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\HomeHero;

defined( 'ABSPATH' ) || exit;

final class Migration {

	/** Slugs/filenames del hero histórico que buscamos al sembrar. */
	private const LEGACY_DESKTOP_FILENAMES = array( 'hero-desktop.webp', 'hero-desktop.png' );
	private const LEGACY_MOBILE_FILENAMES  = array( 'hero-movile.webp', 'hero-movile.png' );

	/**
	 * Si no hay settings persistidos todavía, siembra defaults.
	 * Idempotente: una vez sembrado, no vuelve a correr.
	 */
	public static function maybe_seed(): void {
		// Seed SOLO si la option nunca fue inicializada. El guard anterior miraba
		// "slides no vacío": si el owner borraba todos los slides a propósito, la
		// option quedaba con slides:[] y el próximo boot la re-sembraba, resucitando
		// el slide eliminado. Distinguimos "nunca inicializada" (option === null) de
		// "vaciada a propósito" (option existe con slides:[]).
		$raw = get_option( Settings::OPTION_KEY, null );
		if ( null !== $raw ) {
			return;
		}

		// Un default de get_option no distingue ausencia de fallo de lectura.
		// Antes de sembrar valores, confirma directamente que la option no existe
		// y que la base respondió sin error, evitando sustituir configuración real.
		global $wpdb;
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				Settings::OPTION_KEY
			)
		);
		if ( '' !== $wpdb->last_error ) {
			// La DB no respondió: no sembrar con información dudosa.
			return;
		}
		if ( null !== $exists ) {
			// La fila EXISTE pero get_option dijo null: `notoptions` quedó
			// envenenado en el object cache (una lectura fallida transitoria
			// se persistió como "no existe"). Sin esta purga, el hero queda
			// invisible PARA SIEMPRE hasta un flush manual — la detección
			// debe auto-curar, no solo abstenerse de pisar la config.
			wp_cache_delete( Settings::OPTION_KEY, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			return;
		}

		$desktop_id = self::find_attachment_by_filenames( self::LEGACY_DESKTOP_FILENAMES );
		$mobile_id  = self::find_attachment_by_filenames( self::LEGACY_MOBILE_FILENAMES );

		$slide = Settings::default_slide();

		if ( $desktop_id > 0 ) {
			$slide['desktop_id'] = $desktop_id;
		}
		if ( $mobile_id > 0 ) {
			$slide['mobile_id'] = $mobile_id;
		}

		$slide['alt']   = '';
		$slide['title'] = 'Encuentra tu próxima historia';
		$slide['lede']  = 'Manga, cómics, preventas y novedades en un solo lugar.';
		$slide['ctas']  = array(
			array( 'label' => 'Ver manga',    'url' => '/manga/',  'style' => 'manga' ),
			array( 'label' => 'Ver cómics',   'url' => '/comics/', 'style' => 'comics' ),
			array( 'label' => 'Ver catálogo', 'url' => '/tienda/', 'style' => 'shop' ),
		);

		// Si ningún attachment se encontró, dejamos slide pero sin IDs:
		// Settings::sanitize() lo descartará al guardar (porque desktop_id=0
		// y mobile_id=0). Persistimos defaults igual para inicializar
		// la option y evitar correr maybe_seed otra vez.
		$payload = array(
			'slides'   => ( $desktop_id > 0 || $mobile_id > 0 ) ? array( $slide ) : array(),
			'carousel' => Settings::default_carousel(),
		);

		Settings::save( $payload );
	}

	/**
	 * Encuentra el attachment ID cuyo archivo (`_wp_attached_file`) termine
	 * con uno de los $filenames. Devuelve 0 si no existe.
	 *
	 * @param array<int, string> $filenames
	 */
	private static function find_attachment_by_filenames( array $filenames ): int {
		global $wpdb;
		if ( empty( $filenames ) ) {
			return 0;
		}

		// Anclamos con '/' antes del filename para evitar matches greedy del tipo
		// `old-hero-desktop.webp` cuando el patrón es `hero-desktop.webp`.
		$like_values = array_map(
			static fn( string $f ): string => '%/' . $wpdb->esc_like( $f ),
			$filenames
		);
		$conditions  = array_fill( 0, count( $filenames ), 'meta_value LIKE %s' );
		$where_sql   = implode( ' OR ', $conditions );

		// Estrategia: query meta_value LIKE '%/hero-desktop.webp' OR LIKE '%/hero-mobile.png'.
		$sql = "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND ( {$where_sql} ) LIMIT 1";

		$id = $wpdb->get_var( $wpdb->prepare( $sql, ...$like_values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql es literal de este método salvo $wpdb->postmeta y los marcadores LIKE que genera array_fill(), y se entrega a $wpdb->prepare() en esta misma línea; el sniff sólo reconoce la consulta escrita dentro del prepare().

		return $id ? (int) $id : 0;
	}
}
