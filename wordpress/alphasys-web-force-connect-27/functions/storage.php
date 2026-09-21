<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wfc27_station_table() {
	global $wpdb;
	return $wpdb->prefix . 'wfc27_station';
}

function wfc27_trips_table() {
	global $wpdb;
	return $wpdb->prefix . 'wfc27_trips_v2';
}

function wfc27_stage_outbound( $json ) {
	global $wpdb;
	$payload = is_string( $json ) ? json_decode( $json, true ) : $json;
	$encoded = is_array( $payload ) ? wp_json_encode( $payload ) : false;
	if ( ! $encoded || strlen( $encoded ) > 120000 ) {
		return new WP_Error( 'wfc27_invalid_json', 'A station item needs a JSON object of at most 120 KB.' );
	}
	$id = wp_generate_uuid4();
	$stored = $wpdb->insert( wfc27_station_table(), array( 'envelope_id' => $id, 'json' => $encoded, 'status' => 'outbound_ready' ), array( '%s', '%s', '%s' ) );
	return $stored ? $id : new WP_Error( 'wfc27_station_error', 'Could not stage the envelope.' );
}

function wfc27_create_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	$station = wfc27_station_table();
	$trips = wfc27_trips_table();
	dbDelta( "CREATE TABLE {$station} (
		envelope_id varchar(100) NOT NULL,
		json longtext NOT NULL,
		status varchar(24) NOT NULL,
		PRIMARY KEY  (envelope_id),
		KEY status (status)
	) {$charset};" );
	dbDelta( "CREATE TABLE {$trips} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		sent_count int(11) NOT NULL DEFAULT 0,
		received_count int(11) NOT NULL DEFAULT 0,
		occurred_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY occurred_at (occurred_at)
	) {$charset};" );
}
