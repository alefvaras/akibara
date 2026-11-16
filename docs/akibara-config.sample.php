<?php
/**
 * Akibara — Plantilla Oficial de Configuración Twelve-Factor (wp-config.php)
 *
 * Este archivo documenta todas las constantes de configuración técnica de Akibara.
 * Todas las directivas operan con valores predeterminados canónicos óptimos en código
 * (ejecutados en OPcache a 0 ms y sin consultas a la base de datos).
 *
 * Si deseas modificar cualquier ajuste en tu entorno local o en producción,
 * simplemente COPIA la línea correspondiente a tu `wp-config.php` y DESCOMÉNTALA
 * cambiando el valor según tus requerimientos.
 *
 * @package Akibara
 * @version 1.122.0
 */

// ============================================================================
// 1. RENDIMIENTO E IMÁGENES (ImageQuality)
// ============================================================================
// Calidad de compresión para las copias AVIF derivadas que genera WordPress al subir portadas.
// Las portadas maestras se optimizan previamente en Akibara Image Studio.
// - Rango permitido : Número entero de 40 a 90.
// - Por defecto     : 60 (medido en la tienda: 39% menos peso que el estándar de 82 sin degradación visual).
//
// define( 'AKB_IMAGE_QUALITY_AVIF', 60 );


// ============================================================================
// 2. CATÁLOGO, ORDENACIÓN Y VITRINA (CatalogOrder)
// ============================================================================
// Criterio de ordenación de tomos en la portada, listados de categoría y catálogo general:
// - 'disponibles_primero' : (Recomendado) Prioriza tomos con stock físico inmediato; los agotados
//                           se desplazan al final del catálogo para maximizar la conversión.
// - 'intercalado'         : Muestra los tomos en orden cronológico / editorial puro sin agrupar por stock.
// - Por defecto           : 'disponibles_primero'
//
// define( 'AKB_CATALOG_ORDER_SHOWCASE', 'disponibles_primero' );


// ============================================================================
// 3. PAGINACIÓN DE LANDINGS DE SERIE (LandingPageSize)
// ============================================================================
// Cantidad de tomos que se muestran por página en las fichas de serie (/serie/<slug>/):
// - Rango permitido : Número entero de 12 a 120 (debe ser múltiplo de columnas de la grilla).
// - Por defecto     : 48 (permite ver casi cualquier colección completa sin paginación lenta).
//
// define( 'AKB_SERIES_LANDING_PER_PAGE', 48 );


// ============================================================================
// 4. SALUD SEO E IDENTIDAD PARA AGENTES DE IA (LlmsManifest)
// ============================================================================
// Identidad corporativa canónica servida en /llms.txt para rastreadores y modelos de IA.
// Si no se define, se utiliza la descripción oficial canónica de Akibara en código.
//
// define( 'AKB_LLMS_IDENTITY', 'Akibara es la tienda de manga y cómics en Chile especializada en ediciones oficiales importadas de España y Argentina (Ivrea, Panini, Planeta Cómic, Milky Way Ediciones, Arechi, Distrito Manga). Despacho a todo Chile.' );


// ============================================================================
// 5. AUTOCOMPLETADO DE DIRECCIONES (Google Places API New)
// ============================================================================
// Interruptor maestro del autocompletado predictivo en Checkout Blocks y Mi Cuenta:
// - true  : Autocompletado activo (hace lazy-load de Google Places al enfocar el campo de dirección).
// - false : Apaga el autocompletado en caso de fallas externas del proveedor sin perder la clave.
// - Por defecto : true (activo automáticamente cuando hay API key configurada).
//
// define( 'AKB_ADDRESS_AUTOCOMPLETE_ENABLED', true );

// Clave de API de Google Maps con permisos para Places API (New).
// - Formato obligatorio : Debe comenzar con 'AIza' y tener exactamente 39 caracteres alfanuméricos.
// - Restricción HTTP    : Restringir en Google Cloud Console a 'https://akibara.cl/*' y 'https://*.akibara.cl/*'.
//
// define( 'AKB_GOOGLE_MAPS_API_KEY', 'AIzaSyA0B1C2D3E4F5G6H7I8J9K0L1M2N3O4P5Q' );


// ============================================================================
// 6. CARRITO LATERAL DESPLEGABLE (NativeCart)
// ============================================================================
// Activar el drawer deslizable nativo fuera de las páginas /carrito/ y /checkout/:
// - true  : (Recomendado) Drawer nativo de alto rendimiento hidratado vía AJAX.
// - false : Desactiva el drawer lateral.
// - Por defecto : true
//
// define( 'AKB_NATIVE_CART_ENABLED', true );

// Desplegar automáticamente el carrito lateral tras hacer clic en "Añadir al carrito":
// - true  : (Recomendado) Abre el drawer confirmando de inmediato el producto añadido.
// - false : Solo actualiza el contador de la cabecera sin desplegar el cajón.
// - Por defecto : true
//
// define( 'AKB_NATIVE_CART_AUTO_OPEN', true );

// Selectores CSS de los elementos que abren el carrito (separados por coma si son varios):
// - Por defecto : '.akb-cart-trigger'
//
// define( 'AKB_NATIVE_CART_TRIGGER_SELECTOR', '.akb-cart-trigger' );


// ============================================================================
// 7. ALGORITMOS DE RECOMENDACIÓN Y UPSELLS (Recommendations)
// ============================================================================
// Heurística de selección para el bloque «Productos relacionados» en la ficha de producto (PDP):
// - 'otra_serie' : (Recomendado) Diversidad garantizada: una serie distinta por cada hueco,
//                  iniciando siempre por el primer tomo disponible para enganchar nuevas lecturas.
// - 'comprables' : Selección aleatoria de la misma categoría priorizando stock disponible.
// - Por defecto  : 'otra_serie'
//
// define( 'AKB_RECO_RELATED', 'otra_serie' );

// Comportamiento del bloque «También te recomendamos…» cuando TODOS los siguientes tomos están agotados:
// - 'mostrar'   : (Recomendado) Mantiene los siguientes tomos visibles en orden de lectura con el botón «Avísame cuando vuelva».
// - 'completar' : Salta los agotados y busca tomos posteriores de la misma serie que sí tengan stock.
// - 'ocultar'   : Oculta por completo el bloque si no hay tomos contiguos disponibles.
// - Por defecto : 'mostrar'
//
// define( 'AKB_RECO_SERIE_AGOTADA', 'mostrar' );

// Regla de presentación para «Más vendidos» en estados vacíos (carrito vacío, error 404, etc.):
// - 'una_por_serie' : (Recomendado) Máximo una tarjeta por serie para ofrecer variedad de puertas de entrada.
// - 'tal_cual'      : Lista de más vendidos directos (puede repetir tomos de la misma serie popular).
// - Por defecto     : 'una_por_serie'
//
// define( 'AKB_RECO_MAS_VENDIDOS', 'una_por_serie' );

// Mostrar carrusel de «Lo más vendido» cuando una búsqueda en la tienda no arroja resultados:
// - 'yes' : (Recomendado) Muestra tomos populares bajo el mensaje sin resultados para evitar rebote.
// - 'no'  : Deja solo el mensaje informativo de búsqueda sin resultados.
// - Por defecto : 'yes'
//
// define( 'AKB_RECO_MAS_VENDIDOS_BUSQUEDA', 'yes' );


// ============================================================================
// 8. TRANSPORTE DE CORREO SMTP TRANSACCIONAL (akibara-correo-smtp.php)
// ============================================================================
// Credenciales de envío seguro para notificaciones de compra, boletas y avisos de stock:
// define( 'AKIBARA_HOSTINGER_MAIL_ENABLED', true );
// define( 'AKIBARA_HOSTINGER_SMTP_USERNAME', 'contacto@akibara.cl' );
// define( 'AKIBARA_HOSTINGER_SMTP_PASSWORD', 'tu_clave_secreta_smtp_aqui' );
// define( 'AKIBARA_HOSTINGER_SMTP_HOST', 'smtp.hostinger.com' );
// define( 'AKIBARA_HOSTINGER_SMTP_PORT', 465 );
// define( 'AKIBARA_HOSTINGER_SMTP_ENCRYPTION', 'ssl' );


// ============================================================================
// 9. PURGA AUTOMÁTICA EN CLOUDFLARE (EdgePurge)
// ============================================================================
// Token de purga instantánea en Cloudflare al actualizar precios o publicar tomos:
// define( 'AKIBARA_CLOUDFLARE_ZONE_ID', 'tu_zone_id_cloudflare_aqui' );
// define( 'AKIBARA_CLOUDFLARE_API_TOKEN', 'tu_token_api_cloudflare_aqui' );


// ============================================================================
// 10. TEXTOS Y MICROCOPIAS DEL SITIO (SiteCopy / AKB_SITE_COPYS)
// ============================================================================
// Todos los textos editoriales del sitio poseen valores de fábrica óptimos en código
// que se ejecutan a 0 ms sin consultas a la base de datos (0 SQL).
//
// Puedes personalizar cualquier texto de dos formas:
//
// FORMA A (Recomendada): Mediante el array asociativo AKB_SITE_COPYS.
// Descomenta el array y ajusta los valores que desees modificar:
//
// define( 'AKB_SITE_COPYS', array(
//     // Promesas comerciales y sellos de confianza
//     'akb_copy_promise_installments' => '3 cuotas sin interés',
//     'akb_copy_promise_dispatch'     => 'Despacha en 24–48 h',
//
//     // Buscador de cabecera y vacíos
//     'akb_copy_search_placeholder'   => 'Buscar manga, series o autor…',
//     'akb_copy_search_empty_popular' => 'Lo más vendido',
//
//     // Pie de página (Contacto y Soporte)
//     'akb_copy_footer_support_hours' => 'Horario de soporte: Lun a Vie · 10:00 a 18:00 hrs',
//     'akb_copy_footer_phone'         => '+56 9 4424 2844',
//
//     // Coordinación de retiro
//     'akb_copy_pickup_titulo'        => 'Retiro en punto de entrega',
//     'akb_copy_pickup_cuerpo'        => 'Te avisaremos por correo cuando tu pedido esté listo para entrega.',
//
//     // Avisos de stock en carrito
//     'akb_copy_cart_ya_en_carrito'   => 'Ya tienes todas las unidades disponibles',
//     'akb_copy_cart_sin_stock'       => 'Tomo agotado',
//
//     // Barra de serie en ficha de producto ({serie} se reemplaza por el nombre)
//     'akb_copy_pdp_series_link'      => 'Ver los tomos de {serie}',
//
//     // Encabezados de recomendaciones
//     'akb_copy_reco_upsells_heading' => 'Siguientes tomos de la serie',
//     'akb_copy_reco_related_heading' => 'También te recomendamos…',
// ) );
//
// FORMA B: Mediante constantes directas individuales:
// define( 'AKB_COPY_PROMISE_INSTALLMENTS', '3 cuotas sin interés' );
// define( 'AKB_COPY_FOOTER_PHONE', '+56 9 4424 2844' );
// define( 'AKB_COPY_FOOTER_SUPPORT_HOURS', 'Horario de soporte: Lun a Vie · 10:00 a 18:00 hrs' );


// ============================================================================
// 11. GAMIFICACIÓN Y NIVELES DE FIDELIZACIÓN (Rewards Tiers)
// ============================================================================
// Akibara Rewards cuenta con 3 niveles: Lector (1.0x), Coleccionista (1.2x) y
// Residente de Akiba (1.5x).
//
// Los montos, multiplicadores y vigencia se configuran desde el panel de administración
// (wp-admin/admin.php?page=akibara-rewards) o fijando estas constantes Twelve-Factor:
//
// - AKB_REWARDS_TIERS_ENABLED          : Activa o desactiva el sistema de niveles (true / false).
// - AKB_REWARDS_TIER_COLECCIONISTA_MIN : Puntos históricos mínimos requeridos para Coleccionista (defecto: 2000).
// - AKB_REWARDS_TIER_COLECCIONISTA_MULT: Multiplicador de puntos de compra para Coleccionista (defecto: 1.2 = +20% pts).
// - AKB_REWARDS_TIER_RESIDENTE_MIN     : Puntos históricos mínimos requeridos para Residente (defecto: 8000).
// - AKB_REWARDS_TIER_RESIDENTE_MULT    : Multiplicador de puntos para Residente de Akiba (defecto: 1.5 = +50% pts).
// - AKB_REWARDS_TIER_VALIDITY_DAYS     : Días de vigencia del estatus tras alcanzarlo (defecto: 365 días / 1 año móvil).
//                                        0 = Vitalicio (el estatus no vence jamás). Cada compra renueva el plazo.
//
// define( 'AKB_REWARDS_TIERS_ENABLED', true );
// define( 'AKB_REWARDS_TIER_COLECCIONISTA_MIN', 2000 );
// define( 'AKB_REWARDS_TIER_COLECCIONISTA_MULT', 1.2 );
// define( 'AKB_REWARDS_TIER_RESIDENTE_MIN', 8000 );
// define( 'AKB_REWARDS_TIER_RESIDENTE_MULT', 1.5 );
// define( 'AKB_REWARDS_TIER_VALIDITY_DAYS', 365 );


