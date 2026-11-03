<?php
/**
 * Guardrail Inviolable de Higiene AST y Prevención de Código Espagueti.
 *
 * Utiliza PhpParser v5 para auditar:
 * 1. Variables de iteración no utilizadas en bucles foreach ($k => $v).
 * 2. Variables locales asignadas y nunca leídas en su ámbito (dead assignments).
 * 3. Diagnóstico de lecturas SQL de stock y bloqueo de escrituras directas.
 *
 * Uso:
 *   php tools/guardrail-ast-hygiene.php [--path=ruta] [--fail-on-error] [--fail-on-warning]
 *
 * @package Akibara\Quality
 */

declare( strict_types = 1 );

require_once __DIR__ . '/akibara-business/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

final class AstScopeAnalyzer extends NodeVisitorAbstract {
	/**
	 * @var array<int, array{file: string, line: int, message: string, type: string}>
	 */
	public array $issues = array();

	private string $current_file = '';

	/**
	 * Pila de ámbitos (funciones, métodos, closures).
	 *
	 * @var array<int, array{
	 *     name: string,
	 *     start_line: int,
	 *     assigned: array<string, int>,
	 *     read: array<string, bool>,
	 *     foreach_keys: array<string, int>,
	 *     foreach_keys_read: array<string, bool>
	 * }>
	 */
	private array $scope_stack = array();

	public function set_file( string $file ): void {
		$this->current_file = $file;
	}

	/** Resuelve únicamente rutas literales; nunca ejecuta el archivo auditado. */
	private function literal_path( Node $expression, array $values ): ?string {
		if ( $expression instanceof Node\Scalar\String_ ) {
			return $expression->value;
		}
		if ( $expression instanceof Node\Scalar\MagicConst\Dir ) {
			return dirname( $this->current_file );
		}
		if ( $expression instanceof Expr\Variable && is_string( $expression->name ) ) {
			return $values[ $expression->name ] ?? null;
		}
		if ( $expression instanceof Expr\ConstFetch && 'AKIBARA_BUSINESS_DIR' === (string) $expression->name
			&& preg_match( '#^(.*/plugins/akibara-business/)#', $this->current_file, $matches ) ) {
			return $matches[1];
		}
		if ( $expression instanceof Expr\BinaryOp\Concat ) {
			$left = $this->literal_path( $expression->left, $values );
			$right = $this->literal_path( $expression->right, $values );
			return null !== $left && null !== $right ? $left . $right : null;
		}
		if ( $expression instanceof Expr\FuncCall && $expression->name instanceof Node\Name
			&& 'dirname' === strtolower( (string) $expression->name ) && isset( $expression->args[0] ) ) {
			$value = $this->literal_path( $expression->args[0]->value, $values );
			$levels = $expression->args[1]->value ?? null;
			if ( null !== $value && ( null === $levels || ( $levels instanceof Node\Scalar\Int_ && $levels->value > 0 ) ) ) {
				return dirname( $value, null === $levels ? 1 : $levels->value );
			}
		}
		return null;
	}

	/** Fragmentos estáticos de una consulta; los valores dinámicos no se ejecutan. */
	private function sql_text( Node $node ): string {
		if ( $node instanceof Node\Scalar\String_ || $node instanceof Node\InterpolatedStringPart ) {
			return $node->value;
		}
		if ( $node instanceof Node\Scalar\InterpolatedString ) {
			return implode( '', array_map( fn( $part ) => $this->sql_text( $part ), $node->parts ) );
		}
		if ( $node instanceof Expr\BinaryOp\Concat ) {
			return $this->sql_text( $node->left ) . $this->sql_text( $node->right );
		}
		if ( $node instanceof Expr\Variable && is_string( $node->name ) && ! empty( $this->scope_stack ) ) {
			$scope = $this->scope_stack[ count( $this->scope_stack ) - 1 ];
			return $scope['values'][ $node->name ] ?? '?';
		}
		return '?';
	}

	public function enterNode( Node $node ) {
		// Lecturas por lotes son diagnósticos; las escrituras requieren la API de inventario.
		if ( ! $node->getAttribute( 'akb_sql_reported' ) && ( $node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\InterpolatedString || $node instanceof Expr\BinaryOp\Concat ) ) {
			$val = $this->sql_text( $node );
			if ( stripos( $val, '_stock_status' ) !== false && preg_match( '/\b(SELECT|UPDATE|INSERT|DELETE|REPLACE)\b/i', $val, $sql_verb ) ) {
				// Permitir en scripts de migración o guardrails explícitos.
				if ( ! str_contains( $this->current_file, 'guardrail' ) && ! str_contains( $this->current_file, 'migration' ) && ! str_contains( $this->current_file, 'tests/' ) ) {
					foreach ( ( new \PhpParser\NodeFinder() )->findInstanceOf( $node, Node::class ) as $fragment ) {
						$fragment->setAttribute( 'akb_sql_reported', true );
					}
					$this->issues[] = array(
						'file'    => $this->current_file,
						'line'    => $node->getStartLine(),
						'message' => 'SELECT' === strtoupper( $sql_verb[1] )
							? 'Lectura SQL de stock: revisar política comercial y presupuesto de consultas; no implica un defecto por sí sola.'
							: 'Escritura SQL directa de stock: usar CRUD de WooCommerce para preservar sincronización e inventario.',
						'type'    => 'SELECT' === strtoupper( $sql_verb[1] ) ? 'SQL_STOCK_READ' : 'SQL_STOCK_WRITE',
					);
				}
			}
		}

		// Entrar a un nuevo ámbito de función/método/closure.
		if ( $node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction ) {
			// Capturar una variable también la lee en el ámbito que crea la función.
			if ( ! empty( $this->scope_stack ) && ( $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction ) ) {
				$outer = &$this->scope_stack[ count( $this->scope_stack ) - 1 ];
				$parameters = array();
				foreach ( $node->params as $parameter ) {
					if ( $parameter->var instanceof Expr\Variable && is_string( $parameter->var->name ) ) {
						$parameters[ $parameter->var->name ] = true;
					}
				}
				$captures = $node instanceof Expr\Closure
					? array_map( fn( $use ) => $use->var, $node->uses )
					: ( new \PhpParser\NodeFinder() )->findInstanceOf( $node->expr, Expr\Variable::class );
				foreach ( $captures as $capture ) {
					if ( is_string( $capture->name ) && ! isset( $parameters[ $capture->name ] ) ) {
						$outer['read'][ $capture->name ] = true;
						if ( isset( $outer['foreach_keys'][ $capture->name ] ) ) {
							$outer['foreach_keys_read'][ $capture->name ] = true;
						}
					}
				}
				unset( $outer );
			}
			$name = $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_ ? (string) $node->name : 'closure';
			$this->scope_stack[] = array(
				'name'              => $name,
				'start_line'        => $node->getStartLine(),
				'assigned'          => array(),
				'read'              => array(),
				'foreach_keys'      => array(),
				'foreach_keys_read' => array(),
				'values'            => array(),
			);
			foreach ( $node->params as $param ) {
				if ( $param->var instanceof Expr\Variable ) {
					$param->var->setAttribute( 'is_write_target', true );
					if ( $param->byRef && is_string( $param->var->name ) ) {
						$this->scope_stack[ count( $this->scope_stack ) - 1 ]['read'][ $param->var->name ] = true;
					}
				}
			}
			return null;
		}

		if ( empty( $this->scope_stack ) ) {
			return null;
		}

		$current_scope = &$this->scope_stack[ count( $this->scope_stack ) - 1 ];

		if ( $node instanceof Stmt\Catch_ && $node->var instanceof Expr\Variable ) {
			$node->var->setAttribute( 'is_write_target', true );
		}

		if ( $node instanceof Stmt\StaticVar && $node->var instanceof Expr\Variable ) {
			$node->var->setAttribute( 'is_write_target', true );
		}

		if ( $node instanceof Stmt\Global_ ) {
			foreach ( $node->vars as $global_var ) {
				if ( $global_var instanceof Expr\Variable ) {
					$global_var->setAttribute( 'is_write_target', true );
				}
			}
		}

		// Bucles foreach: registrar $key => $val.
		if ( $node instanceof Stmt\Foreach_ ) {
			if ( $node->keyVar instanceof Expr\Variable && is_string( $node->keyVar->name ) ) {
				$node->keyVar->setAttribute( 'is_write_target', true );
				$key_name = $node->keyVar->name;
				if ( ! str_starts_with( $key_name, '_' ) && ! str_starts_with( $key_name, 'unused' ) && ! str_starts_with( $key_name, 'dummy' ) ) {
					$current_scope['foreach_keys'][ $key_name ] = $node->getStartLine();
				}
			}
			if ( $node->valueVar instanceof Expr\Variable ) {
				$node->valueVar->setAttribute( 'is_write_target', true );
			}
		}

		// Asignaciones directas $var = expr.
		if ( $node instanceof Expr\Assign && $node->var instanceof Expr\Variable && is_string( $node->var->name ) ) {
			$node->var->setAttribute( 'is_write_target', true );
			$var_name = $node->var->name;
			$current_scope['values'][ $var_name ] = $this->literal_path( $node->expr, $current_scope['values'] );
			// Ignorar variables mágicas, globals o intencionales.
			if ( ! in_array( $var_name, array( 'this', 'wpdb', 'post', 'product' ), true ) && ! str_starts_with( $var_name, '_' ) ) {
				$current_scope['assigned'][ $var_name ] = $node->getStartLine();
			}
		}

		// Las plantillas incluidas comparten las variables del método que las carga.
		if ( $node instanceof Expr\Include_ ) {
			$template = $this->literal_path( $node->expr, $current_scope['values'] );
			if ( null !== $template && is_file( $template ) && is_readable( $template ) ) {
				$nodes = ( new ParserFactory() )->createForHostVersion()->parse( file_get_contents( $template ) );
				$variables = ( new \PhpParser\NodeFinder() )->findInstanceOf( $nodes ?? array(), Expr\Variable::class );
				foreach ( $variables as $variable ) {
					if ( is_string( $variable->name ) ) {
						$current_scope['read'][ $variable->name ] = true;
						if ( isset( $current_scope['foreach_keys'][ $variable->name ] ) ) {
							$current_scope['foreach_keys_read'][ $variable->name ] = true;
						}
					}
				}
			}
		}

		// Lectura de variables.
		if ( $node instanceof Expr\Variable && is_string( $node->name ) ) {
			if ( ! $node->getAttribute( 'is_write_target' ) ) {
				$read_name = $node->name;
				$current_scope['read'][ $read_name ] = true;
				if ( isset( $current_scope['foreach_keys'][ $read_name ] ) ) {
					$current_scope['foreach_keys_read'][ $read_name ] = true;
				}
			}
		}

		return null;
	}

	public function leaveNode( Node $node ) {
		if ( $node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction ) {
			$finished_scope = array_pop( $this->scope_stack );
			if ( $finished_scope !== null ) {
				// Evaluar foreach keys no leídos.
				foreach ( $finished_scope['foreach_keys'] as $k_name => $k_line ) {
					if ( empty( $finished_scope['foreach_keys_read'][ $k_name ] ) ) {
						$this->issues[] = array(
							'file'    => $this->current_file,
							'line'    => $k_line,
							'message' => sprintf( 'Clave de bucle foreach "$%s" no se utiliza en el cuerpo del bucle en "%s".', $k_name, $finished_scope['name'] ),
							'type'    => 'UNUSED_FOREACH_KEY',
						);
					}
				}

				// Evaluar asignaciones muertas (variables asignadas pero nunca leídas).
				foreach ( $finished_scope['assigned'] as $var_name => $var_line ) {
					if ( empty( $finished_scope['read'][ $var_name ] ) ) {
						$this->issues[] = array(
							'file'    => $this->current_file,
							'line'    => $var_line,
							'message' => sprintf( 'Variable local "$%s" asignada en línea %d pero nunca leída en el método "%s".', $var_name, $var_line, $finished_scope['name'] ),
							'type'    => 'DEAD_ASSIGNMENT',
						);
					}
				}
			}
		}

		return null;
	}
}

// Entradas explícitas permiten verificar el instrumento con fixtures aislados.
$target_paths = array();
$fail_errors = false;
$fail_warnings = false;
for ( $i = 1; $i < count( $argv ); $i++ ) {
	$argument = $argv[ $i ];
	if ( '--fail-on-error' === $argument ) {
		$fail_errors = true;
	} elseif ( '--fail-on-warning' === $argument ) {
		$fail_warnings = true;
	} elseif ( str_starts_with( $argument, '--path=' ) ) {
		$target_paths[] = substr( $argument, 7 );
	} elseif ( '--path' === $argument && isset( $argv[ $i + 1 ] ) ) {
		$target_paths[] = $argv[ ++$i ];
	} elseif ( '--help' === $argument || '-h' === $argument ) {
		echo "Uso: php tools/guardrail-ast-hygiene.php [--path=ruta] [--fail-on-error] [--fail-on-warning]" . PHP_EOL;
		exit( 0 );
	} else {
		fwrite( STDERR, "Opción desconocida o incompleta: {$argument}" . PHP_EOL );
		exit( 2 );
	}
}

$default_paths = array(
	__DIR__ . '/../public_html/wp-content/plugins/akibara-business/src',
	__DIR__ . '/../public_html/wp-content/plugins/akibara-search/src',
	__DIR__ . '/../public_html/wp-content/plugins/akibara-rewards/src',
	__DIR__ . '/../public_html/wp-content/plugins/akibara-search/templates/admin',
	__DIR__ . '/../public_html/wp-content/plugins/akibara-business/templates/admin',
);

$target_paths = $target_paths ?: $default_paths;
$parser = ( new ParserFactory() )->createForHostVersion();
$analyzer = new AstScopeAnalyzer();
$traverser = new NodeTraverser();
$traverser->addVisitor( $analyzer );

$total_files = 0;
$start_time = microtime( true );

echo "=== Guardrail de Higiene AST y Prevención de Código Espagueti ===" . PHP_EOL;

$scan_errors = array();
$files = array();
foreach ( $target_paths as $target ) {
	if ( ! file_exists( $target ) ) {
		$scan_errors[] = "Ruta inexistente: {$target}";
		continue;
	}
	$iterator = is_dir( $target )
		? new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $target, FilesystemIterator::SKIP_DOTS ) )
		: array( new SplFileInfo( $target ) );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
			$files[ $file->getRealPath() ] = true;
		}
	}
}
ksort( $files );
foreach ( array_keys( $files ) as $filepath ) {
	try {
		$code = file_get_contents( $filepath );
		if ( false === $code ) {
			throw new RuntimeException( 'No se pudo leer el archivo.' );
		}
		$analyzer->set_file( $filepath );
		$stmts = $parser->parse( $code );
		if ( null !== $stmts ) {
			$traverser->traverse( $stmts );
		}
		$total_files++;
	} catch ( Throwable $e ) {
		$scan_errors[] = "{$filepath}: " . $e->getMessage();
	}
}
if ( 0 === $total_files && empty( $scan_errors ) ) {
	$scan_errors[] = 'No se analizó ningún archivo PHP.';
}

$elapsed = round( microtime( true ) - $start_time, 2 );
echo 'Alcance: análisis estático de sintaxis y usos locales; no demuestra equivalencia comercial ni resuelve SQL totalmente dinámico o todas las plantillas de WordPress.' . PHP_EOL;

echo "Archivos analizados: {$total_files} en {$elapsed}s" . PHP_EOL;

$errors = array_filter( $analyzer->issues, fn( $i ) => $i['type'] === 'SQL_STOCK_WRITE' || $i['type'] === 'UNUSED_FOREACH_KEY' );
$warnings = array_filter( $analyzer->issues, fn( $i ) => $i['type'] === 'DEAD_ASSIGNMENT' );
$stock_reads = array_filter( $analyzer->issues, fn( $i ) => $i['type'] === 'SQL_STOCK_READ' );

echo "Problemas detectados: " . count( $analyzer->issues ) . PHP_EOL;
echo "  - Claves de foreach no utilizadas: " . count( array_filter( $analyzer->issues, fn( $i ) => $i['type'] === 'UNUSED_FOREACH_KEY' ) ) . PHP_EOL;
echo "  - Escrituras SQL directas de stock: " . count( array_filter( $errors, fn( $i ) => 'SQL_STOCK_WRITE' === $i['type'] ) ) . PHP_EOL;
echo "  - Lecturas SQL de stock (diagnóstico): " . count( $stock_reads ) . PHP_EOL;
echo "  - Asignaciones sin lectura local probada: " . count( $warnings ) . PHP_EOL;

if ( ! empty( $errors ) ) {
	echo PHP_EOL . "--- LISTA DE DEFECTOS CRÍTICOS ---" . PHP_EOL;
	foreach ( $errors as $issue ) {
		$short_file = str_replace( dirname( __DIR__ ) . '/', '', $issue['file'] );
		echo " [{$issue['type']}] {$short_file}:{$issue['line']} - {$issue['message']}" . PHP_EOL;
	}
}

foreach ( array_merge( $warnings, $stock_reads ) as $issue ) {
	$short_file = str_replace( dirname( __DIR__ ) . '/', '', $issue['file'] );
	echo " [{$issue['type']}] {$short_file}:{$issue['line']} - {$issue['message']}" . PHP_EOL;
}
foreach ( $scan_errors as $error ) {
	fwrite( STDERR, " [SCAN_ERROR] {$error}" . PHP_EOL );
}
if ( ! empty( $scan_errors ) || ( $fail_errors && ! empty( $errors ) ) || ( $fail_warnings && ! empty( $warnings ) ) ) {
	fwrite( STDERR, PHP_EOL . 'FALLA DE GUARDRAIL: análisis incompleto o hallazgos bloqueantes.' . PHP_EOL );
	exit( 1 );
}

if ( ! empty( $analyzer->issues ) ) {
	echo PHP_EOL . 'Análisis completado con hallazgos; revisar los avisos anteriores.' . PHP_EOL;
} else {
	echo PHP_EOL . 'Sin hallazgos en los archivos analizados.' . PHP_EOL;
}
exit( 0 );
