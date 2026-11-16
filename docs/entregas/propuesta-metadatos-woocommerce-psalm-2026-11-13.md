# Propuesta revisable: metadatos WooCommerce para Psalm

Estado: **APLICADA Y SELLADA por excepción expresa del dueño el 2026-11-13: “Autorizar solo metadatos y su sellado revisado”.** La excepción anterior del riel sigue separada; ninguna excepción autoriza producción. Se modificó únicamente la entrada de metadatos del plugin en psalm.xml, sin alterar niveles, reglas ni exclusiones. El informe anterior conservaba cinco HookNotFound; uno agrupa seis acciones en un foreach. No son cinco fallos de ejecución demostrados: WooCommerce sí emite esos hooks. El informe nuevo de Psalm con taint tiene 0 errores y 1890 informativos visibles. No se declara verde el gate integral mientras no terminen sus demás etapas.

Sellado verificado: psalm.xml SHA-256 `89ca2cc918ae3887a9645abcffd0756d2c0ae47877f568762df4db6854de8c89`; hooks.json `c6ca9ffa7f3336415c6b0cd08b6d3b198cd4e9889130e8af95c17818b13cac86`. QA independiente de productores y probes consta en la auditoría del riel; se repitieron 2/2 controles antes del sellado. En el verificador sólo se renovó esa entrada y se agregó el JSON; sus funciones e invariantes quedaron idénticos. Controles negativos en copia temporal detectaron cambio de XML, cambio de JSON y ausencia de JSON. Los tres archivos quedaron 0444/uchg. Preimagen únicamente local y recibo `.local-quality/metadata-seal-20261113.json`. El verificador completo sigue rechazando los tres hashes del riel aún no renovados: no extender esta aprobación a deploy/guardrail/QA como conjunto.

Comprobación posterior: el test existente de metadatos incorpora ahora un tercer control de sellado. Usa el mapa del verificador real y coteja diagnósticos iniciales contra el repo; cada alteración/ausencia añade exactamente su causa y no oculta fallos del riel. También comprueba 0444/uchg en macOS. Resultado actual 3/3, sin cambios en los archivos protegidos ni en el test histórico del sello.

## Cambio autorizado y validación

1. Crear `tools/psalm/woocommerce/hooks.json` con el JSON incluido abajo. Se limita a contratos de productores WooCommerce reales: argumentos y clases, sin exclusiones, supresiones, baselines ni cambios de severidad.
2. En psalm.xml, sustituir únicamente la entrada autocerrada del plugin por:

```xml
<pluginClass class="PsalmWordPress\Plugin">
    <hooks>
        <file name="tools/psalm/woocommerce/hooks.json" />
    </hooks>
</pluginClass>
```

Conservar `errorLevel="4"`, `findUnusedCode="true"`, taint, hooks core predeterminados y toda la configuración restante. El soporte de hooks/file está implementado en `vendor/humanmade/psalm-plugin-wordpress/Plugin.php:108`; no modificar vendor.
3. Registrar explícitamente las seis acciones Woo de CatalogSnapshot::register con los mismos callbacks, prioridad20 y accepted_args0. El plugin de Psalm no resuelve la unión de literales del foreach: sustituirlo por seis add_action literales permite comprobar nombres/firmas individualmente. No crear hooks falsos ni wrappers para evadir el análisis.
4. Ejecutar Psalm completo con taint, PHPStan y las suites del candidato. No asumir que el JSON elimina todos los errores: corregir cualquier discrepancia de contrato en fuente y revisar el diff.
5. En sandbox separado, verificar controles negativos con hook mal escrito y callback de tipo incompatible: deben seguir fallando. El positivo usa los productores documentados y pasa. No dejar canarios en runtime.
6. QA independiente revisa contratos contra la versión Woo instalada, preservación de reglas y los negativos. Solo después se podrá renovar el hash autorizado de psalm.xml y añadir el archivo de metadatos al sello. El verificador sigue siendo inmutable: cualquier operación necesaria sobre su protección requiere autorización explícita y nunca se realizará por un atajo.

## JSON propuesto

```json
{
  "hooks": [
    {
      "name": "woocommerce_get_query_vars",
      "file": "public_html/wp-content/plugins/woocommerce/includes/class-wc-query.php",
      "type": "filter",
      "args": 1,
      "doc": {
        "description": "Contract verified against the installed WooCommerce producer.",
        "long_description": "",
        "long_description_html": "",
        "tags": [
          {
            "name": "param",
            "types": [
              "array"
            ],
            "variable": "$query_vars",
            "content": "Argument emitted by the WooCommerce producer."
          }
        ]
      }
    },
    {
      "name": "woocommerce_account_menu_items",
      "file": "public_html/wp-content/plugins/woocommerce/includes/wc-account-functions.php",
      "type": "filter",
      "args": 2,
      "doc": {
        "description": "Contract verified against the installed WooCommerce producer.",
        "long_description": "",
        "long_description_html": "",
        "tags": [
          {
            "name": "param",
            "types": [
              "array"
            ],
            "variable": "$items",
            "content": "Argument emitted by the WooCommerce producer."
          },
          {
            "name": "param",
            "types": [
              "array"
            ],
            "variable": "$endpoints",
            "content": "Argument emitted by the WooCommerce producer."
          }
        ]
      }
    },
    {
      "name": "woocommerce_account_{$key}_endpoint",
      "file": "public_html/wp-content/plugins/woocommerce/includes/wc-template-functions.php",
      "type": "action",
      "args": 1,
      "doc": {
        "description": "Contract verified against the installed WooCommerce producer.",
        "long_description": "",
        "long_description_html": "",
        "tags": [
          {
            "name": "param",
            "types": [
              "mixed"
            ],
            "variable": "$value",
            "content": "Argument emitted by the WooCommerce producer."
          }
        ]
      }
    },
    {
      "name": "woocommerce_endpoint_{$endpoint}_title",
      "file": "public_html/wp-content/plugins/woocommerce/includes/class-wc-query.php",
      "type": "filter",
      "args": 3,
      "doc": {
        "description": "Contract verified against the installed WooCommerce producer.",
        "long_description": "",
        "long_description_html": "",
        "tags": [
          {
            "name": "param",
            "types": [
              "string"
            ],
            "variable": "$title",
            "content": "Argument emitted by the WooCommerce producer."
          },
          {
            "name": "param",
            "types": [
              "string"
            ],
            "variable": "$endpoint",
            "content": "Argument emitted by the WooCommerce producer."
          },
          {
            "name": "param",
            "types": [
              "string"
            ],
            "variable": "$action",
            "content": "Argument emitted by the WooCommerce producer."
          }
        ]
      }
    },
    {
      "name": "woocommerce_new_product",
      "file": "public_html/wp-content/plugins/woocommerce/includes/data-stores/class-wc-product-data-store-cpt.php",
      "type": "action",
      "args": 2,
      "doc": {
        "description": "Contract verified against the installed WooCommerce producer.",
        "long_description": "",
        "long_description_html": "",
        "tags": [
          {
            "name": "param",
            "types": [
              "int"
            ],
            "variable": "$product_id",
            "content": "Argument emitted by the WooCommerce producer."
          },
          {
            "name": "param",
            "types": [
              "WC_Product"
            ],
            "variable": "$product",
            "content": "Argument emitted by the WooCommerce producer."
          }
        ]
      }
    },
    {
      "name": "woocommerce_update_product",
      "file": "public_html/wp-content/plugins/woocommerce/includes/data-stores/class-wc-product-data-store-cpt.php",
      "type": "action",
      "args": 2,
      "doc": {
        "description": "Contract verified against the installed WooCommerce producer.",
        "long_description": "",
        "long_description_html": "",
        "tags": [
          {
            "name": "param",
            "types": [
              "int"
            ],
            "variable": "$product_id",
            "content": "Argument emitted by the WooCommerce producer."
          },
          {
            "name": "param",
            "types": [
              "WC_Product"
            ],
            "variable": "$product",
            "content": "Argument emitted by the WooCommerce producer."
          }
        ]
      }
    },
    {
      "name": "woocommerce_product_set_stock",
      "file": "public_html/wp-content/plugins/woocommerce/includes/wc-stock-functions.php",
      "type": "action",
      "args": 1,
      "doc": {
        "description": "Contract verified against the installed WooCommerce producer.",
        "long_description": "",
        "long_description_html": "",
        "tags": [
          {
            "name": "param",
            "types": [
              "WC_Product"
            ],
            "variable": "$product",
            "content": "Argument emitted by the WooCommerce producer."
          }
        ]
      }
    },
    {
      "name": "woocommerce_variation_set_stock",
      "file": "public_html/wp-content/plugins/woocommerce/includes/wc-stock-functions.php",
      "type": "action",
      "args": 1,
      "doc": {
        "description": "Contract verified against the installed WooCommerce producer.",
        "long_description": "",
        "long_description_html": "",
        "tags": [
          {
            "name": "param",
            "types": [
              "WC_Product"
            ],
            "variable": "$product",
            "content": "Argument emitted by the WooCommerce producer."
          }
        ]
      }
    },
    {
      "name": "woocommerce_product_set_stock_status",
      "file": "public_html/wp-content/plugins/woocommerce/includes/data-stores/class-wc-product-data-store-cpt.php",
      "type": "action",
      "args": 3,
      "doc": {
        "description": "Contract verified against the installed WooCommerce producer.",
        "long_description": "",
        "long_description_html": "",
        "tags": [
          {
            "name": "param",
            "types": [
              "int"
            ],
            "variable": "$product_id",
            "content": "Argument emitted by the WooCommerce producer."
          },
          {
            "name": "param",
            "types": [
              "string"
            ],
            "variable": "$stock_status",
            "content": "Argument emitted by the WooCommerce producer."
          },
          {
            "name": "param",
            "types": [
              "WC_Product"
            ],
            "variable": "$product",
            "content": "Argument emitted by the WooCommerce producer."
          }
        ]
      }
    },
    {
      "name": "woocommerce_variation_set_stock_status",
      "file": "public_html/wp-content/plugins/woocommerce/includes/data-stores/class-wc-product-data-store-cpt.php",
      "type": "action",
      "args": 3,
      "doc": {
        "description": "Contract verified against the installed WooCommerce producer.",
        "long_description": "",
        "long_description_html": "",
        "tags": [
          {
            "name": "param",
            "types": [
              "int"
            ],
            "variable": "$product_id",
            "content": "Argument emitted by the WooCommerce producer."
          },
          {
            "name": "param",
            "types": [
              "string"
            ],
            "variable": "$stock_status",
            "content": "Argument emitted by the WooCommerce producer."
          },
          {
            "name": "param",
            "types": [
              "WC_Product"
            ],
            "variable": "$product",
            "content": "Argument emitted by the WooCommerce producer."
          }
        ]
      }
    }
  ]
}
```

## Límites

No se garantiza compatibilidad con una versión futura de Woo sin volver a contrastar productores. Una diferencia de versión obliga a revisar este contrato, no a ignorar el error. Mantener información existente de Psalm visible; ninguna supresión nueva se considera parte de esta propuesta. El permiso para esta modificación tampoco autoriza producción ni resuelve recuperación de datos o publicación coordinada.

QA independiente: tools/test-woocommerce-hook-metadata.mjs comprueba AST de10productores/SHA de5archivos; positivo0, tipoerróneo rechazado soloInvalidArgument y hookTYPO rechazado soloHookNotFound. Integración incondicional en stage1 de guardrail. La primera activación descubrió callbacks HomeDestacados que recibían IDs como boolean force: se corrigieron ocho registros a accepted_args0 y se probó una sola invalidación por request.
