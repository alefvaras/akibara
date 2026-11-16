#!/usr/bin/env bash

# Canonical production identity shared by promotion and mutation-evidence pipelines.
# Keep this file data-only so it is safe to source from fail-closed scripts.
release_contract_schema="akibara-production-profile-v1"
release_set="akibara-business-1.95.2_search-0.1.4_checkout-2.0.1_theme-production-2.1.49"
production_release_digest="69742e71e4db932cce795c05430eb7cece2e2013b1ebe83e2c624db3e146fac4"
expected_release_digest="912e4a93629f03735315905bb2cf31c2811962abe3c7a6e7ccda25304c38f58a"

production_theme_version="2.1.49"
production_theme_archive_sha256="e8c5ce31a5cbf1e0e49acba4dd5ba5873fdd0314d70b55c75930e40efe0f983e"
production_theme_files="102"

production_superset_contract_relative="ops/contracts/akibara-gp-2.1.49-production-superset.json"
production_superset_contract_sha256="f3e1430b69541d6f5407bea717b91848cd014085d86ec76020c09c08431f440a"
production_theme_preimage_version="2.1.44"
production_theme_preimage_normal_files="102"
production_theme_preimage_normal_tree_sha256="13a3fb4f96376ce8a0dd7a9db6677123ea6d201a98eba8b8b2d8da2bf27950fe"
production_theme_preimage_appledouble_files="5"
production_theme_preimage_appledouble_tree_sha256="eba4636ee1739671829f94eee7db8870298d402af6a607364069a1eb6966f781"
