<?php
/**
 * Template: Tab Sinónimos de SearchAdminSection.
 *
 * @package Akibara\Search
 * @var array<string, mixed> $view_data
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

use Akibara\Search\SearchAdminSection;
use Akibara\Search\Synonyms;

$lines   = (array) ( $view_data['lines'] ?? array() );
$prefill = (string) ( $view_data['prefill'] ?? '' );
$shared  = (array) ( $view_data['shared'] ?? array() );
?>
<h2>Sinónimos</h2>
<?php if ( '' !== $prefill ) : ?>
	<div class="notice notice-info inline">
		<p>Se agregó una línea nueva al final con <strong><?php echo esc_html( $prefill ); ?></strong>. Escribe al lado el término que sí existe en el catálogo (separado por coma) y guarda; nada se guardó todavía.</p>
	</div>
<?php endif; ?>

<p class="description" id="akb-synonyms-desc">
	<?php
	printf(
		'Un grupo por línea; términos equivalentes separados por coma. Al buscar cualquiera del grupo, el buscador matchea todos — sin reindexar. Ejemplo: <code>aot, attack on titan, shingeki no kyojin, ataque a los titanes</code>. Las palabras muy cortas y de relleno (de, la, on…) se ignoran solas al buscar, así que no hace falta escribirlas: «ataque titanes» encuentra «el ataque de los titanes». Límite: %1$d grupos, %2$d términos por grupo, %3$d caracteres y %4$d palabras por término, contando también las de relleno. Un término que no cumple se descarta al guardar y se avisa. El motor aplica un presupuesto ponderado por búsqueda para impedir combinaciones que degraden el catálogo.',
		(int) Synonyms::MAX_GROUPS,
		(int) Synonyms::MAX_TERMS_PER_GROUP,
		(int) Synonyms::MAX_TERM_LENGTH,
		(int) Synonyms::MAX_TERM_TOKENS
	);
	?>
</p>

<?php foreach ( $shared as $compartido ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php
			printf(
				'Ojo: <strong>%1$s</strong> está en las líneas %2$s. Para el buscador eso las vuelve <strong>un solo grupo</strong>: quien busque cualquier término de una también encuentra los de la otra%3$s. Si son obras distintas, quita el término repetido de una de las líneas.',
				esc_html( '«' . implode( '» / «', (array) $compartido['terminos'] ) . '»' ),
				esc_html( implode( ' y ', array_map( 'strval', (array) $compartido['grupos'] ) ) ),
				count( (array) $compartido['terminos'] ) > 1 ? esc_html( ' (las palabras de relleno como «la» o «de» no cuentan, así que son el mismo término)' ) : ''
			);
			?>
		</p>
	</div>
<?php endforeach; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'akb_search_synonyms_nonce' ); ?>
	<input type="hidden" name="action" value="<?php echo esc_attr( 'akb_search_save_synonyms' ); ?>">
	<textarea id="akb-synonyms" name="synonyms" rows="14" class="large-text code" spellcheck="false" aria-label="Grupos de sinónimos" aria-describedby="akb-synonyms-desc"><?php echo esc_textarea( implode( "\n", $lines ) ); ?></textarea>
	<?php submit_button( 'Guardar sinónimos' ); ?>
</form>
