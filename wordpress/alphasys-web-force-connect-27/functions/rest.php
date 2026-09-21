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
	$payload = $request->get_json_params();
	if ( ! is_array( $payload ) || ! isset( $payload['summary'], $payload['posts'], $payload['postmeta'] ) || ! is_array( $payload['posts'] ) || ! is_array( $payload['postmeta'] ) ) {
		return new WP_Error( 'wfc27_invalid_packet', 'Packet must have summary, posts, and postmeta.', array( 'status' => 400 ) );
	}
	if ( ! is_array( $payload['summary'] ) || ( isset( $payload['deletes'] ) && ! is_array( $payload['deletes'] ) ) ) {
		return new WP_Error( 'wfc27_invalid_packet', 'Packet summary or deletes are invalid.', array( 'status' => 400 ) );
	}
	$packet_id = isset( $payload['summary']['packet_id'] ) ? (string) $payload['summary']['packet_id'] : '';
	if ( ( '' !== $packet_id && ! preg_match( '/^[A-Za-z0-9]{15,18}$/', $packet_id ) ) || count( $payload['posts'] ) > 500 || count( $payload['postmeta'] ) > 5000 ) {
		return new WP_Error( 'wfc27_invalid_packet', 'Packet ID or item count is invalid.', array( 'status' => 400 ) );
	}
	if ( '' !== $packet_id && ( (int) ( $payload['summary']['posts'] ?? -1 ) !== count( $payload['posts'] ) || (int) ( $payload['summary']['postmeta'] ?? -1 ) !== count( $payload['postmeta'] ) ) ) {
		return new WP_Error( 'wfc27_invalid_packet', 'Packet counts do not match its records.', array( 'status' => 400 ) );
	}
	$raw = wp_json_encode( $payload );
	if ( ! $raw || strlen( $raw ) > 2 * MB_IN_BYTES ) {
		return new WP_Error( 'wfc27_packet_size', 'Packet exceeds the accepted size.', array( 'status' => 413 ) );
	}
	if ( '' !== $packet_id ) {
		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . wfc27_packets_table() . ' (packet_id,payload,status,received_at) VALUES (%s,%s,%s,%s)',
			$packet_id, $raw, 'received', current_time( 'mysql', true )
		) );
		if ( $wpdb->last_error ) {
			return new WP_Error( 'wfc27_storage_error', 'Packet could not be stored.', array( 'status' => 500 ) );
		}
	} elseif ( $payload['posts'] || $payload['postmeta'] || ! empty( $payload['deletes'] ) ) {
		return new WP_Error( 'wfc27_invalid_packet', 'Content requires a packet ID.', array( 'status' => 400 ) );
	}
	wfc27_acknowledge_results( $payload['summary'] );
	if ( isset( $payload['summary']['train_state'] ) && in_array( $payload['summary']['train_state'], array( 'running', 'paused' ), true ) ) {
		update_option( 'wfc27_train_state', $payload['summary']['train_state'], false );
	}
	update_option( 'wfc27_last_heartbeat', current_time( 'mysql', true ), false );
	$wpdb->insert( wfc27_trips_table(), array(
		'packet_id' => $packet_id,
		'post_count' => count( $payload['posts'] ),
		'meta_count' => count( $payload['postmeta'] ),
		'received_at' => current_time( 'mysql', true ),
	), array( '%s', '%d', '%d', '%s' ) );
	if ( wp_rand( 1, 60 ) === 1 ) {
		$wpdb->query( 'DELETE FROM ' . wfc27_trips_table() . ' WHERE received_at < UTC_TIMESTAMP() - INTERVAL 30 DAY' );
	}
	$response = wfc27_pending_results( $packet_id );
	$response['summary'] = array( 'received_packet_id' => $packet_id );
	return rest_ensure_response( $response );
}

function wfc27_acknowledge_results( $summary ) {
	global $wpdb;
	foreach ( (array) ( $summary['acknowledged_results'] ?? array() ) as $packet_id ) {
		if ( is_string( $packet_id ) && preg_match( '/^[A-Za-z0-9]{15,18}$/', $packet_id ) ) {
			$wpdb->update( wfc27_packets_table(), array( 'status' => 'reported' ), array( 'packet_id' => $packet_id, 'status' => 'completed' ), array( '%s' ), array( '%s', '%s' ) );
		}
	}
	foreach ( (array) ( $summary['acknowledged_deletions'] ?? array() ) as $pair ) {
		if ( ! is_array( $pair ) || empty( $pair['sf_id'] ) || empty( $pair['wp_id'] ) ) {
			continue;
		}
		$packet_id = 'local-delete-' . (int) $pair['wp_id'];
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . wfc27_packets_table() . " SET status = 'reported' WHERE packet_id LIKE %s AND status = 'completed'", $wpdb->esc_like( $packet_id ) . '-%' ) );
	}
}

function wfc27_pending_results( $current_packet_id = '' ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT packet_id,status,result_json,error_text FROM ' . wfc27_packets_table() . ' WHERE status IN (%s,%s) AND packet_id != %s ORDER BY id ASC LIMIT 100', 'completed', 'error', $current_packet_id ), ARRAY_A );
	$result = array( 'completed' => array(), 'deleted' => array(), 'errors' => array() );
	foreach ( $rows as $row ) {
		if ( 'error' === $row['status'] ) {
			$result['errors'][] = array( 'packet_id' => $row['packet_id'], 'message' => $row['error_text'] );
			continue;
		}
		$data = json_decode( $row['result_json'], true );
		if ( ! is_array( $data ) ) {
			continue;
		}
		if ( isset( $data['packet_id'] ) ) {
			$result['completed'][] = array_intersect_key( $data, array_flip( array( 'packet_id', 'posts', 'postmeta' ) ) );
		}
		foreach ( (array) ( $data['deleted'] ?? array() ) as $deleted ) {
			$result['deleted'][] = $deleted;
		}
	}
	return $result;
}
