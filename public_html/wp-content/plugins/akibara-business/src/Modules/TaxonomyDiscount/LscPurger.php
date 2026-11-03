<?php
/**
 * LscPurger — purga LiteSpeed al guardar config del descuento por taxonomía.
 *
 * Engancha `update_option_{option}` y `add_option_{option}` sobre el conjunto
 * CERRADO que declara `Settings::OPTIONS`, con flag de instancia para
 * deduplicar. Antes escuchaba
 * el `updated_option` GLOBAL y descartaba por prefijo: eso corría en CADA
 * escritura de option del sitio —incluidas las de WooCommerce, el cron y los
 * transients no autoload— para atender seis nombres conocidos de antemano.
 * También escucha el action `akibara_taxonomy_discount_pct_saved` que dispara
 * AdminFieldType al persistir term-meta.
 *
 * El reindex async de FacetWP que corría acá se retiró en 1.62.0 (Fase 4.4 del
 * cutover): FacetWP ya no existe en prod. Los descuentos por taxonomía se
 * aplican en runtime (PriceFilters), sin tocar postmeta indexado, así que no
 * requieren reindexar el buscador propio al guardar.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\TaxonomyDiscount;

use Akibara\Business\Utilities\DiscountCeiling;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.16.1
 */
final class LscPurger {

	private bool $scheduled = false;

	public function register(): void {
		// El nombre del hook estuvo mal desde el principio y por eso esta purga no
		// corría NUNCA fuera del panel. Core dispara `update_option_{$option}`
		// (wp-includes/option.php:1019) —después de escribir con éxito, pese al
		// presente del nombre— y `add_option_{$option}` (1176) cuando la fila no
		// existía. `updated_option_{$option}`, que es lo que había aquí, no lo
		// dispara nadie: la forma en pasado va sin sufijo.
		foreach ( Settings::OPTIONS as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'schedule_now' ) );
			add_action( 'add_option_' . $option, array( $this, 'schedule_now' ) );
			// Borrar una opción también cambia el precio y requiere invalidación.
			// Usar delete_option_{$option}; deleted_option es el hook genérico sin sufijo.

			add_action( 'delete_option_' . $option, array( $this, 'schedule_now' ) );
		}
		// El tope de descuento no es una option de ESTE módulo (la comparte con
		// «Arma tu pack», por eso vive en `Utilities\DiscountCeiling` y no en
		// `Settings::OPTIONS`), pero acota el % de cada término: bajarlo cambia
		// precios servidos igual que apagar la campaña. Guardar por el panel ya
		// purga; esto cubre el `wp option update` que no pasa por el formulario.
		add_action( 'update_option_' . DiscountCeiling::OPTION, array( $this, 'schedule_now' ) );
		add_action( 'add_option_' . DiscountCeiling::OPTION, array( $this, 'schedule_now' ) );
		add_action( 'delete_option_' . DiscountCeiling::OPTION, array( $this, 'schedule_now' ) );
		add_action( 'akibara_taxonomy_discount_pct_saved', array( $this, 'schedule_now' ) );
	}

	public function schedule_now(): void {
		if ( $this->scheduled ) {
			return;
		}
		if ( self::el_panel_ya_purga() ) {
			return;
		}
		$this->scheduled = true;
		add_action( 'shutdown', array( $this, 'do_purge' ), 99 );
	}

	/**
	 * Detecta si el guardado del panel ya programó la purga de esta petición.
	 *
	 * AdminPanel::handle_save() agenda litespeed_purge_all en shutdown. El centinela
	 * compartido evita duplicar esa purga y su propagación a Cloudflare. No es un
	 * guarda de permisos: el panel valida nonce y capability por separado.
	 * Las escrituras desde CLI, cron o migración conservan la purga de este módulo
	 * cuando no hay otro responsable en la petición.
	 */
	private static function el_panel_ya_purga(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- no autoriza nada: sólo evita purgar dos veces lo mismo.
		return isset( $_POST['akibara_panel_save'] );
	}

	public function do_purge(): void {
		// Sin guarda de `function_exists( 'do_action')`: si WordPress no está
		// cargado, `add_action` de arriba tampoco existió y este método no se
		// alcanza nunca. La guarda no podía fallar.
		//
		// Va en `shutdown` y no inline porque NO es barato: ver la nota de
		// `el_panel_ya_purga()`. Antes decía «Barato, queda inline», escrito
		// antes de que se midiera la propagación al borde en 1.108.9.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de LiteSpeed: es SU purga.
		do_action( 'litespeed_purge_all' );
	}
}
