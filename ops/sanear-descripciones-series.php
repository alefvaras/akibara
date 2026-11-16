<?php
/**
 * ops/sanear-descripciones-series.php
 * Sanea las descripciones de pa_serie retirando el bloque estático de ficha técnica
 * para que quede exclusivamente la sinopsis comercial y SeriesFacts sirva la ficha dinámica.
 */

if ( ! defined( 'ABSPATH' ) ) {
    require_once dirname( __DIR__ ) . '/public_html/wp-load.php';
}

$cli_args = isset( $argv ) && is_array( $argv ) ? $argv : ( isset( $args ) && is_array( $args ) ? $args : array() );
$dry_run  = ( in_array( '--apply', $cli_args, true ) || in_array( 'apply', $cli_args, true ) ) ? false : true;

echo $dry_run ? "=== MODO SIMULACIÓN (DRY-RUN) ===\n" : "=== APLICANDO EN BASE DE DATOS ===\n";

$terms = get_terms( array(
    'taxonomy'   => 'pa_serie',
    'hide_empty' => false,
) );

$pattern = '/(?:\r?\n|<br\s*\/?>|<\/p>|<p>)\s*<strong>(?:Autor|Autor original|Guion|Línea original|Demografía|Editorial oficial|Estado en|Formato de edición):<\/strong>[\s\S]*$/iu';

$modified = 0;
$backup = array();

foreach ( $terms as $term ) {
    $desc = $term->description;
    if ( preg_match( $pattern, $desc ) ) {
        $clean_desc = preg_replace( $pattern, '', $desc );
        $clean_desc = trim( $clean_desc );
        // Si termina con etiquetas abiertas tipo <p>, cerrarlas limpiamente
        if ( substr_count( $clean_desc, '<p>' ) > substr_count( $clean_desc, '</p>' ) ) {
            $clean_desc .= '</p>';
        }

        $backup[ $term->term_id ] = array(
            'slug'     => $term->slug,
            'name'     => $term->name,
            'original' => $desc,
            'clean'    => $clean_desc,
        );

        $modified++;
        if ( $modified <= 5 || ! $dry_run ) {
            echo "[$term->slug] $term->name\n";
            echo "--- ANTES (final) ---\n" . substr( $desc, -250 ) . "\n";
            echo "--- DESPUÉS (final) ---\n" . substr( $clean_desc, -250 ) . "\n\n";
        }
    }
}

echo "Total series a sanear: $modified\n";

if ( ! empty( $backup ) ) {
    $backup_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/uploads' : '/tmp';
    $backup_file = $backup_dir . '/series-descriptions-' . date( 'Ymd-His' ) . '.json';
    file_put_contents( $backup_file, json_encode( $backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
    echo "Respaldo guardado en: $backup_file\n";
}

if ( ! $dry_run ) {
    foreach ( $backup as $term_id => $data ) {
        wp_update_term( $term_id, 'pa_serie', array(
            'description' => $data['clean'],
        ) );
    }
    echo "¡Se actualizaron $modified series con éxito!\n";
}
