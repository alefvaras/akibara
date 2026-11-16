<?php
/**
 * Actualización editorial de series de cómics occidentales en pa_serie.
 * Elimina 'Estado en Japón' y la lista indiscriminada de autores,
 * adaptando los metadatos al estándar de cómic occidental (Guion, Dibujo, Línea original).
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

$updates = array(
    'biblioteca-vertigo-john-constantine-hellblazer' => array(
        'lead' => "<strong>Biblioteca Vertigo: John Constantine, Hellblazer</strong> recopila en tomos de lujo la legendaria etapa del mago callejero y nigromante creado por Alan Moore para el sello Vertigo de DC Comics. Cínico, fumador empedernido y maestro del engaño con gabardina raída, <strong>John Constantine</strong> recorre el submundo oculto de Londres librando guerras silenciosas contra demonios del infierno, sectas y ángeles corruptos, donde la victoria casi siempre exige el sacrificio devastador de sus seres queridos.\nCon guiones magistrales de autores como Garth Ennis, Jamie Delano y Brian Azzarello, es la cumbre indiscutible del horror y ocultismo urbano moderno.\nEncuentra la <strong>Biblioteca Vertigo Hellblazer en español</strong> editada por <strong>Panini Comics España</strong> en <strong>Akibara</strong>, con despacho seguro a todo Chile.",
        'bullets' => "<strong>Guion principal:</strong> Jamie Delano, Garth Ennis, Paul Jenkins<br />\n<strong>Línea original:</strong> DC Comics / Vertigo (EE.UU.)<br />\n<strong>Editorial oficial:</strong> Panini España (España)<br />\n<strong>Estado de la obra:</strong> Finalizada (300 números originales)<br />\n<strong>Estado de la edición:</strong> En curso (14 tomos publicados)",
    ),
    'invencible' => array(
        'lead' => "<strong>Invencible</strong> (<em>Invincible</em>), la aclamada obra maestra de superhéroes creada por <strong>Robert Kirkman</strong> (autor de <em>The Walking Dead</em>) y Cory Walker para Image Comics, reinventó el cómic de justicieros con un realismo visceral, consecuencias permanentes y batallas sangrientas. <strong>Mark Grayson</strong> parece un adolescente ordinario, con la única excepción de que su padre es <strong>Omni-Man</strong>, el superhéroe alienígena más poderoso de la Tierra. Al manifestar sus propios poderes de superfuerza y vuelo bajo el alias de Invencible, Mark descubrirá una desgarradora y oscura traición que pondrá en jaque el destino de la humanidad.\nConcluida de manera magistral a lo largo de sus tomos integrales, inspiró la exitosa serie animada de Amazon Prime Video.\nConsigue <strong>Invencible en español</strong> en su edición de <strong>Planeta Cómic</strong> en <strong>Akibara</strong>, con despacho rápido a todo Chile.",
        'bullets' => "<strong>Guion:</strong> Robert Kirkman<br />\n<strong>Dibujo:</strong> Cory Walker, Ryan Ottley<br />\n<strong>Línea original:</strong> Image Comics / Skybound (EE.UU.)<br />\n<strong>Editorial oficial:</strong> Planeta Cómic (España)<br />\n<strong>Estado de la obra:</strong> Finalizada (144 números originales)<br />\n<strong>Estado de la edición:</strong> En curso (6 tomos publicados)",
    ),
    'the-walking-dead' => array(
        'lead' => "<strong>The Walking Dead</strong> (<em>Los Muertos Vivientes</em>), la legendaria epopeya postapocalíptica creada por <strong>Robert Kirkman</strong> y dibujada magistralmente por Tony Moore y <strong>Charlie Adlard</strong> para Image Comics (ganadora de los Premios Eisner), redefinió el cómic de zombis a nivel mundial. Tras despertar de un coma en un hospital abandonado en Georgia, el oficial de policía <strong>Rick Grimes</strong> descubre que el mundo ha sido arrasado por muertos vivientes carnívoros («caminantes»). En su desesperada lucha por hallar a su esposa Lori y a su hijo Carl, Rick liderará a un grupo de supervivientes descubriendo pronto que los verdaderos monstruos no son los zombis, sino los humanos sobrevivientes.\nCompletada en <strong>193 grapas</strong> recopiladas en prestigiosos tomos integrales de tapa dura o rústica, inspiró la célebre serie televisiva de AMC.\nEncuentra la colección de <strong>The Walking Dead en español</strong> por <strong>Planeta Cómic</strong> en <strong>Akibara</strong>, con despacho rápido a todo Chile.",
        'bullets' => "<strong>Guion:</strong> Robert Kirkman<br />\n<strong>Dibujo:</strong> Charlie Adlard, Tony Moore<br />\n<strong>Línea original:</strong> Image Comics / Skybound (EE.UU.)<br />\n<strong>Editorial oficial:</strong> Planeta Cómic (España)<br />\n<strong>Estado de la obra:</strong> Finalizada (193 números originales)<br />\n<strong>Estado de la edición:</strong> En curso (8 tomos publicados)",
    ),
    'predicador' => array(
        'lead' => "<strong>Predicador</strong> (<em>Preacher</em>), la irreverente, salvaje y galardonada obra maestra de <strong>Garth Ennis</strong> y <strong>Steve Dillon</strong> para el sello Vertigo de DC Comics (Premio Eisner), es uno de los mayores hitos del cómic de los noventa. En un pequeño pueblo de Texas, el reverendo desilusionado <strong>Jesse Custer</strong> es poseído por Génesis, una entidad sobrenatural nacida de la unión prohibida entre un ángel y un demonio que le otorga «La Palabra de Dios»: la capacidad de obligar a cualquiera a obedecer sus mandatos. Al descubrir que Dios ha abandonado su trono en el cielo, Jesse emprenderá un sangriento viaje por Estados Unidos junto a su exnovia Tulip y el vampiro irlandés Cassidy para obligar al Creador a rendir cuentas.\nUna road-movie hiperviolenta de sátira religiosa, lealtad y humor negro que redefinió el cómic adulto para siempre.\nConsigue la colección de <strong>Predicador en español</strong> por <strong>Panini Comics España</strong> en <strong>Akibara</strong>, con envíos a todo Chile.",
        'bullets' => "<strong>Guion:</strong> Garth Ennis<br />\n<strong>Dibujo:</strong> Steve Dillon<br />\n<strong>Línea original:</strong> DC Comics / Vertigo (EE.UU.)<br />\n<strong>Editorial oficial:</strong> Panini España (España)<br />\n<strong>Estado de la obra:</strong> Finalizada (66 números originales)<br />\n<strong>Estado de la edición:</strong> Completa (3 tomos)",
    ),
    'from-hell' => array(
        'lead' => "<strong>From Hell</strong> (<em>Desde el infierno</em>), la monumental e intimidante obra maestra de novela gráfica histórica concebida por el genio del guion <strong>Alan Moore</strong> (autor de <em>Watchmen</em> y <em>V de Vendetta</em>) con el sobrecogedor arte en tinta y carboncillo de <strong>Eddie Campbell</strong>, es un hito de la literatura moderna. A lo largo de diez años de rigurosa investigación documental, Moore disecciona los asesinatos en serie de Whitechapel en 1888 atribuidos a <strong>Jack el Destripador</strong>, tejiendo una oscura conspiración masónica ligada a la Casa Real británica y el médico sir William Gull como el nacimiento simbólico del siglo XX.\nGalardonada con los mayores reconocimientos de la crítica internacional, incluye un exhaustivo apéndice de notas históricas de Moore.\nCompra <strong>From Hell en español</strong> en su prestigiosa edición de <strong>Planeta Cómic</strong> en <strong>Akibara</strong>, con despacho seguro a todo Chile.",
        'bullets' => "<strong>Guion:</strong> Alan Moore<br />\n<strong>Dibujo:</strong> Eddie Campbell<br />\n<strong>Línea original:</strong> Top Shelf Productions (EE.UU. / Reino Unido)<br />\n<strong>Editorial oficial:</strong> Planeta Cómic (España)<br />\n<strong>Estado de la obra:</strong> Novela gráfica autoconclusiva<br />\n<strong>Estado de la edición:</strong> Completa (1 tomo)",
    ),
    'saga-integral' => array(
        'lead' => "<strong>Saga</strong>, la deslumbrante epopeya espacial y fantasía dramática creada por el guionista <strong>Brian K. Vaughan</strong> y la virtuosa ilustradora <strong>Fiona Staples</strong> para Image Comics (ganadora de múltiples Premios Eisner y Harvey), es una de las obras cumbres del cómic independiente internacional. En una galaxia desgarrada por una guerra infinita entre dos civilizaciones enemigas —el planeta tecnológicamente avanzado Terrada y su satélite mágico Guirnalda—, dos soldados desertores de bandos opuestos, <strong>Alana</strong> y <strong>Marko</strong>, se enamoran y dan a luz a su hija híbrida <strong>Hazel</strong>, debiendo huir de cazarrecompensas interestelares y ejércitos para proteger su hogar.\nPresentada en tomos integrales de tapa dura con un despliegue cromático deslumbrante, es una odisea conmovedora e imprescindible.\nCompra <strong>Saga en español</strong> en su edición de <strong>Planeta Cómic</strong> en <strong>Akibara</strong>, con despacho seguro a todo Chile.",
        'bullets' => "<strong>Guion:</strong> Brian K. Vaughan<br />\n<strong>Dibujo:</strong> Fiona Staples<br />\n<strong>Línea original:</strong> Image Comics (EE.UU.)<br />\n<strong>Editorial oficial:</strong> Planeta Cómic (España)<br />\n<strong>Estado de la obra:</strong> En curso en EE.UU.<br />\n<strong>Estado de la edición:</strong> En pausa (3 tomos publicados)",
    ),
    'la-cosa-del-pantano-de-alan-moore' => array(
        'lead' => "<strong>La Cosa del Pantano de Alan Moore</strong> (<em>Saga of the Swamp Thing</em>) es la legendaria etapa que revolucionó el cómic estadounidense de terror y fantasía en DC Comics, catapultando la carrera de <strong>Alan Moore</strong> al estrellato internacional. A partir del mítico número 21 («La lección de anatomía»), Moore reinventa por completo el origen del monstruo: el botánico Alec Holland no es un hombre transformado en planta, sino una conciencia vegetal elemental que cree haber sido humana. Con una prosa poética y temas de ecología profunda, amor y horror cósmico, Moore expande las posibilidades expresivas del cómic.\nCon el dibujo atmosférico de Stephen Bissette y John Totleben, es una obra maestra de valor histórico incalculable.\nCompra la colección de <strong>La Cosa del Pantano en español</strong> por <strong>Panini Comics España</strong> en <strong>Akibara</strong>, con despacho a todo Chile.",
        'bullets' => "<strong>Guion:</strong> Alan Moore<br />\n<strong>Dibujo:</strong> Stephen R. Bissette, John Totleben<br />\n<strong>Línea original:</strong> DC Comics / Vertigo (EE.UU.)<br />\n<strong>Editorial oficial:</strong> Panini España (España)<br />\n<strong>Estado de la obra:</strong> Etapa completa<br />\n<strong>Estado de la edición:</strong> En curso (3 tomos publicados)",
    ),
);

echo "Actualizando descripciones en pa_serie...\n";
$updated = 0;

foreach ($updates as $slug => $data) {
    $term = $wpdb->get_row($wpdb->prepare(
        "SELECT t.term_id, tt.term_taxonomy_id FROM {$wpdb->terms} t
         JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
         WHERE tt.taxonomy = 'pa_serie' AND t.slug = %s",
        $slug
    ));

    if (!$term) {
        echo "  [SKIP] Serie no encontrada: {$slug}\n";
        continue;
    }

    $full_desc = trim($data['lead']) . "\n\n" . trim($data['bullets']);

    $res = $wpdb->update(
        $wpdb->term_taxonomy,
        array('description' => $full_desc),
        array('term_taxonomy_id' => $term->term_taxonomy_id),
        array('%s'),
        array('%d')
    );

    if ($res !== false) {
        echo "  [OK] Actualizada ficha técnica de: {$slug}\n";
        $updated++;
    } else {
        echo "  [ERROR] Falló actualización de: {$slug}\n";
    }
}

echo "Total series actualizadas: {$updated}/" . count($updates) . "\n";
