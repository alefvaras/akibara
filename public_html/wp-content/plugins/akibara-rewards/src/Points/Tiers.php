<?php
/**
 * Tiers — Niveles de fidelización y gamificación de Akibara Rewards.
 *
 * Niveles basados en el histórico total acumulado (`Ledger::total_earned()`):
 *   1. Lector: 0 a 1.999 pts (multiplicador 1.0x)
 *   2. Coleccionista: 2.000 a 7.999 pts (multiplicador 1.2x)
 *   3. Residente de Akiba: 8.000+ pts (multiplicador 1.5x)
 *
 * Cero CPTs zombis, cero tablas adicionales, O(1) puro.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.37
 * @api
 * @psalm-suppress HookNotFound
 * @psalm-suppress InvalidOperand
 */
final class Tiers {

	public const TIER_LECTOR        = 'lector';
	public const TIER_COLECCIONISTA = 'coleccionista';
	public const TIER_RESIDENTE     = 'residente_akiba';

	public const META_TIER            = '_akb_rewards_tier';
	public const META_TIER_MULTIPLIER = '_akb_rewards_tier_multiplier';
	public const META_TIER_EXPIRES_AT = '_akb_rewards_tier_expires_at';

	/**
	 * Devuelve la lista completa de niveles configurados (en orden ascendente por puntos mínimos).
	 *
	 * @return list<array{slug:string,name:string,min:int,multiplier:float,badge_class:string,icon:string,max_pts:int|null}>
	 */
	public static function get_all_tiers_ascending(): array {
		$tiers = array_values( self::get_tier_definitions() );
		usort( $tiers, static fn( array $a, array $b ): int => $a['min'] <=> $b['min'] );

		$total = count( $tiers );
		for ( $i = 0; $i < $total; $i++ ) {
			if ( $i < $total - 1 ) {
				$tiers[ $i ]['max_pts'] = $tiers[ $i + 1 ]['min'] - 1;
			} else {
				$tiers[ $i ]['max_pts'] = null;
			}
		}

		return $tiers;
	}

	/**
	 * Definición dinámica de los niveles leyendo las opciones del panel (con valores canónicos por defecto).
	 *
	 * @return array<string,array{slug:string,name:string,min:int,multiplier:float,badge_class:string,icon:string}>
	 */
	public static function get_tier_definitions(): array {
		$col_min  = Options::tier_coleccionista_min();
		$col_mult = Options::tier_coleccionista_multiplier();
		$res_min  = Options::tier_residente_min();
		$res_mult = Options::tier_residente_multiplier();

		return array(
			self::TIER_RESIDENTE    => array(
				'slug'        => self::TIER_RESIDENTE,
				'name'        => 'Residente de Akiba',
				'min'         => $res_min,
				'multiplier'  => $res_mult,
				'badge_class' => 'akb-tier-badge--residente-akiba',
				'icon'        => '👑',
			),
			self::TIER_COLECCIONISTA => array(
				'slug'        => self::TIER_COLECCIONISTA,
				'name'        => 'Coleccionista',
				'min'         => $col_min,
				'multiplier'  => $col_mult,
				'badge_class' => 'akb-tier-badge--coleccionista',
				'icon'        => '🥈',
			),
			self::TIER_LECTOR       => array(
				'slug'        => self::TIER_LECTOR,
				'name'        => 'Lector',
				'min'         => 0,
				'multiplier'  => 1.0,
				'badge_class' => 'akb-tier-badge--lector',
				'icon'        => '📖',
			),
		);
	}

	/**
	 * Registra hooks relacionados con insignias de nivel en reseñas públicas.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_review_before_comment_text', array( $this, 'render_review_badge' ), 10, 1 );
		add_action( 'akibara_gp_account_badge', array( $this, 'render_account_badge' ) );
		add_action( 'template_redirect', array( $this, 'private_tier_headers' ), 0, 0 );
	}

	/** El nivel personal servido en la cabecera requiere una respuesta privada y fresca. */
	public function private_tier_headers(): void {
		if ( ! is_user_logged_in() || ! Options::tiers_enabled() ) {
			return;
		}
		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'Nivel personal de Puntos Akibara' );
	}

	public function render_account_badge(): void {
		$user_id = (int) get_current_user_id();
		if ( $user_id < 1 || ! Options::tiers_enabled() ) {
			return;
		}
		$tier = self::for_user( $user_id );
		printf( '<span class="akb-header-tier" title="%s" aria-hidden="true">%s</span>', esc_attr( 'Nivel ' . $tier['name'] ), esc_html( $tier['icon'] ) );
	}

	/** Texto de progreso derivado del mismo nivel y multiplicador que acredita el pedido. */
	public static function progress_label( int $user_id ): string {
		if ( $user_id < 1 || ! Options::tiers_enabled() ) {
			return '';
		}
		$tier = self::for_user( $user_id );
		$text = sprintf( 'Nivel %s · %s× Puntos Akibara en compras.', $tier['name'], self::format_multiplier( $tier['multiplier'], ',' ) );
		if ( $tier['pts_to_next'] > 0 && $tier['next_tier'] ) {
			$text .= sprintf( ' Te faltan %s Puntos Akibara para %s.', number_format_i18n( $tier['pts_to_next'] ), $tier['next_tier'] );
		}
		return $text;
	}

	/**
	 * Muestra la insignia de nivel del autor en la reseña de WooCommerce.
	 *
	 * @param \WP_Comment|mixed $comment Comentario actual.
	 * @return void
	 */
	public function render_review_badge( $comment ): void {
		if ( ! $comment instanceof \WP_Comment || (int) $comment->user_id <= 0 || ! Options::tiers_enabled() ) {
			return;
		}

		$user_id = (int) $comment->user_id;
		$tier    = self::for_user( $user_id );

		// En nivel Lector (base), no se ensucia la ficha; se premia visualmente a Coleccionista y Residente.
		if ( self::TIER_LECTOR === $tier['slug'] ) {
			return;
		}

		printf(
			'<div class="akb-review-tier-wrap">%s</div>',
			wp_kses_post( self::badge_html( $tier['slug'], $tier['name'], $tier['icon'], true ) )
		);
	}

	/**
	 * Determina el nivel correspondiente a un total de puntos acumulados.
	 *
	 * Función PURA.
	 *
	 * @param int $total_earned Puntos históricos ganados (≥ 0).
	 * @return array{
	 *     slug: string,
	 *     name: string,
	 *     min: int,
	 *     multiplier: float,
	 *     badge_class: string,
	 *     icon: string,
	 *     next_tier: string|null,
	 *     next_min: int|null,
	 *     pts_to_next: int,
	 *     progress_pct: float
	 * }
	 */
	public static function get_tier_by_points( int $total_earned ): array {
		$defs   = self::get_tier_definitions();
		$earned = max( 0, $total_earned );

		if ( $earned >= $defs[ self::TIER_RESIDENTE ]['min'] ) {
			$current = $defs[ self::TIER_RESIDENTE ];
			return array_merge(
				$current,
				array(
					'next_tier'    => null,
					'next_min'     => null,
					'pts_to_next'  => 0,
					'progress_pct' => 100.0,
				)
			);
		}

		if ( $earned >= $defs[ self::TIER_COLECCIONISTA ]['min'] ) {
			$current     = $defs[ self::TIER_COLECCIONISTA ];
			$next_min    = $defs[ self::TIER_RESIDENTE ]['min'];
			$span        = $next_min - $current['min'];
			$progress    = $span > 0 ? ( ( $earned - $current['min'] ) / $span ) * 100.0 : 0.0;
			return array_merge(
				$current,
				array(
					'next_tier'    => $defs[ self::TIER_RESIDENTE ]['name'],
					'next_min'     => $next_min,
					'pts_to_next'  => max( 0, $next_min - $earned ),
					'progress_pct' => round( min( 100.0, max( 0.0, $progress ) ), 1 ),
				)
			);
		}

		$current     = $defs[ self::TIER_LECTOR ];
		$next_min    = $defs[ self::TIER_COLECCIONISTA ]['min'];
		$span        = $next_min - $current['min'];
		$progress    = $span > 0 ? ( ( $earned - $current['min'] ) / $span ) * 100.0 : 0.0;
		return array_merge(
			$current,
			array(
				'next_tier'    => $defs[ self::TIER_COLECCIONISTA ]['name'],
				'next_min'     => $next_min,
				'pts_to_next'  => max( 0, $next_min - $earned ),
				'progress_pct' => round( min( 100.0, max( 0.0, $progress ) ), 1 ),
			)
		);
	}

	/**
	 * Nivel actual de un cliente por su ID de usuario.
	 *
	 * Si la vigencia temporal está activa (> 0 días) y el periodo expiró sin actividad,
	 * aplica degradación suave (Soft-Landing) en lugar de corte destructivo a cero.
	 *
	 * @param int $user_id ID del usuario en WordPress.
	 * @return array{
	 *     slug: string,
	 *     name: string,
	 *     min: int,
	 *     multiplier: float,
	 *     badge_class: string,
	 *     icon: string,
	 *     next_tier: string|null,
	 *     next_min: int|null,
	 *     pts_to_next: int,
	 *     progress_pct: float,
	 *     soft_landed?: bool,
	 *     expired_from?: string,
	 *     expires_at?: int
	 * }
	 */
	public static function for_user( int $user_id ): array {
		if ( $user_id <= 0 || ! Options::tiers_enabled() ) {
			return self::get_tier_by_points( 0 );
		}

		$earned_tier   = self::get_tier_by_points( Ledger::total_earned( $user_id ) );
		$validity_days = Options::tier_validity_days();

		if ( $validity_days <= 0 || self::TIER_LECTOR === $earned_tier['slug'] ) {
			return $earned_tier;
		}

		$day_seconds = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		$expires_at  = (int) get_user_meta( $user_id, self::META_TIER_EXPIRES_AT, true );
		if ( $expires_at <= 0 ) {
			// Primera consulta con vigencia activa: proyecta la ventana en memoria sin escribir en GET.
			$earned_tier['expires_at'] = time() + ( $validity_days * $day_seconds );
			return $earned_tier;
		}

		if ( time() <= $expires_at ) {
			$earned_tier['expires_at'] = $expires_at;
			return $earned_tier;
		}

		// Expirado: aplicar Soft-Landing
		$defs = self::get_tier_definitions();
		if ( self::TIER_RESIDENTE === $earned_tier['slug'] ) {
			$col_tier = $defs[ self::TIER_COLECCIONISTA ];
			$next_min = $defs[ self::TIER_RESIDENTE ]['min'];
			return array_merge(
				$col_tier,
				array(
					'next_tier'    => $defs[ self::TIER_RESIDENTE ]['name'],
					'next_min'     => $next_min,
					'pts_to_next'  => 0,
					'progress_pct' => 0.0,
					'soft_landed'  => true,
					'expired_from' => $earned_tier['name'],
				)
			);
		}

		$base_tier                 = self::get_tier_by_points( 0 );
		$base_tier['pts_to_next']  = 0;
		$base_tier['soft_landed']  = true;
		$base_tier['expired_from'] = $earned_tier['name'];
		return $base_tier;
	}

	/**
	 * Renueva la fecha de vencimiento móvil del nivel tras una compra acreditada o liberada.
	 *
	 * @param int $user_id ID del usuario.
	 * @return void
	 */
	public static function renew_validity( int $user_id ): void {
		if ( $user_id <= 0 || ! Options::tiers_enabled() ) {
			return;
		}

		$validity_days = Options::tier_validity_days();
		if ( $validity_days <= 0 ) {
			return;
		}

		$day_seconds = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		update_user_meta( $user_id, self::META_TIER_EXPIRES_AT, time() + ( $validity_days * $day_seconds ) );
	}

	/**
	 * Multiplicador de puntos de compra para un usuario.
	 *
	 * @param int $user_id ID del usuario.
	 * @return float Multiplicador (1.0, 1.2 o 1.5).
	 */
	public static function multiplier_for_user( int $user_id ): float {
		if ( $user_id <= 0 || ! Options::tiers_enabled() ) {
			return 1.0;
		}

		return self::for_user( $user_id )['multiplier'];
	}

	/**
	 * Aplica el multiplicador sobre una cantidad base de puntos.
	 *
	 * Función PURA.
	 *
	 * @param int   $base_points Puntos base calculados.
	 * @param float $multiplier  Multiplicador (≥ 1.0).
	 * @return int Puntos finales redondeados.
	 */
	public static function apply_multiplier( int $base_points, float $multiplier ): int {
		if ( $base_points <= 0 || $multiplier <= 1.0 ) {
			return max( 0, $base_points );
		}

		return (int) round( $base_points * $multiplier );
	}

	/**
	 * Formatea un multiplicador con precisión hasta 2 decimales sin perder paso 0.05 (ej. 1.2 -> '1.2', 1.25 -> '1.25').
	 *
	 * @param float  $mult Multiplicador numérico.
	 * @param string $dec  Separador decimal ('.' o ',').
	 * @return string
	 */
	public static function format_multiplier( float $mult, string $dec = '.' ): string {
		$formatted = rtrim( rtrim( number_format( $mult, 2, $dec, '' ), '0' ), $dec );
		if ( false === strpos( $formatted, $dec ) ) {
			$formatted .= $dec . '0';
		}

		return $formatted;
	}

	/**
	 * Genera el markup HTML del badge de nivel.
	 *
	 * @param string $slug        Slug del nivel ('lector', 'coleccionista', 'residente_akiba').
	 * @param string $name        Nombre legible.
	 * @param string $icon        Icono o emoji.
	 * @param bool   $with_prefix Si incluye prefijo «Nivel:».
	 * @return string Markup accesible.
	 */
	public static function badge_html( string $slug, string $name, string $icon = '', bool $with_prefix = false ): string {
		$defs  = self::get_tier_definitions();
		$class = $defs[ $slug ]['badge_class'] ?? 'akb-tier-badge--lector';
		$ico   = '' !== $icon ? $icon : ( $defs[ $slug ]['icon'] ?? '' );
		$label = $with_prefix ? sprintf( 'Nivel %s', $name ) : $name;

		return sprintf(
			'<span class="akb-tier-badge %s" title="%s"><span class="akb-tier-badge__icon" aria-hidden="true">%s</span> <span class="akb-tier-badge__label">%s</span></span>',
			esc_attr( $class ),
			esc_attr( sprintf( 'Nivel %s de Puntos Akibara', $name ) ),
			esc_html( $ico ),
			esc_html( $label )
		);
	}
}
