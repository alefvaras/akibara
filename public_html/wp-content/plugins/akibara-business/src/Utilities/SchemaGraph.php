<?php
/**
 * Contrato compartido del `@graph` JSON-LD que publica Rank Math.
 *
 * Reúne las dos cosas que siete módulos necesitaban por separado y resolvían
 * cada uno a su manera: cómo se reconoce el tipo de un nodo, y en qué ORDEN
 * puede tocar el grafo cada uno de los callbacks del plugin.
 *
 * ── El orden es un contrato, no una casualidad ──────────────────────────────
 * `rank_math/json_ld` lo consumen nueve callbacks repartidos en siete clases, y
 * hasta ahora el contrato entre ellos existía únicamente como números sueltos
 * en nueve `add_filter()`. Nadie podía leer el orden sin abrir los siete
 * ficheros, y cambiar un número era indistinguible de un ajuste inocuo.
 *
 * Las etapas, de la primera a la última:
 *
 *   1. {@see PRODUCT_OFFERS} (90) — completa la ficha de producto (Offer,
 *      shipping, devoluciones) sobre el nodo Product que Rank Math ya emitió.
 *   2. {@see SERIES_COLLECTION} (91) — adjunta la colección de la landing de
 *      serie. Va después de producto porque puede referenciar sus nodos.
 *   3. {@see PAGE_ATTACHMENTS} (92) — cuelga del WebPage/CollectionPage ya
 *      presente: breadcrumb alineado, hub de series, página de envíos, marca.
 *   4. {@see ROUTE_COLLECTIONS} (99) — las rutas propias `/a-pedido/` y
 *      `/disponibles/`, que sustituyen la colección entera de su archive y por
 *      eso deben correr después de quien la construyó.
 *   5. {@see PRODUCT_RELATIONSHIPS} (100) — restaura relaciones entre nodos ya
 *      definitivos (Book ↔ Series).
 *   6. {@see GLOBAL_CLEANUP} (999) — higiene final del grafo COMPLETO. Es la
 *      última por definición: limpia lo que dejaron todas las anteriores.
 *
 * Dos callbacks pueden compartir etapa —92 tiene cuatro— cuando adjuntan nodos
 * independientes entre sí. Lo que NO puede pasar es que un módulo se adelante a
 * la etapa que produce lo que él modifica.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Utilities;

defined( 'ABSPATH' ) || exit;

/** Vocabulario y orden compartidos del `@graph` de Rank Math. */
final class SchemaGraph {

	/** Etapa 1: la ficha de producto y su oferta. */
	public const PRODUCT_OFFERS = 90;

	/** Etapa 2: la colección de una landing de serie. */
	public const SERIES_COLLECTION = 91;

	/** Etapa 3: nodos que se cuelgan de la página ya existente. */
	public const PAGE_ATTACHMENTS = 92;

	/** Etapa 4: rutas propias que sustituyen la colección del archive. */
	public const ROUTE_COLLECTIONS = 99;

	/** Etapa 5: relaciones entre nodos ya definitivos. */
	public const PRODUCT_RELATIONSHIPS = 100;

	/** Etapa 6: higiene final sobre el grafo completo. */
	public const GLOBAL_CLEANUP = 999;

	/**
	 * ¿El nodo declara este `@type`?
	 *
	 * JSON-LD permite un tipo suelto o una lista, y el grafo lo compone un
	 * plugin de terceros: la forma exacta no la controlamos. Había tres
	 * implementaciones de esta pregunta en el árbol con tres semánticas
	 * distintas —una comparaba en estricto contra la lista, otra casteaba a
	 * string, otra sólo miraba el escalar—, así que el mismo nodo podía ser
	 * `CollectionPage` para un módulo y no serlo para el de al lado.
	 *
	 * @param array<string,mixed> $node Nodo del `@graph`.
	 * @param string              $type Tipo buscado.
	 */
	public static function node_has_type( array $node, string $type ): bool {
		$declared = $node['@type'] ?? array();
		$declared = is_array( $declared ) ? $declared : array( $declared );

		foreach ( $declared as $candidate ) {
			if ( is_scalar( $candidate ) && $type === (string) $candidate ) {
				return true;
			}
		}

		return false;
	}
}
