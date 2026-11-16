import { readFileSync, writeFileSync } from 'fs';
import { join } from 'path';

console.log("Generando Base Consolidada de Información y Export Cypher para Neo4j & NotebookLM...");

const graph = JSON.parse(readFileSync('./graphify-out/graph.json', 'utf8'));

// Identificar módulos y componentes clave
const modules = [
  {
    name: "Akibara Rewards",
    slug: "akibara-rewards",
    version: "0.4.33",
    role: "Programa de fidelización first-party: Puntos Akibara (acumulación 2% neto con carencia de 7 días vía Action Scheduler, canje 1 pt = $1 CLP con preservación de Envío Gratis, bono primera compra +500 pts, cumpleaños) y reseñas con foto.",
    god_nodes: ["Ledger", "Accrual", "Redemption", "FirstPurchase", "Invitations"],
    tables: ["wp_akb_rewards_ledger", "wp_akb_rewards_review_forms"],
    options: ["akb_rewards_points_ratio", "akb_rewards_first_purchase_points", "akb_rewards_birthday_points", "akb_rewards_escrow_days"],
    hooks: ["woocommerce_order_status_processing", "woocommerce_order_status_completed", "woocommerce_order_status_refunded", "woocommerce_order_status_cancelled", "woocommerce_order_status_failed", "akb_rewards_release_accrual_escrow"]
  },
  {
    name: "Akibara Business",
    slug: "akibara-business",
    version: "1.120.36",
    role: "Núcleo de negocio y catálogo: Estantería digital Hondana (AccountCollection), navegación de series (SeriesFacts), avisos de stock (BackInStock), matriz de precios (PricingMatrix) y reglas financieras chilenas.",
    god_nodes: ["CustomerCollection", "CollectionResolver", "ContinuityResolver", "CollectionBadges", "SeriesFacts", "BackInStock", "CaptureForm", "InstockEmail", "PricingSynchronizer"],
    tables: ["wp_akb_bis_subscribers", "wp_akb_series_follow_queue"],
    options: ["_akb_business_financial_rules", "akb_bis_batch_size", "_akb_owned_gen"],
    hooks: ["woocommerce_order_status_processing", "woocommerce_order_status_completed", "woocommerce_customer_email_verified", "akb_bis_notify_product"]
  },
  {
    name: "Akibara Search",
    slug: "akibara-search",
    version: "0.1.89",
    role: "Motor de búsqueda invertido y facetas nativo: Sugerencias instantáneas (<15ms), facetas server-first sin jQuery, scoring binario (0=instock, 1=outofstock con penalización -250) y actualización sincrónica instantánea de stock_rank.",
    god_nodes: ["Indexer", "RebuildJob", "Scorer", "FacetRegistry", "SearchAdminSection"],
    tables: ["wp_akb_search_docs", "wp_akb_search_tokens", "wp_akb_search_facet_index", "wp_akb_search_qlog", "wp_akb_search_work"],
    options: ["akb_search_facet_settings", "akb_search_rebuild_status", "akb_search_effective_price"],
    hooks: ["woocommerce_product_set_stock_status", "save_post_product", "akb_search_work_process"]
  },
  {
    name: "Akibara Cart & Checkout Blocks",
    slug: "akibara-cart-checkout-blocks",
    version: "2.2.37",
    role: "Integración nativa con WooCommerce Store API y Checkout Blocks: Validación algorítmica de RUT chileno (Módulo 11), directorio de 346 comunas DPA y selector de puntos de retiro PUDO Blue Express.",
    god_nodes: ["CheckoutFields", "ComunaDirectory", "PudoIntegration", "StoreApiIntegration", "CheckoutBlockIntegration"],
    tables: [],
    options: ["akibara_checkout_blocks_settings"],
    hooks: ["woocommerce_store_api_checkout_update_order_from_request"]
  },
  {
    name: "Akibara GP (Tema)",
    slug: "akibara-gp",
    version: "2.1.152",
    role: "Tema hijo de GeneratePress: Sistema de diseño brutalista refinado 'Akibara Manga Crimson v3', sticky add-to-cart reactivo, navegación de tomos por serie, cajón de carrito nativo y clearance anti-colisión.",
    god_nodes: ["main.js", "sticky-atc.js", "pdp-ajax-atc.js", "collection-badges.js", "series-order.php"],
    tables: ["wp_akb_wishlist"],
    options: ["akibara_theme_settings"],
    hooks: ["wp_enqueue_scripts", "woocommerce_before_single_product", "woocommerce_after_single_product_summary"]
  },
  {
    name: "MU Plugins & Infraestructura",
    slug: "mu-plugins",
    version: "1.3.0",
    role: "Infraestructura crítica y centinelas: Transporte SMTP oficial con copia de rescate, suite consolidada Blue Express (akibara-bluex-suite.php), catálogo declarativo de claves CAS, y guardrails de caché LiteSpeed/Cloudflare.",
    god_nodes: ["akibara-correo-smtp.php", "akibara-mail-transport-watch.php", "akibara-bluex-suite.php", "akibara-provider-access.php", "akibara-litespeed-guardrails.php"],
    tables: [],
    options: ["akibara_mail_transport_outage", "akibara_mail_transport_watch", "akb_bluex_watch_state"],
    hooks: ["phpmailer_init", "wp_mail_failed", "litespeed_control_set_nocache"]
  }
];

// Generar script Cypher para Neo4j
let cypher = `// ============================================================================
// GRAFO DE CONOCIMIENTO ARQUITECTÓNICO DE AKIBARA (NEO4J CYPHER IMPORT)
// Generado: ${new Date().toISOString()}
// Motor: Graphify AST + Semántica Canónica
// ============================================================================

// Restricciones de Unicidad
CREATE CONSTRAINT unique_component_id IF NOT EXISTS FOR (c:Component) REQUIRE c.id IS UNIQUE;
CREATE CONSTRAINT unique_module_id IF NOT EXISTS FOR (m:Module) REQUIRE m.id IS UNIQUE;
CREATE CONSTRAINT unique_class_id IF NOT EXISTS FOR (cls:Class) REQUIRE cls.id IS UNIQUE;
CREATE CONSTRAINT unique_table_id IF NOT EXISTS FOR (t:Table) REQUIRE t.name IS UNIQUE;
CREATE CONSTRAINT unique_hook_id IF NOT EXISTS FOR (h:Hook) REQUIRE h.name IS UNIQUE;

`;

// Nodos de componentes
modules.forEach(m => {
  cypher += `MERGE (c:Component {id: '${m.slug}', name: '${m.name}', version: '${m.version}', role: '${m.role.replace(/'/g, "\\'")}'})\n`;
  m.god_nodes.forEach(node => {
    cypher += `MERGE (cls:Class {id: '${m.slug}:${node}', name: '${node}'})\n`;
    cypher += `MERGE (cls)-[:PART_OF]->(c)\n`;
  });
  m.tables.forEach(table => {
    cypher += `MERGE (t:Table {name: '${table}'})\n`;
    cypher += `MERGE (c)-[:WRITES_TO]->(t)\n`;
  });
  m.hooks.forEach(hook => {
    cypher += `MERGE (h:Hook {name: '${hook}'})\n`;
    cypher += `MERGE (c)-[:LISTENS_TO]->(h)\n`;
  });
});

// Relaciones cruzadas clave (Inter-module Architecture)
cypher += `
// Relaciones entre módulos (Cross-module dependencies)
MATCH (rewards:Component {id: 'akibara-rewards'}), (biz:Component {id: 'akibara-business'})
MERGE (rewards)-[:COORDINATES_WITH {type: 'User Ledger & Discounts'}]->(biz);

MATCH (search:Component {id: 'akibara-search'}), (biz:Component {id: 'akibara-business'})
MERGE (search)-[:SYNCS_PRICING_FROM {filter: 'akb_search_effective_price'}]->(biz);

MATCH (theme:Component {id: 'akibara-gp'}), (biz:Component {id: 'akibara-business'})
MERGE (theme)-[:HYDRATES_UI_FROM {module: 'AccountCollection & Series'}]->(biz);

MATCH (theme:Component {id: 'akibara-gp'}), (search:Component {id: 'akibara-search'})
MERGE (theme)-[:INTEGRATES_SEARCH {client: 'akb-search.js'}]->(search);

MATCH (ledger:Class {id: 'akibara-rewards:Ledger'}), (t:Table {name: 'wp_akb_rewards_ledger'})
MERGE (ledger)-[:MANAGES_LEDGER_UNDER_LOCK {lock: 'GET_LOCK(akb_rewards_user_{id})'}]->(t);

MATCH (custCol:Class {id: 'akibara-business:CustomerCollection'}), (h:Hook {name: 'woocommerce_order_status_processing'})
MERGE (custCol)-[:INVALIDATES_CACHE_ON]->(h);

MATCH (accrual:Class {id: 'akibara-rewards:Accrual'}), (h:Hook {name: 'woocommerce_order_status_processing'})
MERGE (accrual)-[:ACCRUES_IDEMPOTENT_ON {event_key: 'order:{id}:accrual'}]->(h);
`;

writeFileSync('./graphify-out/akibara_neo4j_export.cypher', cypher, 'utf8');
console.log("✓ Export Cypher guardado en graphify-out/akibara_neo4j_export.cypher");

