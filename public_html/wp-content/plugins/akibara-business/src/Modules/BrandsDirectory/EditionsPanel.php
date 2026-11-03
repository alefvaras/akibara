<?php
/**
 * EditionsPanel — Akibara → Sitio → «Editoriales · Ediciones».
 *
 * Una pantalla con todas las familias de editorial y sus ediciones: las
 * etiquetas de la familia (menú, conmutador) y, por edición, los tres hechos
 * que ve el cliente (idioma, formato, numeración y precio) y las series
 * destacadas. Con vista previa de lo automático (conteo, precio «desde»,
 * series con más tomos) para que el dueño decida si necesita escribir algo.
 *
 * Escribe term-meta y options DIRECTO (nunca `wp_update_term`): así no
 * dispara `edited_term` ni la reindexación del buscador. Al guardar purga por
 * URL sólo lo que cambia (los archivos de la edición y la landing), nunca
 * `purge_all`, que enfría las 1.568 fichas.
 *
 * Mismas decisiones que CortesPanel: enroll de lo que el render pintó, filas
 * independientes, diff antes de escribir, respaldo con «Deshacer».
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BrandsDirectory;

use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.114.18
 */
final class EditionsPanel {

	public const PANEL_TAB = 'brand_editions';

	private const ACTION_SAVE      = 'akb_editions_save';
	private const ACTION_RESTORE   = 'akb_editions_restore';
	private const OPTION_BACKUP    = 'akb_editions_backup';
	private const MAX_BACKUPS      = 3;
	private const TRANSIENT_NOTICE = 'akb_editions_notice_';
	private const CAP              = 'manage_woocommerce';

	/** Series que se ofrecen en el selector: todas las de la edición (Ivrea Argentina ronda las 150), ordenadas por tomos; el filtro por nombre hace el resto. */
	private const PICKER_LIMIT = 500;

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_RESTORE, array( $this, 'handle_restore' ) );
	}

	/**
	 * La pantalla entera (es una sección `is_action` del panel: trae su propio form).
	 *
	 * @return void
	 */
	public function render(): void {
		$this->render_notice();

		echo '<p class="description" style="max-width:760px">Lo que el cliente ve en <strong>/marca/&lt;edición&gt;/</strong> (el conmutador Argentina · España) y en la landing de cada familia (<strong>/panini/</strong>, <strong>/ivrea/</strong>): las tarjetas de edición con sus hechos y series destacadas. El conteo, el precio «desde» y las series se calculan solos; lo que escribas aquí manda.</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="akb-editions-form">';
		wp_nonce_field( self::ACTION_SAVE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE ) . '">';

		foreach ( BrandLandings::all() as $key => $landing ) {
			$this->render_family( (string) $key, $landing );
		}

		echo '<p class="submit"><button type="submit" class="button button-primary">Guardar ediciones</button></p>';
		echo '</form>';

		$this->render_restore();
		$this->print_assets();
	}

	/**
	 * Una familia: sus etiquetas y una tarjeta por edición.
	 *
	 * @param string               $key     Clave de la familia.
	 * @param array<string, mixed> $landing Fila de BrandLandings::all().
	 * @return void
	 */
	private function render_family( string $key, array $landing ): void {
		$url = BrandLandings::url( $key );
		echo '<h2 style="margin:24px 0 8px">' . esc_html( (string) $landing['callout_label'] ) . '</h2>';
		echo '<input type="hidden" name="akb_family_enroll[]" value="' . esc_attr( $key ) . '">';
		echo '<p class="description">' . ( '' !== $url ? 'Landing: <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $url ) . '</a>' : 'La landing de esta familia no está publicada: el conmutador no enlaza a «Comparar» hasta que exista la página <code>/' . esc_html( (string) $landing['page_slug'] ) . '/</code>.' ) . '</p>';

		echo '<table class="form-table" role="presentation" style="max-width:760px"><tbody>';
		foreach ( BrandLandings::label_fields() as $field => $label ) {
			$id = 'akb-family-' . $key . '-' . $field;
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th>';
			echo '<td><input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="akb_family[' . esc_attr( $key ) . '][' . esc_attr( $field ) . ']" value="' . esc_attr( (string) ( $landing[ $field ] ?? '' ) ) . '" maxlength="80"></td></tr>';
		}
		echo '</tbody></table>';

		foreach ( (array) ( $landing['brands'] ?? array() ) as $slug ) {
			$term = get_term_by( 'slug', (string) $slug, Shortcode::TAXONOMY );
			if ( $term instanceof WP_Term ) {
				$this->render_edition( $term );
			} else {
				echo '<p class="notice notice-warning inline" style="padding:8px 12px">La edición <code>' . esc_html( (string) $slug ) . '</code> no existe como marca: créala en Productos → Marcas o quítala de la familia.</p>';
			}
		}
	}

	/**
	 * Una edición: hechos y series destacadas, con la vista previa de lo automático.
	 *
	 * @param WP_Term $term Edición.
	 * @return void
	 */
	private function render_edition( WP_Term $term ): void {
		$id     = (int) $term->term_id;
		$region = BrandRegion::region( BrandRegion::for_term( $term ) );
		$chosen = EditionFacts::chosen_series( $id );
		$auto   = EditionFacts::series_by_volume( (int) $term->term_taxonomy_id, self::PICKER_LIMIT );
		// La tabla de precios de WooCommerce no conoce el descuento editorial, así
		// que con una campaña vigente este número es el de lista. En la tienda no
		// se publica (ver `EditionFacts::price_from()`); acá se muestra dicho tal
		// cual es, porque el dueño necesita el dato aunque hoy no salga.
		$price  = EditionFacts::price_from_lookup( $term );
		$nota   = EditionFacts::price_is_overridden() ? ' (precio de lista: con la campaña vigente no se publica)' : '';

		echo '<fieldset class="akb-edition" style="margin:12px 0 20px;padding:12px 16px;border:1px solid #c3c4c7;background:#fff;max-width:760px">';
		echo '<legend style="font-weight:600">' . esc_html( $term->name ) . ' <span style="color:#646970;font-weight:400">· ' . esc_html( (string) ( $region['label'] ?? '' ) ) . ' · ' . esc_html( number_format_i18n( (int) $term->count ) ) . ' productos' . ( $price > 0 ? ' · desde ' . esc_html( wp_strip_all_tags( html_entity_decode( (string) wc_price( $price ), ENT_QUOTES, 'UTF-8' ) ) . $nota ) : '' ) . '</span></legend>';
		echo '<input type="hidden" name="akb_edition_enroll[]" value="' . esc_attr( (string) $id ) . '">';

		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( EditionFacts::fields() as $key => $label ) {
			$fid = 'akb-edition-' . $id . '-' . $key;
			echo '<tr><th scope="row"><label for="' . esc_attr( $fid ) . '">' . esc_html( $label ) . '</label></th>';
			echo '<td><input type="text" class="large-text" id="' . esc_attr( $fid ) . '" name="akb_edition[' . esc_attr( (string) $id ) . '][' . esc_attr( $key ) . ']" value="' . esc_attr( (string) get_term_meta( $id, $key, true ) ) . '" maxlength="' . esc_attr( (string) EditionFacts::MAX_LEN ) . '"></td></tr>';
		}

		echo '<tr><th scope="row">Series destacadas</th><td>';
		echo '<p class="description" style="margin:0 0 6px">Hasta ' . esc_html( (string) EditionFacts::MAX_SERIES ) . '. Lo que elijas va primero; el resto se completa hasta ' . esc_html( (string) EditionFacts::MAX_SERIES ) . ' con las series de más tomos de esta edición (en gris las que entrarían solas). Todas las series de la edición están en la lista: usa el filtro.</p>';
		echo '<div class="akb-editions-picker" data-akb-editions-picker data-max="' . esc_attr( (string) EditionFacts::MAX_SERIES ) . '">';
		echo '<p class="akb-editions-picker__bar"><label class="screen-reader-text" for="akb-series-filter-' . esc_attr( (string) $id ) . '">Filtrar series</label><input type="search" id="akb-series-filter-' . esc_attr( (string) $id ) . '" class="akb-editions-picker__filter" placeholder="Filtrar por nombre…" autocomplete="off"> <span class="akb-editions-picker__count" data-akb-editions-count aria-live="polite">' . esc_html( sprintf( '%d de %d elegidas', count( $chosen ), EditionFacts::MAX_SERIES ) ) . '</span></p>';
		echo '<div class="akb-editions-picker__list">';
		$listed   = array();
		$huecos   = max( 0, EditionFacts::MAX_SERIES - count( $chosen ) );
		$rellenan = array_slice( array_values( array_diff( array_keys( $auto ), $chosen ) ), 0, $huecos );
		if ( function_exists( '_prime_term_caches' ) ) {
			_prime_term_caches( array_merge( $chosen, array_keys( $auto ) ) );
		}
		foreach ( array_merge( $chosen, array_keys( $auto ) ) as $serie_id ) {
			if ( isset( $listed[ $serie_id ] ) ) {
				continue;
			}
			$serie = get_term( (int) $serie_id, 'pa_serie' );
			if ( ! $serie instanceof WP_Term ) {
				continue;
			}
			$listed[ $serie_id ] = true;
			$n                   = (int) ( $auto[ $serie_id ] ?? 0 );
			$automatic           = in_array( (int) $serie_id, $rellenan, true );
			echo '<label class="akb-editions-picker__item' . ( $automatic ? ' is-auto' : '' ) . '"><input type="checkbox" name="akb_edition_series[' . esc_attr( (string) $id ) . '][]" value="' . esc_attr( (string) $serie_id ) . '"' . checked( in_array( (int) $serie_id, $chosen, true ), true, false ) . '> <span class="akb-editions-picker__name">' . esc_html( $serie->name ) . '</span>' . ( $n > 0 ? ' <span class="akb-editions-picker__n">' . esc_html( (string) $n ) . ' tomos</span>' : '' ) . '</label>';
		}
		echo '<p class="akb-editions-picker__empty" data-akb-editions-empty hidden>Ninguna serie coincide con el filtro.</p>';
		echo '</div></div></td></tr>';
		echo '</tbody></table></fieldset>';
	}

	/**
	 * Guarda etiquetas de familia y hechos de edición: valida, diff, respaldo, purga.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( self::ACTION_SAVE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verificado arriba.
		$families = isset( $_POST['akb_family_enroll'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['akb_family_enroll'] ) ) : array();
		$editions = isset( $_POST['akb_edition_enroll'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['akb_edition_enroll'] ) ) : array();
		$fam_rows = isset( $_POST['akb_family'] ) ? (array) wp_unslash( $_POST['akb_family'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cada campo se sanea abajo (sanitize_labels / sanitize_fact / sanitize_series) y sólo lo enrolado se escribe.
		$ed_rows  = isset( $_POST['akb_edition'] ) ? (array) wp_unslash( $_POST['akb_edition'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cada campo se sanea abajo (sanitize_labels / sanitize_fact / sanitize_series) y sólo lo enrolado se escribe.
		$ed_ser   = isset( $_POST['akb_edition_series'] ) ? (array) wp_unslash( $_POST['akb_edition_series'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cada campo se sanea abajo (sanitize_labels / sanitize_fact / sanitize_series) y sólo lo enrolado se escribe.
		// phpcs:enable

		$snapshot = array( 'families' => array(), 'editions' => array() );
		$cambios  = 0;
		$purgar   = array();
		$all      = BrandLandings::all();

		$purgar_todo = false;
		foreach ( $families as $key ) {
			if ( ! isset( $all[ $key ] ) ) {
				continue; // Clave inventada: no escribe nada.
			}
			$raw   = isset( $fam_rows[ $key ] ) && is_array( $fam_rows[ $key ] ) ? $fam_rows[ $key ] : array();
			$antes = BrandLandings::labels( $key );
			$nuevo = BrandLandings::sanitize_labels( $raw, $key );
			if ( $antes === $nuevo ) {
				continue;
			}
			$snapshot['families'][ $key ] = $antes;
			BrandLandings::save_labels( $key, $nuevo );
			++$cambios;
			// El texto del menú está en TODAS las páginas: purgar tres URLs lo
			// dejaría viejo en las 1.500 restantes. Es el único caso en que se
			// purga todo, y cambiar el menú es raro.
			if ( ( $antes['menu_label'] ?? '' ) !== ( $nuevo['menu_label'] ?? '' ) ) {
				$purgar_todo = true;
			}
			$purgar = array_merge( $purgar, $this->family_urls( $key, $all[ $key ] ) );
		}

		foreach ( $editions as $term_id ) {
			$term = get_term( $term_id, Shortcode::TAXONOMY );
			if ( ! $term instanceof WP_Term ) {
				continue; // Id inventado o de otra taxonomía: no escribe nada.
			}
			$facts  = isset( $ed_rows[ $term_id ] ) && is_array( $ed_rows[ $term_id ] ) ? $ed_rows[ $term_id ] : array();
			$series = isset( $ed_ser[ $term_id ] ) ? (array) $ed_ser[ $term_id ] : array();
			$antes  = EditionFacts::snapshot( $term_id );
			$nuevo  = array();
			foreach ( array_keys( EditionFacts::fields() ) as $key ) {
				$nuevo[ $key ] = EditionFacts::sanitize_fact( $facts[ $key ] ?? '' );
			}
			$nuevo[ EditionFacts::META_SERIES ] = implode( ',', EditionFacts::sanitize_series( $series ) );
			if ( $antes === $nuevo ) {
				continue; // Sin cambios: la base no se toca.
			}
			$snapshot['editions'][ $term_id ] = $antes;
			EditionFacts::save( $term_id, $nuevo, explode( ',', $nuevo[ EditionFacts::META_SERIES ] ) );
			++$cambios;
			$link = get_term_link( $term );
			if ( is_string( $link ) && '' !== $link ) {
				$purgar[] = $link;
			}
			$landing = BrandLandings::for_term( $term );
			$url     = null !== $landing ? BrandLandings::url( (string) $landing['key'] ) : '';
			if ( '' !== $url ) {
				$purgar[] = $url;
			}
		}

		if ( $cambios > 0 ) {
			$this->push_backup( $snapshot );
			$this->purge( $purgar, $purgar_todo );
		}

		$this->notice(
			$cambios > 0 ? 'success' : 'warning',
			$cambios > 0
				? sprintf( '%d cambio(s) guardado(s). %s', $cambios, $purgar_todo ? 'Cambió el texto del menú: se purgó la caché de página entera (aparece en todas las páginas).' : 'Las páginas afectadas se purgaron en el servidor; el borde (Cloudflare) las renueva al vencer su caché, en unos minutos.' )
				: 'Sin cambios que guardar.'
		);
		$this->redirect_back();
	}

	/**
	 * Las URLs que cambian con una familia: su landing y los archivos de sus ediciones.
	 *
	 * @param string               $key     Clave.
	 * @param array<string, mixed> $landing Fila de BrandLandings::all().
	 * @return list<string>
	 */
	private function family_urls( string $key, array $landing ): array {
		$urls = array();
		$url  = BrandLandings::url( $key );
		if ( '' !== $url ) {
			$urls[] = $url;
		}
		foreach ( (array) ( $landing['brands'] ?? array() ) as $slug ) {
			$term = get_term_by( 'slug', (string) $slug, Shortcode::TAXONOMY );
			$link = $term instanceof WP_Term ? get_term_link( $term ) : '';
			if ( is_string( $link ) && '' !== $link ) {
				$urls[] = $link;
			}
		}
		return $urls;
	}

	/**
	 * Purga por URL (o todo, sólo si cambió el menú). Hooks públicos de LiteSpeed.
	 *
	 * @param list<string> $urls URLs.
	 * @param bool         $todo Purgar la caché de página entera.
	 * @return void
	 */
	private function purge( array $urls, bool $todo ): void {
		if ( $todo ) {
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
			return;
		}
		foreach ( array_unique( $urls ) as $url ) {
			do_action( 'litespeed_purge_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
		}
	}

	/**
	 * Deshace el último guardado.
	 *
	 * @return void
	 */
	public function handle_restore(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( self::ACTION_RESTORE );

		$backups = get_option( self::OPTION_BACKUP, array() );
		$ultimo  = is_array( $backups ) && isset( $backups[0] ) && is_array( $backups[0] ) ? $backups[0] : null;
		if ( null === $ultimo ) {
			$this->notice( 'warning', 'No hay un guardado anterior que deshacer.' );
			$this->redirect_back();
		}

		$repuestas   = 0;
		$purgar      = array();
		$purgar_todo = false;
		$all         = BrandLandings::all();
		foreach ( (array) ( $ultimo['families'] ?? array() ) as $key => $labels ) {
			$key    = (string) $key;
			$labels = (array) $labels;
			if ( ( $labels['menu_label'] ?? '' ) !== ( BrandLandings::labels( $key )['menu_label'] ?? '' ) ) {
				$purgar_todo = true;
			}
			BrandLandings::save_labels( $key, $labels );
			++$repuestas;
			if ( isset( $all[ $key ] ) ) {
				$purgar = array_merge( $purgar, $this->family_urls( $key, $all[ $key ] ) );
			}
		}
		foreach ( (array) ( $ultimo['editions'] ?? array() ) as $term_id => $row ) {
			$row = (array) $row;
			EditionFacts::save( (int) $term_id, $row, explode( ',', (string) ( $row[ EditionFacts::META_SERIES ] ?? '' ) ) );
			++$repuestas;
			$term = get_term( (int) $term_id, Shortcode::TAXONOMY );
			$link = $term instanceof WP_Term ? get_term_link( $term ) : '';
			if ( is_string( $link ) && '' !== $link ) {
				$purgar[] = $link;
			}
			$landing = $term instanceof WP_Term ? BrandLandings::for_term( $term ) : null;
			if ( null !== $landing && isset( $all[ $landing['key'] ] ) ) {
				$purgar = array_merge( $purgar, $this->family_urls( (string) $landing['key'], $all[ $landing['key'] ] ) );
			}
		}
		array_shift( $backups );
		update_option( self::OPTION_BACKUP, $backups, false );
		// Deshacer cambia lo servido igual que guardar: misma purga.
		$this->purge( $purgar, $purgar_todo );

		$this->notice( 'success', sprintf( 'Se repuso la configuración anterior de %d fila(s).', $repuestas ) );
		$this->redirect_back();
	}

	/**
	 * @param array<string, mixed> $snapshot Lo previo a este guardado.
	 * @return void
	 */
	private function push_backup( array $snapshot ): void {
		$backups = get_option( self::OPTION_BACKUP, array() );
		$backups = is_array( $backups ) ? $backups : array();
		array_unshift( $backups, $snapshot + array( 'at' => time() ) );
		update_option( self::OPTION_BACKUP, array_slice( $backups, 0, self::MAX_BACKUPS ), false );
	}

	private function render_restore(): void {
		$backups = get_option( self::OPTION_BACKUP, array() );
		if ( ! is_array( $backups ) || array() === $backups ) {
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px">';
		wp_nonce_field( self::ACTION_RESTORE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_RESTORE ) . '">';
		echo '<button type="submit" class="button">Deshacer el último guardado</button>';
		echo '</form>';
	}

	private function render_notice(): void {
		$aviso = get_transient( self::TRANSIENT_NOTICE . get_current_user_id() );
		if ( ! is_array( $aviso ) || '' === (string) ( $aviso['texto'] ?? '' ) ) {
			return;
		}
		delete_transient( self::TRANSIENT_NOTICE . get_current_user_id() );
		printf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			esc_attr( 'success' === ( $aviso['tipo'] ?? '' ) ? 'success' : 'warning' ),
			esc_html( (string) $aviso['texto'] )
		);
	}

	private function notice( string $tipo, string $texto ): void {
		set_transient( self::TRANSIENT_NOTICE . get_current_user_id(), array( 'tipo' => $tipo, 'texto' => $texto ), MINUTE_IN_SECONDS );
	}

	private function redirect_back(): void {
		wp_safe_redirect( add_query_arg( array( 'page' => 'akibara-panel', 'tab' => self::PANEL_TAB ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Estilos y filtro del selector, en línea: sólo existen en esta pantalla.
	 */
	private function print_assets(): void {
		?>
		<style>
			.akb-editions-picker { max-width: 520px; }
			.akb-editions-picker__bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0 0 6px; }
			.akb-editions-picker__filter { flex: 1 1 200px; min-width: 0; }
			.akb-editions-picker__count { font-size: 12px; color: #50575e; }
			.akb-editions-picker.is-full .akb-editions-picker__count { color: #b32d2e; font-weight: 600; }
			.akb-editions-picker__list { max-height: 260px; overflow-y: auto; border: 1px solid #8c8f94; border-radius: 4px; background: #fff; padding: 4px; }
			.akb-editions-picker__item { display: flex; align-items: center; gap: 8px; padding: 6px; min-height: 32px; border-radius: 3px; cursor: pointer; }
			.akb-editions-picker__item[hidden] { display: none; }
			.akb-editions-picker__item:hover { background: #f0f0f1; }
			.akb-editions-picker__item.is-auto .akb-editions-picker__name { color: #50575e; }
			.akb-editions-picker__n { margin-left: auto; font-size: 12px; color: #50575e; }
			.akb-editions-picker__empty { margin: 10px 6px; color: #50575e; font-style: italic; }
		</style>
		<script>
		( function () {
			var norm = function ( v ) { return v.toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ); };
			document.querySelectorAll( '[data-akb-editions-picker]' ).forEach( function ( root ) {
				var max = parseInt( root.getAttribute( 'data-max' ), 10 ) || 0;
				var boxes = Array.prototype.slice.call( root.querySelectorAll( 'input[type="checkbox"]' ) );
				var counter = root.querySelector( '[data-akb-editions-count]' );
				var empty = root.querySelector( '[data-akb-editions-empty]' );
				var filter = root.querySelector( '.akb-editions-picker__filter' );
				var sync = function () {
					var picked = boxes.filter( function ( b ) { return b.checked; } ).length;
					boxes.forEach( function ( b ) { b.disabled = ! b.checked && max > 0 && picked >= max; } );
					root.classList.toggle( 'is-full', max > 0 && picked >= max );
					if ( counter ) { counter.textContent = picked + ' de ' + max + ' elegidas'; }
				};
				boxes.forEach( function ( b ) { b.addEventListener( 'change', sync ); } );
				if ( filter ) {
					filter.addEventListener( 'input', function () {
						var q = norm( filter.value.trim() ), shown = 0;
						boxes.forEach( function ( b ) {
							var item = b.closest( '.akb-editions-picker__item' );
							var hit = '' === q || norm( item.textContent ).indexOf( q ) !== -1;
							item.hidden = ! hit;
							if ( hit ) { shown++; }
						} );
						if ( empty ) { empty.hidden = shown > 0; }
					} );
				}
				sync();
			} );
		} )();
		</script>
		<?php
	}
}
