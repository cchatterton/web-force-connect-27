<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'wfc27_register_rest_routes' );
function wfc27_register_rest_routes() {
	register_rest_route( 'wfc27/v1', '/train', array(
		'methods' => 'POST',
		'callback' => 'wfc27_receive_train',
		'permission_callback' => 'wfc27_can_receive_train',
	) );
}

function wfc27_can_receive_train() {
	$receiver_id = (int) get_option( 'wfc27_receiver_user_id', 0 );
	return current_user_can( 'manage_options' ) || ( $receiver_id > 0 && get_current_user_id() === $receiver_id );
}

function wfc27_receive_train( WP_REST_Request $request ) {
	global $wpdb;
	if ( strlen( $request->get_body() ) > 2 * MB_IN_BYTES ) {
		return new WP_Error( 'wfc27_train_size', 'Train exceeds 2 MB.', array( 'status' => 413 ) );
	}
	$data = $request->get_json_params();
	if ( ! is_array( $data ) || ( $data['protocol'] ?? '' ) !== 'wfc27.station.v2' || ! isset( $data['envelopes'], $data['receipts'] ) || ! is_array( $data['envelopes'] ) || ! is_array( $data['receipts'] ) || count( $data['envelopes'] ) > 100 || count( $data['receipts'] ) > 100 ) {
		return new WP_Error( 'wfc27_invalid_train', 'Invalid station train.', array( 'status' => 400 ) );
	}
	$capacity = max( 1, min( 100, (int) ( $data['capacity'] ?? 25 ) ) );
	$incoming = array();
	foreach ( $data['envelopes'] as $envelope ) {
		if ( ! is_array( $envelope ) || ! preg_match( '/^[A-Za-z0-9]{15,18}$/', (string) ( $envelope['id'] ?? '' ) ) || ! isset( $envelope['payload'] ) || ! is_string( $envelope['payload'] ) ) {
			return new WP_Error( 'wfc27_invalid_envelope', 'Invalid station envelope.', array( 'status' => 400 ) );
		}
		$payload = $envelope['payload'];
		if ( strlen( $payload ) > 120000 ) {
			return new WP_Error( 'wfc27_invalid_envelope', 'Envelope payload is too large.', array( 'status' => 400 ) );
		}
		$incoming[] = array( 'id' => 'sf:' . $envelope['id'], 'json' => $payload );
	}
	$station = wfc27_station_table();
	$received = array();
	foreach ( $incoming as $item ) {
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$station} (envelope_id,json,status) VALUES (%s,%s,%s)", $item['id'], $item['json'], 'inbound_received' ) );
		if ( $wpdb->last_error ) {
			return new WP_Error( 'wfc27_storage_error', 'Station could not store the envelope.', array( 'status' => 500 ) );
		}
		$received[] = substr( $item['id'], 3 );
	}
	foreach ( $data['receipts'] as $id ) {
		if ( is_string( $id ) && preg_match( '/^[0-9a-f-]{36}$/', $id ) ) {
			$wpdb->update( $station, array( 'status' => 'outbound_delivered' ), array( 'envelope_id' => $id, 'status' => 'outbound_ready' ), array( '%s' ), array( '%s', '%s' ) );
		}
	}
	$outbound = $wpdb->get_results( $wpdb->prepare( "SELECT envelope_id,json FROM {$station} WHERE status = %s ORDER BY envelope_id LIMIT %d", 'outbound_ready', $capacity ), ARRAY_A );
	$envelopes = array();
	$response_bytes = 0;
	foreach ( $outbound as $row ) {
		$response_bytes += strlen( $row['json'] );
		if ( $response_bytes > 1800000 ) {
			break;
		}
		$envelopes[] = array( 'id' => $row['envelope_id'], 'payload' => $row['json'] );
	}
	if ( isset( $data['train_state'] ) && in_array( $data['train_state'], array( 'running', 'paused' ), true ) ) {
		update_option( 'wfc27_train_state', $data['train_state'], false );
	}
	update_option( 'wfc27_last_heartbeat', current_time( 'mysql', true ), false );
	$wpdb->insert( wfc27_trips_table(), array(
		'sent_count' => count( $envelopes ), 'received_count' => count( $received ), 'occurred_at' => current_time( 'mysql', true ),
	), array( '%d', '%d', '%s' ) );
	return rest_ensure_response( array( 'protocol' => 'wfc27.station.v2', 'receipts' => $received, 'envelopes' => $envelopes ) );
}
