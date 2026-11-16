<?php
$terms = get_terms([
    'taxonomy' => 'pa_serie',
    'hide_empty' => false,
]);

echo "Auditing all " . count($terms) . " series descriptions...\n\n";

$norma_mentions = [];
$long_descriptions = [];
$empty_descriptions = [];

foreach ($terms as $t) {
    $desc = $t->description;
    $len = strlen($desc);
    $word_count = str_word_count(strip_tags($desc));

    $prods = get_posts([
        'post_type' => 'product',
        'tax_query' => [['taxonomy' => 'pa_serie', 'field' => 'term_id', 'terms' => $t->term_id]],
        'numberposts' => 5
    ]);
    $actual_brands = [];
    foreach ($prods as $p) {
        $b = wp_get_post_terms($p->ID, 'product_brand', ['fields' => 'names']);
        $actual_brands = array_merge($actual_brands, $b);
    }
    $actual_brands = array_unique($actual_brands);

    if (empty(trim($desc))) {
        $empty_descriptions[] = [
            'id' => $t->term_id,
            'name' => $t->name,
            'actual_brands' => $actual_brands,
        ];
    }

    if (preg_match('/\bnorma(\s+editorial)?\b/i', $desc)) {
        $norma_mentions[] = [
            'id' => $t->term_id,
            'name' => $t->name,
            'actual_brands' => $actual_brands,
            'desc_preview' => substr(strip_tags($desc), 0, 150),
        ];
    }

    if ($word_count > 250) {
        $long_descriptions[] = [
            'id' => $t->term_id,
            'name' => $t->name,
            'words' => $word_count,
            'chars' => $len,
            'actual_brands' => $actual_brands,
        ];
    }
}

echo "=== SERIES MENTIONING NORMA (" . count($norma_mentions) . ") ===\n";
foreach ($norma_mentions as $n) {
    echo "  ID {$n['id']} ({$n['name']}) -> Actual: " . implode(', ', $n['actual_brands']) . "\n";
}

echo "\n=== EMPTY SERIES DESCRIPTIONS (" . count($empty_descriptions) . ") ===\n";
foreach ($empty_descriptions as $e) {
    echo "  ID {$e['id']} ({$e['name']}) -> Actual: " . implode(', ', $e['actual_brands']) . "\n";
}

echo "\n=== LONG SERIES DESCRIPTIONS (>250 words) (" . count($long_descriptions) . ") ===\n";
foreach ($long_descriptions as $l) {
    echo "  ID {$l['id']} ({$l['name']}): {$l['words']} words ({$l['chars']} chars) -> Brand: " . implode(', ', $l['actual_brands']) . "\n";
}
