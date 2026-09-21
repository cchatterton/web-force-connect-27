<?php
// Run with WP-CLI eval-file on a disposable local WordPress site.
if ( wp_get_environment_type() !== 'local' ) {
	throw new RuntimeException( 'Station test requires a local WordPress environment.' );
}
$outbound = wfc27_stage_outbound( 'plain text from WordPress' );
if ( is_wp_error( $outbound ) ) {
	throw new RuntimeException( $outbound->get_error_message() );
}
$source_id = '001000000000001AAA';
$train = static function ( $envelopes, $receipts ) {
	$request = new WP_REST_Request( 'POST', '/wfc27/v1/train' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'protocol' => 'wfc27.station.v2', 'capacity' => 25, 'envelopes' => $envelopes, 'receipts' => $receipts ) ) );
	$response = wfc27_receive_train( $request );
	if ( is_wp_error( $response ) ) {
		throw new RuntimeException( $response->get_error_message() );
	}
	return $response->get_data();
};
try {
	$first = $train( array( array( 'id' => $source_id, 'payload' => 'not JSON from Salesforce' ) ), array() );
	if ( $first['receipts'] !== array( $source_id ) || $first['envelopes'][0]['id'] !== $outbound || $first['envelopes'][0]['payload'] !== 'plain text from WordPress' ) {
		throw new RuntimeException( 'First train did not exchange envelopes.' );
	}
	global $wpdb;
	$station = wfc27_station_table();
	$pending_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$station} WHERE envelope_id = %s", $outbound ) );
	if ( 'outbound_pending' !== $pending_status ) {
		throw new RuntimeException( 'Sent packet should await a receipt.' );
	}
	$trip_id = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . wfc27_trips_table() );
	$members = $wpdb->get_results( $wpdb->prepare( 'SELECT envelope_id,direction FROM ' . wfc27_trip_packets_table() . ' WHERE trip_id = %d ORDER BY direction', $trip_id ), ARRAY_A );
	if ( count( $members ) !== 2 || $members[0]['envelope_id'] !== 'sf:' . $source_id || $members[0]['direction'] !== 'received' || $members[1]['envelope_id'] !== $outbound || $members[1]['direction'] !== 'sent' ) {
		throw new RuntimeException( 'Sync does not list its sent and received packets.' );
	}
	$retry = $train( array(), array() );
	if ( count( $retry['envelopes'] ) !== 1 || $retry['envelopes'][0]['id'] !== $outbound ) {
		throw new RuntimeException( 'Unacknowledged packet was not reoffered.' );
	}
	$train( array( array( 'id' => $source_id, 'payload' => 'not JSON from Salesforce' ) ), array( $outbound ) );
	$inbound_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$station} WHERE envelope_id = %s", 'sf:' . $source_id ) );
	$outbound_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$station} WHERE envelope_id = %s", $outbound ) );
	$inbound_payload = $wpdb->get_var( $wpdb->prepare( "SELECT json FROM {$station} WHERE envelope_id = %s", 'sf:' . $source_id ) );
	if ( 1 !== $inbound_count || 'outbound_delivered' !== $outbound_status || 'not JSON from Salesforce' !== $inbound_payload ) {
		throw new RuntimeException( 'Station did not deduplicate or acknowledge envelopes.' );
	}
	WP_CLI::success( 'Bidirectional station receipt, deduplication, and acknowledgement passed.' );
} finally {
	$wpdb->delete( wfc27_station_table(), array( 'envelope_id' => $outbound ), array( '%s' ) );
	$wpdb->delete( wfc27_station_table(), array( 'envelope_id' => 'sf:' . $source_id ), array( '%s' ) );
}
