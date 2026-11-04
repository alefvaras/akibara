<?php
/**
 * Capa de presentación y renderizado HTML de la ficha de datos de serie.
 *
 * Imprime la ficha completa, el resumen compacto sobre la rejilla y el enlace
 * al último tomo en el contador de resultados, garantizando accesibilidad,
 * marcado semántico y protección contra datos no verificados.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFacts;

use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Utilities\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Renderizador de vistas de SeriesFacts.
 *
 * @since 1.120.4
 */
final class SeriesFactsView {

	/** Ancla de la ficha completa. */
	public const SHEET_ID = 'ficha-de-la-serie';

	/** Taxonomía de marca/editorial. */
	private const BRAND_TAXONOMY = 'product_brand';

	/** Taxonomía de autor. */
	private const AUTHOR_TAXONOMY = 'pa_autor';

	/** Cuántos números de tomo agotado se nombran antes de resumir. */
	private const MAX_LISTED_VOLUMES = 6;

	/** Cuántos autores se enlazan antes de resumir. */
	private const MAX_LISTED_AUTHORS = 3;

	/**
	 * Imprime el resumen de una sola línea sobre la rejilla de productos.
	 *
	 * @param array<string, mixed> $facts                 Hechos normalizados.
	 * @param bool                 $price_is_publishable ¿El precio es publicable?
	 * @return void
	 */
	public static function render_summary( array $facts, bool $price_is_publishable = true ): void {
		$label = '';
		$value = '';
		$pairs = self::publication_phrases( $facts );
		if ( array() !== $pairs ) {
			$label = $pairs[0]['label'];
			$value = esc_html( $pairs[0]['phrase']['titular'] );
			if ( '' !== $pairs[0]['phrase']['detalle'] ) {
				$value .= ', ' . sprintf(
					'<span class="akb-series-summary__note">%s</span>',
					esc_html( $pairs[0]['phrase']['detalle'] )
				);
			}
		} else {
			$price = self::price_pair( $facts, $price_is_publishable );
			if ( null !== $price ) {
				$label = $price['label'];
				$value = $price['value'];
			}
		}

		$link = Text::one_line_option( SeriesFacts::OPTION_SUMMARY_LINK, SeriesFacts::DEFAULT_SUMMARY_LINK );
		?>
		<div class="akb-series-summary">
			<?php if ( '' !== $value ) : ?>
				<dl class="akb-series-summary__facts">
					<div class="akb-series-summary__item">
						<dt class="akb-series-summary__label"><?php echo esc_html( $label ); ?></dt>
						<dd class="akb-series-summary__value"><?php echo $value; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en el punto de construcción. ?></dd>
					</div>
				</dl>
			<?php endif; ?>
			<a class="akb-series-summary__more" href="#<?php echo esc_attr( self::SHEET_ID ); ?>"><?php echo esc_html( $link ); ?>&nbsp;<span aria-hidden="true">↓</span></a>
		</div>
		<?php
	}

	/**
	 * Añade a la línea del total un enlace a la ficha del último tomo de la serie.
	 *
	 * @param string                    $html  HTML existente de otros módulos.
	 * @param array<string, mixed>|null $facts Hechos normalizados.
	 * @return string HTML escapado con el enlace appended.
	 */
	public static function result_count_extra( string $html, ?array $facts ): string {
		if ( null === $facts ) {
			return $html;
		}

		return $html . self::latest_volume_link( $facts );
	}

	/**
	 * Genera el enlace <a> a la ficha del tomo más nuevo.
	 *
	 * @param array<string, mixed> $facts Hechos normalizados.
	 * @return string
	 */
	public static function latest_volume_link( array $facts ): string {
		$latest = $facts['ultimo_tomo'] ?? null;
		if ( (int) ( $facts['tomos'] ?? 0 ) < 2 || ! is_array( $latest ) ) {
			return '';
		}

		$url = get_permalink( (int) ( $latest['id'] ?? 0 ) );
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$text = str_replace(
			SeriesFacts::VOLUME_MARKER,
			(string) (int) $latest['numero'],
			esc_html( Text::one_line_option( SeriesFacts::OPTION_LATEST_VOLUME, SeriesFacts::DEFAULT_LATEST_VOLUME ) )
		);

		return '<a class="akb-series-latest" href="' . esc_url( $url ) . '">' . $text . '&nbsp;<span aria-hidden="true">→</span></a>';
	}

	/**
	 * Imprime la ficha completa de una serie bajo la rejilla.
	 *
	 * @param array<string, mixed> $facts                 Hechos normalizados.
	 * @param bool                 $price_is_publishable ¿El precio es publicable?
	 * @return void
	 */
	public static function render( array $facts, bool $price_is_publishable = true ): void {
		?>
		<section class="akb-series-facts" aria-label="Datos de esta edición y de la obra original" id="<?php echo esc_attr( self::SHEET_ID ); ?>">
			<dl class="akb-series-facts__list">
				<?php
				self::render_publication_rows( $facts );
				self::render_stock_rows( $facts );
				self::render_price_row( $facts, $price_is_publishable );
				self::render_author_row( $facts );
				self::render_brand_row( $facts );
				self::render_updated_row( $facts );
				?>
			</dl>
		</section>
		<?php
	}

	/**
	 * Renderiza las filas de publicación (edición y obra).
	 *
	 * @param array<string, mixed> $facts Hechos normalizados.
	 * @return void
	 */
	public static function render_publication_rows( array $facts ): void {
		foreach ( self::publication_phrases( $facts ) as $pair ) {
			self::render_publication_phrase( $pair['label'], $pair['phrase'] );
		}
	}

	/**
	 * Frases de publicación con su rótulo en el orden en que se publican.
	 *
	 * @param array<string, mixed> $facts Hechos normalizados.
	 * @return list<array{label:string,phrase:array{titular:string,detalle:string}}>
	 */
	public static function publication_phrases( array $facts ): array {
		$term    = get_queried_object();
		$term_id = ( is_object( $term ) && isset( $term->term_id ) ) ? (int) $term->term_id : 0;
		$scoped  = PublicationStatus::scoped_for_term( $term_id );
		$work    = $scoped['obra'];
		$edition = $scoped['edicion'];
		$work_phrase = PublicationStatus::work_phrase(
			(string) $work['estado'],
			(int) $work['tomos'],
			(int) ( $facts['tomos'] ?? 0 )
		);

		$pairs          = array();
		$edition_phrase = array();
		$publishers     = is_array( $facts['editoriales'] ?? null ) ? $facts['editoriales'] : array();
		if ( 1 === count( $publishers ) && is_array( $publishers[0] ) ) {
			$publisher_name = trim( (string) ( $publishers[0]['name'] ?? '' ) );
			if ( '' !== $publisher_name ) {
				$edition_phrase = PublicationStatus::edition_phrase(
					(string) $edition['estado'],
					(int) $edition['tomos']
				);
				if ( array() !== $edition_phrase ) {
					$pairs[] = array(
						'label'  => 'Edición ' . $publisher_name,
						'phrase' => $edition_phrase,
					);
				}
			}
		}

		$work_adds_information = array() === $edition_phrase
			|| (string) $work['estado'] !== (string) $edition['estado']
			|| ( '' !== (string) ( $work_phrase['detalle'] ?? '' )
				&& (string) ( $work_phrase['detalle'] ?? '' ) !== (string) ( $edition_phrase['detalle'] ?? '' ) );

		if ( $work_adds_information && array() !== $work_phrase ) {
			$pairs[] = array(
				'label'  => 'Obra original',
				'phrase' => $work_phrase,
			);
		}

		return $pairs;
	}

	/**
	 * Imprime una frase de publicación ya compuesta.
	 *
	 * @param string                                       $label  Alcance visible del dato.
	 * @param array{titular:string,detalle:string}|array{} $phrase Frase sin escapar, o array vacío.
	 * @return void
	 */
	public static function render_publication_phrase( string $label, array $phrase ): void {
		if ( array() === $phrase ) {
			return;
		}

		$value = esc_html( $phrase['titular'] );
		if ( '' !== $phrase['detalle'] ) {
			$value .= ' ' . sprintf(
				'<span class="akb-series-facts__note">%s</span>',
				esc_html( $phrase['detalle'] )
			);
		}

		self::render_item( $label, $value );
	}

	/**
	 * Conteos por estado de stock.
	 *
	 * @param array<string, mixed> $facts Hechos normalizados.
	 * @return void
	 */
	public static function render_stock_rows( array $facts ): void {
		foreach ( self::stock_rows( $facts ) as $row ) {
			$status  = $row['status'];
			$value   = $row['texto'];
			$volumes = StockStatus::OUT_OF_STOCK === $status
				? ( $facts['agotados_tomos'] ?? array() )
				: array();

			self::render_item(
				$row['label'],
				array() === $volumes
					? esc_html( $value )
					: esc_html( $value . ': ' ) . sprintf(
						'<span class="akb-series-facts__vols">%s</span>',
						self::summarized_list( array_map( 'esc_html', $volumes ), self::MAX_LISTED_VOLUMES )
					)
			);
		}
	}

	/**
	 * Filas de disponibilidad para la ficha y para la tarjeta de guía.
	 *
	 * @param array<string, mixed> $facts Hechos normalizados.
	 * @return array<int,array{status:string,label:string,count:int,texto:string}>
	 */
	public static function stock_rows( array $facts ): array {
		$counts = array(
			StockStatus::IN_STOCK     => (int) ( $facts['instock'] ?? 0 ),
			StockStatus::ON_BACKORDER => (int) ( $facts['preventa'] ?? 0 ),
		);
		$agotados = (int) ( $facts['agotados'] ?? 0 );
		if ( $agotados > 0 ) {
			$counts[ StockStatus::OUT_OF_STOCK ] = $agotados;
		}

		$rows = array();
		foreach ( $counts as $status => $count ) {
			$rows[] = array(
				'status' => (string) $status,
				'label'  => StockStatus::label( (string) $status ),
				'count'  => $count,
				'texto'  => number_format_i18n( $count ) . ( 1 === $count ? ' tomo' : ' tomos' ),
			);
		}

		return $rows;
	}

	/**
	 * Rango de precios con el formato del sitio.
	 *
	 * @param array<string, mixed> $facts                 Hechos normalizados.
	 * @param bool                 $price_is_publishable ¿El precio es publicable?
	 * @return void
	 */
	public static function render_price_row( array $facts, bool $price_is_publishable = true ): void {
		$price = self::price_pair( $facts, $price_is_publishable );
		if ( null === $price ) {
			return;
		}

		self::render_item( $price['label'], $price['value'] );
	}

	/**
	 * Rótulo y valor del rango de precios, o null si no se puede publicar.
	 *
	 * @param array<string, mixed> $facts                 Hechos normalizados.
	 * @param bool                 $price_is_publishable ¿El precio es publicable?
	 * @return array{label:string,value:string}|null Valor ya escapado.
	 */
	public static function price_pair( array $facts, bool $price_is_publishable = true ): ?array {
		if ( ! $price_is_publishable ) {
			return null;
		}
		if ( null === ( $facts['price_min'] ?? null ) || null === ( $facts['price_max'] ?? null ) ) {
			return null;
		}
		if ( ! function_exists( 'wc_price' ) ) {
			return null;
		}

		$min = (float) $facts['price_min'];
		$max = (float) $facts['price_max'];

		$value = ( $min < $max && function_exists( 'wc_format_price_range' ) )
			? wc_format_price_range( $min, $max )
			: wc_price( $min );

		return array(
			'label' => $min < $max ? 'Precios' : 'Precio',
			'value' => wp_kses_post( (string) $value ),
		);
	}

	/**
	 * Editorial(es) de la serie enlazadas a su archive.
	 *
	 * @param array<string, mixed> $facts Hechos normalizados.
	 * @return void
	 */
	public static function render_brand_row( array $facts ): void {
		if ( empty( $facts['editoriales'] ) ) {
			return;
		}

		$rendered = array();
		foreach ( $facts['editoriales'] as $brand ) {
			$rendered[] = self::term_markup( (string) $brand['name'], (int) $brand['term_id'], self::BRAND_TAXONOMY );
		}

		self::render_item(
			1 === count( $facts['editoriales'] ) ? 'Editorial' : 'Editoriales',
			implode( ', ', $rendered )
		);
	}

	/**
	 * Enlaza los autores de la serie con sus archives de autor.
	 *
	 * @param array<string, mixed> $facts Hechos normalizados.
	 * @return void
	 */
	public static function render_author_row( array $facts ): void {
		if ( empty( $facts['autores'] ) ) {
			return;
		}

		$rendered = array();
		foreach ( $facts['autores'] as $author ) {
			$rendered[] = self::term_markup( (string) $author['name'], (int) $author['term_id'], self::AUTHOR_TAXONOMY );
		}

		self::render_item(
			1 === count( $facts['autores'] ) ? 'Autor' : 'Autores',
			self::summarized_list( $rendered, self::MAX_LISTED_AUTHORS )
		);
	}

	/**
	 * Nombre de término enlazado o texto plano si no hay enlace válido.
	 *
	 * @param string $name     Nombre del término.
	 * @param int    $term_id  ID del término.
	 * @param string $taxonomy Taxonomía.
	 * @return string HTML ya escapado.
	 */
	public static function term_markup( string $name, int $term_id, string $taxonomy ): string {
		if ( $term_id < 1 || ! function_exists( 'get_term_link' ) ) {
			return esc_html( $name );
		}

		$link = get_term_link( $term_id, $taxonomy );
		if ( ! is_string( $link ) || '' === $link ) {
			return esc_html( $name );
		}

		return sprintf(
			'<a class="akb-series-facts__link" href="%s">%s</a>',
			esc_url( $link ),
			esc_html( $name )
		);
	}

	/**
	 * Renglón de fecha de última actualización del catálogo.
	 *
	 * @param array<string, mixed> $facts Hechos normalizados.
	 * @return void
	 */
	public static function render_updated_row( array $facts ): void {
		$iso = (string) ( $facts['updated'] ?? '' );
		if ( '' === $iso ) {
			return;
		}

		$year  = (int) substr( $iso, 0, 4 );
		$month = (int) substr( $iso, 5, 2 );
		$day   = (int) substr( $iso, 8, 2 );
		$name  = PedidoPolicy::mes( $month );
		if ( '' === $name ) {
			return;
		}

		$human = sprintf( '%d de %s de %d', $day, $name, $year );

		self::render_item(
			'Catálogo actualizado',
			sprintf(
				'<time datetime="%s">%s</time>',
				esc_attr( $iso ),
				esc_html( $human )
			)
		);
	}

	/**
	 * Un par etiqueta/valor del <dl>.
	 *
	 * @param string $label Etiqueta visible.
	 * @param string $value HTML del valor, ya escapado.
	 * @return void
	 */
	public static function render_item( string $label, string $value ): void {
		?>
		<div class="akb-series-facts__item">
			<dt class="akb-series-facts__label"><?php echo esc_html( $label ); ?></dt>
			<dd class="akb-series-facts__value"><?php echo $value; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en el punto de construcción. ?></dd>
		</div>
		<?php
	}

	/**
	 * Enumeración en español con sufijo resumido: «a, b y c» o «a, b, c y N más».
	 *
	 * @param array<int, string> $parts Elementos ya escapados.
	 * @param int                $max   Límite de elementos nombrados.
	 * @return string HTML ya escapado.
	 */
	public static function summarized_list( array $parts, int $max ): string {
		$total = count( $parts );
		if ( 0 === $total ) {
			return '';
		}
		if ( 1 === $total ) {
			return $parts[0];
		}

		if ( $total > $max ) {
			$named   = array_slice( $parts, 0, $max );
			$dropped = $total - $max;
			if ( 1 === $dropped ) {
				$named = array_slice( $parts, 0, $max + 1 );
				$last  = array_pop( $named );
				return implode( ', ', $named ) . ' y ' . $last;
			}

			return sprintf(
				'%s y %d más',
				implode( ', ', $named ),
				$dropped
			);
		}

		$last = array_pop( $parts );

		return implode( ', ', $parts ) . ' y ' . $last;
	}
}
