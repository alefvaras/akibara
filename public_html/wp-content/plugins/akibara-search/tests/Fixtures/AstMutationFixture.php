<?php
declare( strict_types = 1 );

namespace Akibara\Search;

final class AstMutationFixture {
	public function mutant_foreach( array $items ): void {
		foreach ( $items as $useless_key => $item ) {
			echo (string) $item;
		}
	}

	public function mutant_dead_assign(): void {
		$dead_variable = 42;
		echo 'hello';
	}

	public function mutant_sql(): void {
		$sql = "SELECT * FROM wp_postmeta WHERE meta_key = '_stock_status'";
		echo (string) $sql;
	}
}
