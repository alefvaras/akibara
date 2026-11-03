<?php
/**
 * Template: Tab Índice de SearchAdminSection.
 *
 * @package Akibara\Search
 * @var array<string, mixed> $view_data
 * @var \Akibara\Search\SearchAdminSection $this
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$data    = (array) ( $view_data['data'] ?? array() );
$verdict = (array) ( $view_data['verdict'] ?? array() );

$this->render_health_band( $data, $verdict );
$this->render_services(
	is_array( $data['flags'] ?? null ) ? $data['flags'] : array(),
	is_array( $data['editorial'] ?? null ) ? $data['editorial'] : array(),
	is_array( $data['semantic'] ?? null ) ? $data['semantic'] : array()
);
$this->render_state_table( $data );
$this->render_maintenance( $data, $verdict );
$this->render_diagnostics( $data );
