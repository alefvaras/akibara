import mysql from 'mysql2/promise';

const htmlContent = `<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group akb-section akb-section--alt has-global-padding is-layout-constrained">
  <style>
    .akb-pts-wrap { max-width: 1000px; margin: 0 auto; padding: 20px 10px 80px; }
    .akb-pts-title { font-family: var(--font-heading); text-transform: uppercase; font-style: italic; color: var(--aki-white); letter-spacing: -1px; }
    .akb-pts-hero { text-align: center; margin-bottom: 56px; }
    .akb-pts-hero .akb-pts-cta { margin-top: 28px; }
    .akb-pts-hero-badges { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; margin-top: 24px; }
    .akb-pts-hero-badge { background: var(--aki-surface-2); border: 1px solid var(--aki-border); padding: 10px 18px; display: inline-flex; align-items: center; gap: 8px; font-size: 0.95rem; font-weight: 700; color: var(--aki-white); }
    .akb-pts-hero-badge span { color: var(--aki-yellow); font-size: 1.1rem; }
    .akb-pts-steps { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 20px; margin-bottom: 56px; }
    .akb-pts-step { background: var(--aki-surface-2); border: 1px solid var(--aki-border); border-radius: 0; padding: 28px 24px; }
    .akb-pts-step .akb-pts-num { display: inline-block; background: var(--aki-red); color: #fff; font-weight: 700; padding: 4px 12px; border-radius: 50px; margin-bottom: 14px; font-size: .85rem; letter-spacing: .04em; }
    .akb-pts-step h3 { color: var(--aki-white); font-family: var(--font-heading); text-transform: uppercase; font-style: italic; font-size: 1.4rem; margin: 0 0 10px; }
    .akb-pts-step p { color: var(--aki-gray-300); line-height: 1.6; margin: 0; font-size: 0.95rem; }
    .akb-pts-table { width: 100%; border-collapse: collapse; background: var(--aki-surface-2); border: 1px solid var(--aki-border); border-radius: 0; overflow: hidden; margin-bottom: 16px; }
    .akb-pts-table th, .akb-pts-table td { padding: 14px 18px; text-align: left; border-bottom: 1px solid var(--aki-border); }
    .akb-pts-table th { background: var(--aki-surface-3); color: var(--aki-gray-200); font-size: .8rem; text-transform: uppercase; letter-spacing: .08em; font-weight: 600; }
    .akb-pts-table td { color: var(--aki-gray-200); }
    .akb-pts-table td:last-child { color: var(--aki-yellow); font-weight: 700; }
    .akb-pts-table tr:last-child td { border-bottom: 0; }
    .akb-pts-note { color: var(--aki-gray-400); font-size: .9rem; margin: 0 0 40px; }
    .akb-pts-box-example { background: #141414; border: 1px solid var(--aki-border); border-left: 4px solid var(--aki-yellow); padding: 22px 24px; margin: 24px 0 48px; }
    .akb-pts-box-ref { border-left: 4px solid var(--aki-red, #CC0010); background: #141414; padding: 20px 24px; margin-bottom: 48px; border-top: 1px solid #333; border-right: 1px solid #333; border-bottom: 1px solid #333; }
    .akb-pts-rules { background: var(--aki-surface-2); border: 1px solid var(--aki-border); border-radius: 0; padding: 28px 24px; margin-bottom: 48px; }
    .akb-pts-rules h2 { color: var(--aki-white); font-family: var(--font-heading); text-transform: uppercase; font-style: italic; font-size: 1.8rem; margin: 0 0 18px; }
    .akb-pts-accordion { border: 1px solid var(--aki-border); background: var(--aki-surface-2); margin-bottom: 12px; }
    .akb-pts-accordion summary { padding: 18px 24px; font-weight: 700; color: var(--aki-white); cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: center; font-size: 1.05rem; user-select: none; }
    .akb-pts-accordion summary::-webkit-details-marker { display: none; }
    .akb-pts-accordion summary::after { content: '+'; font-size: 1.4rem; color: var(--aki-yellow); line-height: 1; transition: transform 0.2s ease; }
    .akb-pts-accordion[open] summary::after { content: '−'; }
    .akb-pts-accordion-content { padding: 0 24px 20px; border-top: 1px solid var(--aki-border); }
    .akb-pts-accordion-content ul { margin: 16px 0 0; padding-left: 20px; }
    .akb-pts-accordion-content li { color: var(--aki-gray-300); line-height: 1.7; margin-bottom: 10px; }
    .akb-pts-accordion-content li:last-child { margin-bottom: 0; }
    .akb-pts-accordion-content li strong { color: var(--aki-gray-100); }
    .akb-pts-cta { text-align: center; display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; }
    .akb-pts-btn { display: inline-flex; align-items: center; justify-content: center; min-height: 48px; padding: 12px 28px; border-radius: 0; font-weight: 700; text-decoration: none; letter-spacing: .04em; text-transform: uppercase; }
    .akb-pts-btn--primary { background: var(--aki-buy-bg, #CC0010); color: var(--aki-buy-fg, #fff); }
    .akb-pts-btn--primary:hover { background: #b0000e; color: #fff; }
    .akb-pts-btn--ghost { border: 1px solid var(--aki-border-light, #444); color: var(--aki-gray-200, #ccc); }
    .akb-pts-btn--ghost:hover { border-color: var(--aki-red, #CC0010); color: var(--aki-white, #fff); }
    .akb-pts-btn:focus-visible { outline: none; box-shadow: var(--aki-focus-ring); }
    .akb-pts-tablewrap { overflow-x: auto; -webkit-overflow-scrolling: touch; margin-bottom: 16px; }
    .akb-pts-tablewrap .akb-pts-table { margin-bottom: 0; }
    @media (max-width: 768px) {
      .akb-pts-wrap { padding: 16px 6px 56px; }
      .akb-pts-step, .akb-pts-rules, .akb-pts-box-ref, .akb-pts-box-example { padding: 20px 18px; }
      .akb-pts-accordion summary { padding: 16px 18px; font-size: 1rem; }
      .akb-pts-accordion-content { padding: 0 18px 18px; }
      .akb-pts-table th, .akb-pts-table td { padding: 12px 14px; font-size: .95rem; }
    }
  </style>

  <div class="akb-pts-wrap">

    <div class="akb-pts-hero">
      <h1 class="akb-pts-title" style="font-size: clamp(2.2rem, 6vw, 3.8rem); margin-bottom: 12px; line-height: 1.1;">1 PUNTO = $1 PESO. ASÍ DE SIMPLE.</h1>
      <p style="font-size: 1.15rem; color: var(--aki-gray-300); line-height: 1.6; max-width: 680px; margin: 0 auto;">
        Acumula saldo directo en pesos con cada compra, reseña con foto aprobada y en tu cumpleaños. Sin conversiones confusas ni letra chica: cada punto que ganas es un peso para descontar en tus próximas colecciones.
      </p>

      <div class="akb-pts-hero-badges">
        <div class="akb-pts-hero-badge">
          <span>🪙</span> 2% de vuelta en tus compras
        </div>
        <div class="akb-pts-hero-badge">
          <span>🎁</span> +500 pts ($500) en tu 1ra compra
        </div>
        <div class="akb-pts-hero-badge">
          <span>📸</span> +500 pts ($500) por reseña con foto
        </div>
      </div>

      <div class="akb-pts-cta">
        <a class="akb-pts-btn akb-pts-btn--primary" href="/mi-cuenta/rewards/">Ver mis puntos en Mi Cuenta</a>
        <a class="akb-pts-btn akb-pts-btn--ghost" href="/tienda/">Encuentra tus próximos tomos</a>
      </div>
    </div>

    <div class="akb-pts-steps">
      <div class="akb-pts-step">
        <span class="akb-pts-num">1 · COMPRA</span>
        <h3>Suma en tus pedidos</h3>
        <p>Ganas 2 puntos por cada $100 pagados en productos con tu cuenta (+500 pts de regalo en tu primera compra con cuenta).</p>
      </div>
      <div class="akb-pts-step">
        <span class="akb-pts-num">2 · RECIBES</span>
        <h3>Acreditación inmediata</h3>
        <p>Se acreditan automáticamente cuando tu pedido pasa a «Procesando» al confirmar el pago en la pasarela.</p>
      </div>
      <div class="akb-pts-step">
        <span class="akb-pts-num">3 · DESCUENTAS</span>
        <h3>Canje directo en 2 tramos</h3>
        <p>1 punto = $1. Canjea en compras desde $25.000 en productos (hasta $2.000) y desde $50.000 (hasta $5.000 por pedido).</p>
      </div>
    </div>

    <h2 class="akb-pts-title" style="font-size: 2rem; margin-bottom: 18px;">Todas las formas de ganar</h2>
    [akb_puntos_resumen]

    <h2 class="akb-pts-title" style="font-size: 2rem; margin-bottom: 14px;">Cuánto ganas en tus compras</h2>
    <p style="color: var(--aki-gray-300); font-size: 1.05rem; line-height: 1.6; margin-bottom: 20px;">Cada compra acumula el 2% de su valor en puntos. Aquí puedes ver ejemplos según el total pagado en productos:</p>
    [akb_puntos_resumen solo="ejemplos"]

    <div class="akb-pts-box-example">
      <h3 style="color: var(--aki-white); font-family: var(--font-heading); text-transform: uppercase; font-size: 1.3rem; margin: 0 0 10px;">
        Ejemplo real en tu primera compra
      </h3>
      <p style="color: var(--aki-gray-300); font-size: 1.05rem; line-height: 1.6; margin: 0;">
        Compra de $20.000 (<strong>400 pts</strong>) + Bono de bienvenida (<strong>500 pts</strong>) + 1 reseña con foto aprobada (<strong>500 pts</strong>) = <strong style="color: var(--aki-yellow); font-size: 1.15rem;">1.400 puntos ($1.400 CLP)</strong> listos para descontar en tu siguiente compra.
      </p>
    </div>

    <h2 class="akb-pts-title" style="font-size: 2rem; margin-bottom: 14px;">Cuánto puedes canjear en cada pedido</h2>
    <p style="color: var(--aki-gray-300); font-size: 1.05rem; line-height: 1.6; margin-bottom: 20px;">Para cuidar precios justos y stock permanente en todo el catálogo de mangas y cómics importados, los puntos se canjean de forma escalonada:</p>

    <div class="akb-pts-tablewrap">
      <table class="akb-pts-table">
        <thead>
          <tr>
            <th scope="col">Subtotal en productos</th>
            <th scope="col">Tope de canje</th>
            <th scope="col">Descuento máximo</th>
            <th scope="col">Condición comercial</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><strong>$25.000 a $49.999</strong> (Tramo 1)</td>
            <td>Hasta 2.000 puntos</td>
            <td>Hasta $2.000 CLP</td>
            <td>Sin cupones promocionales</td>
          </tr>
          <tr>
            <td><strong>Desde $50.000</strong> (Tramo 2)</td>
            <td>Hasta 5.000 puntos</td>
            <td>Hasta $5.000 CLP</td>
            <td>Sin cupones promocionales</td>
          </tr>
        </tbody>
      </table>
    </div>
    <p class="akb-pts-note">El despacho se calcula por separado y no se paga con puntos. Los puntos se descuentan directamente del subtotal de productos sin superar el 50% de la compra.</p>

    <div class="akb-pts-box-ref">
      <h2 class="akb-pts-title" style="font-size: 1.8rem; margin: 0 0 12px; color: #fff;">Programa de Referidos: Regala $2.000 y Gana 1.000 Puntos</h2>
      <p style="color: var(--aki-gray-300); font-size: 1rem; line-height: 1.6; margin-bottom: 16px;">Comparte tu pasión por el manga y los cómics con tus amigos y gana saldo para tu colección con beneficios para ambos:</p>
      <ul style="color: var(--aki-gray-300); line-height: 1.8; margin-bottom: 18px; padding-left: 20px;">
        <li><strong>Para tu amigo (Invitado):</strong> Recibe <strong>$2.000 CLP de descuento inmediato</strong> en su primera compra con tu enlace o cupón de referido, en compras desde $30.000 CLP en mangas y cómics a precio normal (no acumulable con otros cupones).</li>
        <li><strong>Para ti (Padrino):</strong> Recibes <strong>1.000 Puntos Akibara ($1.000 CLP)</strong> acreditados en tu cuenta cuando tu amigo complete y reciba su primer pedido.</li>
        <li><strong>Límite para invitar:</strong> Puedes invitar y recibir recompensa por hasta 10 amigos (máximo 10.000 puntos en total por referidos).</li>
        <li><strong>Un regalo de bienvenida a tu medida:</strong> Si tu amigo aprovecha los $2.000 de descuento directo en su primera compra, ese es su beneficio de bienvenida (no se suma a los 500 puntos de bienvenida, pero sí acumula su 2% normal de la compra).</li>
        <li><strong>Juego limpio entre lectores:</strong> El beneficio es para nuevos lectores que compren por primera vez. No es válido auto-invitarse ni usar el mismo RUT, teléfono o correo entre cuentas.</li>
      </ul>
      <div style="text-align: left;">
        <a class="akb-pts-btn akb-pts-btn--primary" href="/mi-cuenta/rewards/">Obtener mi enlace de referido</a>
      </div>
    </div>

    <div class="akb-pts-rules">
      <h2>Lo que tienes que saber (Términos del Programa)</h2>

      <details class="akb-pts-accordion" open>
        <summary>1 · Canje escalonado, mínimos y elección del mejor beneficio</summary>
        <div class="akb-pts-accordion-content">
          <ul>
            <li><strong>Canje escalonado en 2 tramos:</strong> Puedes canjear hasta 2.000 puntos ($2.000 CLP) en pedidos de $25.000 a $49.999 en productos, y hasta 5.000 puntos ($5.000 CLP) en pedidos desde $50.000 (sin superar nunca el 50% de los productos).</li>
            <li><strong>No acumulables con cupones:</strong> Los puntos no se suman con cupones promocionales en un mismo pedido. En el carrito podrás elegir con claridad tu cupón o canjear tus puntos.</li>
            <li><strong>Despacho independiente y Envío Gratis protegido:</strong> El costo de envío se calcula de forma independiente y no se paga con puntos. Si tu pedido alcanza los $60.000 en productos (Envío Gratis), conservas tu Envío Gratis íntegro aunque canjees tus Puntos Akibara (el umbral se evalúa sobre tus productos antes de descontar los puntos mediante nuestro switch inteligente en el checkout).</li>
            <li><strong>Acreditación inmediata:</strong> Los puntos se acreditan automáticamente cuando el pedido queda confirmado en «Procesando». La parte del pedido pagada con puntos no genera nuevos puntos.</li>
          </ul>
        </div>
      </details>

      <details class="akb-pts-accordion">
        <summary>2 · Bono de bienvenida, reseñas con foto, cumpleaños y referidos</summary>
        <div class="akb-pts-accordion-content">
          <ul>
            <li><strong>Bono de bienvenida (+500 pts = $500 CLP):</strong> Al realizar tu primera compra con cuenta en Akibara por tu cuenta, recibes +500 puntos de regalo al confirmarse tu pago para tus próximas compras (uno por persona, RUT, correo y teléfono). Aplica cuando no se haya utilizado un cupón de primera compra (como el 10% del popup o cupón de referido).</li>
            <li><strong>Reseñas con foto (+500 pts = $500 CLP c/u):</strong> Cada reseña aprobada con fotografía real del tomo o cómic recibido otorga +500 puntos, con un tope máximo de 5 reseñas premiadas por cuenta (hasta 2.500 puntos / $2.500 CLP). En el formulario de invitación puedes adjuntar tu foto y solicitar tus puntos, u opinar sin ellos.</li>
            <li><strong>Cumpleaños (+1.000 pts = $1.000 CLP):</strong> Registra tu fecha de nacimiento en Mi Cuenta y recibe 1.000 puntos de regalo cada año para tus compras.</li>
            <li><strong>Referidos (1.000 pts por amigo):</strong> Ganas 1.000 puntos cuando tu invitado reciba su primera compra (hasta 10 invitados premiados = 10.000 puntos). Tu amigo recibe $2.000 CLP de descuento en compras desde $30.000 en mangas y cómics a precio normal.</li>
          </ul>
        </div>
      </details>

      <details class="akb-pts-accordion">
        <summary>3 · Vigencia de 12 meses y avisos de vencimiento</summary>
        <div class="akb-pts-accordion-content">
          <ul>
            <li><strong>Duración de 1 año:</strong> Tus puntos tienen una vigencia de 12 meses desde el momento en que se acreditan en tu cuenta.</li>
            <li><strong>Uso inteligente (FIFO):</strong> Al realizar un canje, el sistema descuenta automáticamente los puntos más antiguos primero para evitar que caduquen.</li>
            <li><strong>Aviso preventivo:</strong> Te notificamos por correo electrónico 30 días antes de que tus puntos próximos a vencer expiren, para que puedas aprovecharlos a tiempo.</li>
          </ul>
        </div>
      </details>

      <details class="akb-pts-accordion">
        <summary>4 · Devoluciones, anulaciones y vigencia histórica</summary>
        <div class="akb-pts-accordion-content">
          <ul>
            <li><strong>Devoluciones o anulaciones:</strong> Si un pedido se cancela o reembolsa, los puntos otorgados por esa compra se descuentan del saldo y los puntos canjeados se reincorporan a tu cuenta en proporción exacta.</li>
            <li><strong>Personales e intransferibles:</strong> Los puntos pertenecen a tu cuenta registrada, no se transfieren a terceros ni son canjeables por dinero en efectivo fuera del proceso de compra.</li>
            <li><strong>Vigencia histórica:</strong> El programa de recompensas Akibara Puntos rige para compras realizadas a partir del 17 de agosto de 2026. Los pedidos anteriores a dicha fecha no generan puntos retroactivos.</li>
          </ul>
        </div>
      </details>

    </div>

    <div class="akb-pts-cta">
      <a class="akb-pts-btn akb-pts-btn--primary" href="/mi-cuenta/rewards/">Ver mis puntos en Mi Cuenta</a>
      <a class="akb-pts-btn akb-pts-btn--ghost" href="/tienda/">Encuentra tus próximos tomos</a>
    </div>

  </div>
</div>
<!-- /wp:group -->`;

const referidosContent = `<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group akb-section akb-section--alt has-global-padding is-layout-constrained">
  <style>
    .akb-ref-wrap { max-width: 900px; margin: 0 auto; padding: 20px 10px 80px; }
    .akb-ref-title { font-family: var(--font-heading); text-transform: uppercase; font-style: italic; color: var(--aki-white); letter-spacing: -1px; }
    .akb-ref-hero { text-align: center; margin-bottom: 56px; }
    .akb-ref-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px; margin-bottom: 48px; }
    .akb-ref-card { background: var(--aki-surface-2); border: 1px solid var(--aki-border); padding: 32px 24px; border-radius: 0; }
    .akb-ref-card--highlight { border-top: 4px solid var(--aki-red, #CC0010); }
    .akb-ref-card h3 { color: var(--aki-white); font-family: var(--font-heading); text-transform: uppercase; font-style: italic; font-size: 1.5rem; margin: 0 0 12px; }
    .akb-ref-card p { color: var(--aki-gray-300); line-height: 1.6; margin: 0 0 16px; font-size: 0.95rem; }
    .akb-ref-badge { display: inline-block; background: var(--aki-surface-3); color: var(--aki-yellow); font-weight: 700; padding: 6px 14px; font-size: 1.1rem; margin-bottom: 16px; border: 1px solid var(--aki-border); }
    .akb-ref-steps { background: #141414; border: 1px solid #333; padding: 28px 24px; margin-bottom: 48px; }
    .akb-ref-steps h2 { color: #fff; font-family: var(--font-heading); text-transform: uppercase; font-style: italic; font-size: 1.8rem; margin: 0 0 20px; }
    .akb-ref-steps ol { margin: 0; padding-left: 20px; color: var(--aki-gray-300); line-height: 1.8; }
    .akb-ref-steps li { margin-bottom: 14px; }
    .akb-ref-steps li strong { color: #fff; }
    .akb-ref-cta { text-align: center; display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; }
    .akb-ref-btn { display: inline-flex; align-items: center; justify-content: center; min-height: 48px; padding: 12px 28px; border-radius: 0; font-weight: 700; text-decoration: none; letter-spacing: .04em; text-transform: uppercase; }
    .akb-ref-btn--primary { background: var(--aki-buy-bg, #CC0010); color: var(--aki-buy-fg, #fff); }
    .akb-ref-btn--primary:hover { background: #b0000e; color: #fff; }
    .akb-ref-btn--ghost { border: 1px solid var(--aki-border-light, #444); color: var(--aki-gray-200, #ccc); }
    .akb-ref-btn--ghost:hover { border-color: var(--aki-red, #CC0010); color: var(--aki-white, #fff); }
  </style>

  <div class="akb-ref-wrap">
    <div class="akb-ref-hero">
      <h1 class="akb-ref-title" style="font-size: clamp(2.2rem, 5vw, 4rem); margin-bottom: 20px;">Programa de Referidos</h1>
      <p style="font-size: 1.15rem; color: var(--aki-gray-300); line-height: 1.6; max-width: 680px; margin: 0 auto;">Comparte tu pasión por el manga y los cómics con tus amigos. Con tu enlace de invitación, ellos ahorran en su primera compra y tú sumas Puntos Akibara para tu colección.</p>
      <div class="akb-ref-cta" style="margin-top: 28px;">
        <a class="akb-ref-btn akb-ref-btn--primary" href="/mi-cuenta/rewards/">Obtener mi enlace para compartir</a>
        <a class="akb-ref-btn akb-ref-btn--ghost" href="/akibara-puntos/">Ver sistema de puntos completo</a>
      </div>
    </div>

    <div class="akb-ref-grid">
      <div class="akb-ref-card akb-ref-card--highlight">
        <span class="akb-ref-badge">Para tu amigo</span>
        <h3>$2.000 de regalo</h3>
        <p>Tu amigo recibe un <strong>descuento directo de $2.000 CLP</strong> en el carrito de su primera compra al entrar con tu enlace.</p>
        <p style="color: var(--aki-gray-400); font-size: 0.88rem;">Válido en compras desde $30.000 CLP en mangas y cómics a precio normal (no acumulable con otros cupones).</p>
      </div>

      <div class="akb-ref-card akb-ref-card--highlight">
        <span class="akb-ref-badge">Para ti</span>
        <h3>1.000 Puntos ($1.000)</h3>
        <p>Apenas tu amigo complete y reciba su primer pedido, acreditamos <strong>1.000 Puntos Akibara</strong> en tu cuenta.</p>
        <p style="color: var(--aki-gray-400); font-size: 0.88rem;">Puedes invitar a los amigos que quieras y recibir puntos por hasta 10 amigos que completen su primera compra (hasta 10.000 puntos en total).</p>
      </div>
    </div>

    <div class="akb-ref-steps">
      <h2>Cómo funciona paso a paso</h2>
      <ol>
        <li><strong>Obtén tu enlace personal:</strong> Entra a tu cuenta en <a href="/mi-cuenta/rewards/" style="color:#ffcc00;text-decoration:underline;">Mi Cuenta > Puntos</a> y copia tu enlace de invitación exclusivo o compártelo directo por WhatsApp.</li>
        <li><strong>Tu amigo entra y compra:</strong> Tu amigo visita la tienda a través de tu link. El descuento de $2.000 se aplica automáticamente en su carrito al alcanzar $30.000 en mangas a precio normal.</li>
        <li><strong>¡Ambos ganan!:</strong> Tu amigo disfruta de su descuento de bienvenida y, cuando su pedido sea despachado y completado, tú recibes 1.000 Puntos Akibara para descontar en tus próximos tomos favoritos.</li>
      </ol>
    </div>

    <div class="akb-ref-steps" style="background: var(--aki-surface-2);">
      <h2>Reglas claras y juego limpio</h2>
      <ul style="margin: 0; padding-left: 20px; color: var(--aki-gray-300); line-height: 1.8;">
        <li><strong>Nuevos lectores:</strong> El descuento de $2.000 está pensado para amigos que compren por primera vez en Akibara.</li>
        <li><strong>Beneficio de bienvenida exclusivo:</strong> Al aprovechar los $2.000 de descuento inmediato, este reemplaza el bono de bienvenida en puntos (tu amigo sí acumula su 2% normal de la compra).</li>
        <li><strong>Sin trampas ni auto-invitaciones:</strong> No es válido auto-referirse ni usar cuentas con el mismo RUT, teléfono o correo electrónico.</li>
        <li><strong>Garantía de compra:</strong> Si un pedido se cancela o se devuelve, los puntos asociados al referido se ajustan automáticamente.</li>
      </ul>
    </div>

    <div class="akb-ref-cta">
      <a class="akb-ref-btn akb-ref-btn--primary" href="/mi-cuenta/rewards/">Ir a Mi Cuenta y Compartir</a>
      <a class="akb-ref-btn akb-ref-btn--ghost" href="/tienda/">Encuentra tus próximos tomos</a>
    </div>
  </div>
</div>
<!-- /wp:group -->`;

async function main() {
  const host = process.env.DB_HOST || '127.0.0.1';
  const port = Number(process.env.DB_PORT || 3306);
  const user = process.env.DB_USER || 'akibara';
  const password = process.env.DB_PASSWORD || 'akibara_local';
  const database = process.env.DB_NAME || 'akibara';

  const connection = await mysql.createConnection({
    host,
    port,
    user,
    password,
    database
  });

  console.log('Conectado a MariaDB local.');
  const [rows] = await connection.execute(
    'SELECT ID, post_title FROM wp_posts WHERE post_name = "akibara-puntos" LIMIT 1'
  );

  if (!rows || rows.length === 0) {
    console.error('No se encontró la página akibara-puntos');
    process.exit(1);
  }

  const postId = rows[0].ID;
  console.log(`Actualizando página akibara-puntos (ID: ${postId})...`);

  await connection.execute(
    'UPDATE wp_posts SET post_content = ? WHERE ID = ?',
    [htmlContent, postId]
  );
  console.log('✓ Página akibara-puntos actualizada con éxito.');

  const [refRows] = await connection.execute(
    'SELECT ID, post_title FROM wp_posts WHERE post_name = "programa-referidos" LIMIT 1'
  );

  if (refRows && refRows.length > 0) {
    const refId = refRows[0].ID;
    console.log(`Actualizando página programa-referidos (ID: ${refId})...`);
    await connection.execute(
      'UPDATE wp_posts SET post_content = ? WHERE ID = ?',
      [referidosContent, refId]
    );
    console.log('✓ Página programa-referidos actualizada con éxito.');
  }

  await connection.end();
}

main().catch(err => {
  console.error(err);
  process.exit(1);
});
