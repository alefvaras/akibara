<?php
/**
 * Asigna la palabra clave objetivo (rank_math_focus_keyword) a todas las series (pa_serie),
 * y actualiza la metadata SEO de las series que lo requieran (como The Walking Dead 422).
 */

defined( 'ABSPATH' ) || exit( 1 );

global $wpdb;

$terms = get_terms([
    'taxonomy'   => 'pa_serie',
    'hide_empty' => false,
]);

echo "Iniciando asignación de palabra clave objetivo para " . count( $terms ) . " series...\n";

$enhancements = new \Akibara\Business\Modules\SeoEnhancements\SeoEnhancements();
$reflection = new ReflectionClass( $enhancements );
$generated_meta_method = $reflection->getMethod( 'generated_series_meta' );
$generated_meta_method->setAccessible( true );

$updated_fk = 0;
$updated_seo = 0;

foreach ( $terms as $term ) {
    $term_id = (int) $term->term_id;
    $fk = get_term_meta( $term_id, 'rank_math_focus_keyword', true );

    // 1. Asignar focus keyword si está vacío
    if ( empty( $fk ) ) {
        $desired_fk = trim( $term->name );
        update_term_meta( $term_id, 'rank_math_focus_keyword', $desired_fk );
        $updated_fk++;
        echo "  [FK] ID $term_id ({$term->name}) => '$desired_fk'\n";
    }

    // 2. Si es The Walking Dead (422), actualizar títulos y descripciones a 7 tomos
    if ( $term_id === 422 ) {
        $title = 'The Walking Dead cómic: 7 tomos en español | Akibara Chile';
        $desc  = 'The Walking Dead: 7 tomos catalogados de la edición Planeta Cómic en español. Desde $45.600 en Akibara, con despacho a todo Chile.';
        
        update_term_meta( $term_id, 'rank_math_title', $title );
        update_term_meta( $term_id, '_akibara_rank_math_title_ownership', 'generated_snapshot_v1' );
        update_term_meta( $term_id, '_akibara_rank_math_title_snapshot_sha256', hash( 'sha256', $title ) );

        update_term_meta( $term_id, 'rank_math_description', $desc );
        update_term_meta( $term_id, '_akibara_rank_math_description_ownership', 'generated_snapshot_v1' );
        update_term_meta( $term_id, '_akibara_rank_math_description_snapshot_sha256', hash( 'sha256', $desc ) );

        $updated_seo++;
        echo "  [SEO-422] Actualizado título y descripción para The Walking Dead (7 tomos).\n";
    }

    // 3. Si tiene título o descripción vacía y cuenta > 0, poblar con su meta generada
    $cur_title = get_term_meta( $term_id, 'rank_math_title', true );
    if ( empty( $cur_title ) && $term->count > 0 ) {
        $meta = $generated_meta_method->invoke( $enhancements, $term );
        if ( ! empty( $meta['title'] ) && ! empty( $meta['description'] ) ) {
            update_term_meta( $term_id, 'rank_math_title', $meta['title'] );
            update_term_meta( $term_id, '_akibara_rank_math_title_ownership', 'generated_snapshot_v1' );
            update_term_meta( $term_id, '_akibara_rank_math_title_snapshot_sha256', hash( 'sha256', $meta['title'] ) );

            update_term_meta( $term_id, 'rank_math_description', $meta['description'] );
            update_term_meta( $term_id, '_akibara_rank_math_description_ownership', 'generated_snapshot_v1' );
            update_term_meta( $term_id, '_akibara_rank_math_description_snapshot_sha256', hash( 'sha256', $meta['description'] ) );

            $updated_seo++;
            echo "  [SEO-POPULATE] ID $term_id ({$term->name}) => Título: '{$meta['title']}'\n";
        }
    }
}

echo "\nResultado:\n";
echo "- Palabras clave asignadas: $updated_fk\n";
echo "- Metas SEO actualizadas/pobladas: $updated_seo\n";
