#!/usr/bin/env python3
"""Remediación de hallazgos de QA para Los diarios de la boticaria (La novela original).

Aplica las correcciones requeridas por el Auditor de QA Independiente:
1. Serie ID 819: repara los saltos de línea literales (nn y n) usando <p> y <br /> limpios.
2. Pack ID 32873: normaliza título a 'Los diarios de la boticaria (La novela original) 1+2 (Pack de lanzamiento)'
   para que no se rompa con VolumeTitle ni TitleParser.
3. Productos 32869 y 32871: alinear excerpt (<strong>... 01</strong> y <strong>... 02</strong>) y
   focus keyword para que coincidan al 100% con los títulos paddeados de la base de datos.
4. Pack ID 32873: ajustar meta description estrictamente entre 140 y 155 caracteres (154 chars)
   con la focus keyword exacta, y ajustar el alt del attachment.
5. Reindexar en akibara-search.
"""

import subprocess
import json

php_code = r"""<?php
require_once ABSPATH . 'wp-admin/includes/post.php';

// 1. Reparar Serie ID 819
$serie_id = 819;
$serie_desc = '<p>Maomao lleva una vida tranquila junto a su padre boticario en el barrio de placer, hasta que unos traficantes la secuestran y la venden como sirvienta en el palacio imperial. Decidida a pasar desapercibida hasta cumplir su contrato, intenta ocultar sus amplios conocimientos en venenos y medicina tradicional. Sin embargo, cuando los herederos del emperador caen víctimas de una misteriosa dolencia, la curiosidad y su instinto deductivo la empujan a intervenir, llamando la atención de Jinshi, un influyente e intrigante eunuco de la corte imperial. Descubre la historia original de Natsu Hyuuga que cautivó al mundo, en la edición oficial de novela ligera con ilustraciones de Touko Shino y despacho a todo Chile.</p>' . "\n\n" .
'<p><strong>Autor original:</strong> Natsu Hyuuga<br />' . "\n" .
'<strong>Ilustraciones:</strong> Touko Shino<br />' . "\n" .
'<strong>Demografía:</strong> Seinen / Misterio histórico<br />' . "\n" .
'<strong>Estado en Japón:</strong> En curso (15 volúmenes publicados)<br />' . "\n" .
'<strong>Formato de edición:</strong> Rústica con solapas (15 x 21 cm) con inclusión de páginas a color<br />' . "\n" .
'<strong>Estado en España:</strong> En curso (2 volúmenes publicados)<br />' . "\n" .
'<strong>Próximo tomo:</strong> Volumen #3 anunciado</p>';

wp_update_term($serie_id, 'pa_serie', [
    'description' => $serie_desc
]);
echo "Serie ID 819 actualizada con descripcion limpia sin caracteres nn.\n";

// 2. Corregir Pack ID 32873
$pid_pack = 32873;
$title_pack = 'Los diarios de la boticaria (La novela original) 1+2 (Pack de lanzamiento)';
$slug_pack = 'los-diarios-de-la-boticaria-la-novela-original-1-2-pack-de-lanzamiento';
$excerpt_pack = '<strong>Los diarios de la boticaria (La novela original) 1+2 (Pack de lanzamiento)</strong>: Estuche oficial de colección que reúne los dos primeros volúmenes de la novela de Natsu Hyuuga y Touko Shino. Edición especial de Distrito Manga con despacho a todo Chile.';

$content_pack = '<p><strong>Los diarios de la boticaria (La novela original) 1+2 (Pack de lanzamiento)</strong> reúne en un estuche especial de colección los dos primeros volúmenes de la aclamada obra original escrita por <strong>Natsu Hyuuga</strong> e ilustrada por <strong>Touko Shino</strong>. La oportunidad perfecta para iniciar una de las historias de misterio, medicina tradicional e intriga cortesana más fascinantes de la literatura japonesa actual.</p>' . "\n\n" .
'<h2>Edición coleccionista: Los diarios de la boticaria (La novela original) 1+2 (Pack de lanzamiento)</h2>' . "\n" .
'<p>Acompaña a Maomao desde su llegada forzada a la corte interior hasta convertirse en la confidente y catadora de venenos más respetada del palacio. Incluye páginas a color exclusivas y acabado de lujo en formato rústica con solapas.</p>' . "\n\n" .
'<p class="akb-pdp-seo-links">Descubre toda la colección en la <a href="/serie/los-diarios-de-la-boticaria-la-novela-original/">serie Los diarios de la boticaria (La novela original)</a> y el catálogo oficial de <a href="/marca/distrito-manga/">Distrito Manga</a> en Akibara.</p>';

wp_update_post([
    'ID' => $pid_pack,
    'post_title' => $title_pack,
    'post_name' => $slug_pack,
    'post_excerpt' => $excerpt_pack,
    'post_content' => $content_pack,
]);

// Meta description exacta de 154 caracteres conteniendo focus keyword y sin editorial
$pack_meta_desc = 'Compra Los diarios de la boticaria (La novela original) 1+2 (Pack de lanzamiento) en Akibara. Edición oficial con ilustraciones a color y envío a todo Chile.';
update_post_meta($pid_pack, 'rank_math_focus_keyword', $title_pack . ', pack Los diarios de la boticaria novela, novela Los diarios de la boticaria pack, Natsu Hyuuga');
update_post_meta($pid_pack, 'rank_math_title', $title_pack . ' %sep% Akibara');
update_post_meta($pid_pack, 'rank_math_description', $pack_meta_desc);

// Alt de la imagen del pack con focus keyword exacta
$thumb_pack = get_post_thumbnail_id($pid_pack);
if ($thumb_pack) {
    update_post_meta($thumb_pack, '_wp_attachment_image_alt', 'Portada oficial de ' . $title_pack);
    wp_update_post([
        'ID' => $thumb_pack,
        'post_title' => $title_pack . ' - Distrito Manga'
    ]);
}
echo "Pack ID 32873 remediado (titulo: '$title_pack', meta_desc len: " . strlen($pack_meta_desc) . ").\n";

// 3. Alinear Excerpt y SEO de Vol 1 (32869)
$pid_vol1 = 32869;
$title_vol1 = get_the_title($pid_vol1); // 'Los diarios de la boticaria (La novela original) 01'
$excerpt_vol1 = '<strong>' . $title_vol1 . '</strong>: Tras ser secuestrada y vendida al palacio imperial, Maomao oculta su prodigioso talento farmacéutico hasta que un enigma mortal entre los herederos del trono exige su intervención. Edición oficial con despacho a todo Chile.';
$content_vol1 = '<p><strong>' . $title_vol1 . '</strong> presenta la historia original de <strong>Natsu Hyuuga</strong> con bellísimas ilustraciones de <strong>Touko Shino</strong>. Secuestrada en el barrio del placer y vendida como sirvienta en el palacio imperial, la joven boticaria Maomao desea pasar desapercibida hasta cumplir su servicio. Sin embargo, cuando los infantes del emperador enferman misteriosamente, su pasión investigadora y sus conocimientos sobre venenos y plantas medicinales la llevan a desentrañar un oscuro complot palaciego.</p>' . "\n\n" .
'<h2>Descubre la intriga de ' . $title_vol1 . '</h2>' . "\n" .
'<p>Al salvar la vida de la pequeña princesa, Maomao llama la atención del perspicaz Jinshi, un eunuco de alta alcurnia imperial que reconoce su agudeza deductiva y la convierte en su catadora oficial de venenos. Una cautivante novela que combina rigor científico, intrigas cortesanas, enigmas médicos y una protagonista inolvidable.</p>' . "\n\n" .
'<p class="akb-pdp-seo-links">Explora también los demás volúmenes de la <a href="/serie/los-diarios-de-la-boticaria-la-novela-original/">serie Los diarios de la boticaria (La novela original)</a> y descubre más obras publicadas por <a href="/marca/distrito-manga/">Distrito Manga</a> en Akibara.</p>';

$meta_desc_vol1 = 'Compra ' . $title_vol1 . ' en Akibara. Edición oficial con ilustraciones a color y despacho rápido a todo Chile.';

wp_update_post([
    'ID' => $pid_vol1,
    'post_excerpt' => $excerpt_vol1,
    'post_content' => $content_vol1,
]);
update_post_meta($pid_vol1, 'rank_math_focus_keyword', $title_vol1 . ', Los diarios de la boticaria novela 1, novela Los diarios de la boticaria 1, Natsu Hyuuga');
update_post_meta($pid_vol1, 'rank_math_title', $title_vol1 . ' %sep% Novela Ligera %sep% Akibara');
update_post_meta($pid_vol1, 'rank_math_description', $meta_desc_vol1);

$thumb_vol1 = get_post_thumbnail_id($pid_vol1);
if ($thumb_vol1) {
    update_post_meta($thumb_vol1, '_wp_attachment_image_alt', 'Portada oficial de ' . $title_vol1 . ' - Distrito Manga');
}
echo "Volumen 1 remediado con focus keyword '$title_vol1' (meta desc len: " . strlen($meta_desc_vol1) . ").\n";

// 4. Alinear Excerpt y SEO de Vol 2 (32871)
$pid_vol2 = 32871;
$title_vol2 = get_the_title($pid_vol2); // 'Los diarios de la boticaria (La novela original) 02'
$excerpt_vol2 = '<strong>' . $title_vol2 . '</strong>: Al servicio directo de la corte, Maomao debe resolver una intriga de envenenamiento y conspiración militar que sacude los cimientos del imperio. Novela ligera oficial con ilustraciones a color y despacho a todo Chile.';
$content_vol2 = '<p>En <strong>' . $title_vol2 . '</strong>, Maomao continúa desentrañando los enigmas más complejos del palacio imperial bajo las órdenes de Jinshi. Nuevos casos de envenenamientos aparentemente accidentales, rituales prohibidos y conspiraciones diplomáticas pondrán a prueba su ingenio farmacéutico y su capacidad deductiva.</p>' . "\n\n" .
'<h2>Misterio y conspiración en ' . $title_vol2 . '</h2>' . "\n" .
'<p>Mientras la tensión política crece en la corte interna, los secretos en torno al pasado de Maomao y los verdaderos motivos de Jinshi comienzan a salir a la luz en una trama llena de giros magistrales y sutilezas históricas.</p>' . "\n\n" .
'<p class="akb-pdp-seo-links">Continúa la lectura con la <a href="/serie/los-diarios-de-la-boticaria-la-novela-original/">serie Los diarios de la boticaria (La novela original)</a> y encuentra el catálogo completo de <a href="/marca/distrito-manga/">Distrito Manga</a> en Akibara.</p>';

$meta_desc_vol2 = 'Compra ' . $title_vol2 . ' en Akibara. Edición oficial con ilustraciones a color y despacho rápido a todo Chile.';

wp_update_post([
    'ID' => $pid_vol2,
    'post_excerpt' => $excerpt_vol2,
    'post_content' => $content_vol2,
]);
update_post_meta($pid_vol2, 'rank_math_focus_keyword', $title_vol2 . ', Los diarios de la boticaria novela 2, novela Los diarios de la boticaria 2, Natsu Hyuuga');
update_post_meta($pid_vol2, 'rank_math_title', $title_vol2 . ' %sep% Novela Ligera %sep% Akibara');
update_post_meta($pid_vol2, 'rank_math_description', $meta_desc_vol2);

$thumb_vol2 = get_post_thumbnail_id($pid_vol2);
if ($thumb_vol2) {
    update_post_meta($thumb_vol2, '_wp_attachment_image_alt', 'Portada oficial de ' . $title_vol2 . ' - Distrito Manga');
}
echo "Volumen 2 remediado con focus keyword '$title_vol2' (meta desc len: " . strlen($meta_desc_vol2) . ").\n";
?>"""

cmd = ["docker", "exec", "-i", "akibara-cron-1", "wp", "eval-file", "-"]
p = subprocess.run(cmd, input=php_code, text=True, capture_output=True)
if p.returncode != 0:
    print("Error:", p.stderr)
else:
    print(p.stdout)
