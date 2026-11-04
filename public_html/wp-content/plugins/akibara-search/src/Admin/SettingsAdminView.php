<?php
/**
 * SettingsAdminView — renderizado, componentes de formulario y diffs del tab Ajustes.
 *
 * @package Akibara\Search\Admin
 */

declare( strict_types = 1 );

namespace Akibara\Search\Admin;

defined( 'ABSPATH' ) || exit;

use Akibara\Search\BrandRelax;
use Akibara\Search\EmptyStateCopy;
use Akibara\Search\EmptyStateSuggestions;
use Akibara\Search\Fuzzy;
use Akibara\Search\Novedad;
use Akibara\Search\PopularQueries;
use Akibara\Search\Search;
use Akibara\Search\SearchAdminSection;
use Akibara\Search\Stopwords;

/**
 * Vista y helpers para los ajustes y palancas del motor de búsqueda.
 */
final class SettingsAdminView {

	public static function render_settings( SearchAdminSection $admin ): void {
		$admin->render_template(
			'tab-ajustes.php',
			array(
				'current' => self::settings_current_values(),
			)
		);
	}

	/**
	 * Fila de texto libre para la tabla de Ajustes.
	 *
	 * Mismo contrato accesible que `settings_toggle()`: la etiqueta REAL en el
	 * `label for` del `th` y la descripción por `aria-describedby`.
	 *
	 * @param string $name  Slug (el name del input es texto_<slug>).
	 * @param string $label Etiqueta.
	 * @param string $desc  Descripción.
	 * @param string $value Valor actual.
	 * @return void
	 */
	public static function settings_text( string $name, string $label, string $desc, string $value ): void {
		$id = 'akb-text-' . $name;
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="text" class="large-text" id="%1$s" name="texto_%3$s" value="%4$s" maxlength="%6$d" aria-describedby="%1$s-desc"><p class="description" id="%1$s-desc">%5$s</p></td></tr>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( $value ),
			// El tope se ve mientras se escribe: el guardado recorta a esta misma
			// cifra, y un aviso cortado a media frase es peor que uno corto.
			esc_html( $desc . sprintf( ' Máximo %d caracteres.', EmptyStateCopy::MAX_LENGTH ) ),
			(int) EmptyStateCopy::MAX_LENGTH
		);
	}

	/**
	 * Fila de lista de palabras para la tabla de Ajustes: una por línea.
	 *
	 * Mismo contrato accesible que `settings_text()`. Se guarda con
	 * `Stopwords::sanitize_words()`, el validador de Palabras ignoradas, y el
	 * motor la lee con el mismo (`EmptyStateSuggestions::tail_words()`).
	 *
	 * Cuando la lista vigente difiere de los valores predeterminados de fábrica,
	 * la interfaz expone la opción de restaurarla e indica las diferencias.
	 * La comparación se basa en la clave normalizada del motor (`EmptyStateSuggestions::tail_words_key()`).
	 *
	 * @param string             $name    Slug (el name del textarea es palabras_<slug>).
	 * @param string             $label   Etiqueta.
	 * @param string             $desc    Descripción.
	 * @param array<int, string> $words   Lista vigente.
	 * @param array<int, string> $factory Lista de fábrica.
	 * @return void
	 */
	public static function settings_words( string $name, string $label, string $desc, array $words, array $factory ): void {
		$id = 'akb-words-' . $name;
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><textarea class="large-text code" rows="6" spellcheck="false" id="%1$s" name="palabras_%3$s" aria-describedby="%1$s-desc">%4$s</textarea><p class="description" id="%1$s-desc">%5$s</p>%6$s</td></tr>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $name ),
			esc_textarea( implode( "\n", $words ) ),
			esc_html( $desc ),
			self::settings_words_reset( $id, $name, $words, $factory ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- settings_words_reset() escapa cada tramo.
		);
	}

	/**
	 * Casilla «Volver a la lista de fábrica» y el resumen de la diferencia, o
	 * nada si la lista vigente ya es la de fábrica.
	 *
	 * @param string             $id      Id del textarea.
	 * @param string             $name    Slug (el name de la casilla es fabrica_<slug>).
	 * @param array<int, string> $words   Lista vigente.
	 * @param array<int, string> $factory Lista de fábrica.
	 * @return string HTML escapado.
	 */
	public static function settings_words_reset( string $id, string $name, array $words, array $factory ): string {
		if ( EmptyStateSuggestions::tail_words_key( $words ) === EmptyStateSuggestions::tail_words_key( $factory ) ) {
			return '';
		}

		$vigentes = '' === EmptyStateSuggestions::tail_words_key( $words ) ? array() : explode( ', ', EmptyStateSuggestions::tail_words_key( $words ) );
		$fabrica  = explode( ', ', EmptyStateSuggestions::tail_words_key( $factory ) );
		$partes   = self::word_groups(
			array(
				array( array_values( array_diff( $fabrica, $vigentes ) ), 'faltan de la de fábrica: ', array( 'faltan ', ' palabras de la de fábrica' ) ),
				array( array_values( array_diff( $vigentes, $fabrica ) ), 'agregaste: ', array( 'agregaste ', ' palabras' ) ),
			)
		);

		$reset_id = $id . '-fabrica';

		return sprintf(
			'<p><label for="%1$s"><input type="checkbox" id="%1$s" name="fabrica_%2$s" value="1" aria-describedby="%1$s-desc"> Volver a la lista de fábrica</label></p><p class="description" id="%1$s-desc">%3$s</p>',
			esc_attr( $reset_id ),
			esc_attr( $name ),
			esc_html(
				'Tu lista no es la de fábrica (' . implode( '; ', $partes ) . '). Marca la casilla y guarda para volver a ella: lo escrito en el cuadro se descarta y, desde entonces, cada mejora de la lista de fábrica te llega sola.'
			)
		);
	}

	/**
	 * Cada grupo de palabras de una diferencia, en una frase: enumerado hasta
	 * `WORDS_LISTED_MAX` palabras y contado desde ahí, porque cien palabras
	 * seguidas no se leen (una lista vaciada entera, por ejemplo). Un grupo
	 * vacío no se dice.
	 *
	 * La comparten la fila de «Palabras de formato» (qué la separa de la de
	 * fábrica) y el registro de cambios (qué cambió al guardar).
	 *
	 * @param array<int, array{0: array<int, string>, 1: string, 2: array{0: string, 1: string}}> $grupos
	 *        Cada grupo: [palabras, prefijo con la lista, [prefijo, sufijo] con la cuenta].
	 * @return array<int, string>
	 */
	public static function word_groups( array $grupos ): array {
		$partes = array();
		foreach ( $grupos as list( $lista, $con_lista, $con_cuenta ) ) {
			if ( array() === $lista ) {
				continue;
			}
			$partes[] = count( $lista ) > SearchAdminSection::WORDS_LISTED_MAX
				? $con_cuenta[0] . count( $lista ) . $con_cuenta[1]
				: $con_lista . implode( ', ', $lista );
		}

		return $partes;
	}

	/**
	 * Valor GUARDADO de cada palanca de Ajustes, con el default de su consumidor
	 * real. Una sola fuente para el checkbox y para el diff del registro de
	 * cambios: si difieren, el panel inventa cambios que nadie hizo.
	 *
	 * Los valores predeterminados coinciden con los de los módulos consumidores
	 * (como sku_fastpath = 'yes' en QueryEngine), asegurando que el diff previo
	 * al guardado no registre modificaciones espurias sobre opciones no persistidas.
	 *
	 * @return array<string, string>
	 */
	public static function settings_current_values(): array {
		return array(
			'fuzzy'        => (string) get_option( Fuzzy::OPTION, 'no' ),
			'sku_fastpath' => (string) get_option( 'akb_search_sku_fastpath', 'yes' ),
			'novedad'      => (string) get_option( Novedad::OPTION, 'no' ),
			'indexing'     => (string) get_option( Search::OPTION_INDEXING, 'no' ),
			'suggest'      => (string) get_option( Search::OPTION_SUGGEST, 'no' ),
			'facets'       => (string) get_option( Search::OPTION_FACETS, 'no' ),
			// Default 'yes': corresponde a `PopularQueries::enabled()`.
			'popular'      => (string) get_option( PopularQueries::OPTION_ENABLED, 'yes' ),
			// Los textos entran al mismo array para calcular el diferencial unificado de auditoría.
			'empty_hint_catalog' => EmptyStateCopy::catalog(),
			'empty_hint_filters' => EmptyStateCopy::filters(),
			'empty_hint_suggest' => EmptyStateCopy::suggest(),
			'relaxed_notice'     => BrandRelax::template(),
			// Default 'yes', el mismo de `EmptyStateSuggestions::enabled()`.
			'empty_suggestions'   => (string) get_option( EmptyStateSuggestions::OPTION_ENABLED, 'yes' ),
			'empty_series_label'  => EmptyStateCopy::series_label(),
			'empty_popular_label' => EmptyStateCopy::popular_label(),
			// Clave normalizada de palabras de formato (sin orden ni tildes) para comparación determinista:
			'empty_tail_words'    => EmptyStateSuggestions::tail_words_key( EmptyStateSuggestions::tail_words() ),
			'network_notice'      => EmptyStateCopy::network(),
		);
	}

	/**
	 * Fila de toggle (checkbox) para la tabla de Ajustes.
	 *
	 * @param string $name  Slug (el name del input es toggle_<slug>).
	 * @param string $label Etiqueta.
	 * @param string $desc  Descripción.
	 * @param bool   $on    Estado actual.
	 * @return void
	 */
	public static function settings_toggle( string $name, string $label, string $desc, bool $on ): void {
		// Accesibilidad: el nombre accesible proviene del th asociado mediante label for,
		// y la descripción se vincula mediante aria-describedby sin duplicar etiquetas.
		$id = 'akb-toggle-' . $name;
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="checkbox" id="%1$s" name="toggle_%3$s" value="yes" aria-describedby="%1$s-desc"%4$s><p class="description" id="%1$s-desc">%5$s</p></td></tr>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $desc )
		);
	}

	/**
	 * Guarda Ajustes: los interruptores del motor, los servicios de la tienda y
	 * los mensajes de «sin resultados». Checkbox ausente = 'no' (semántica HTML
	 * estándar), salvo el de las salidas, que exige su marcador de presencia; un
	 * texto o una lista ausentes conservan el valor vigente.
	 *
	 * @return void
	 */
	public static function posted_settings_text( string $name, string $fallback, array $before, array &$avisos ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_save_settings() ya pasó por guard().
		if ( ! isset( $_POST[ 'texto_' . $name ] ) ) {
			return $before[ $name ];
		}

		$recortado     = false;
		$sin_etiquetas = false;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce en guard(); EmptyStateCopy::sanitize() limpia el texto (quita etiquetas y lo aplana a una línea).
		$value = EmptyStateCopy::sanitize( wp_unslash( $_POST[ 'texto_' . $name ] ), $fallback, $recortado, $sin_etiquetas );

		$label = '«' . ( SearchAdminSection::SETTINGS_TEXT_LABELS[ $name ] ?? $name ) . '»';
		if ( $recortado ) {
			$avisos[] = $label . ' quedó recortado a ' . EmptyStateCopy::MAX_LENGTH . ' caracteres.';
		}
		if ( $sin_etiquetas ) {
			$avisos[] = $label . ' se guardó sin las etiquetas HTML (o lo que empieza por «<»): el aviso es texto plano.';
		}

		return $value;
	}

	/**
	 * Diff legible de los toggles de Ajustes para el AuditLog: solo lo que
	 * CAMBIÓ, en formato `clave: antes→después`. Vacío = guardado sin cambios
	 * (no se registra — el log es de cambios, no de clicks) [P2-E].
	 *
	 * @param array<string, string> $before Valores antes del save.
	 * @param array<string, string> $after  Valores guardados.
	 * @return array<int, string>
	 */
	public static function settings_changes( array $before, array $after ): array {
		// El registro se lee en pantalla: `sku_fastpath: no→yes` obligaba a
		// traducir slugs mentalmente. Clave desconocida cae al slug crudo.
		$labels = array(
			'fuzzy'        => 'Corrección de errores de tipeo',
			'sku_fastpath' => 'Búsqueda por código (SKU / ISBN)',
			'novedad'      => 'Filtro «Novedades»',
			'indexing'     => 'Indexación automática',
			'suggest'      => 'Autocompletado',
			'popular'      => 'Bloque «Populares» del desplegable',
			'empty_hint_catalog' => 'Pista sin resultados (catálogo)',
			'empty_hint_filters' => 'Pista sin resultados (filtros sobre una búsqueda)',
			'empty_hint_suggest' => 'Pista sin resultados (desplegable)',
			'relaxed_notice'     => 'Aviso «no tenemos esa editorial»',
			'empty_suggestions'   => 'Salidas en «sin resultados»',
			'empty_series_label'  => 'Rótulo de la serie sugerida',
			'empty_popular_label' => 'Rótulo de lo más buscado',
			'empty_tail_words'    => 'Palabras de formato de «Quizá buscabas»',
			'network_notice'      => 'Aviso de conexión del catálogo',
			'facets'       => 'Filtros del catálogo',
		);

		$changes = array();
		foreach ( $after as $key => $value ) {
			if ( ( $before[ $key ] ?? '' ) !== $value ) {
				// El formato de auditoría se determina según el tipo de clave:
				// palabras de formato (resumen sintético), textos personalizados o interruptores booleanos.
				if ( 'empty_tail_words' === $key ) {
					$what = self::tail_words_change( (string) ( $before[ $key ] ?? '' ), $value );
				} elseif ( isset( SearchAdminSection::SETTINGS_TEXT_LABELS[ $key ] ) ) {
					$what = sprintf( 'se reescribió («%s»)', $value );
				} else {
					$what = 'yes' === $value ? 'se activó' : 'se apagó';
				}
				$changes[] = sprintf( '%s: %s', $labels[ (string) $key ] ?? (string) $key, $what );
			}
		}
		return $changes;
	}

	/**
	 * Genera un resumen compacto de cambios en palabras de formato para el registro de auditoría.
	 * Describe las diferencias específicas de adición o remoción sin volcar la huella completa.
	 *
	 * @param string $before Huella anterior (`EmptyStateSuggestions::tail_words_key()`).
	 * @param string $after  Huella guardada; distinta de la anterior.
	 * @return string
	 */
	public static function tail_words_change( string $before, string $after ): string {
		if ( EmptyStateSuggestions::tail_words_key( EmptyStateSuggestions::default_tail_words() ) === $after ) {
			return 'volvió a la de fábrica';
		}
		if ( '' === $after ) {
			return 'se vació';
		}

		$antes   = '' === $before ? array() : explode( ', ', $before );
		$despues = explode( ', ', $after );

		return implode(
			'; ',
			self::word_groups(
				array(
					array( array_values( array_diff( $despues, $antes ) ), 'agregaste ', array( 'agregaste ', ' palabras' ) ),
					array( array_values( array_diff( $antes, $despues ) ), 'quitaste ', array( 'quitaste ', ' palabras' ) ),
				)
			)
		);
	}

	/**
	 * Sub-tab "Precios": editor de los rangos de la faceta Precio, derivados del
	 * catálogo (nada hardcodeado) y editables. Los rangos se resuelven query-time
	 * con BETWEEN sobre value_num, así que todo cambio aplica de inmediato SIN
	 * reconstruir el índice. Rangos SEMI-ABIERTOS `[min, max)` contiguos.
	 *
	 * Cada fila muestra el CORTE EFECTIVO y el count real (preview honesto). La
	 * etiqueta SIEMPRE se deriva del rango y se exhibe como texto estático — no
	 * hay input de texto libre, garantizando total consistencia con el corte numérico.
	 * Filas vacías se descartan al guardar (agregar = llenar una fila en blanco;
	 * quitar = vaciarla). Sin jQuery.
	 *
	 * @return void
	 */

}
