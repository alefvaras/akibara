// @ts-check
/**
 * Akibara Puntos: lo que la tienda PROMETE en cada pantalla.
 *
 * Los puntos se acreditan al pasar el pedido a «Procesando», y eso se prueba
 * por CLI. Lo que se mira aquí es la promesa: que el número prometido sea
 * coherente entre la ficha, el carrito y la página del programa, porque tres
 * cifras distintas para lo mismo es la forma más rápida de perder la confianza.
 */

import { expect, test } from '@playwright/test';
import { aLaVista } from './soporte/a-la-vista.mjs';
import { AVISO, FALTA_EL_AVISO, PROMESA, seLeeLaPromesa, siDeclaradoApagado } from './soporte/aviso-de-puntos.mjs';
import { productoConBadge } from './soporte/tienda.mjs';

test.describe( 'puntos', () => {

	test( 'la ficha promete puntos con su equivalencia en pesos', { tag: '@prod' }, async ( { page } ) => {
		const url = await productoConBadge( page, 'Disponible' );
		test.skip( ! url, 'sin tomos disponibles en este entorno' );
		await page.goto( String( url ), { waitUntil: 'domcontentloaded' } );
		await siDeclaradoApagado( page );

		// La clase real del aviso (akibara-rewards, PdpNotice). La de antes
		// (`.akb-rw-pdp, [class*="puntos"]`) no encontraba nada y la prueba se
		// saltaba siempre, diciendo que el programa estaba apagado (REV-R-R-3-2).
		//
		// A la vista, y no sólo presente: innerText() de Chromium devuelve el
		// texto de un aviso plegado (height:0) o con display:none
		// (REV-F-R-rev1-2), y toBeVisible() da por visible uno con opacity:0,
		// transparente, fuera de la pantalla o recortado a 1x1 (REV-F-R-rev2-1).
		// En los dos casos la promesa salía en verde sin que el cliente la viera.
		const aviso = page.locator( AVISO ).first();
		await aLaVista( aviso, FALTA_EL_AVISO );

		// El dueño pidió la equivalencia explícita: «480 Puntos Akibara = $480».
		const texto = await aviso.innerText();
		expect( texto, 'sin la equivalencia en pesos el número no significa nada para el cliente' ).toMatch( PROMESA );
		// Y se lee entera: el aviso a la vista no basta si la promesa, que va en
		// su propio <strong>, se esconde sola (REV-F-R-3-1, REV-M-R-2-1 a 2-3), y
		// innerText la da completa aunque el cliente lea «480 Punt…» (REV-Z-R-1o-1).
		await seLeeLaPromesa( page );
	} );

	test( 'la portada anuncia el programa con la equivalencia', { tag: '@prod' }, async ( { page } ) => {
		await page.goto( '/', { waitUntil: 'domcontentloaded' } );

		const cuerpo = await page.locator( 'body' ).innerText();
		test.skip( ! cuerpo.includes( 'Akibara Puntos' ), 'el programa no se anuncia en la portada de este entorno' );

		// La equivalencia es lo que convierte un número en una promesa: «1 punto
		// = $1 en tu próxima compra». Sin ella, «ganas 480 puntos» no dice nada.
		expect( cuerpo ).toMatch( /1\s*(punto|pt)\w*\s*=\s*\$\s*1/i );
	} );

	test( 'el sello de la portada conserva la equivalencia también en móvil', async ( { page } ) => {
		// Encontrado por este mismo carril el 2026-09-18: por debajo de 480 px
		// el CSS escondía la segunda línea del sello, así que en el ancho donde
		// está la mayoría del tráfico se leía «Akibara Puntos» a secas — sin lo
		// único que le da sentido al número.
		await page.goto( '/', { waitUntil: 'domcontentloaded' } );

		const sello = page.locator( '.akb-trust-item', { hasText: 'Akibara Puntos' } ).first();
		test.skip( ! ( await sello.count() ), 'este entorno no pinta el sello de puntos' );

		await expect( sello ).toBeVisible();
		await expect( sello ).toContainText( /1\s*punto\s*=\s*\$\s*1/i );

		// Y sin cortar el texto ni empujar la página de lado.
		expect(
			await sello.evaluate( ( el ) => el.scrollHeight > el.clientHeight + 1 ),
			'el sello no puede mostrar la frase a medias'
		).toBe( false );
		expect(
			await page.evaluate( () => document.documentElement.scrollWidth > window.innerWidth + 1 ),
			'mostrar la segunda línea no puede provocar desplazamiento horizontal'
		).toBe( false );
	} );

	test( 'la página del programa carga y explica cómo se ganan', async ( { page } ) => {
		const respuesta = await page.goto( '/akibara-puntos/', { waitUntil: 'domcontentloaded' } );
		test.skip( 404 === respuesta?.status(), 'este entorno no publica la página del programa' );

		expect( respuesta?.status() ).toBe( 200 );
		const cuerpo = await page.locator( 'body' ).innerText();
		expect( cuerpo ).toMatch( /gana|acumula|canje/i );
	} );
} );
