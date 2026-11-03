<?php
/**
 * Template: Tab Palabras Ignoradas (Stopwords) de SearchAdminSection.
 *
 * @package Akibara\Search
 * @var array<string, mixed> $view_data
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

use Akibara\Search\SearchAdminSection;
use Akibara\Search\Stopwords;

$vigentes = (array) ( $view_data['vigentes'] ?? array() );
$defaults = (array) ( $view_data['defaults'] ?? array() );
?>
<h2>Palabras ignoradas</h2>
<p class="description" id="akb-stopwords-desc">Palabras que el buscador ignora (artículos, preposiciones…). Una por línea o separadas por coma. Aplica de inmediato, sin reconstruir el índice. Los números nunca se ignoran.</p>
<p class="description">También deciden qué serie ofrece «Quizá buscabas» cuando una búsqueda da cero: para ofrecer una serie cuyo nombre sigue más allá de lo escrito («kaguya sama novela» → Kaguya-sama: Love is War), lo que el cliente escribió tiene que conservar dos palabras que no estén en esta lista, y lo que sobra sólo puede ser números, palabras de esta lista o palabras de formato (Ajustes → «Palabras de formato»): «kaguya sama en español» ofrece Kaguya-sama porque «en» está aquí y «español» allá. Lo mismo vale detrás de una serie cuyo nombre tiene una sola palabra que no está en esta lista —Kingdom, The Climber—: «kingdom tomo 3» la ofrece y «kingdom hearts» no.</p>

<?php if ( array() === $vigentes ) : ?>
	<p class="description">
		<?php
		printf(
			'Ahora mismo el buscador no ignora ninguna palabra. Para volver a la lista de fábrica, pégala aquí y guarda: <code>%s</code>',
			esc_html( implode( ', ', $defaults ) )
		);
		?>
	</p>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'akb_search_stopwords_nonce' ); ?>
	<input type="hidden" name="action" value="<?php echo esc_attr( 'akb_search_save_stopwords' ); ?>">
	<textarea id="akb-stopwords" name="stopwords" rows="10" class="large-text code" spellcheck="false" aria-label="Palabras ignoradas" aria-describedby="akb-stopwords-desc"><?php echo esc_textarea( implode( "\n", $vigentes ) ); ?></textarea>
	<?php submit_button( 'Guardar palabras ignoradas' ); ?>
</form>
