/**
 * Mide en el /tienda/ SERVIDO cuántas portadas del primer pliegue móvil
 * quedan con `loading="lazy"`. El viewport es el de la medición del gate:
 * 390x844 (iPhone 14/15), donde la 4ª portada entra ~20px al pliegue.
 */
import { chromium } from 'playwright';

const navegador = await chromium.launch();
const pagina = await navegador.newPage( { viewport: { width: 390, height: 844 } } );
await pagina.goto( 'http://localhost:8080/tienda/', { waitUntil: 'load' } );

const filas = await pagina.evaluate( () => {
	const imgs = [ ...document.querySelectorAll( 'ul.products img, .products img' ) ];
	return imgs.slice( 0, 8 ).map( ( img, i ) => {
		const r = img.getBoundingClientRect();
		return {
			n: i + 1,
			top: Math.round( r.top + window.scrollY ),
			alto: Math.round( r.height ),
			loading: img.getAttribute( 'loading' ) || '(sin atributo)',
			fetchpriority: img.getAttribute( 'fetchpriority' ) || '-',
			alt: ( img.getAttribute( 'alt' ) || '' ).slice( 0, 28 ),
		};
	} );
} );

console.log( 'viewport 390x844 — pliegue en y=844\n' );
for ( const f of filas ) {
	const dentro = f.top < 844;
	console.log(
		`#${ f.n }  top=${ String( f.top ).padStart( 4 ) }  alto=${ f.alto }  loading=${ f.loading.padEnd( 14 ) } fetchpriority=${ f.fetchpriority }  ${ dentro ? 'DENTRO del pliegue' : 'bajo el pliegue' }  ${ f.alt }`
	);
}

const dentroLazy = filas.filter( ( f ) => f.top < 844 && f.loading === 'lazy' );
console.log( `\nPortadas dentro del pliegue con loading="lazy": ${ dentroLazy.length }` );
await navegador.close();
process.exit( dentroLazy.length === 0 ? 0 : 1 );
