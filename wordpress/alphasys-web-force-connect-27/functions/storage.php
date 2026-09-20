<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wfc27_packets_table() {
	global $wpdb;
	return $wpdb->prefix . 'wfc27_packets';
}

function wfc27_identity_table() {
	global $wpdb;
	return $wpdb->prefix . 'wfc27_identity';
}

function wfc27_create_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	$packets = wfc27_packets_table();
	$identity = wfc27_identity_table();
	dbDelta( "CREATE TABLE {$packets} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		packet_id varchar(80) NOT NULL,
		payload longtext NOT NULL,
		result_json longtext NULL,
		status varchar(20) NOT NULL DEFAULT 'received',
		received_at datetime NOT NULL,
		processed_at datetime NULL,
		error_text text NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY packet_id (packet_id),
		KEY status_id (status,id)
	) {$charset};" );
	dbDelta( "CREATE TABLE {$identity} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		sf_id varchar(18) NOT NULL,
		wp_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
		post_type varchar(100) NOT NULL DEFAULT '',
		bound_post_fields longtext NULL,
		bound_meta_keys longtext NULL,
		meta_ids longtext NULL,
		status_entered_at datetime NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY sf_id (sf_id),
		KEY wp_post_id (wp_post_id)
	) {$charset};" );
}

function wfc27_identity_for_sf( $sf_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wfc27_identity_table() . ' WHERE sf_id = %s LIMIT 1', $sf_id ), ARRAY_A );
}

function wfc27_identity_for_post( $post_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wfc27_identity_table() . ' WHERE wp_post_id = %d LIMIT 1', $post_id ), ARRAY_A );
}

function wfc27_store_identity( $sf_id, $post_id, $post_type, $post_fields, $meta_keys, $meta_ids, $status_entered_at ) {
	global $wpdb;
	$saved = $wpdb->replace(
		wfc27_identity_table(),
		array(
			'sf_id' => $sf_id,
			'wp_post_id' => $post_id,
			'post_type' => $post_type,
			'bound_post_fields' => wp_json_encode( array_values( array_unique( $post_fields ) ) ),
			'bound_meta_keys' => wp_json_encode( array_values( array_unique( $meta_keys ) ) ),
			'meta_ids' => wp_json_encode( $meta_ids ),
			'status_entered_at' => $status_entered_at,
		),
		array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
	);
	if ( false === $saved ) {
		throw new RuntimeException( 'WFC27 identity could not be stored.' );
	}
}

function wfc27_record_local_deletion( $post_id ) {
	global $wpdb;
	if ( ! empty( $GLOBALS['wfc27_applying_packet'] ) ) {
		return;
	}
	$identity = wfc27_identity_for_post( $post_id );
	if ( ! $identity ) {
		return;
	}
	$deleted = array( 'sf_id' => $identity['sf_id'], 'wp_id' => (int) $post_id );
	$recorded = $wpdb->insert(
		wfc27_packets_table(),
		array(
			'packet_id' => 'local-delete-' . $post_id . '-' . time(),
			'payload' => '{}',
			'result_json' => wp_json_encode( array( 'deleted' => array( $deleted ) ) ),
			'status' => 'completed',
			'received_at' => current_time( 'mysql', true ),
			'processed_at' => current_time( 'mysql', true ),
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	if ( false === $recorded ) {
		return;
	}
	$wpdb->update( wfc27_identity_table(), array( 'wp_post_id' => 0 ), array( 'sf_id' => $identity['sf_id'] ), array( '%d' ), array( '%s' ) );
}
add_action( 'deleted_post', 'wfc27_record_local_deletion' );
