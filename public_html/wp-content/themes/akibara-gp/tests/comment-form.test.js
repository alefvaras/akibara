'use strict';

const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const test = require('node:test');

const themeRoot = join(__dirname, '..');
const read = (relative) => readFileSync(join(themeRoot, relative), 'utf8');

const php = read('inc/blog.php');
const css = read('assets/css/blog.css');

test('las etiquetas visibles del formulario de comentarios llevan clase propia', () => {
	// El tema intentó revelar las etiquetas pisando `.screen-reader-text` con
	// `position: static`, y perdía contra el `position: absolute !important` de
	// `wc-blocks.css`: las cuatro quedaban ENCIMA de su campo, en la misma
	// esquina, y el texto tecleado se solapaba con el de la etiqueta en las 33
	// entradas del blog [QA 2026-08-16]. Reutilizar una clase de terceros para
	// algo que debe verse es la trampa; la clase propia la desactiva.
	assert.match(
		php,
		/akb-comment-label/,
		'el filtro debe reemplazar la clase de utilidad por una propia'
	);
	assert.match(
		css,
		/\.akb-post__comments \.comment-form label\.akb-comment-label \{/,
		'y el CSS debe estilar esa clase propia'
	);
	assert.doesNotMatch(
		css,
		/\.akb-post__comments \.comment-form label\.screen-reader-text \{/,
		'no debe quedar el hack sobre .screen-reader-text: es la pelea de especificidad que ya se perdió una vez'
	);
});

test('el textarea del comentario recibe el mismo trato que los demás campos', () => {
	// `comment_field` NO viaja en `comment_form_default_fields`: se quedaba con
	// la etiqueta encima del área de texto —el campo más grande y el único
	// imprescindible— mientras los otros tres ya estaban corregidos.
	assert.match(
		php,
		/add_filter\( 'comment_form_defaults', 'akibara_blog_comment_textarea_label'/,
		'debe existir el filtro que alcanza al textarea'
	);
	assert.match(
		php,
		/add_filter\( 'comment_form_default_fields', 'akibara_blog_comment_fields_without_placeholder'/,
		'y el que alcanza a nombre, correo y web'
	);
	// Ambos caminos deben pasar por la MISMA preparación, o uno se quedará atrás
	// la próxima vez que se toque.
	const usos = php.match(/akibara_blog_prepare_comment_field\(/g) || [];
	assert.ok(
		usos.length >= 3,
		'los dos filtros deben delegar en la función común de preparación'
	);
});

test('el placeholder se retira sin morder atributos vecinos', () => {
	// `\s*` no exige frontera y parte `data-placeholder` por la mitad, dejando
	// un `data-` suelto en el HTML (reproducido con PHP).
	assert.match(
		php,
		/'\/\\s\+placeholder=/,
		'el patrón debe exigir el espacio previo con \\s+, no \\s*'
	);
	assert.doesNotMatch(
		php,
		/'\/\\s\*placeholder=/,
		'\\s* vuelve a corromper data-placeholder / aria-placeholder'
	);
});

test('el salto del índice deja el título visible y el scroll-spy alineado', () => {
	const blogCss = read('assets/css/blog.css');
	const tocJs = read('assets/js/page-toc.js');

	// `html { scroll-padding-top: 78px }` ya compensa el header sticky. Sumar
	// otros 132 dejaba el título destino a 210 px del borde: un cuarto de la
	// pantalla móvil ocupado por el final de la sección anterior [QA 2026-08-16].
	// Los comentarios se quitan ANTES de aislar la regla: el de esta misma
	// declaración contiene una llave (`html { scroll-padding-top: 78px }`) y
	// cortaba el bloque por la mitad.
	const sinComentarios = blogCss.replace(/\/\*[\s\S]*?\*\//g, '');
	const regla = sinComentarios.match(
		/\.akb-post__content :is\(h2, h3\)\[id\] \{([\s\S]*?)\}/
	);
	assert.ok(regla, 'debe existir la regla de anclas del contenido');
	const valor = regla[1].match(/scroll-margin-top:\s*(\d+)px/);
	assert.ok(valor, 'la regla debe declarar scroll-margin-top');
	assert.ok(
		Number(valor[1]) <= 48,
		`scroll-margin-top de ${valor[1]}px se suma al scroll-padding-top del html y hunde el destino`
	);

	// El acoplamiento: la banda activa del scroll-spy tiene que empezar por
	// encima de donde aterriza el título, o el índice resalta la sección
	// anterior a la que el lector acaba de pedir.
	const banda = tocJs.match(/rootMargin:\s*'(-?\d+)px/);
	assert.ok(banda, 'page-toc.js debe declarar el rootMargin superior');
	const aterriza = 78 + Number(valor[1]);
	assert.ok(
		Math.abs(Number(banda[1])) <= aterriza,
		`la banda empieza en ${banda[1]}px pero el título aterriza en ${aterriza}px: quedaría fuera`
	);
});
