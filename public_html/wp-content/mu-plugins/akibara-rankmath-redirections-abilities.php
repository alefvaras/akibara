<?php
/**
 * Plugin Name: Akibara — Rank Math Redirections Abilities (MCP)
 * Description: Expone las redirecciones de Rank Math como abilities de WordPress para operarlas vía MCP. Envuelve exclusivamente la API oficial RankMath\Redirections\DB y Cache — sin SQL directo. Escrituras fail-closed: IDs explícitos, expected_count obligatorio y dry_run por defecto.
 * Version: 1.1.0
 * Author: Akibara
 *
 * @package Akibara\MuPlugins
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Máximo de IDs aceptados por una escritura. Techo de seguridad, no una meta.
 */
const AKIBARA_RM_REDIRECTIONS_MAX_IDS = 500;

/**
 * Permiso: el mismo que exige la pantalla oficial de Redirections.
 *
 * @return bool
 */
function akibara_rm_redirections_can_manage(): bool {
	return current_user_can( 'rank_math_redirections' ) || current_user_can( 'manage_options' );
}

/**
 * Fila normalizada para salida MCP.
 *
 * @param array<string,mixed> $row Fila cruda de la API oficial.
 * @return array<string,mixed>
 */
function akibara_rm_redirections_row( array $row ): array {
	$sources = maybe_unserialize( $row['sources'] ?? '' );

	return array(
		'id'          => absint( $row['id'] ?? 0 ),
		'sources'     => is_array( $sources ) ? $sources : false,
		'url_to'      => (string) ( $row['url_to'] ?? '' ),
		'header_code' => (string) ( $row['header_code'] ?? '' ),
		'status'      => (string) ( $row['status'] ?? '' ),
	);
}

/**
 * Cuántas filas pide cada página al lector de Rank Math.
 *
 * El lector pagina (`paged` + `limit`) y devuelve el total en `count`, así que
 * esto es tamaño de página, no tope: lo que decide dónde parar es
 * AKIBARA_RM_REDIRECTIONS_MAX_ROWS.
 */
const AKIBARA_RM_REDIRECTIONS_PAGE = 500;

/**
 * Tope duro de filas por estado para acotar memoria. Las respuestas declaran
 * _incompleto con el total real de cada estado cuando la lectura se trunca.
 */
const AKIBARA_RM_REDIRECTIONS_MAX_ROWS = 10000;

/**
 * ¿La última lectura se quedó corta? Se anota acá para que los conteos lo digan.
 *
 * @var array<string,int>
 */
$GLOBALS['akibara_rm_redirections_truncado'] = array();

/**
 * Lee filas por estado usando el lector oficial de Rank Math, paginando.
 *
 * @param array<int,string> $statuses Estados a incluir.
 * @return array<int,array<string,mixed>>
 */
function akibara_rm_redirections_rows( array $statuses ): array {
	$rows = array();
	$GLOBALS['akibara_rm_redirections_truncado'] = array();

	foreach ( $statuses as $status ) {
		$pagina = 1;
		$leidas = 0;

		do {
			$result = \RankMath\Redirections\DB::get_redirections(
				array(
					'limit'  => AKIBARA_RM_REDIRECTIONS_PAGE,
					'paged'  => $pagina,
					'status' => $status,
				)
			);

			$tanda = (array) ( $result['redirections'] ?? array() );
			$total = (int) ( $result['count'] ?? 0 );

			foreach ( $tanda as $row ) {
				$rows[] = akibara_rm_redirections_row( (array) $row );
			}

			$leidas += count( $tanda );
			++$pagina;

			if ( $leidas >= AKIBARA_RM_REDIRECTIONS_MAX_ROWS && $leidas < $total ) {
				// Se dice, no se calla: el consumidor decide qué hacer con un
				// conteo que sabe incompleto.
				$GLOBALS['akibara_rm_redirections_truncado'][ $status ] = $total;
				break;
			}
		} while ( array() !== $tanda && $leidas < $total );
	}

	return $rows;
}

/**
 * Conteos por estado y código, vía lector oficial.
 *
 * @return array<string,mixed>
 */
function akibara_rm_redirections_counts(): array {
	$counts = array();
	foreach ( akibara_rm_redirections_rows( array( 'active', 'inactive', 'trashed' ) ) as $row ) {
		$key            = $row['status'] . ':' . $row['header_code'];
		$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
	}
	ksort( $counts );

	$truncado = (array) ( $GLOBALS['akibara_rm_redirections_truncado'] ?? array() );
	if ( array() !== $truncado ) {
		// Un conteo incompleto que no se declara es un conteo que miente.
		$counts['_incompleto'] = $truncado;
	}

	return $counts;
}

/**
 * Valida y ejecuta un cambio de estado fail-closed sobre IDs explícitos.
 *
 * @param array<string,mixed> $input        Entrada MCP.
 * @param array<int,string>   $from_states  Estados de origen permitidos.
 * @param string              $to_state     Estado destino.
 * @return array<string,mixed>|\WP_Error
 */
function akibara_rm_redirections_change_status( array $input, array $from_states, string $to_state ) {
	if ( ! class_exists( '\RankMath\Redirections\DB' ) ) {
		return new \WP_Error( 'akibara_rm_unavailable', 'El módulo Redirections de Rank Math no está disponible.' );
	}

	$ids = array_values( array_unique( array_map( 'absint', (array) ( $input['ids'] ?? array() ) ) ) );
	$ids = array_filter( $ids );
	if ( array() === $ids ) {
		return new \WP_Error( 'akibara_rm_no_ids', 'Debes entregar IDs explícitos.' );
	}
	if ( AKIBARA_RM_REDIRECTIONS_MAX_IDS < count( $ids ) ) {
		return new \WP_Error( 'akibara_rm_too_many', 'Máximo ' . AKIBARA_RM_REDIRECTIONS_MAX_IDS . ' IDs por operación.' );
	}

	$expected = absint( $input['expected_count'] ?? 0 );
	if ( count( $ids ) !== $expected ) {
		return new \WP_Error(
			'akibara_rm_expected_mismatch',
			sprintf( 'expected_count (%d) no coincide con la cantidad de IDs (%d).', $expected, count( $ids ) )
		);
	}

	$eligible = array();
	foreach ( akibara_rm_redirections_rows( $from_states ) as $row ) {
		$eligible[ $row['id'] ] = $row;
	}

	// Captura el truncamiento antes de counts(), que vuelve a leer y reinicia el global.
	$truncado = (array) ( $GLOBALS['akibara_rm_redirections_truncado'] ?? array() );

	$missing = array_values( array_diff( $ids, array_keys( $eligible ) ) );
	if ( array() !== $missing ) {
		if ( array() !== $truncado ) {
			// Fail-closed: una lectura truncada no puede probar que un ID NO es
			// elegible. Sin esto, un ID legítimo más allá del corte (p. ej. la
			// fila 10.001) recibía el mensaje falso "no está en ese estado".
			return new \WP_Error(
				'akibara_rm_eligibles_incompletos',
				sprintf(
					'La lectura de elegibles se cortó en %d filas (de %s reales); no se puede afirmar que %d ID(s) no sean elegibles.',
					AKIBARA_RM_REDIRECTIONS_MAX_ROWS,
					implode( ',', $truncado ),
					count( $missing )
				)
			);
		}

		return new \WP_Error(
			'akibara_rm_ids_not_eligible',
			sprintf(
				'%d ID(s) no están en estado %s: %s',
				count( $missing ),
				implode( '/', $from_states ),
				implode( ',', array_slice( $missing, 0, 20 ) )
			)
		);
	}

	$dry_run = ! array_key_exists( 'dry_run', $input ) || false !== $input['dry_run'];
	if ( $dry_run ) {
		$respuesta = array(
			'dry_run'       => true,
			'to_state'      => $to_state,
			'eligible_ids'  => count( $ids ),
			'counts_before' => akibara_rm_redirections_counts(),
			'sample'        => array_slice( array_intersect_key( $eligible, array_flip( $ids ) ), 0, 5 ),
		);
		if ( array() !== $truncado ) {
			$respuesta['_incompleto'] = $truncado;
		}

		return $respuesta;
	}

	$updated = \RankMath\Redirections\DB::change_status( $ids, $to_state );
	// La UI oficial no purga la caché de aciertos al cambiar estado; DB::delete sí.
	// Replicamos la purga de delete() para que una fila retirada deje de servirse.
	if ( class_exists( '\RankMath\Redirections\Cache' ) ) {
		\RankMath\Redirections\Cache::purge( $ids );
	}

	return array(
		'dry_run'       => false,
		'to_state'      => $to_state,
		'requested'     => count( $ids ),
		'updated_rows'  => (int) $updated,
		'counts_after'  => akibara_rm_redirections_counts(),
	);
}

/**
 * Registra la ability de palabras clave de enfoque. No depende del módulo
 * Redirections: escribe la meta oficial `rank_math_focus_keyword`, exactamente
 * la que guarda la metabox de Rank Math.
 */
function akibara_register_focus_keyword_ability(): void {
	\wp_register_ability(
		'akibara/set-focus-keyword',
		array(
			'category'            => 'akibara-operations',
			'label'               => 'Fijar palabra clave de enfoque Rank Math',
			'description'         => 'Escribe la meta oficial rank_math_focus_keyword para entradas/páginas/productos publicados. Fail-closed: pares explícitos post_id→keyword, expected_count y dry_run=true por defecto. Solo actualiza; keyword vacía no está permitida.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'items'          => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 100,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'post_id' => array( 'type' => 'integer' ),
								'keyword' => array(
									'type'      => 'string',
									'minLength' => 3,
									'maxLength' => 120,
								),
							),
							'required'             => array( 'post_id', 'keyword' ),
							'additionalProperties' => false,
						),
					),
					'expected_count' => array( 'type' => 'integer' ),
					'dry_run'        => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'required'             => array( 'items', 'expected_count' ),
				'additionalProperties' => false,
			),
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'mcp'          => array( 'public' => true ),
			),
			'permission_callback' => static function (): bool {
				return current_user_can( 'edit_others_posts' ) || current_user_can( 'manage_options' );
			},
			'execute_callback'    => static function ( $input = array() ) {
				$items    = (array) ( $input['items'] ?? array() );
				$expected = absint( $input['expected_count'] ?? 0 );
				if ( count( $items ) !== $expected ) {
					return new \WP_Error(
						'akibara_fk_expected_mismatch',
						sprintf( 'expected_count (%d) no coincide con la cantidad de items (%d).', $expected, count( $items ) )
					);
				}

				$plan = array();
				foreach ( $items as $item ) {
					$post_id = absint( $item['post_id'] ?? 0 );
					$keyword = sanitize_text_field( (string) ( $item['keyword'] ?? '' ) );
					$post    = get_post( $post_id );
					if ( ! $post || 'publish' !== $post->post_status ) {
						return new \WP_Error(
							'akibara_fk_post_invalid',
							sprintf( 'El post %d no existe o no está publicado.', $post_id )
						);
					}
					if ( '' === $keyword ) {
						return new \WP_Error( 'akibara_fk_empty_keyword', sprintf( 'Keyword vacía para el post %d.', $post_id ) );
					}
					$plan[] = array(
						'post_id'  => $post_id,
						'title'    => get_the_title( $post ),
						'previous' => (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ),
						'keyword'  => $keyword,
					);
				}

				$dry_run = ! array_key_exists( 'dry_run', $input ) || false !== $input['dry_run'];
				if ( $dry_run ) {
					return array(
						'dry_run' => true,
						'plan'    => $plan,
					);
				}

				$applied = array();
				foreach ( $plan as $row ) {
					update_post_meta( $row['post_id'], 'rank_math_focus_keyword', $row['keyword'] );
					$applied[] = array(
						'post_id' => $row['post_id'],
						'keyword' => $row['keyword'],
						'stored'  => (string) get_post_meta( $row['post_id'], 'rank_math_focus_keyword', true ),
					);
				}

				return array(
					'dry_run' => false,
					'applied' => $applied,
				);
			},
		)
	);
}

add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		\wp_register_ability_category(
			'akibara-operations',
			array(
				'label'       => 'Akibara Operations',
				'description' => 'Operaciones first-party de Akibara expuestas vía MCP, envolviendo APIs oficiales de los plugins.',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		akibara_register_focus_keyword_ability();

		// En contextos donde Rank Math no bootea completo (p. ej. WP-CLI) su
		// autoloader Composer no está registrado; lo incluimos: es idempotente
		// y su classmap cubre Redirections\DB y el query builder.
		if ( ! class_exists( '\RankMath\Redirections\DB' ) ) {
			$autoload = WP_PLUGIN_DIR . '/seo-by-rank-math/vendor/autoload.php';
			if ( is_readable( $autoload ) ) {
				require_once $autoload;
			}
		}

		if ( ! class_exists( '\RankMath\Redirections\DB' ) ) {
			return;
		}

		$permission = 'akibara_rm_redirections_can_manage';

		\wp_register_ability(
			'akibara/redirections-status',
			array(
				'category'            => 'akibara-operations',
				'label'               => 'Estado de redirecciones Rank Math',
				'description'         => 'Devuelve conteos de redirecciones Rank Math por estado y código HTTP, usando el lector oficial del plugin. Solo lectura.',
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'properties'           => new stdClass(),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'mcp'          => array( 'public' => true ),
				),
				'permission_callback' => $permission,
				'execute_callback'    => static function () {
					return array( 'counts' => akibara_rm_redirections_counts() );
				},
			)
		);

		\wp_register_ability(
			'akibara/redirections-snapshot',
			array(
				'category'            => 'akibara-operations',
				'label'               => 'Snapshot de redirecciones Rank Math',
				'description'         => 'Devuelve las filas de redirecciones (id, sources, destino, código, estado) vía el lector oficial de Rank Math. Solo lectura.',
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'properties'           => array(
						'statuses' => array(
							'type'    => 'array',
							'items'   => array(
								'type' => 'string',
								'enum' => array( 'active', 'inactive', 'trashed' ),
							),
							'default' => array( 'active', 'inactive' ),
						),
					),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'mcp'          => array( 'public' => true ),
				),
				'permission_callback' => $permission,
				'execute_callback'    => static function ( $input = array() ) {
					$statuses = (array) ( $input['statuses'] ?? array( 'active', 'inactive' ) );
					$rows     = akibara_rm_redirections_rows( $statuses );

					// Declara el truncamiento con el mismo contrato de counts():
					// _incompleto contiene un mapa estado → total real.
					$truncado = (array) ( $GLOBALS['akibara_rm_redirections_truncado'] ?? array() );
					$out      = array(
						'total' => count( $rows ),
						'rows'  => $rows,
					);
					if ( array() !== $truncado ) {
						$out['_incompleto'] = $truncado;
					}

					return $out;
				},
			)
		);

		\wp_register_ability(
			'akibara/redirections-trash',
			array(
				'category'            => 'akibara-operations',
				'label'               => 'Enviar redirecciones Rank Math a papelera',
				'description'         => 'Mueve IDs explícitos de redirecciones a la papelera con la API oficial (DB::change_status + Cache::purge). Fail-closed: exige expected_count y dry_run=true por defecto. Reversible con akibara/redirections-restore.',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'ids'            => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'integer' ),
							'minItems' => 1,
						),
						'expected_count' => array( 'type' => 'integer' ),
						'dry_run'        => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
					'required'             => array( 'ids', 'expected_count' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'mcp'          => array( 'public' => true ),
				),
				'permission_callback' => $permission,
				'execute_callback'    => static function ( $input = array() ) {
					return akibara_rm_redirections_change_status( (array) $input, array( 'active', 'inactive' ), 'trashed' );
				},
			)
		);

		\wp_register_ability(
			'akibara/redirections-restore',
			array(
				'category'            => 'akibara-operations',
				'label'               => 'Restaurar redirecciones Rank Math desde papelera',
				'description'         => 'Restaura IDs explícitos desde la papelera a estado activo con la API oficial. Fail-closed: exige expected_count y dry_run=true por defecto.',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'ids'            => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'integer' ),
							'minItems' => 1,
						),
						'expected_count' => array( 'type' => 'integer' ),
						'dry_run'        => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
					'required'             => array( 'ids', 'expected_count' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'mcp'          => array( 'public' => true ),
				),
				'permission_callback' => $permission,
				'execute_callback'    => static function ( $input = array() ) {
					return akibara_rm_redirections_change_status( (array) $input, array( 'trashed' ), 'active' );
				},
			)
		);
	}
);
