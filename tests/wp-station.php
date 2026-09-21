<?php
// Run with WP-CLI eval-file on a disposable local WordPress site.
if ( wp_get_environment_type() !== 'local' ) {
	throw new RuntimeException( 'Station test requires a local WordPress environment.' );
}
$outbound = wfc27_stage_outbound( array( 'test' => 'from WordPress' ) );
if ( is_wp_error( $outbound ) ) {
	throw new RuntimeException( $outbound->get_error_message() );
}
$source_id = '001000000000001AAA';
$train = static function ( $envelopes, $receipts ) {
	$request = new WP_REST_Request( 'POST', '/wfc27/v1/train' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'protocol' => 'wfc27.station.v1', 'capacity' => 25, 'envelopes' => $envelopes, 'receipts' => $receipts ) ) );
	$response = wfc27_receive_train( $request );
	if ( is_wp_error( $response ) ) {
		throw new RuntimeException( $response->get_error_message() );
	}
	return $response->get_data();
};
try {
	$first = $train( array( array( 'id' => $source_id, 'payload' => array( 'test' => 'from Salesforce' ) ) ), array() );
	if ( $first['receipts'] !== array( $source_id ) || $first['envelopes'][0]['id'] !== $outbound ) {
		throw new RuntimeException( 'First train did not exchange envelopes.' );
	}
	$train( array( array( 'id' => $source_id, 'payload' => array( 'test' => 'from Salesforce' ) ) ), array( $outbound ) );
	global $wpdb;
	$station = wfc27_station_table();
	$inbound_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$station} WHERE envelope_id = %s", 'sf:' . $source_id ) );
	$outbound_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$station} WHERE envelope_id = %s", $outbound ) );
	if ( 1 !== $inbound_count || 'outbound_delivered' !== $outbound_status ) {
		throw new RuntimeException( 'Station did not deduplicate or acknowledge envelopes.' );
	}
	WP_CLI::success( 'Bidirectional station receipt, deduplication, and acknowledgement passed.' );
} finally {
	$wpdb->delete( wfc27_station_table(), array( 'envelope_id' => $outbound ), array( '%s' ) );
	$wpdb->delete( wfc27_station_table(), array( 'envelope_id' => 'sf:' . $source_id ), array( '%s' ) );
}
