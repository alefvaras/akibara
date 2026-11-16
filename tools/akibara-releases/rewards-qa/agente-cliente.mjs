// AGENTE 1 — CLIENTE (gate qa-rewards, orden del dueño 2026-08-31).
// Emula a una persona real en móvil (375x812, touch): busca un tomo, crea
// cuenta con cumpleaños, ve la promesa de puntos en la ficha, compra, cobra
// sus puntos, canjea en el checkout y reseña por el enlace de token.
//
// Todo lo que crea lo BORRA al final: usuario, pedido (y borradores del
// checkout), las NOTAS del pedido (comentarios que `$order->delete( true )`
// deja huérfanos), ledger, reseña, token — y REPONE el stock que la compra
// consumió, porque borrar un pedido no lo devuelve. El espejo local queda
// como estaba: si no, el siguiente agente mide sobre basura del anterior, y
// a la tercera vuelta el tomo queda agotado y el gate falla sin culpa del
// código.
//
// Selectores y validadores verificados contra el Docker local antes de
// escribir esto. Los tres puntos donde el contrato no sobrevivió al contacto
// con el sitio real van marcados «DESVÍO» con su porqué.
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const BASE = 'http://localhost:8080';
const CWD  = '/Users/alefvaras/Documents/akibara';
const DIR  = '/Users/alefvaras/Documents/akibara/tools/akibara-releases/rewards-qa';
const MAILPIT = 'http://localhost:8025/api/v1';

const SELLO    = Date.now();
const EMAIL    = `qa-cliente-${SELLO}@akibara.cl`;
const LOGIN    = `qacliente${SELLO}`;
const CLAVE    = 'QaCliente!2026';

const PRODUCTO = 27347;                                // $14.000 — el que se compra.
const FICHA    = '/product/frieren-10-ivrea-argentina/'; // La ficha del aviso de puntos.

// DESVÍO 1: el contrato pedía el RUT «11.111.111-1», pero RutValidator lo
// rechaza a propósito (cuerpo de dígitos todos iguales = patrón trivial no
// asignable). «12.345.678-5» tiene DV correcto y pasa el candado del server.
const RUT = '12.345.678-5';

// DESVÍO 2: el contrato pedía el celular «+56 9 1234 5678», que ChileanPhone
// rechaza. «+56 9 8765 4321» es el que el validador da por bueno.
const CELULAR = '+56 9 8765 4321';

// Matemática esperada del saldo tras la primera compra: 2 puntos por cada
// $100 de productos (14.000 → 280) + bono de bienvenida (500) = 780.
const SALDO_ESPERADO = 780;
const CANJE = 300;

const cli = ( cmd ) => execSync(
  `docker compose run --rm -T cli wp ${cmd} --path=/var/www/html`,
  { cwd: CWD, encoding: 'utf8' }
);

// wp-cli mezcla avisos (purgas de LiteSpeed, deprecations de terceros) con lo
// que pedimos: el dato es SIEMPRE la última línea con contenido.
const ultima = ( salida ) => String( salida ).split( '\n' ).map( ( l ) => l.trim() ).filter( Boolean ).pop() || '';
const cliDato = ( cmd ) => ultima( cli( cmd ) );

const run = async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext( {
    viewport: { width: 375, height: 812 },
    isMobile: true,
    hasTouch: true,
  } );
  const p = await ctx.newPage();

  let fallas = 0;
  // Lo que hay que borrar (o reponer) sí o sí al final, pase lo que pase.
  const rastro = { usuario: 0, pedido: 0, comentario: 0, stock: null };

  // Conteo de wp_comments ANTES de tocar nada. Las notas de pedido SON
  // comentarios (`comment_type = order_note`) y HPOS las guarda con
  // `comment_post_ID` = id del pedido: `$order->delete( true )` borra el pedido
  // de las tablas HPOS y NO toca sus notas. Cada corrida del gate dejaba 8
  // huérfanas apuntando a un pedido inexistente, y la limpieza las declaraba
  // «verificada» porque sólo miraba usuario, pedido, stock y reseña.
  const comentariosAntes = ( () => {
    try {
      return parseInt( cliDato( `eval 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" );'` ), 10 );
    } catch ( e ) {
      return -1;
    }
  } )();
  console.log( `comentarios-antes: ${comentariosAntes}` );

  const foto = async ( nombre ) => {
    const limpio = String( nombre ).replace( /[^a-z0-9-]+/gi, '-' ).replace( /^-|-$/g, '' );
    try {
      await p.screenshot( { path: `${DIR}/cliente-${limpio}-${SELLO}.png`, fullPage: true } );
    } catch ( e ) { /* una captura fallida no puede tumbar el gate. */ }
  };

  const check = async ( nombre, ok ) => {
    console.log( `${nombre}: ${ok ? 'OK' : 'FALLA'}` );
    if ( ! ok ) {
      fallas++;
      await foto( nombre );
    }
  };

  // Cada flujo va aislado: si uno revienta, se anota y el recorrido sigue —
  // un gate que se detiene en el primer tropiezo sólo reporta un defecto por
  // vuelta.
  const paso = async ( nombre, fn ) => {
    try {
      await fn();
    } catch ( e ) {
      console.log( `${nombre}: FALLA (${String( e.message ).split( '\n' )[ 0 ].slice( 0, 160 )})` );
      fallas++;
      await foto( nombre );
    }
  };

  // El nonce del Store API viaja en la CABECERA `Nonce` de un GET al carrito,
  // y va atado a la sesión: se pide en el mismo contexto que la escritura.
  const nonceYFetch = ( ruta, opciones ) => p.evaluate( async ( args ) => {
    const cabeza = await fetch( '/wp-json/wc/store/v1/cart', { credentials: 'same-origin', cache: 'no-store' } );
    const nonce = cabeza.headers.get( 'Nonce' );
    const r = await fetch( '/wp-json/wc/store/v1' + args.ruta, Object.assign(
      { credentials: 'same-origin', cache: 'no-store' },
      args.opciones,
      { headers: Object.assign( { 'Content-Type': 'application/json', Nonce: nonce }, ( args.opciones || {} ).headers || {} ) }
    ) );
    let cuerpo = '';
    try { cuerpo = JSON.stringify( await r.json() ).slice( 0, 220 ); } catch ( e ) { cuerpo = '(sin json)'; }
    return { status: r.status, nonce: !! nonce, cuerpo };
  }, { ruta, opciones } );

  const agregarAlCarrito = async () => {
    const r = await nonceYFetch( '/cart/add-item', {
      method: 'POST',
      body: JSON.stringify( { id: PRODUCTO, quantity: 1 } ),
    } );
    if ( r.status !== 201 ) {
      console.log( `carrito-respuesta: ${r.status} ${r.cuerpo}` );
    }
    return r;
  };

  const vaciarCarrito = () => nonceYFetch( '/cart/items', { method: 'DELETE' } );

  // La única lectura fiable de «¿esta pestaña está logueada?»: el bloque de
  // datos que akibara-rewards publica en el carrito del Store API. El DOM de
  // Mi cuenta puede venir de una pintura anterior a la última cookie.
  const sesionActiva = () => p.evaluate( async () => {
    const r = await fetch( '/wp-json/wc/store/v1/cart', { credentials: 'same-origin', cache: 'no-store' } );
    const j = await r.json();
    const ext = ( j.extensions || {} )[ 'akibara-rewards' ] || {};
    return ext.logged_in === true;
  } );

  const correosDe = async ( direccion ) => {
    const r = await fetch( `${MAILPIT}/messages?limit=200` );
    const j = await r.json();
    return ( j.messages || [] ).filter( ( m ) => ( m.To || [] ).some( ( t ) => t.Address === direccion ) );
  };

  try {
    // ------------------------------------------------------------------
    // 1. DESCUBRIMIENTO — el search sheet de la barra inferior.
    // ------------------------------------------------------------------
    await paso( 'descubrimiento', async () => {
      await p.goto( BASE, { waitUntil: 'domcontentloaded' } );

      // El botón «Buscar» del bottom-nav es el ÚLTIMO del documento: el
      // primero es el submit del formulario del drawer.
      await p.locator( 'button:has-text("Buscar")' ).last().click();
      await p.waitForTimeout( 500 );
      await check( 'buscador-sheet-abre', await p.locator( '#akb-mobile-search-input' ).isVisible() );

      // El autocompletado va con debounce + fetch: se ESPERA al nodo que
      // corresponda, nunca un sleep fijo (un 1500 ms a ojo fallaba una de
      // cada tres vueltas sin que hubiera nada roto).
      const esperar = ( selector ) => p.locator( selector ).first()
        .waitFor( { state: 'visible', timeout: 15000 } ).catch( () => {} );

      // Al enfocar, el panel ya trae 5 «Popular: …»: esperar «algún item»
      // daba por buena una lista que todavía no había visto la consulta.
      // Lo que se espera es el item que DICE Frieren.
      await p.locator( '#akb-mobile-search-input' ).fill( 'frieren' );
      const itemFrieren = p.locator( '.akb-suggest-item', { hasText: /Frieren/i } ).first();
      await itemFrieren.waitFor( { state: 'visible', timeout: 15000 } ).catch( () => {} );
      await check( 'buscador-sugiere-frieren', await itemFrieren.isVisible().catch( () => false ) );

      // Consulta sin resultados: ni el drawer ni el listado pueden romperse.
      // El panel puede responder con el vacío seco o con una corrección
      // («quizá quisiste decir»): ambas son respuestas correctas.
      await p.locator( '#akb-mobile-search-input' ).fill( 'zzzqueryqagate' );
      await esperar( '.akb-suggest-empty, .akb-suggest-correction' );
      const avisoVacio = await p.locator( '.akb-suggest-empty, .akb-suggest-correction' ).count();
      await check( 'buscador-sin-resultados-avisa', avisoVacio > 0 );

      const r = await p.goto( `${BASE}/tienda/?_buscar=zzzqueryqagate`, { waitUntil: 'domcontentloaded' } );
      const vacio = await p.locator( '.akb-empty-state' ).first().isVisible().catch( () => false );
      await check( 'tienda-sin-resultados-no-rompe', r.status() === 200 && vacio );
    } );

    // ------------------------------------------------------------------
    // 2. REGISTRO CON CUMPLEAÑOS.
    // ------------------------------------------------------------------
    await paso( 'registro', async () => {
      await p.goto( `${BASE}/mi-cuenta/`, { waitUntil: 'domcontentloaded' } );

      // El bloque de acceso vive dentro de dos <details>: sin abrir el de
      // «Crear cuenta», el formulario está en el DOM pero no es visible.
      await p.locator( 'summary:has-text("Crear cuenta")' ).click().catch( () => {} );
      await p.waitForTimeout( 400 );

      const entrar = async () => {
        await p.goto( `${BASE}/mi-cuenta/`, { waitUntil: 'domcontentloaded' } );
        await p.locator( 'summary:has-text("Iniciar sesión")' ).click().catch( () => {} );
        await p.fill( '#username', EMAIL );
        await p.fill( '#password', CLAVE );
        await Promise.all( [ p.waitForNavigation().catch( () => {} ), p.locator( 'button[name="login"]' ).click() ] );
        await p.waitForTimeout( 800 );
      };

      const campoEmail = p.locator( 'form.register input[name="email"]' );
      const hayForm = ( await campoEmail.count() ) > 0 && await campoEmail.isVisible().catch( () => false );

      if ( ! hayForm ) {
        console.log( 'registro-via-cli (form no disponible)' );
        rastro.usuario = parseInt( cliDato( `user create ${LOGIN} ${EMAIL} --user_pass='${CLAVE}' --role=customer --porcelain` ), 10 );
        cli( `eval 'update_user_meta( ${rastro.usuario}, "_akb_rewards_birthday_md", "03-15" );'` );
        await entrar();
      } else {
        // Los selects de cumpleaños se DESCUBREN del HTML servido y se
        // informan: si el plugin les cambia el name, el log lo dice en vez
        // de fallar con un selector fantasma.
        const nombres = await p.evaluate( () => Array.from(
          document.querySelectorAll( 'form.register select' )
        ).map( ( s ) => s.name ).filter( ( n ) => /^akb_/.test( n ) && /birthday/.test( n ) ) );
        console.log( `registro-selects-cumple: ${nombres.join( ', ' ) || '(ninguno)'}` );
        await check( 'registro-cumple-dia-y-mes', nombres.length === 2 );

        await campoEmail.fill( EMAIL );
        await p.selectOption( 'form.register select[name="akb_rw_birthday_dia"]', '15' );
        await p.selectOption( 'form.register select[name="akb_rw_birthday_mes"]', '3' ); // marzo
        await Promise.all( [
          p.waitForNavigation( { timeout: 45000 } ).catch( () => {} ),
          p.locator( 'form.register button[name="register"]' ).click(),
        ] );
        await p.waitForTimeout( 1200 );

        rastro.usuario = parseInt( cliDato( `eval 'echo (int) email_exists("${EMAIL}");'` ), 10 ) || 0;
        await check( 'registro-crea-usuario', rastro.usuario > 0 );

        const md = cliDato( `eval 'echo get_user_meta( ${rastro.usuario}, "_akb_rewards_birthday_md", true );'` );
        await check( 'registro-guarda-cumple-15-marzo', md === '03-15' );

        // El registro de Woo NO pide contraseña (manda un enlace para
        // crearla), así que la fijamos por CLI para dejar la cuenta del gate
        // con la clave acordada. Cambiar la clave INVALIDA las cookies de
        // sesión: sin volver a entrar, el checkout siguiente se cursa como
        // invitado y el pedido nace sin cliente (cero puntos, cero canje).
        // Ese fue el defecto que costó esta ronda; por eso el re-login.
        if ( rastro.usuario > 0 ) {
          cli( `user update ${rastro.usuario} --user_pass='${CLAVE}'` );
          await entrar();
        }
      }

      await check( 'registro-queda-logueado', ( await p.locator( '.woocommerce-MyAccount-navigation' ).count() ) > 0 );
      await check( 'sesion-viva-en-el-store-api', await sesionActiva() );
    } );

    // ------------------------------------------------------------------
    // 3. FICHA — la promesa de puntos que ve el cliente antes de comprar.
    // ------------------------------------------------------------------
    await paso( 'ficha-aviso-puntos', async () => {
      await p.goto( `${BASE}${FICHA}`, { waitUntil: 'domcontentloaded' } );
      const aviso = p.locator( ':text("Puntos Akibara")' ).first();
      const visible = await aviso.isVisible().catch( () => false );
      await check( 'ficha-aviso-puntos', visible );
      if ( visible ) {
        console.log( `ficha-aviso-texto: ${( await aviso.innerText() ).replace( /\s+/g, ' ' ).trim().slice( 0, 80 )}` );
      }
    } );

    // ------------------------------------------------------------------
    // 4. COMPRA → PUNTOS.
    // ------------------------------------------------------------------
    await paso( 'compra', async () => {
      // Comprar CONSUME stock, y borrar el pedido no lo repone: se anota el
      // valor previo para devolverlo en la limpieza. Sin esto, tres vueltas
      // del gate dejan el tomo agotado y la cuarta falla sin culpa del código.
      rastro.stock = cliDato( `eval '$p = wc_get_product(${PRODUCTO}); echo (int) $p->get_stock_quantity() . "|" . $p->get_stock_status();'` );
      console.log( `stock-previo: ${rastro.stock}` );
      await check( 'producto-comprable', /\|instock$/.test( rastro.stock ) );

      const alta = await agregarAlCarrito();
      await check( 'carrito-store-api', alta.nonce && alta.status === 201 );

      await p.goto( `${BASE}/checkout/`, { waitUntil: 'domcontentloaded' } );
      await p.locator( '#billing-first_name' ).waitFor( { timeout: 45000 } );

      await p.fill( '#email', EMAIL ).catch( () => {} );
      await p.fill( '#billing-first_name', 'QA' );
      await p.fill( '#billing-last_name', 'Cliente' );
      await p.fill( '#billing-address_1', 'Av. Providencia 1234' );

      // La opción de la RM se llama «Metropolitana de Santiago» (sin la
      // palabra «Región»): se elige por coincidencia, no por literal.
      const region = await p.evaluate( () => {
        const s = document.getElementById( 'billing-state' );
        const o = Array.from( s.options ).find( ( x ) => /Metropolitana/i.test( x.textContent ) );
        return o ? o.value : '';
      } );
      await p.selectOption( '#billing-state', region );
      await p.waitForTimeout( 800 );

      // Comuna: combobox first-party (input + listbox propio). Escribir no
      // basta — hay que ELEGIR la opción para que canonice el valor.
      await p.click( '#billing-city' );
      await p.fill( '#billing-city', 'Santiago' );
      await p.waitForTimeout( 1200 );
      await p.locator( '.akb-comuna-option', { hasText: 'Santiago' } ).first().click();
      await check( 'checkout-comuna-elegida', ( await p.inputValue( '#billing-city' ) ) === 'Santiago' );

      await p.fill( '#billing-phone', CELULAR );
      await p.fill( '#contact-akibara-rut', RUT ).catch( () => {} );
      await p.locator( '#radio-control-wc-payment-method-options-bacs' ).check().catch( () => {} );
      await p.waitForTimeout( 2500 );

      await p.locator( 'button:has-text("REALIZAR EL PEDIDO")' ).first().click();
      await p.waitForURL( /order-received/, { timeout: 90000 } );

      rastro.pedido = parseInt( ( p.url().match( /order-received\/(\d+)/ ) || [ 0, 0 ] )[ 1 ], 10 );
      await check( 'checkout-pedido-creado', rastro.pedido > 0 );
      if ( ! rastro.pedido ) {
        console.log( 'checkout-errores: ' + JSON.stringify(
          await p.locator( '.wc-block-components-validation-error, .wc-block-store-notice' ).allInnerTexts().catch( () => [] )
        ) );
        return;
      }

      cli( `wc shop_order update ${rastro.pedido} --status=shipping-progress --user=1` );
      await check( 'pedido-listo-para-enviar', cliDato( `eval 'echo wc_get_order(${rastro.pedido})->get_status();'` ) === 'shipping-progress' );

      const saldo = parseInt( cliDato( `eval 'echo \\Akibara\\Rewards\\Points\\Ledger::balance(${rastro.usuario});'` ), 10 );
      console.log( `saldo-medido: ${saldo} (esperado ${SALDO_ESPERADO} = 280 del 2% + 500 de bono)` );
      await check( 'saldo-2pct-mas-bono', saldo === SALDO_ESPERADO );

      await p.goto( `${BASE}/mi-cuenta/rewards/`, { waitUntil: 'domcontentloaded' } );
      const cuenta = await p.locator( '.entry-content' ).first().innerText();
      await check( 'mi-cuenta-muestra-saldo', cuenta.includes( `${SALDO_ESPERADO} Puntos Akibara` ) );
      await check( 'mi-cuenta-historial-compra', cuenta.includes( `Compra #${rastro.pedido}` ) );
      await check( 'mi-cuenta-historial-bono', /Bono de bienvenida/i.test( cuenta ) );

      // Correo consolidado: UNO solo con «Puntos» en el asunto, no dos.
      const conPuntos = ( await correosDe( EMAIL ) ).filter( ( m ) => /Puntos/i.test( m.Subject ) );
      console.log( `correos-puntos: ${JSON.stringify( conPuntos.map( ( m ) => m.Subject ) )}` );
      await check( 'correo-puntos-unico', conPuntos.length === 1 );
    } );

    // ------------------------------------------------------------------
    // 5. CANJE EN EL CHECKOUT.
    // ------------------------------------------------------------------
    await paso( 'canje-checkout', async () => {
      await agregarAlCarrito();
      await p.goto( `${BASE}/checkout/`, { waitUntil: 'domcontentloaded' } );
      await p.locator( '.akb-rw-checkout-box' ).waitFor( { timeout: 45000 } );

      // Espera POR CONDICIÓN, nunca por reloj. El plazo fijo de 2.500 ms que
      // había aquí dio VERDE en la pasada 1 del gate y ROJO en la pasada 2 con
      // el mismo código y el mismo espejo: no medía el canje, medía la suerte.
      // La sonda hermana `gate-bordes/sonda-canje-latencia-checkout.mjs`
      // cronometró el viaje real de `extensionCartUpdate()` en la superficie
      // servida (3 vueltas, sondeo cada 50 ms, desde el clic hasta que la fila
      // del resumen existe): caja=[552, 600, 569] ms y resumen=[611, 640, 620]
      // ms — PEOR caso 640 ms, mediana 620 ms (medido el 2026-09-01). O sea que
      // los 2.500 ms daban un margen de menos de 4x sobre una operación de red:
      // suficiente para acertar casi siempre y fallar cuando el espejo estaba
      // ocupado. El techo de 10 s de abajo NO es el plazo esperado — es el
      // margen que deja que una ausencia REAL siga siendo roja en vez de
      // esconderse tras un `waitFor` sin tope.
      const TECHO_CANJE = 10000;
      const esperar = ( fn, arg ) => p.waitForFunction( fn, arg, { timeout: TECHO_CANJE } ).catch( () => {} );

      await p.fill( '#akb-rw-canje', String( CANJE ) );
      await p.locator( 'button:has-text("Canjear")' ).first().click();

      await esperar( ( n ) => {
        const c = document.querySelector( '.akb-rw-checkout-box' );
        return !! c && new RegExp( 'Canje activo:\\s*' + n ).test( ( c.textContent || '' ).replace( /\s+/g, ' ' ) );
      }, CANJE );
      const caja = await p.locator( '.akb-rw-checkout-box' ).innerText();
      await check( `canje-activo-${CANJE}`, caja.includes( `Canje activo: ${CANJE}` ) );

      // El descuento tiene que VERSE en el resumen, no sólo en la caja: la
      // fila del fee se llama «Puntos Akibara» y trae el monto bruto. Se
      // espera EXACTAMENTE la condición que después se asierta (mismo
      // predicado que `VER_RESUMEN` en la sonda de latencia).
      await esperar( ( n ) => Array.from( document.querySelectorAll( '.wc-block-components-totals-item' ) )
        .some( ( x ) => /Puntos Akibara/.test( x.textContent || '' )
          && new RegExp( '[-−]\\s*\\$\\s*' + n ).test( ( x.textContent || '' ).replace( /\s+/g, ' ' ) ) ), CANJE );
      const filas = await p.locator( '.wc-block-components-totals-item' ).allInnerTexts();
      const fila = filas.find( ( f ) => f.includes( 'Puntos Akibara' ) ) || '';
      console.log( `canje-fila-resumen: ${JSON.stringify( fila.replace( /\n+/g, ' ' ) )}` );
      await check( 'canje-descuento-en-resumen', /[-−]\s*\$\s*300/.test( fila ) );

      // Quitar el canje viaja por el MISMO `extensionCartUpdate()`: el plazo
      // fijo era igual de frágil en esta dirección.
      await p.locator( 'button:has-text("Quitar canje")' ).first().click();
      await esperar( () => {
        const c = document.querySelector( '.akb-rw-checkout-box' );
        return !! c && ! /Canje activo/.test( c.textContent || '' );
      } );
      const sinCanje = await p.locator( '.akb-rw-checkout-box' ).innerText();
      await check( 'canje-se-puede-quitar', ! sinCanje.includes( 'Canje activo' ) );

      const vaciado = await vaciarCarrito();
      await check( 'carrito-vaciado', vaciado.status === 200 );
    } );

    // ------------------------------------------------------------------
    // 6. HOSTIL — el enlace de reseña por token es de UN SOLO USO.
    // ------------------------------------------------------------------
    await paso( 'resena-token', async () => {
      if ( ! rastro.pedido ) {
        await check( 'resena-token', false );
        return;
      }

      const token = cliDato( `eval '$t = \\Akibara\\Rewards\\Reviews\\Invitations::issue_token(${rastro.pedido}); echo $t;'` );
      await check( 'resena-token-emitido', /^[A-Za-z0-9]{20,64}$/.test( token ) );

      await p.goto( `${BASE}/resena/${token}/`, { waitUntil: 'domcontentloaded' } );
      await check( 'resena-form-visible', ( await p.locator( 'body' ).innerText() ).includes( 'Tu reseña del pedido' ) );

      await p.locator( 'input[name="akb_rf_product"]' ).first().check().catch( () => {} );
      await p.locator( 'label[for="akb-rf-star5"]' ).click();
      await p.fill( '#akb_rf_content', 'Excelente edicion: papel grueso, traduccion cuidada y llego rapidisimo.' );
      await Promise.all( [
        p.waitForNavigation( { timeout: 45000 } ).catch( () => {} ),
        p.locator( 'form button[type="submit"]' ).first().click(),
      ] );
      await p.waitForTimeout( 1200 );
      // «Gracias» a secas también está en el pie del sitio: lo que confirma
      // el envío es la frase de la pantalla de agradecimiento.
      await check( 'resena-gracias', ( await p.locator( 'body' ).innerText() ).includes( 'quedó en revisión' ) );

      await p.goto( `${BASE}/resena/${token}/`, { waitUntil: 'domcontentloaded' } );
      await check( 'resena-token-un-solo-uso', ( await p.locator( 'body' ).innerText() ).includes( 'ya no sirve' ) );

      // DESVÍO 3: el contrato buscaba «la última reseña en espera» a secas,
      // y en el espejo local YA hay reseñas pendientes de otras pruebas —
      // ese get_comments habría borrado la de otro. Se acota por el correo
      // del autor, que ReviewForm copia del pedido: identifica la nuestra
      // aunque el pedido haya quedado sin cliente (ahí `user_id` es 0 y
      // filtrar por él dejaba la reseña viva; pasó en esta misma ronda).
      rastro.comentario = parseInt( cliDato(
        `eval '$c = get_comments( array( "type" => "review", "status" => "hold", "author_email" => "${EMAIL}", "number" => 1 ) ); echo $c ? $c[0]->comment_ID : 0;'`
      ), 10 ) || 0;
      await check( 'resena-queda-pendiente', rastro.comentario > 0 );
    } );
  } finally {
    // ----------------------------------------------------------------
    // 7. LIMPIEZA — el gate deja el espejo como lo encontró.
    // ----------------------------------------------------------------
    await ctx.close().catch( () => {} );
    await browser.close().catch( () => {} );

    const limpiezas = [];
    const limpiar = ( etiqueta, cmd ) => {
      try {
        cli( cmd );
        limpiezas.push( etiqueta );
      } catch ( e ) {
        limpiezas.push( `${etiqueta}!` );
      }
    };

    // La reseña se re-busca por correo: si el flujo 6 murió antes de anotar
    // el ID, igual hay que barrerla.
    if ( ! rastro.comentario ) {
      try {
        rastro.comentario = parseInt( cliDato(
          `eval '$c = get_comments( array( "status" => "hold", "author_email" => "${EMAIL}", "number" => 1 ) ); echo $c ? $c[0]->comment_ID : 0;'`
        ), 10 ) || 0;
      } catch ( e ) { /* si ni eso responde, lo dice la verificación de abajo. */ }
    }
    if ( rastro.comentario ) {
      limpiar( 'reseña', `comment delete ${rastro.comentario} --force` );
    }
    if ( rastro.pedido ) {
      // HPOS: los pedidos NO son posts — `wp post delete` no los toca.
      limpiar( 'tokens', `eval 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}akb_rewards_review_forms WHERE order_id = ${rastro.pedido}");'` );
      // Las notas del pedido van ANTES de borrarlo: `$order->delete( true )`
      // no las arrastra y después ya no hay pedido del que colgarlas.
      limpiar( 'notas-pedido', `eval 'global $wpdb; $ids = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d", ${rastro.pedido} ) ); foreach ( $ids as $cid ) { wp_delete_comment( (int) $cid, true ); } echo count( $ids );'` );
      limpiar( 'pedido', `eval '$o = wc_get_order(${rastro.pedido}); if ( $o ) { $o->delete( true ); }'` );
    }
    // Red de seguridad: si la página de gracias no llegó a leerse, el pedido
    // igual existe. Se barre por el correo del gate — nadie más lo usa — con
    // sus tokens y sus NOTAS, en CUALQUIER estado.
    limpiar( 'pedidos-por-correo', `eval '$ids = wc_get_orders( array( "limit" => -1, "status" => "any", "billing_email" => "${EMAIL}", "return" => "ids" ) ); global $wpdb; foreach ( $ids as $id ) { $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}akb_rewards_review_forms WHERE order_id = %d", $id ) ); foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d", $id ) ) as $cid ) { wp_delete_comment( (int) $cid, true ); } $o = wc_get_order( $id ); if ( $o ) { $o->delete( true ); } } echo count( $ids );'` );
    if ( rastro.usuario ) {
      // Abrir el checkout de bloques crea un pedido `checkout-draft` aunque
      // nunca se pague: en una vuelta roja ese borrador se queda vivo. Sus
      // notas se van con él por la misma razón que arriba.
      limpiar( 'borradores', `eval '$ids = wc_get_orders( array( "limit" => -1, "status" => "checkout-draft", "customer_id" => ${rastro.usuario}, "return" => "ids" ) ); global $wpdb; foreach ( $ids as $id ) { foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d", $id ) ) as $cid ) { wp_delete_comment( (int) $cid, true ); } $o = wc_get_order( $id ); if ( $o ) { $o->delete( true ); } }'` );
      limpiar( 'ledger', `eval 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}akb_rewards_ledger WHERE user_id = ${rastro.usuario}");'` );
      limpiar( 'usuario', `user delete ${rastro.usuario} --yes --reassign=1` );
    }
    if ( rastro.stock && /^\d+\|/.test( rastro.stock ) ) {
      const [ cantidad, estado ] = rastro.stock.split( '|' );
      limpiar( 'stock', `eval '$p = wc_get_product(${PRODUCTO}); wc_update_product_stock( $p, ${parseInt( cantidad, 10 )}, "set" ); $p = wc_get_product(${PRODUCTO}); $p->set_stock_status("${estado}"); $p->save();'` );
    }
    console.log( `limpieza: ${limpiezas.join( ', ' ) || '(nada que borrar)'}` );

    // Un residuo vivo es una falla: el siguiente agente mediría sobre él.
    const quedaUsuario = parseInt( cliDato( `eval 'echo (int) email_exists("${EMAIL}");'` ), 10 ) || 0;
    const quedaPedido = cliDato( `eval 'echo count( wc_get_orders( array( "limit" => -1, "status" => "any", "billing_email" => "${EMAIL}", "return" => "ids" ) ) ) ? "si" : "no";'` );
    const stockAhora = rastro.stock ? cliDato( `eval '$p = wc_get_product(${PRODUCTO}); echo (int) $p->get_stock_quantity() . "|" . $p->get_stock_status();'` ) : rastro.stock;
    const stockRepuesto = ! rastro.stock || stockAhora === rastro.stock;
    if ( ! stockRepuesto ) {
      console.log( `stock-sin-reponer: previo=${rastro.stock} ahora=${stockAhora}` );
    }
    const quedaResena = parseInt( cliDato(
      `eval '$c = get_comments( array( "author_email" => "${EMAIL}", "number" => 1, "count" => false ) ); echo $c ? count( $c ) : 0;'`
    ), 10 ) || 0;

    // Conteo TOTAL de wp_comments antes/después: es el único candado que ve
    // las notas de pedido. Filtrar por autor o por tipo dejaría pasar
    // exactamente lo que se escapaba (las notas las escribe el sistema, no el
    // cliente del gate). Si la lectura de partida falló (-1), se dice y no se
    // finge un veredicto.
    let comentariosDespues = -1;
    try {
      comentariosDespues = parseInt( cliDato( `eval 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" );'` ), 10 );
    } catch ( e ) { /* queda en -1: la comparación de abajo lo trata como falla. */ }
    const comentariosIguales = comentariosAntes >= 0 && comentariosDespues === comentariosAntes;
    console.log( `comentarios-antes-despues: ${comentariosAntes} → ${comentariosDespues} ${comentariosIguales ? 'OK' : 'FALLA'}` );

    const limpio = ! quedaUsuario && quedaPedido === 'no' && stockRepuesto && ! quedaResena && comentariosIguales;
    console.log( `limpieza-verificada: ${limpio ? 'OK' : 'FALLA'}` );
    if ( ! limpio ) fallas++;

    console.log( `CLIENTE-FALLAS=${fallas}` );
    process.exit( fallas > 0 ? 1 : 0 );
  }
};

run();
