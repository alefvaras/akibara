#!/usr/bin/env python3
"""
Normalización crítica de fichas de producto en Akibara.
Elimina encabezados H3 artificiales, corrige contenido de Invencible 04 (Guerra Viltrumita),
limpia artefactos en Tokyo Ghoul y The Walking Dead (05, 06, 07), estandariza enlaces internos SEO
y asegura consistencia editorial completa tanto en Local Docker como en Producción.
"""

# Mapeo de contenidos canónicos limpios y libres de H3 / enlaces externos / artefactos
CLEAN_DESCRIPTIONS = {
    "The Walking Dead 05": {
        "h2": "The Walking Dead Libro 5: La llegada de Negan y la Guerra Total",
        "content": """<h2>The Walking Dead Libro 5: La llegada de Negan y la Guerra Total</h2>

<p><strong>The Walking Dead Libro 5</strong> reúne los números 97 al 120 de la serie creada por <strong>Robert Kirkman</strong> y <strong>Charlie Adlard</strong>. La aparente estabilidad de la Zona Segura de Alexandria tras el asedio de los caminantes se desmorona de forma irreversible cuando Rick Grimes y su grupo entran en contacto con la Colonia Hilltop. Lo que parecía un acuerdo pacífico de intercambio comercial revela una realidad mucho más oscura: el sometimiento bajo el puño de hierro de los Salvadores, un implacable ejército de extorsionadores liderado por el carismático y brutal <strong>Negan</strong> y su bate de béisbol envuelto en alambre de espino, <strong>Lucille</strong>.</p>

<p>Golpeado por la culpa y forzado a arrodillarse ante la tiranía de Negan para proteger a su comunidad, Rick Grimes comprende pronto que la sumisión conducirá a la aniquilación gradual de Alexandria. En el más absoluto secreto, Rick comienza a forjar una alianza de resistencia junto a Maggie en Hilltop y el Rey Ezekiel en El Reino, preparando el terreno para el conflicto militar más grande de la historia del cómic: <strong>All Out War (Guerra Total)</strong>.</p>

<p>Por primera vez en la serie, los supervivientes no combaten únicamente contra muertos vivientes, sino en una guerra táctica con armas de fuego, emboscadas y asedios a fortalezas humanas donde cada decisión carga un peso mortal.</p>

<p><strong>Edición Planeta Cómic, edición integral</strong> · Cartoné · 16.8×25.7 cm · 560 páginas a color y blanco y negro · Recopila los números 97 a 120 de <em>The Walking Dead</em> (Image Comics, 2012 a 2014) · Guion de <strong>Robert Kirkman</strong> · Arte de <strong>Charlie Adlard</strong> · Recomendado +18 años · Envío a todo Chile desde Akibara.</p>

<p class="akb-pdp-seo-links">Puedes explorar la colección completa en nuestra sección de <a href="/serie/the-walking-dead/">The Walking Dead</a> y descubrir todos los títulos de <a href="/marca/planeta-espana/">Planeta Cómic</a> disponibles en Akibara con despacho rápido a todo Chile.</p>""",
        "excerpt": "The Walking Dead Libro 5 en edición integral cartoné Planeta Cómic. Recopila los números 97 a 120 de la serie de Robert Kirkman y Charlie Adlard. Envío a todo Chile."
    },

    "The Walking Dead 06": {
        "h2": "The Walking Dead Libro 6: Un Nuevo Comienzo y los Susurradores",
        "content": """<h2>The Walking Dead Libro 6: Un Nuevo Comienzo y los Susurradores</h2>

<p><strong>The Walking Dead Libro 6</strong> reúne los números 121 al 144 de la saga postapocalíptica de <strong>Robert Kirkman</strong> y <strong>Charlie Adlard</strong>. El volumen comienza con la conclusión definitiva de <strong>Guerra Total</strong>, donde la alianza entre Alexandria, Hilltop y El Reino lanza su ofensiva final contra el santuario de los Salvadores. En el clímax de la batalla, Rick logra someter personalmente a Negan y toma una decisión histórica: encarcelarlo de por vida en lugar de ejecutarlo, sentando las bases morales de una nueva civilización.</p>

<p>La historia da un audaz salto temporal hacia el arco <strong>Un nuevo comienzo</strong>. Las comunidades han prosperado restableciendo redes de comercio, agricultura y herrería, y un Carl más maduro comienza a forjar su propio camino. Sin embargo, la paz se quiebra con la aparición de una amenaza inédita: <strong>Los Susurradores</strong>, humanos nómadas liderados por <strong>Alpha</strong> y <strong>Beta</strong> que visten pieles de caminantes para camuflarse entre las hordas y defienden una filosofía salvaje que rechaza la civilización.</p>

<p>El tomo culmina en las sobrecogedoras páginas del emblemático número 144, donde Alpha marca una frontera infranqueable entre ambos mundos con una hilera de cabezas decapitadas en picas, dejando a las comunidades al borde de un abismo emocional irreversible.</p>

<p><strong>Edición Planeta Cómic, edición integral</strong> · Cartoné · 16.8×25.7 cm · 560 páginas a color y blanco y negro · Recopila los números 121 a 144 de <em>The Walking Dead</em> (Image Comics, 2014 a 2015) · Guion de <strong>Robert Kirkman</strong> · Arte de <strong>Charlie Adlard</strong> · Recomendado +18 años · Envío a todo Chile desde Akibara.</p>

<p class="akb-pdp-seo-links">Puedes explorar la colección completa en nuestra sección de <a href="/serie/the-walking-dead/">The Walking Dead</a> y descubrir todos los títulos de <a href="/marca/planeta-espana/">Planeta Cómic</a> disponibles en Akibara con despacho rápido a todo Chile.</p>""",
        "excerpt": "The Walking Dead Libro 6 en edición integral cartoné Planeta Cómic. Recopila los números 121 a 144 de la serie de Robert Kirkman y Charlie Adlard. Envío a todo Chile."
    },

    "The Walking Dead 07": {
        "h2": "The Walking Dead Libro 7: La Guerra de los Susurradores y A Certain Doom",
        "content": """<h2>The Walking Dead Libro 7: La Guerra de los Susurradores y A Certain Doom</h2>

<p><strong>The Walking Dead Libro 7</strong> reúne los números 145 al 168 de la épica creación de <strong>Robert Kirkman</strong> y <strong>Charlie Adlard</strong>. Tras el descubrimiento de la macabra frontera de cabezas en picas colocada por Alpha, el dolor y la sed de venganza sacuden a los habitantes de Alexandria, Hilltop y El Reino. Consciente de que un ataque desorganizado contra una horda de miles de caminantes significaría la muerte de todos, Rick Grimes moviliza a sus mejores aliados para formar un ejército disciplinado.</p>

<p>La tensión estalla en el arco <strong>The Whisperer War (La Guerra de los Susurradores)</strong>. Con la intervención imprevista de Negan en el conflicto y la furia desatada de Beta tras la caída de Alpha, las comunidades enfrentan una ofensiva militar brutal donde las lealtades se ponen a prueba como nunca antes.</p>

<p>El tomo culmina en el devastador clímax de <strong>A Certain Doom</strong>, donde la horda más descomunal jamás vista es dirigida contra los muros de Alexandria, forzando a Rick y a sus compañeros a librar una defensa desesperada que cobrará sacrificios desgarradores en el corazón del relato.</p>

<p><strong>Edición Planeta Cómic, edición integral</strong> · Cartoné · 16.8×25.7 cm · 560 páginas a color y blanco y negro · Recopila los números 145 a 168 de <em>The Walking Dead</em> (Image Comics, 2015 a 2017) · Guion de <strong>Robert Kirkman</strong> · Arte de <strong>Charlie Adlard</strong> · Recomendado +18 años · Envío a todo Chile desde Akibara.</p>

<p class="akb-pdp-seo-links">Puedes explorar la colección completa en nuestra sección de <a href="/serie/the-walking-dead/">The Walking Dead</a> y descubrir todos los títulos de <a href="/marca/planeta-espana/">Planeta Cómic</a> disponibles en Akibara con despacho rápido a todo Chile.</p>""",
        "excerpt": "The Walking Dead Libro 7 en edición integral cartoné Planeta Cómic. Recopila los números 145 a 168 de la serie de Robert Kirkman y Charlie Adlard. Envío a todo Chile."
    },

    "Invencible 04": {
        "h2": "Invencible Vol. 4: La Guerra Viltrumita",
        "content": """<h2>Invencible Vol. 4: La Guerra Viltrumita</h2>

<p><strong>Invencible 04</strong> representa el punto culminante de la monumental saga de superhéroes creada por <strong>Robert Kirkman</strong>, <strong>Ryan Ottley</strong> y <strong>Cory Walker</strong>. En este volumen, Mark Grayson deja atrás los conflictos locales de la Tierra para embarcarse en la misión más peligrosa de su vida: un viaje a los confines del cosmos junto a su padre <strong>Nolan (Omni-Man)</strong>, su hermano <strong>Oliver</strong> y <strong>Allen el Alien</strong> con el objetivo de unir a la Coalición de Planetas Libres.</p>

<p>La confrontación total contra el temible <strong>Imperio Viltrumita</strong> liderado por el <strong>Gran Regente Thragg</strong> y el despiadado <strong>Conquest</strong> desata la legendaria <strong>Guerra Viltrumita</strong>. A través de batallas a escala planetaria de una ferocidad inaudita, Kirkman y Ottley entregan páginas dobles inolvidables que redefinen para siempre el género superheroico y el destino del universo.</p>

<p>Las secuelas físicas y emocionales de la guerra obligan a Mark a replantearse el costo de la victoria, las consecuencias de su moralidad y el futuro de su relación con Atom Eve en un mundo que no volverá a ser el mismo.</p>

<p><strong>Edición Planeta Cómic, edición integral</strong> · Cartoné · 16.8×25.7 cm · 672 páginas a color · Recopila los números 71 a 96 de <em>Invincible</em> (Image Comics, 2010 a 2012) · Guion de <strong>Robert Kirkman</strong> · Arte de <strong>Ryan Ottley</strong> y <strong>Cory Walker</strong> · Recomendado +16 años (violencia gráfica intensa) · Envío a todo Chile desde Akibara.</p>

<p class="akb-pdp-seo-links">Puedes explorar la colección completa en nuestra sección de <a href="/serie/invencible/">Invencible</a> y descubrir todos los títulos de <a href="/marca/planeta-espana/">Planeta Cómic</a> disponibles en Akibara con despacho rápido a todo Chile.</p>""",
        "excerpt": "Invencible 04 en edición integral cartoné Planeta Cómic. Recopila los números 71 a 96 de la serie de Robert Kirkman y Ryan Ottley: La Guerra Viltrumita. Envío a todo Chile."
    },

    "Tokyo Ghoul 01": {
        "h2": "Tokyo Ghoul 01 — El nacimiento de un híbrido entre humano y ghoul",
        "content": """<h2>Tokyo Ghoul 01 — El nacimiento de un híbrido entre humano y ghoul</h2>

<p><strong>Tokyo Ghoul 01</strong> inicia la aclamada obra de <strong>Sui Ishida</strong>, uno de los mangas seinen de terror psicológico y acción sobrenatural más influyentes de las últimas décadas. En Tokio, una serie de asesinatos cometidos por ghouls —seres que solo pueden alimentarse de carne humana— aterroriza a la población. Ken Kaneki, un tímido estudiante universitario, conoce en una cafetería a Rize, una joven con sus mismos intereses literarios. Sin embargo, su cita da un giro mortal cuando ella revela su verdadera naturaleza ghoul y ataca a Kaneki, dejándolo al borde de la muerte.</p>

<p>Tras un milagroso trasplante de órganos de emergencia con partes de Rize, Kaneki despierta transformado en el primer híbrido humano-ghoul. Incapaz de tolerar la comida normal y aterrado por sus nuevos instintos carnívoros, encuentra refugio en <strong>Anteiku</strong>, una peculiar cafetería atendida por ghouls que intentan convivir pacíficamente con la sociedad humana.</p>

<p>Este volumen reúne los capítulos 1 al 9 de la serie original, profundizando en el trauma inicial de Kaneki y el conflicto moral entre ambos mundos.</p>

<p><strong>Edición Ivrea Argentina:</strong> manga en español, demografía seinen, formato B6 con sobrecubierta e inclusión de páginas a color. Serie completa en 14 tomos. ISBN: 9788416905898. Envío a todo Chile desde Akibara.</p>

<p class="akb-pdp-seo-links">Puedes explorar la colección completa en nuestra sección de <a href="/serie/tokyo-ghoul/">Tokyo Ghoul</a> y descubrir todos los títulos de <a href="/marca/ivrea-argentina/">Ivrea Argentina</a> disponibles en Akibara con despacho rápido a todo Chile.</p>""",
        "excerpt": "Tokyo Ghoul 01 de Sui Ishida, edición Ivrea Argentina. Manga seinen en español, formato B6 con sobrecubierta y páginas a color. Envío a todo Chile."
    },

    "Tokyo Ghoul 07": {
        "h2": "Tokyo Ghoul 07 — La tortura de Yamori y el despertar del Ghoul de un Ojo",
        "content": """<h2>Tokyo Ghoul 07 — La tortura de Yamori y el despertar del Ghoul de un Ojo</h2>

<p><strong>Tokyo Ghoul 07</strong> alcanza el punto de inflexión más dramático y celebrado de la historia creada por <strong>Sui Ishida</strong>. Kaneki cae en manos de Yamori (Jason), el despiadado ejecutor de la organización Aogiri Tree, y es sometido en la temible sala de torturas a un tormento físico y psicológico que lleva su cordura al límite absoluto.</p>

<p>En medio del sufrimiento extremo y guiado por las apariciones de Rize en su mente, Kaneki se enfrenta a la debilidad de sus ideales pacifistas. Para proteger a quienes ama y sobrevivir a la barbarie, decide abrazar plenamente su condición de ghoul, desatando una transformación psicológica y física que culmina en una de las batallas más icónicas del manga contemporáneo.</p>

<p>Este volumen compila los capítulos 59 al 68 de la serie original, marcando un antes y un después en la evolución del protagonista.</p>

<p><strong>Edición Ivrea Argentina:</strong> manga en español, demografía seinen, formato B6 con sobrecubierta e inclusión de páginas a color. Serie completa en 14 tomos. ISBN: 9788417099930. Envío a todo Chile desde Akibara.</p>

<p class="akb-pdp-seo-links">Puedes explorar la colección completa en nuestra sección de <a href="/serie/tokyo-ghoul/">Tokyo Ghoul</a> y descubrir todos los títulos de <a href="/marca/ivrea-argentina/">Ivrea Argentina</a> disponibles en Akibara con despacho rápido a todo Chile.</p>""",
        "excerpt": "Tokyo Ghoul 07 de Sui Ishida, edición Ivrea Argentina. Manga seinen en español, formato B6 con sobrecubierta y páginas a color. Envío a todo Chile."
    },

    "Tokyo Ghoul 09": {
        "h2": "Tokyo Ghoul 09 — La caza de Kanou y los secretos del CCG",
        "content": """<h2>Tokyo Ghoul 09 — La caza de Kanou y los secretos del CCG</h2>

<p><strong>Tokyo Ghoul 09</strong> prosigue la historia de <strong>Sui Ishida</strong> tras los devastadores acontecimientos de Aogiri Tree y la batalla del distrito 11. Con su nueva determinación y fuerza, Kaneki lidera a su propio grupo independiente en una peligrosa investigación para descubrir la verdad detrás de los experimentos clandestinos del Dr. Kanou y el origen del trasplante de Rize.</p>

<p>Mientras tanto, la Comisión de Contramedidas Ghoul (CCG), con investigadores de élite como Koutarou Amon y Juzo Suzuya, intensifica la búsqueda de ghouls de alto rango, convergiendo en un choque subterráneo de intereses donde las líneas entre humanos y monstruos se vuelven cada vez más difusas.</p>

<p>Este volumen compila los capítulos 80 al 89 de la serie original, ampliando los misterios que rodean a la organización Anteiku y al CCG.</p>

<p><strong>Edición Ivrea Argentina:</strong> manga en español, demografía seinen, formato B6 con sobrecubierta e inclusión de páginas a color. Serie completa en 14 tomos. ISBN: 9788417179571. Envío a todo Chile desde Akibara.</p>

<p class="akb-pdp-seo-links">Puedes explorar la colección completa en nuestra sección de <a href="/serie/tokyo-ghoul/">Tokyo Ghoul</a> y descubrir todos los títulos de <a href="/marca/ivrea-argentina/">Ivrea Argentina</a> disponibles en Akibara con despacho rápido a todo Chile.</p>""",
        "excerpt": "Tokyo Ghoul 09 de Sui Ishida, edición Ivrea Argentina. Manga seinen en español, formato B6 con sobrecubierta y páginas a color. Envío a todo Chile."
    },

    "Tokyo Ghoul 10": {
        "h2": "Tokyo Ghoul 10 — El laboratorio subterráneo y la locura del ciempiés",
        "content": """<h2>Tokyo Ghoul 10 — El laboratorio subterráneo y la locura del ciempiés</h2>

<p><strong>Tokyo Ghoul 10</strong> lleva la tensión a límites insospechados cuando la búsqueda de respuestas conduce a Kaneki y a sus aliados al laboratorio subterráneo secreto del Dr. Kanou. Allí descubren los siniestros experimentos de creación artificial de ghouls de un solo ojo, desvelando una conspiración que sacude los cimientos de la sociedad.</p>

<p>En el caos de la incursión y enfrentado a viejos enemigos y a las fuerzas del CCG, Kaneki recurre a su kakuja incompleto, desatando una forma monstruosa con apariencia de ciempiés que amenaza con devorar su propia conciencia y desatar una violencia incontrolable.</p>

<p>Este volumen compila los capítulos 90 al 100 de la serie original, ofreciendo momentos culminantes de terror psicológico y combates frenéticos.</p>

<p><strong>Edición Ivrea Argentina:</strong> manga en español, demografía seinen, formato B6 con sobrecubierta e inclusión de páginas a color. Serie completa en 14 tomos. ISBN: 9788417179984. Envío a todo Chile desde Akibara.</p>

<p class="akb-pdp-seo-links">Puedes explorar la colección completa en nuestra sección de <a href="/serie/tokyo-ghoul/">Tokyo Ghoul</a> y descubrir todos los títulos de <a href="/marca/ivrea-argentina/">Ivrea Argentina</a> disponibles en Akibara con despacho rápido a todo Chile.</p>""",
        "excerpt": "Tokyo Ghoul 10 de Sui Ishida, edición Ivrea Argentina. Manga seinen en español, formato B6 con sobrecubierta y páginas a color. Envío a todo Chile."
    }
}
