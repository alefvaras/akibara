<?php
/**
 * Pruebas negativas y positivas de tools/guardrail-suppressions.php (R1-R5).
 *
 * Crea repositorios Git aislados en /tmp con código sintético y un binario mock de
 * Psalm para validar cada regla sin tocar datos ni configuración reales.
 *
 * @package Akibara\Quality
 */

declare( strict_types = 1 );

final class GuardrailSuppressionsTestRunner {

	private string $guardrail;

	public function __construct() {
		$this->guardrail = __DIR__ . '/guardrail-suppressions.php';
	}

	public function run(): int {
		$cases = array(
			'R1: bloquea supresión filtrada a HTML en T_INLINE_HTML'               => fn() => $this->test_r1_blocks_inline_html_suppression(),
			'R2: trinquete bloquea aumento de supresiones por componente'          => fn() => $this->test_r2_ratchet_blocks_count_increase(),
			'R3a: bloquea supresión PHPStan sin motivo >= 25 caracteres'           => fn() => $this->test_r3_blocks_phpstan_without_justification(),
			'R3b: bloquea supresión PHPCS sin código de sniff y bloque abierto'    => fn() => $this->test_r3_blocks_phpcs_without_sniff_and_unclosed_disable(),
			'R3c: bloquea omisión en JS sin regla explícita ni justificación'      => fn() => $this->test_r3_blocks_js_eslint_disable_without_reason(),
			'R4: bloquea identificadores vetados aunque baje el conteo'            => fn() => $this->test_r4_blocks_forbidden_identifiers(),
			'R5: bloquea UnusedPsalmSuppress reportado por Psalm'                  => fn() => $this->test_r5_blocks_unused_psalm_suppress(),
			'POS: aprobar código limpio, reducción de deuda y string en test'      => fn() => $this->test_positive_clean_and_debt_reduction(),
		);

		$passed = 0;
		foreach ( $cases as $label => $fn ) {
			$fn();
			++$passed;
			fwrite( STDOUT, "✔ {$label}\n" );
		}

		fwrite( STDOUT, "PASS: {$passed}/" . count( $cases ) . " pruebas de guardrail-suppressions.php aprobadas.\n" );
		return 0;
	}

	private static function tag_phpstan( string $suffix = '' ): string {
		return '@' . 'phpstan-ignore' . $suffix;
	}

	private static function tag_psalm(): string {
		return '@' . 'psalm-suppress';
	}

	private static function tag_phpcs( string $op ): string {
		return 'phpcs' . ':' . $op;
	}

	private static function tag_eslint(): string {
		return 'eslint' . '-disable-next-line';
	}

	private function test_r1_blocks_inline_html_suppression(): void {
		$repo = $this->make_repo( array(
			'public_html/wp-content/plugins/akibara-business/src/View.php' => "<?php\nfunction akb_view(): void {\n?>\n<div class=\"box\"><!-- " . self::tag_phpstan( '-next-line' ) . " --></div>\n<?php\n}\n",
		), false );

		try {
			$res = $this->invoke( $repo, array( '--base=HEAD', '--r5=off' ) );
			$this->assert_same( 1, $res['code'], 'R1 debió fallar con código 1' );
			$this->assert_contains( '[R1 Fuga a HTML]', $res['output'] );
			$this->assert_contains( 'View.php:4', $res['output'] );
		} finally {
			$this->cleanup_dir( $repo );
		}
	}

	private function test_r2_ratchet_blocks_count_increase(): void {
		$psalm_tag = self::tag_psalm();
		$repo      = $this->make_repo( array(
			'public_html/wp-content/plugins/akibara-search/src/Indexer.php' => "<?php\n/**\n * {$psalm_tag} ArgumentTypeCoercion Justificación válida suficientemente larga\n */\nfunction akb_idx( int \$x ): int { return \$x; }\n",
		), true );

		try {
			$this->write_file(
				$repo . '/public_html/wp-content/plugins/akibara-search/src/Indexer.php',
				"<?php\n/**\n * {$psalm_tag} ArgumentTypeCoercion Justificación válida suficientemente larga\n * {$psalm_tag} MixedAssignment Segunda justificación válida suficientemente larga\n */\nfunction akb_idx( int \$x ): int { return \$x; }\n"
			);
			$res = $this->invoke( $repo, array( '--base=HEAD', '--r5=off' ) );
			$this->assert_same( 1, $res['code'], 'R2 debió bloquear el incremento de supresiones' );
			$this->assert_contains( '[R2 Trinquete] akibara-search', $res['output'] );
		} finally {
			$this->cleanup_dir( $repo );
		}
	}

	private function test_r3_blocks_phpstan_without_justification(): void {
		$stan_tag = self::tag_phpstan();
		$repo     = $this->make_repo( array(
			'public_html/wp-content/plugins/akibara-business/src/Old.php' => "<?php\n// {$stan_tag} argument.type (motivo heredado suficientemente largo para contar base)\n\$a = 1;\n",
		), true );

		try {
			$this->write_file(
				$repo . '/public_html/wp-content/plugins/akibara-business/src/Old.php',
				"<?php\n// {$stan_tag} argument.type (corto)\n\$a = 1;\n"
			);
			$res = $this->invoke( $repo, array( '--base=HEAD', '--r5=off' ) );
			$this->assert_same( 1, $res['code'], 'R3 debió bloquear motivo < 25 chars' );
			$this->assert_contains( '[R3 Motivo insuficiente]', $res['output'] );
		} finally {
			$this->cleanup_dir( $repo );
		}
	}

	private function test_r3_blocks_phpcs_without_sniff_and_unclosed_disable(): void {
		$ign = self::tag_phpcs( 'ignore' );
		$dis = self::tag_phpcs( 'disable' );
		$repo = $this->make_repo( array(
			'public_html/wp-content/themes/akibara-gp/inc/helper.php' => "<?php\n// {$ign} WordPress.Security.EscapeOutput.OutputNotEscaped -- motivo legado suficientemente largo\n\$x = 1;\n// {$ign} WordPress.Security.EscapeOutput.OutputNotEscaped -- segundo motivo suficientemente largo\n\$y = 2;\n",
		), true );

		try {
			$this->write_file(
				$repo . '/public_html/wp-content/themes/akibara-gp/inc/helper.php',
				"<?php\n// {$dis} WordPress.DB.PreparedSQL.NotPrepared -- motivo suficientemente largo pero sin cierre\n\$x = 1;\n// {$ign} -- sin codigo de sniff suficientemente largo\n\$y = 2;\n"
			);
			$res = $this->invoke( $repo, array( '--base=HEAD', '--r5=off' ) );
			$this->assert_same( 1, $res['code'], 'R3 debió bloquear ausencia de sniff y bloque abierto' );
			$this->assert_contains( '[R3 Sin sniff]', $res['output'] );
			$this->assert_contains( '[R3 Bloque abierto]', $res['output'] );
		} finally {
			$this->cleanup_dir( $repo );
		}
	}

	private function test_r3_blocks_js_eslint_disable_without_reason(): void {
		$esl  = self::tag_eslint();
		$repo = $this->make_repo( array(
			'public_html/wp-content/plugins/akibara-cart-checkout-blocks/assets/js/widget.js' => "// {$esl} no-alert -- justificación heredada suficientemente larga para base\nconst ok = true;\n",
		), true );

		try {
			$this->write_file(
				$repo . '/public_html/wp-content/plugins/akibara-cart-checkout-blocks/assets/js/widget.js',
				"// {$esl} security/detect-object-injection\nconst ok = true;\n"
			);
			$res = $this->invoke( $repo, array( '--base=HEAD', '--r5=off' ) );
			$this->assert_same( 1, $res['code'], 'R3 debió bloquear directiva JS sin -- <motivo >= 25 chars>' );
			$this->assert_contains( '[R3 Motivo insuficiente]', $res['output'] );
		} finally {
			$this->cleanup_dir( $repo );
		}
	}

	private function test_r4_blocks_forbidden_identifiers(): void {
		$stan_tag = self::tag_phpstan();
		$repo     = $this->make_repo( array(
			'public_html/wp-content/plugins/akibara-business/src/Service.php' => "<?php\n// {$stan_tag} argument.type (supresión previa 1 suficientemente larga)\n// {$stan_tag} cast.string (supresión previa 2 suficientemente larga)\n\$v = 1;\n",
		), true );

		try {
			$this->write_file(
				$repo . '/public_html/wp-content/plugins/akibara-business/src/Service.php',
				"<?php\n// {$stan_tag} complexity.functionLike (justificación muy larga pero identificador vetado por regla cuatro)\n\$v = 1;\n"
			);
			$res = $this->invoke( $repo, array( '--base=HEAD', '--r5=off' ) );
			$this->assert_same( 1, $res['code'], 'R4 debió rechazar complexity.functionLike aunque bajara el conteo' );
			$this->assert_contains( '[R4 Identificador vetado]', $res['output'] );
		} finally {
			$this->cleanup_dir( $repo );
		}
	}

	private function test_r5_blocks_unused_psalm_suppress(): void {
		$repo = $this->make_repo( array(
			'psalm.xml' => "<psalm></psalm>\n",
			'public_html/wp-content/mu-plugins/sample.php' => "<?php\ndeclare(strict_types=1);\n",
		), true );

		$mock_psalm = $repo . '/mock-psalm.php';
		$this->write_file(
			$mock_psalm,
			"#!/usr/bin/env php\n<?php\n"
			. '$mono = in_array( "-m", $argv, true ) || false !== getenv( "NO_COLOR" );' . "\n"
			. '$sev = $mono ? "ERROR" : "\\e[0;31mERROR\\e[0m";' . "\n"
			. 'echo "{$sev} public_html/wp-content/mu-plugins/sample.php:10:4 UnusedPsalmSuppress: This suppression is never used\n";' . "\n"
			. "exit(2);\n"
		);
		chmod( $mock_psalm, 0755 );

		try {
			$res = $this->invoke( $repo, array( '--base=HEAD', '--psalm-bin=' . $mock_psalm ) );
			$this->assert_same( 1, $res['code'], 'R5 debió fallar ante UnusedPsalmSuppress' );
			$this->assert_contains( '[R5 Supresión inútil]', $res['output'] );
		} finally {
			$this->cleanup_dir( $repo );
		}
	}

	private function test_positive_clean_and_debt_reduction(): void {
		$stan_tag = self::tag_phpstan();
		$repo     = $this->make_repo( array(
			'psalm.xml' => "<psalm></psalm>\n",
			'public_html/wp-content/plugins/akibara-rewards/src/Points.php' => "<?php\n// {$stan_tag} argument.type (motivo heredado suficientemente largo para prueba)\n\$p = 10;\n",
			'public_html/wp-content/plugins/akibara-rewards/tests/PointsTest.php' => "<?php\n\$expected = 'sin fuga';\n",
		), true );

		$mock_psalm = $repo . '/mock-psalm-ok.php';
		$this->write_file( $mock_psalm, "#!/usr/bin/env php\n<?php\nexit(0);\n" );
		chmod( $mock_psalm, 0755 );

		try {
			$this->write_file(
				$repo . '/public_html/wp-content/plugins/akibara-rewards/src/Points.php',
				"<?php\ndeclare(strict_types=1);\n\$p = 10;\n"
			);
			$this->write_file(
				$repo . '/public_html/wp-content/plugins/akibara-rewards/tests/PointsTest.php',
				"<?php\n\$html = '<div>ok</div>';\nassert( ! str_contains( \$html, '{$stan_tag}' ) );\n"
			);
			$this->git( $repo, array( 'add', 'public_html/wp-content/plugins/akibara-rewards/src/Points.php', 'public_html/wp-content/plugins/akibara-rewards/tests/PointsTest.php' ) );

			$res_base = $this->invoke( $repo, array( '--base=HEAD', '--psalm-bin=' . $mock_psalm ) );
			$this->assert_same( 0, $res_base['code'], 'Reducción de deuda y literales de prueba debieron aprobar (--base): ' . $res_base['output'] );
			$this->assert_contains( 'PASS: Guardrail Anti-Supresiones R1-R5 limpio', $res_base['output'] );

			$res_staged = $this->invoke( $repo, array( '--staged', '--psalm-bin=' . $mock_psalm ) );
			$this->assert_same( 0, $res_staged['code'], 'Modo --staged limpio debió aprobar: ' . $res_staged['output'] );
		} finally {
			$this->cleanup_dir( $repo );
		}
	}

	/**
	 * @param array<string, string> $files
	 */
	private function make_repo( array $files, bool $commit_files ): string {
		$dir = sys_get_temp_dir() . '/akb-suppr-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( $dir, 0777, true );
		$this->git( $dir, array( 'init', '-q' ) );
		$this->git( $dir, array( 'config', 'user.name', 'Test' ) );
		$this->git( $dir, array( 'config', 'user.email', 'test@example.test' ) );
		$this->write_file( $dir . '/README.md', "# Test\n" );
		if ( $commit_files ) {
			foreach ( $files as $rel => $content ) {
				$this->write_file( $dir . '/' . $rel, $content );
			}
		}
		$this->git( $dir, array( 'add', '.' ) );
		$this->git( $dir, array( 'commit', '-qm', 'Base' ) );
		if ( ! $commit_files ) {
			foreach ( $files as $rel => $content ) {
				$this->write_file( $dir . '/' . $rel, $content );
			}
		}
		return $dir;
	}

	private function write_file( string $path, string $content ): void {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $path, $content );
	}

	/**
	 * @param list<string> $args
	 * @return array{code: int, output: string}
	 */
	private function invoke( string $repo, array $args ): array {
		$full_args = array_merge( array( 'php', $this->guardrail, '--root=' . $repo ), $args );
		$cmd       = implode( ' ', array_map( 'escapeshellarg', $full_args ) ) . ' 2>&1';
		$lines     = array();
		$code      = 0;
		exec( $cmd, $lines, $code );
		return array(
			'code'   => (int) $code,
			'output' => implode( "\n", $lines ),
		);
	}

	/**
	 * @param list<string> $args
	 */
	private function git( string $repo, array $args ): void {
		$cmd = 'git -C ' . escapeshellarg( $root = $repo ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
		$out = array();
		$rc  = 0;
		exec( $cmd, $out, $rc );
		if ( 0 !== $rc ) {
			throw new \RuntimeException( 'Git falló: ' . implode( "\n", $out ) );
		}
	}

	private function assert_same( int $expected, int $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException( "{$message} (esperado {$expected}, recibido {$actual})" );
		}
	}

	private function assert_contains( string $needle, string $haystack ): void {
		if ( ! str_contains( $haystack, $needle ) ) {
			throw new \RuntimeException( "Se esperaba '{$needle}' en la salida:\n{$haystack}" );
		}
	}

	private function cleanup_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $entry ) {
			if ( $entry instanceof \SplFileInfo ) {
				if ( $entry->isDir() ) {
					rmdir( $entry->getPathname() );
				} else {
					unlink( $entry->getPathname() );
				}
			}
		}
		rmdir( $dir );
	}
}

exit( ( new GuardrailSuppressionsTestRunner() )->run() );
