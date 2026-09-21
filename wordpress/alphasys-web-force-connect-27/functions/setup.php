<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wfc27_activate() {
	wfc27_create_tables();
	wfc27_schedule_retention();
}

function wfc27_deactivate() {
	wp_clear_scheduled_hook( 'wfc27_process_inbox' );
	wp_clear_scheduled_hook( 'wfc27_cleanup_sync_logs' );
}

add_action( 'init', 'wfc27_schedule_retention' );
function wfc27_schedule_retention() {
	if ( ! wp_next_scheduled( 'wfc27_cleanup_sync_logs' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wfc27_cleanup_sync_logs' );
	}
}

add_action( 'wfc27_cleanup_sync_logs', 'wfc27_cleanup_sync_logs' );
function wfc27_cleanup_sync_logs() {
	global $wpdb;
	$trips = wfc27_trips_table();
	$members = wfc27_trip_packets_table();
	$empty_days = max( 1, min( 3650, (int) get_option( 'wfc27_empty_retention_days', 7 ) ) );
	$active_days = max( 1, min( 3650, (int) get_option( 'wfc27_packet_retention_days', 90 ) ) );
	$empty_cutoff = gmdate( 'Y-m-d H:i:s', time() - $empty_days * DAY_IN_SECONDS );
	$active_cutoff = gmdate( 'Y-m-d H:i:s', time() - $active_days * DAY_IN_SECONDS );
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT id FROM {$trips} WHERE (sent_count = 0 AND received_count = 0 AND occurred_at < %s) OR ((sent_count > 0 OR received_count > 0) AND occurred_at < %s) ORDER BY id ASC LIMIT 10000",
		$empty_cutoff,
		$active_cutoff
	) );
	if ( ! $ids ) {
		return 0;
	}
	$id_list = implode( ',', array_map( 'intval', $ids ) );
	$wpdb->query( "DELETE FROM {$members} WHERE trip_id IN ({$id_list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DELETE FROM {$trips} WHERE id IN ({$id_list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return count( $ids );
}

add_action( 'init', 'wfc27_maybe_upgrade_tables', 5 );
function wfc27_maybe_upgrade_tables() {
	if ( get_option( 'wfc27_schema_version' ) !== WFC27_VERSION ) {
		wfc27_create_tables();
		wp_clear_scheduled_hook( 'wfc27_process_inbox' );
		update_option( 'wfc27_schema_version', WFC27_VERSION, false );
	}
}
