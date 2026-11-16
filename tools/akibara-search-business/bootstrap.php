<?php
/**
 * Bootstrap local del contrato integrado Search -> Business.
 *
 * @package Akibara\SearchBusiness\Tests
 */

declare( strict_types = 1 );

$akibara_integration_repo_root = dirname( __DIR__, 2 );

require_once $akibara_integration_repo_root . '/public_html/wp-content/plugins/akibara-search/tests/bootstrap.php';

$akibara_integration_business_source = $akibara_integration_repo_root . '/public_html/wp-content/plugins/akibara-business/src/Modules/TaxonomyDiscount/';
foreach (
	array(
		'PriceAuthority.php',
		'AuthoritativeReader.php',
		'AdoptedSnapshot.php',
		'Settings.php',
		'DiscountResolver.php',
		'PriceAuthorityUnavailable.php',
		'SearchPriceProvider.php',
	) as $akibara_integration_source_file
) {
	require_once $akibara_integration_business_source . $akibara_integration_source_file;
}

/*
 * Stubs de WooCommerce de Business. El bootstrap de Search no declara
 * `WC_Product`, y sin él no se puede invocar el filtro real
 * `akb_search_effective_price` — que es justamente lo que este runner existe
 * para acreditar. Verificado que conviven con los stubs de Search sin
 * redeclarar nada.
 */
require_once $akibara_integration_repo_root . '/public_html/wp-content/plugins/akibara-business/tests/wp-stubs.php';
