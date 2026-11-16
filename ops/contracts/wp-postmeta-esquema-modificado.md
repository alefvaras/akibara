# Contrato: wp_postmeta lleva DOS modificaciones de esquema no documentadas

Sellado 2026-08-31 (auditoría DB, ronda 2 — acción [10] del plan v2).
Verificado en LOCAL y en PROD (information_schema.statistics idénticos).

1. **`KEY akibara_meta_lookup (meta_key(32), post_id)`** — índice añadido a
   mano (ningún código en ninguno de los dos árboles lo crea). Lo usan de
   verdad las consultas (meta_key, post_id) de WooCommerce lookup-tables y
   Rank Math (ANALYZE: key_len=139, r_rows=1.00). Si una reinstalación o un
   dbDelta lo pierde, RECREARLO:
   `ALTER TABLE wp_postmeta ADD KEY akibara_meta_lookup (meta_key(32), post_id);`
2. **`KEY meta_key` está a ancho COMPLETO (sub_part=NULL, key_len 1023)**
   en vez del `meta_key(191)` que declara el core (schema.php:157). MariaDB
   lo ELIGE para las 114 claves >32 chars (la más frecuente:
   rank_math_internal_links_processed, 2.183 filas). Un dbDelta de un
   upgrade mayor puede devolverlo a 191 — inocuo hoy (la clave más larga
   mide 42), pero el ancho cambia solo y no es un bug.

**NO EJECUTAR NUNCA `DROP INDEX meta_key`** (acción [14] del plan v2,
sellada): es índice de core, tres consultas de admin degradan 45–185×, y
las auto-actualizaciones de core (enabled en prod) lo recrearían solas.
