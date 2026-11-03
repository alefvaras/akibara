<?php
/**
 * Template: Tab Ajustes de SearchAdminSection.
 *
 * @package Akibara\Search
 * @var array<string, mixed> $view_data
 * @var \Akibara\Search\SearchAdminSection $this
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

use Akibara\Search\BrandRelax;
use Akibara\Search\EmptyStateCopy;
use Akibara\Search\EmptyStateSuggestions;
use Akibara\Search\SearchAdminSection;

$current = (array) ( $view_data['current'] ?? array() );
?>
<h2>Ajustes del buscador</h2>
<p class="description">Palancas del motor. Se aplican de inmediato, sin reconstruir el índice.</p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'akb_search_settings_nonce' ); ?>
	<input type="hidden" name="action" value="<?php echo esc_attr( 'akb_search_save_settings' ); ?>">
	<input type="hidden" name="presente_empty_suggestions" value="1">

	<table class="form-table" role="presentation">
		<tbody>
			<?php
			$this->settings_toggle(
				'fuzzy',
				'Corrección de errores de tipeo',
				'Cuando una búsqueda no encuentra nada, sugiere el término más parecido («jujustu» → «jujutsu») con «¿quisiste decir?». No afecta las búsquedas que sí tienen resultados.',
				'yes' === ( $current['fuzzy'] ?? '' )
			);
			$this->settings_toggle(
				'sku_fastpath',
				'Búsqueda por código (SKU / ISBN)',
				'Encuentra un producto tipeando su código de barras o SKU exacto. No afecta la velocidad de las búsquedas por nombre; apágalo solo si no quieres ofrecer búsqueda por código.',
				'yes' === ( $current['sku_fastpath'] ?? '' )
			);
			$this->settings_toggle(
				'novedad',
				'Filtro «Novedades»',
				'Agrega el filtro de publicados recientemente (últimos 7 días, último mes, últimos 3 meses) en tienda y búsqueda, según la fecha de publicación del producto en el catálogo. Las preventas no cuentan como novedad.',
				'yes' === ( $current['novedad'] ?? '' )
			);
			?>
		</tbody>
	</table>

	<h3>Servicios de la tienda</h3>
	<p class="description">Estas tres palancas cambian lo que ve el cliente. Apagarlas no borra el índice: al encenderlas otra vez, los datos siguen ahí. Cada cambio queda en el registro de cambios y dispara una republicación del buscador.</p>
	<table class="form-table" role="presentation">
		<tbody>
			<?php
			$this->settings_toggle(
				'indexing',
				'Indexación automática',
				'Mantiene el índice al día cuando cambian productos, precios o categorías, y agenda la verificación diaria de consistencia. Apagada, el índice deja de actualizarse: la tienda sigue buscando, pero sobre un catálogo cada vez más viejo.',
				'yes' === ( $current['indexing'] ?? '' )
			);
			$this->settings_toggle(
				'suggest',
				'Autocompletado',
				'El desplegable de sugerencias mientras se escribe (y su endpoint público). Apagado, el buscador sigue funcionando al enviar la búsqueda, sin sugerencias — y deja de registrarse qué busca la gente en «Búsquedas».',
				'yes' === ( $current['suggest'] ?? '' )
			);
			$this->settings_toggle(
				'popular',
				'Bloque «Populares» del desplegable',
				'Las búsquedas más frecuentes de los últimos 30 días, que el desplegable ofrece antes de que el cliente escriba nada. Son las mismas que el aviso de «sin resultados» ofrece como «Lo más buscado». Apagado, el desplegable arranca vacío, el aviso deja de ofrecerlas y el autocompletado sigue funcionando igual. En el desplegable sólo se ve si el «Autocompletado» de arriba está encendido.',
				'yes' === ( $current['popular'] ?? '' )
			);
			$this->settings_toggle(
				'facets',
				'Filtros del catálogo',
				'Los filtros de la tienda y de la búsqueda. Apagados, el catálogo vuelve al listado nativo de WooCommerce, sin filtros ni rangos de precio.',
				'yes' === ( $current['facets'] ?? '' )
			);
			?>
		</tbody>
	</table>

	<h3>Mensajes al cliente</h3>
	<p class="description">Lo que se lee cuando una búsqueda o un filtro no devuelve nada, o no llega a cargar. El título («Sin resultados para «X».») lo escribe la tienda con el término buscado; acá se edita la pista, que es donde va la salida comercial.</p>
	<table class="form-table" role="presentation">
		<tbody>
			<?php
			$this->settings_text(
				'empty_hint_catalog',
				SearchAdminSection::SETTINGS_TEXT_LABELS['empty_hint_catalog'],
				'Se lee cuando lo escrito no encuentra nada, o cuando los filtros dejan la tienda en cero sin una búsqueda. Lo que pongas entre corchetes se convierte en el botón que limpia los filtros aplicados y la búsqueda. Quítalos y el aviso queda sin botón.',
				EmptyStateCopy::catalog()
			);
			$this->settings_text(
				'empty_hint_filters',
				SearchAdminSection::SETTINGS_TEXT_LABELS['empty_hint_filters'],
				'Se lee cuando lo escrito sí encuentra productos y son los filtros marcados los que dejan la página en cero («frieren» con el género «terror»); el título ya dice que son los filtros. Lo que pongas entre corchetes se convierte en el botón que quita los filtros y conserva la búsqueda. Quítalos y el aviso queda sin botón.',
				EmptyStateCopy::filters()
			);
			$this->settings_text(
				'empty_hint_suggest',
				SearchAdminSection::SETTINGS_TEXT_LABELS['empty_hint_suggest'],
				'Ahí no hay filtros que limpiar, por eso es un texto aparte. Se sirve como texto plano: cualquier etiqueta HTML se retira al guardar.',
				EmptyStateCopy::suggest()
			);
			$this->settings_toggle(
				'empty_suggestions',
				'Salidas en «sin resultados»',
				'Cuando una búsqueda no encuentra nada, el aviso ofrece la serie que más se parece a lo escrito («frieren novela» → Frieren) y lo más buscado. Sólo cuando lo que no se encuentra es el texto: si la búsqueda trae productos y son los filtros marcados los que la dejan en cero, el aviso dice eso y ofrece quitarlos. Apagado, el aviso queda con el título y la pista. Una serie cuyo nombre es UNA palabra sólo se ofrece si lo que viene detrás son números, palabras ignoradas o palabras de formato (abajo): «kingdom hearts» no ofrece Kingdom ni «monster high» Monster, porque nombran otra obra. Por lo mismo, «frieren preludio» tampoco ofrece Frieren: si una palabra así no nombra otra obra en tu tienda, agrégala a las palabras de formato. Y la última palabra del nombre tiene que estar entera: «love hinamatsuri» no ofrece Love Hina.',
				'yes' === ( $current['empty_suggestions'] ?? '' )
			);
			$this->settings_words(
				'empty_tail_words',
				'Palabras de formato',
				'Cuando el nombre de una serie sigue más allá de lo escrito («kaguya sama novela» → Kaguya-sama: Love is War), la serie sólo se ofrece si lo que sobra son números, palabras ignoradas o estas palabras. Así «biblioteca vertigo sandman» no ofrece otra obra de la misma colección, y «slam dunk edicion de lujo» sí ofrece Slam Dunk Deluxe. Cada palabra vale también en plural y en el otro género: «manga» cubre «mangas», «nuevo» cubre «nueva», «nuevos» y «nuevas», y la que termina en consonante cubre su femenino en -a: «español» cubre «española» y «españolas». Un plural no cubre su singular: escríbelas en singular y en masculino. Las de fábrica cubren el formato, la edición y el idioma («libro», «tapa dura», «en inglés»), el tomo en palabras hasta el veinte, en ordinal hasta el décimo o en romano hasta el xx («sexto tomo», «tomo xii», «segunda temporada») y cómo se compra («a pedido», «preventa», «precio», «usado»): quitar una hace que esas búsquedas se queden sin serie. Más allá, el cliente escribe la cifra, que vale siempre. Una palabra mal escrita en lo que sobra («kaguya sama novla») también deja sin serie, porque no se distingue del nombre de otra obra. Una por línea o separadas por coma, sin tildes o con ellas. Vacío: sólo números (también «3ra», «2do» o «1era») y palabras ignoradas.',
				EmptyStateSuggestions::tail_words(),
				EmptyStateSuggestions::default_tail_words()
			);
			$this->settings_text(
				'empty_series_label',
				SearchAdminSection::SETTINGS_TEXT_LABELS['empty_series_label'],
				'Va encima del enlace a la serie. Sirve igual con una serie que con dos.',
				EmptyStateCopy::series_label()
			);
			$this->settings_text(
				'empty_popular_label',
				SearchAdminSection::SETTINGS_TEXT_LABELS['empty_popular_label'],
				'Va encima de las búsquedas frecuentes, que salen del bloque «Populares» de arriba.',
				EmptyStateCopy::popular_label()
			);
			$this->settings_text(
				'relaxed_notice',
				SearchAdminSection::SETTINGS_TEXT_LABELS['relaxed_notice'],
				'Se lee cuando alguien busca «jujutsu kaisen ivrea» y ese título es de otra editorial: en vez de no mostrar nada, la tienda muestra los tomos que sí hay y lo explica con esta frase. {editorial} es la que se ignoró y {busqueda} lo que quedó buscándose.',
				BrandRelax::template()
			);
			$this->settings_text(
				'network_notice',
				SearchAdminSection::SETTINGS_TEXT_LABELS['network_notice'],
				'Se lee arriba de los productos cuando un filtro, el orden o una página de resultados no llega a cargar porque se cortó la conexión del cliente. Lo que pongas entre corchetes se convierte en el botón que vuelve a pedir lo que eligió. Quítalos y el aviso queda sin botón: volver a tocar el filtro también lo aplica.',
				EmptyStateCopy::network()
			);
			?>
		</tbody>
	</table>

	<?php submit_button( 'Guardar ajustes' ); ?>
</form>
