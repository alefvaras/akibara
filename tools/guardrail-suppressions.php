<?php
/**
 * Guardrail anti-supresiones R1-R5 para PHP, JS y CSS del runtime de Akibara.
 *
 * Reglas implementadas:
 * - R1: Bloquea anotaciones de omisión (PHPStan/Psalm/PHPCS) dentro de T_INLINE_HTML en el runtime.
 * - R2: Trinquete de conteo por componente respecto a --base=<ref> o --staged (sin archivos baseline).
 * - R3: Exige identificador + justificación explícita (>= 25 caracteres) en toda supresión nueva,
 *       y cierre obligatorio enable para todo bloque disable en PHPCS.
 * - R4: Veta identificadores críticos en líneas nuevas (complexity.*, CyclomaticComplexity*,
 *       NestingLevel*, function.alreadyNarrowedType, TypeDoesNotContainType, RedundantCondition,
 *       UndefinedFunction).
 * - R5: Ejecuta vendor/bin/psalm --find-unused-psalm-suppress --no-progress --output-format=compact
 *       y falla ante UnusedPsalmSuppress (--r5=all por defecto, o --r5=changed / --r5=off).
 *
 * @package Akibara\Quality
 */

declare( strict_types = 1 );

final class GuardrailSuppressions {

	/** @var array<string, string> */
	private const COMPONENTS = array(
		'akibara-business'             => 'public_html/wp-content/plugins/akibara-business',
		'akibara-search'               => 'public_html/wp-content/plugins/akibara-search',
		'akibara-rewards'              => 'public_html/wp-content/plugins/akibara-rewards',
		'akibara-cart-checkout-blocks' => 'public_html/wp-content/plugins/akibara-cart-checkout-blocks',
		'akibara-gp'                   => 'public_html/wp-content/themes/akibara-gp',
		'mu-plugins'                   => 'public_html/wp-content/mu-plugins',
	);

	/** @var list<string> */
	private const FORBIDDEN_R4_IDS = array(
		'complexity.functionLike',
		'complexity.classLike',
		'function.alreadyNarrowedType',
		'TypeDoesNotContainType',
		'RedundantCondition',
		'UndefinedFunction',
	);

	/** @var list<string> */
	private const FORBIDDEN_R4_PREFIXES = array(
		'Generic.Metrics.CyclomaticComplexity',
		'Generic.Metrics.NestingLevel',
	);

	private const MIN_JUSTIFICATION_LEN = 25;

	public static function main( array $argv ): int {
		try {
			$opts = self::parse_args( $argv );
		} catch ( \InvalidArgumentException $e ) {
			fwrite( STDERR, 'Uso inválido: ' . $e->getMessage() . PHP_EOL );
			return 2;
		}

		$root     = $opts['root'];
		$staged   = $opts['staged'];
		$base_ref = $opts['base'];
		$r5_mode  = $opts['r5'];
		$psalm    = $opts['psalm_bin'] ?? ( $root . '/vendor/bin/psalm' );

		if ( ! is_dir( $root . '/.git' ) && ! is_file( $root . '/.git' ) ) {
			fwrite( STDERR, "NO MEDIDO: {$root} no es un repositorio Git." . PHP_EOL );
			return 2;
		}

		$base_target = $staged ? 'HEAD' : (string) $base_ref;
		if ( ! self::git_ref_exists( $root, $base_target ) ) {
			fwrite( STDERR, "NO MEDIDO: la referencia Git base '{$base_target}' no existe." . PHP_EOL );
			return 2;
		}

		$violations = array();

		// R1: Siempre inspecciona T_INLINE_HTML en todos los archivos PHP de los 6 directorios runtime.
		foreach ( self::collect_r1_files( $root, $staged ) as $rel => $content ) {
			foreach ( self::check_r1_inline_html( $rel, $content ) as $err ) {
				$violations[] = $err;
			}
		}

		// R2: Trinquete de conteo por componente (base git show vs target actual).
		$base_counts   = self::count_suppressions_in_git_ref( $root, $base_target );
		$target_counts = $staged
			? self::count_suppressions_in_staged_tree( $root )
			: self::count_suppressions_in_working_tree( $root );

		foreach ( self::COMPONENTS as $component => $_dir ) {
			$before = $base_counts[ $component ] ?? 0;
			$after  = $target_counts[ $component ] ?? 0;
			if ( $after > $before ) {
				$delta        = $after - $before;
				$violations[] = "[R2 Trinquete] {$component}: las supresiones subieron de {$before} a {$after} (+{$delta}). Corrige la causa raíz o retira supresiones equivalentes antes de continuar; prohibido incrementar la deuda.";
			}
		}

		// R3 + R4: Toda supresión agregada exige motivo >= 25 caracteres y no puede usar identificadores vetados.
		$changed_files = self::collect_changed_files_and_added_lines( $root, $staged, $base_target );
		foreach ( $changed_files as $rel => $info ) {
			$content = $info['content'];
			$added   = $info['added_lines'];
			if ( null === $content ) {
				continue;
			}
			foreach ( self::check_r3_r4_on_file( $rel, $content, $added ) as $err ) {
				$violations[] = $err;
			}
		}

		// R5: Psalm --find-unused-psalm-suppress = 0.
		if ( 'off' !== $r5_mode ) {
			foreach ( self::check_r5_unused_psalm( $root, $psalm, $r5_mode, array_keys( $changed_files ) ) as $err ) {
				$violations[] = $err;
			}
		}

		if ( array() !== $violations ) {
			fwrite( STDERR, 'BLOQUEADO por Guardrail Anti-Supresiones (R1-R5): ' . count( $violations ) . ' infracción(es).' . PHP_EOL );
			foreach ( $violations as $msg ) {
				fwrite( STDERR, '  - ' . $msg . PHP_EOL );
			}
			return 1;
		}

		$summary = array();
		foreach ( self::COMPONENTS as $component => $_dir ) {
			$b         = $base_counts[ $component ] ?? 0;
			$a         = $target_counts[ $component ] ?? 0;
			$summary[] = "{$component}={$a}(base:{$b})";
		}
		fwrite( STDOUT, 'PASS: Guardrail Anti-Supresiones R1-R5 limpio [' . implode( ', ', $summary ) . '].' . PHP_EOL );
		return 0;
	}

	/**
	 * @param array<int, string> $argv
	 * @return array{root: string, staged: bool, base: ?string, r5: string, psalm_bin: ?string}
	 */
	private static function parse_args( array $argv ): array {
		$root      = dirname( __DIR__ );
		$staged    = false;
		$base      = null;
		$r5        = 'all';
		$psalm_bin = null;

		foreach ( array_slice( $argv, 1 ) as $arg ) {
			if ( '--staged' === $arg ) {
				$staged = true;
			} elseif ( str_starts_with( $arg, '--base=' ) ) {
				$base = substr( $arg, 7 );
			} elseif ( str_starts_with( $arg, '--root=' ) ) {
				$root = rtrim( substr( $arg, 7 ), '/' );
			} elseif ( str_starts_with( $arg, '--r5=' ) ) {
				$r5 = substr( $arg, 5 );
				if ( ! in_array( $r5, array( 'all', 'changed', 'off' ), true ) ) {
					throw new \InvalidArgumentException( '--r5 debe ser all, changed u off' );
				}
			} elseif ( str_starts_with( $arg, '--psalm-bin=' ) ) {
				$psalm_bin = substr( $arg, 12 );
			} else {
				throw new \InvalidArgumentException( "opción no reconocida: {$arg}" );
			}
		}

		if ( $staged && null !== $base ) {
			throw new \InvalidArgumentException( 'No combines --staged con --base' );
		}
		if ( ! $staged && ( null === $base || '' === $base ) ) {
			throw new \InvalidArgumentException( 'Especifica --staged o --base=<git-ref>' );
		}

		return array(
			'root'      => $root,
			'staged'    => $staged,
			'base'      => $base,
			'r5'        => $r5,
			'psalm_bin' => $psalm_bin,
		);
	}

	private static function component_for_path( string $rel ): ?string {
		foreach ( self::COMPONENTS as $component => $prefix ) {
			if ( str_starts_with( $rel, $prefix . '/' ) ) {
				return $component;
			}
		}
		return null;
	}

	private static function is_runtime_analyzable_file( string $rel ): bool {
		if ( null === self::component_for_path( $rel ) ) {
			return false;
		}
		if ( preg_match( '#/(?:vendor|node_modules|\.git)/#', $rel ) ) {
			return false;
		}
		if ( preg_match( '/\.min\.(?:js|css)$/i', $rel ) ) {
			return false;
		}
		return 1 === preg_match( '/\.(?:php|js|mjs|cjs|css)$/i', $rel );
	}

	/**
	 * @return array<string, string>
	 */
	private static function collect_r1_files( string $root, bool $staged ): array {
		$out = array();
		if ( $staged ) {
			$staged_map = self::read_staged_index_contents( $root );
			foreach ( self::COMPONENTS as $dir ) {
				$abs_dir = $root . '/' . $dir;
				if ( ! is_dir( $abs_dir ) ) {
					continue;
				}
				foreach ( self::list_files_on_disk( $root, $dir ) as $rel ) {
					if ( str_ends_with( strtolower( $rel ), '.php' ) && is_file( $root . '/' . $rel ) ) {
						$out[ $rel ] = $staged_map[ $rel ] ?? (string) file_get_contents( $root . '/' . $rel );
					}
				}
			}
			foreach ( $staged_map as $rel => $content ) {
				if ( self::is_runtime_analyzable_file( $rel ) && str_ends_with( strtolower( $rel ), '.php' ) ) {
					$out[ $rel ] = $content;
				}
			}
			return $out;
		}

		foreach ( self::COMPONENTS as $dir ) {
			if ( ! is_dir( $root . '/' . $dir ) ) {
				continue;
			}
			foreach ( self::list_files_on_disk( $root, $dir ) as $rel ) {
				if ( str_ends_with( strtolower( $rel ), '.php' ) ) {
					$content = file_get_contents( $root . '/' . $rel );
					if ( false !== $content ) {
						$out[ $rel ] = $content;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * @return list<string>
	 */
	private static function check_r1_inline_html( string $rel, string $content ): array {
		$errors = array();
		$tokens = @token_get_all( $content );
		foreach ( $tokens as $token ) {
			if ( ! is_array( $token ) || T_INLINE_HTML !== $token[0] ) {
				continue;
			}
			$html       = $token[1];
			$start_line = $token[2];
			$r1_regex   = '/(@' . 'phpstan-ignore(?:-[a-z-]+)?|@' . 'psalm-suppress|\b' . 'phpcs' . ':(?:ignore|disable|enable))/i';
			if ( preg_match( $r1_regex, $html, $m, PREG_OFFSET_CAPTURE ) ) {
				$offset   = $m[0][1];
				$line_num = $start_line + substr_count( substr( $html, 0, $offset ), "\n" );
				$errors[] = "[R1 Fuga a HTML] {$rel}:{$line_num}: se encontró '{$m[1][0]}' dentro de HTML enviado al cliente (T_INLINE_HTML). Mueve cualquier comentario de análisis a un bloque <?php ... ?> y corrige el problema de origen.";
			}
		}
		return $errors;
	}

	/**
	 * @return array<string, int>
	 */
	private static function count_suppressions_in_git_ref( string $root, string $ref ): array {
		$counts = array_fill_keys( array_keys( self::COMPONENTS ), 0 );
		$files  = self::git_ls_tree( $root, $ref );
		foreach ( $files as $rel ) {
			$component = self::component_for_path( $rel );
			if ( null === $component || ! self::is_runtime_analyzable_file( $rel ) ) {
				continue;
			}
			$content = self::git_show( $root, "{$ref}:{$rel}" );
			if ( null !== $content ) {
				$counts[ $component ] += count( self::extract_suppressions_from_file( $rel, $content ) );
			}
		}
		return $counts;
	}

	/**
	 * @return array<string, int>
	 */
	private static function count_suppressions_in_working_tree( string $root ): array {
		$counts = array_fill_keys( array_keys( self::COMPONENTS ), 0 );
		foreach ( self::COMPONENTS as $component => $dir ) {
			if ( ! is_dir( $root . '/' . $dir ) ) {
				continue;
			}
			foreach ( self::list_files_on_disk( $root, $dir ) as $rel ) {
				if ( ! self::is_runtime_analyzable_file( $rel ) ) {
					continue;
				}
				$content = file_get_contents( $root . '/' . $rel );
				if ( false !== $content ) {
					$counts[ $component ] += count( self::extract_suppressions_from_file( $rel, $content ) );
				}
			}
		}
		return $counts;
	}

	/**
	 * @return array<string, int>
	 */
	private static function count_suppressions_in_staged_tree( string $root ): array {
		$counts     = array_fill_keys( array_keys( self::COMPONENTS ), 0 );
		$index_list = self::git_ls_files_staged( $root );
		foreach ( $index_list as $rel ) {
			$component = self::component_for_path( $rel );
			if ( null === $component || ! self::is_runtime_analyzable_file( $rel ) ) {
				continue;
			}
			$content = self::git_show( $root, ":{$rel}" );
			if ( null !== $content ) {
				$counts[ $component ] += count( self::extract_suppressions_from_file( $rel, $content ) );
			}
		}
		return $counts;
	}

	/**
	 * @return list<array{line: int, kind: string, raw: string}>
	 */
	private static function extract_suppressions_from_file( string $rel, string $content ): array {
		$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
		if ( 'php' === $ext ) {
			return self::extract_php_comment_suppressions( $content );
		}
		if ( in_array( $ext, array( 'js', 'mjs', 'cjs', 'css' ), true ) ) {
			return self::extract_asset_comment_suppressions( $content, $ext );
		}
		return array();
	}

	/**
	 * @return list<array{line: int, kind: string, raw: string}>
	 */
	private static function extract_php_comment_suppressions( string $content ): array {
		$items  = array();
		$tokens    = @token_get_all( $content );
		$php_regex = '/(@' . 'phpstan-ignore(?:-[a-z-]+)?|@' . 'psalm-suppress|\b' . 'phpcs' . ':(?:ignore|disable))\b[^\r\n*]*/';
		foreach ( $tokens as $token ) {
			if ( ! is_array( $token ) || ( T_COMMENT !== $token[0] && T_DOC_COMMENT !== $token[0] ) ) {
				continue;
			}
			$text  = $token[1];
			$lines = explode( "\n", $text );
			foreach ( $lines as $offset => $line_text ) {
				$line_no = $token[2] + $offset;
				if ( preg_match_all( $php_regex, $line_text, $matches ) ) {
					foreach ( $matches[0] as $idx => $raw ) {
						$items[] = array(
							'line' => $line_no,
							'kind' => strtolower( $matches[1][ $idx ] ),
							'raw'  => trim( $raw ),
						);
					}
				}
			}
		}
		return $items;
	}

	/**
	 * @return list<array{line: int, kind: string, raw: string}>
	 */
	private static function extract_asset_comment_suppressions( string $content, string $ext ): array {
		$items      = array();
		$lines      = explode( "\n", $content );
		$css_pat    = '#/\*.*?(' . 'stylelint' . '-disable(?:-next-line|-line)?\b[^\r\n*]*)#';
		$js_pat     = '#(?://|/\*)\s*((?:eslint|' . 'stylelint' . ')-disable(?:-next-line|-line)?\b[^\r\n]*)#';
		$pattern    = 'css' === $ext ? $css_pat : $js_pat;
		$style_kind = 'stylelint' . '-disable';
		$eslint_knd = 'eslint' . '-disable';
		foreach ( $lines as $idx => $line_text ) {
			if ( preg_match( $pattern, $line_text, $m ) ) {
				$raw  = preg_replace( '#\*/.*$#', '', $m[1] ) ?? $m[1];
				$kind = str_starts_with( strtolower( trim( $raw ) ), 'stylelint' ) ? $style_kind : $eslint_knd;
				$items[] = array(
					'line' => $idx + 1,
					'kind' => $kind,
					'raw'  => trim( $raw ),
				);
			}
		}
		return $items;
	}

	/**
	 * @param array<int, true> $added_lines
	 * @return list<string>
	 */
	private static function check_r3_r4_on_file( string $rel, string $content, array $added_lines ): array {
		if ( ! self::is_runtime_analyzable_file( $rel ) ) {
			return array();
		}

		$errors       = array();
		$ext          = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
		$suppressions = self::extract_suppressions_from_file( $rel, $content );

		foreach ( $suppressions as $item ) {
			$line = $item['line'];
			if ( ! isset( $added_lines[ $line ] ) ) {
				continue;
			}
			$kind = $item['kind'];
			$raw  = $item['raw'];

			if ( str_starts_with( $kind, '@' . 'phpstan-ignore' ) ) {
				foreach ( self::validate_phpstan_suppression( $rel, $line, $raw ) as $err ) {
					$errors[] = $err;
				}
			} elseif ( ( '@' . 'psalm-suppress' ) === $kind ) {
				foreach ( self::validate_psalm_suppression( $rel, $line, $raw ) as $err ) {
					$errors[] = $err;
				}
			} elseif ( str_starts_with( $kind, 'phpcs' . ':' ) ) {
				foreach ( self::validate_phpcs_suppression( $rel, $line, $kind, $raw ) as $err ) {
					$errors[] = $err;
				}
			} elseif ( ( 'eslint' . '-disable' ) === $kind || ( 'stylelint' . '-disable' ) === $kind ) {
				foreach ( self::validate_lint_disable( $rel, $line, $kind, $raw ) as $err ) {
					$errors[] = $err;
				}
			}
		}

		// Cualquier archivo PHP modificado con bloques de desactivación PHPCS debe quedar cerrado.
		if ( 'php' === $ext && array() !== $added_lines ) {
			foreach ( self::check_unclosed_phpcs_disable( $rel, $content ) as $err ) {
				$errors[] = $err;
			}
		}

		return $errors;
	}

	/**
	 * @return list<string>
	 */
	private static function validate_phpstan_suppression( string $rel, int $line, string $raw ): array {
		$errors  = array();
		$tag     = '@' . 'phpstan-ignore';
		$re_line = '/^@' . 'phpstan-ignore-(?:line|next-line)\b/i';
		$re_fmt  = '/^@' . 'phpstan-ignore\s+([a-zA-Z0-9_.,-]+)(?:\s+\(([^)]+)\))?\s*$/';
		if ( preg_match( $re_line, $raw ) ) {
			$errors[] = "[R3 Sin identificador] {$rel}:{$line}: '{$raw}' usa {$tag}-line/next-line genérico. Indica el identificador exacto: // {$tag} <identificador> (<motivo >= 25 caracteres>).";
			return $errors;
		}
		if ( ! preg_match( $re_fmt, $raw, $m ) ) {
			$errors[] = "[R3 Sin justificación] {$rel}:{$line}: '{$raw}' debe tener el formato '// {$tag} <identificador> (<motivo >= 25 caracteres>)'. Corrige el tipo en el código o explica por qué el analizador falla.";
			return $errors;
		}
		$ids_raw = $m[1];
		$reason  = isset( $m[2] ) ? trim( $m[2] ) : '';
		foreach ( self::split_ids( $ids_raw ) as $id ) {
			if ( null !== self::match_forbidden_r4( $id ) ) {
				$errors[] = "[R4 Identificador vetado] {$rel}:{$line}: '{$id}' está prohibido en líneas nuevas porque oculta complejidad o código muerto/imposible. Refactoriza la condición o función.";
			}
		}
		if ( mb_strlen( $reason, 'UTF-8' ) < self::MIN_JUSTIFICATION_LEN ) {
			$len      = mb_strlen( $reason, 'UTF-8' );
			$errors[] = "[R3 Motivo insuficiente] {$rel}:{$line}: '{$tag} {$ids_raw}' requiere justificación entre paréntesis de al menos 25 caracteres (actual: {$len}).";
		}
		return $errors;
	}

	/**
	 * @return list<string>
	 */
	private static function validate_psalm_suppression( string $rel, int $line, string $raw ): array {
		$errors = array();
		$tag    = '@' . 'psalm-suppress';
		$re_fmt = '/^@' . 'psalm-suppress\s+([A-Za-z][A-Za-z0-9_,]*)(?:\s+(.*))?$/';
		if ( ! preg_match( $re_fmt, $raw, $m ) ) {
			$errors[] = "[R3 Sintaxis inválida] {$rel}:{$line}: '{$raw}' debe indicar código Psalm y motivo (>= 25 caracteres).";
			return $errors;
		}
		$ids_raw = $m[1];
		$reason  = isset( $m[2] ) ? trim( $m[2] ) : '';
		foreach ( self::split_ids( $ids_raw ) as $id ) {
			if ( null !== self::match_forbidden_r4( $id ) ) {
				$errors[] = "[R4 Identificador vetado] {$rel}:{$line}: '{$tag} {$id}' está prohibido en líneas nuevas porque encubre ramas imposibles o errores de flujo. Corrige el contrato real.";
			}
		}
		if ( mb_strlen( $reason, 'UTF-8' ) < self::MIN_JUSTIFICATION_LEN ) {
			$len      = mb_strlen( $reason, 'UTF-8' );
			$errors[] = "[R3 Motivo insuficiente] {$rel}:{$line}: '{$tag} {$ids_raw}' requiere justificación de al menos 25 caracteres en el docblock (actual: {$len}).";
		}
		return $errors;
	}

	/**
	 * @return list<string>
	 */
	private static function validate_phpcs_suppression( string $rel, int $line, string $kind, string $raw ): array {
		$errors = array();
		$after  = trim( substr( $raw, strlen( $kind ) ) );
		if ( '' === $after || str_starts_with( $after, '--' ) ) {
			$errors[] = "[R3 Sin sniff] {$rel}:{$line}: '{$kind}' sin código de regla sniff explícito está prohibido. Usa '{$kind} Norma.Categoria.Sniff -- <motivo >= 25 caracteres>' o corrige la regla en código.";
			return $errors;
		}

		$parts  = explode( '--', $after, 2 );
		$sniffs = trim( $parts[0] );
		$reason = isset( $parts[1] ) ? trim( $parts[1] ) : '';

		if ( '' === $sniffs || ! str_contains( $sniffs, '.' ) ) {
			$errors[] = "[R3 Sniff inválido] {$rel}:{$line}: '{$raw}' debe enumerar el código de sniff completo (ej. WordPress.DB.PreparedSQL.NotPrepared) antes de '--'.";
		} else {
			foreach ( self::split_ids( $sniffs ) as $id ) {
				if ( null !== self::match_forbidden_r4( $id ) ) {
					$errors[] = "[R4 Identificador vetado] {$rel}:{$line}: '{$id}' está prohibido en líneas nuevas porque oculta complejidad ciclomática o anidamiento excesivo. Extrae helpers.";
				}
			}
		}

		if ( mb_strlen( $reason, 'UTF-8' ) < self::MIN_JUSTIFICATION_LEN ) {
			$len      = mb_strlen( $reason, 'UTF-8' );
			$errors[] = "[R3 Motivo insuficiente] {$rel}:{$line}: '{$kind}' requiere '-- <motivo>' de al menos 25 caracteres (actual: {$len}).";
		}
		return $errors;
	}

	/**
	 * @return list<string>
	 */
	private static function validate_lint_disable( string $rel, int $line, string $kind, string $raw ): array {
		$errors = array();
		$re_ld  = '/^(?:eslint|' . 'stylelint' . ')-disable(?:-next-line|-line)?/';
		$after  = trim( preg_replace( $re_ld, '', $raw ) ?? '' );
		if ( '' === $after || str_starts_with( $after, '--' ) ) {
			$errors[] = "[R3 Sin regla] {$rel}:{$line}: '{$raw}' debe especificar explícitamente la regla desactivada y '-- <motivo >= 25 caracteres>'.";
			return $errors;
		}
		$parts  = explode( '--', $after, 2 );
		$reason = isset( $parts[1] ) ? trim( $parts[1] ) : '';
		if ( mb_strlen( $reason, 'UTF-8' ) < self::MIN_JUSTIFICATION_LEN ) {
			$len      = mb_strlen( $reason, 'UTF-8' );
			$errors[] = "[R3 Motivo insuficiente] {$rel}:{$line}: '{$kind}' requiere '-- <motivo>' de al menos 25 caracteres (actual: {$len}).";
		}
		return $errors;
	}

	/**
	 * @return list<string>
	 */
	private static function check_unclosed_phpcs_disable( string $rel, string $content ): array {
		$errors  = array();
		$open    = array();
		$tokens  = @token_get_all( $content );
		$re_blk  = '/\b' . 'phpcs' . ':(disable|enable)\b([^\r\n*]*)/i';
		foreach ( $tokens as $token ) {
			if ( ! is_array( $token ) || ( T_COMMENT !== $token[0] && T_DOC_COMMENT !== $token[0] ) ) {
				continue;
			}
			$lines = explode( "\n", $token[1] );
			foreach ( $lines as $offset => $line_text ) {
				$line_no = $token[2] + $offset;
				if ( preg_match( $re_blk, $line_text, $m ) ) {
					$action = strtolower( $m[1] );
					$rest   = trim( explode( '--', $m[2], 2 )[0] );
					$sniffs = '' === $rest ? array( '__all__' ) : self::split_ids( $rest );
					if ( 'disable' === $action ) {
						foreach ( $sniffs as $s ) {
							$open[ $s ] = $line_no;
						}
					} else {
						if ( array( '__all__' ) === $sniffs ) {
							$open = array();
						} else {
							foreach ( $sniffs as $s ) {
								unset( $open[ $s ], $open['__all__'] );
							}
						}
					}
				}
			}
		}

		$dis_kw = 'phpcs' . ':disable';
		$en_kw  = 'phpcs' . ':enable';
		foreach ( $open as $sniff => $line_no ) {
			$errors[] = "[R3 Bloque abierto] {$rel}:{$line_no}: '{$dis_kw}' ({$sniff}) no tiene su correspondiente '{$en_kw}' en el mismo archivo. Cierra el ámbito exacto o corrige el código.";
		}
		return $errors;
	}

	/**
	 * @param list<string> $changed_rels
	 * @return list<string>
	 */
	private static function check_r5_unused_psalm( string $root, string $psalm_bin, string $r5_mode, array $changed_rels ): array {
		if ( ! is_file( $root . '/psalm.xml' ) ) {
			return array( '[R5 Psalm] Falta psalm.xml en el repositorio; no se puede verificar UnusedPsalmSuppress.' );
		}
		if ( ! is_file( $psalm_bin ) || ! is_executable( $psalm_bin ) ) {
			return array( "[R5 Psalm] No se encontró ejecutable Psalm en {$psalm_bin}. Instala dependencias Composer antes de validar R5." );
		}

		$cmd = sprintf(
			'cd %s && NO_COLOR=1 %s -m --threads=1 --find-unused-psalm-suppress --no-progress --output-format=compact 2>&1',
			escapeshellarg( $root ),
			escapeshellarg( $psalm_bin )
		);
		$output_lines = array();
		$exit_code    = 0;
		exec( $cmd, $output_lines, $exit_code );

		$unused = array();
		foreach ( $output_lines as $line ) {
			$clean_line = preg_replace( '/\x1b\[[0-9;]*[A-Za-z]/', '', $line ) ?? $line;
			if ( preg_match( '/^ERROR\s+(.+?):(\d+):(\d+)\s+UnusedPsalmSuppress:/', $clean_line, $m ) ) {
				$file = str_replace( '\\', '/', trim( $m[1] ) );
				$file = preg_replace( '#^' . preg_quote( str_replace( '\\', '/', $root ) . '/', '#' ) . '#', '', $file ) ?? $file;
				$unused[] = array(
					'file' => $file,
					'line' => (int) $m[2],
					'raw'  => $line,
				);
			}
		}

		$errors = array();
		if ( 'changed' === $r5_mode ) {
			$changed_set = array_flip( $changed_rels );
			foreach ( $unused as $entry ) {
				if ( isset( $changed_set[ $entry['file'] ] ) ) {
					$errors[] = "[R5 Supresión inútil] {$entry['file']}:{$entry['line']}: UnusedPsalmSuppress detectado en archivo modificado. Elimina el " . '@' . 'psalm-suppress obsoleto.';
				}
			}
			return $errors;
		}

		if ( array() !== $unused ) {
			$sample = array_slice( $unused, 0, 10 );
			foreach ( $sample as $entry ) {
				$errors[] = "[R5 Supresión inútil] {$entry['file']}:{$entry['line']}: UnusedPsalmSuppress — elimina la anotación " . '@' . 'psalm-suppress que ya no aplica.';
			}
			if ( count( $unused ) > count( $sample ) ) {
				$extra    = count( $unused ) - count( $sample );
				$errors[] = "[R5 Supresión inútil] ...y {$extra} ocurrencia(s) adicional(es) de UnusedPsalmSuppress (total: " . count( $unused ) . ').';
			}
			return $errors;
		}

		if ( 0 !== $exit_code ) {
			$tail = implode( ' | ', array_slice( $output_lines, -5 ) );
			return array( "[R5 Psalm] Psalm falló (exit={$exit_code}) durante --find-unused-psalm-suppress: {$tail}" );
		}

		return array();
	}

	private static function match_forbidden_r4( string $id ): ?string {
		if ( in_array( $id, self::FORBIDDEN_R4_IDS, true ) ) {
			return $id;
		}
		foreach ( self::FORBIDDEN_R4_PREFIXES as $prefix ) {
			if ( str_starts_with( $id, $prefix ) ) {
				return $id;
			}
		}
		return null;
	}

	/**
	 * @return list<string>
	 */
	private static function split_ids( string $raw ): array {
		$parts = preg_split( '/[\s,]+/', trim( $raw ) ) ?: array();
		return array_values( array_filter( $parts, static fn( string $v ): bool => '' !== $v ) );
	}

	/**
	 * @return list<string>
	 */
	private static function list_files_on_disk( string $root, string $rel_dir ): array {
		$abs = $root . '/' . $rel_dir;
		$out = array();
		$it  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $abs, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $file_info ) {
			if ( ! $file_info instanceof \SplFileInfo || ! $file_info->isFile() ) {
				continue;
			}
			$rel = str_replace( '\\', '/', substr( $file_info->getPathname(), strlen( $root ) + 1 ) );
			if ( ! preg_match( '#/(?:vendor|node_modules|\.git)/#', $rel ) ) {
				$out[] = $rel;
			}
		}
		sort( $out );
		return $out;
	}

	private static function git_ref_exists( string $root, string $ref ): bool {
		$res = self::run_git( $root, array( 'rev-parse', '--verify', $ref ) );
		return 0 === $res['code'];
	}

	/**
	 * @return list<string>
	 */
	private static function git_ls_tree( string $root, string $ref ): array {
		$res = self::run_git( $root, array( 'ls-tree', '-rz', '--name-only', $ref ) );
		if ( 0 !== $res['code'] ) {
			return array();
		}
		return array_values( array_filter( explode( "\0", $res['stdout'] ), static fn( string $p ): bool => '' !== $p ) );
	}

	/**
	 * @return list<string>
	 */
	private static function git_ls_files_staged( string $root ): array {
		$res = self::run_git( $root, array( 'ls-files', '-z' ) );
		if ( 0 !== $res['code'] ) {
			return array();
		}
		$out = array();
		foreach ( explode( "\0", $res['stdout'] ) as $rel ) {
			if ( '' !== $rel ) {
				$out[] = $rel;
			}
		}
		return $out;
	}

	/**
	 * @return array<string, string>
	 */
	private static function read_staged_index_contents( string $root ): array {
		$res = self::run_git( $root, array( 'diff', '--cached', '--name-only', '-z', '--diff-filter=ACMR' ) );
		if ( 0 !== $res['code'] ) {
			return array();
		}
		$map = array();
		foreach ( explode( "\0", $res['stdout'] ) as $rel ) {
			if ( '' !== $rel ) {
				$blob = self::git_show( $root, ":{$rel}" );
				if ( null !== $blob ) {
					$map[ $rel ] = $blob;
				}
			}
		}
		return $map;
	}

	private static function git_show( string $root, string $spec ): ?string {
		$res = self::run_git( $root, array( 'show', $spec ) );
		return 0 === $res['code'] ? $res['stdout'] : null;
	}

	/**
	 * @return array<string, array{content: ?string, added_lines: array<int, true>}>
	 */
	private static function collect_changed_files_and_added_lines( string $root, bool $staged, string $base_target ): array {
		$pathspecs = array_merge(
			array( '--' ),
			array_values( self::COMPONENTS ),
			array( ':(exclude)*.min.js', ':(exclude)*.min.css', ':(exclude)*/tests/*', ':(exclude)*/vendor/*', ':(exclude)*/node_modules/*', ':(exclude)*/build/*', ':(exclude)*/dist/*' )
		);
		$diff_args = array_merge(
			$staged
				? array( 'diff', '--cached', '--unified=0', '--no-color', $base_target )
				: array( 'diff', '--unified=0', '--no-color', $base_target ),
			$pathspecs
		);
		$added_map = self::stream_added_lines_from_git_diff( $root, $diff_args );

		if ( ! $staged ) {
			$untracked = self::run_git( $root, array_merge( array( 'ls-files', '--others', '--exclude-standard', '-z' ), $pathspecs ) );
			foreach ( explode( "\0", $untracked['stdout'] ) as $rel ) {
				if ( '' === $rel || ! self::is_runtime_analyzable_file( $rel ) ) {
					continue;
				}
				$full = $root . '/' . $rel;
				if ( is_file( $full ) ) {
					$text = (string) file_get_contents( $full );
					$n    = substr_count( $text, "\n" ) + 1;
					$lines = array();
					for ( $i = 1; $i <= $n; $i++ ) {
						$lines[ $i ] = true;
					}
					$added_map[ $rel ] = $lines;
				}
			}
		}

		$result = array();
		foreach ( $added_map as $rel => $line_set ) {
			if ( ! self::is_runtime_analyzable_file( $rel ) ) {
				continue;
			}
			$content = $staged
				? self::git_show( $root, ":{$rel}" )
				: ( is_file( $root . '/' . $rel ) ? (string) file_get_contents( $root . '/' . $rel ) : null );
			$result[ $rel ] = array(
				'content'     => $content,
				'added_lines' => $line_set,
			);
		}
		return $result;
	}

	/**
	 * @param list<string> $args
	 * @return array<string, array<int, true>>
	 */
	private static function stream_added_lines_from_git_diff( string $root, array $args ): array {
		$escaped = array_map( 'escapeshellarg', $args );
		$cmd     = 'git -C ' . escapeshellarg( $root ) . ' ' . implode( ' ', $escaped );
		$spec    = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$proc = proc_open( $cmd, $spec, $pipes );
		if ( ! is_resource( $proc ) ) {
			return array();
		}
		fclose( $pipes[0] );

		$map          = array();
		$current_file = null;
		$current_line = 0;

		while ( false !== ( $raw_line = fgets( $pipes[1] ) ) ) {
			$line = rtrim( $raw_line, "\r\n" );
			if ( str_starts_with( $line, '+++ b/' ) ) {
				$current_file = substr( $line, 6 );
				if ( ! isset( $map[ $current_file ] ) ) {
					$map[ $current_file ] = array();
				}
			} elseif ( str_starts_with( $line, '+++ /dev/null' ) ) {
				$current_file = null;
			} elseif ( null !== $current_file && preg_match( '/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $m ) ) {
				$current_line = (int) $m[1];
			} elseif ( null !== $current_file && str_starts_with( $line, '+' ) && ! str_starts_with( $line, '+++' ) ) {
				$map[ $current_file ][ $current_line ] = true;
				++$current_line;
			}
		}
		fclose( $pipes[1] );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );
		proc_close( $proc );
		return $map;
	}

	/**
	 * @param list<string> $args
	 * @return array{code: int, stdout: string, stderr: string}
	 */
	private static function run_git( string $root, array $args ): array {
		$escaped = array_map( 'escapeshellarg', $args );
		$cmd     = 'git -C ' . escapeshellarg( $root ) . ' ' . implode( ' ', $escaped );
		$spec    = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$proc    = proc_open( $cmd, $spec, $pipes );
		if ( ! is_resource( $proc ) ) {
			return array( 'code' => 2, 'stdout' => '', 'stderr' => 'proc_open falló' );
		}
		fclose( $pipes[0] );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $proc );
		return array(
			'code'   => (int) $code,
			'stdout' => $stdout,
			'stderr' => $stderr,
		);
	}
}

if ( PHP_SAPI === 'cli' && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	exit( GuardrailSuppressions::main( $argv ) );
}
