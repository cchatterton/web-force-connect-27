<?php
/**
 * Run with `wp eval-file` on a local WordPress site with WFC27 active.
 * Exercises duplicate receipt, the scheduled worker, delayed results, and acknowledgement.
 */
if ( ! defined( 'ABSPATH' ) || 'local' !== wp_get_environment_type() || ! function_exists( 'wfc27_process_pending_packets' ) ) {
	throw new RuntimeException( 'Run only on a local WordPress site with WFC27 active.' );
}

function wfc27_test_train_request( $payload ) {
	$request = new WP_REST_Request( 'POST', '/wfc27/v1/train' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $payload ) );
	$response = rest_do_request( $request );
	if ( $response->is_error() ) {
		throw new RuntimeException( wp_json_encode( $response->as_error()->get_error_messages() ) );
	}
	return $response->get_data();
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admins ) {
	throw new RuntimeException( 'No administrator exists on this local test site.' );
}
wp_set_current_user( (int) $admins[0]->ID );
$original_receiver = get_option( 'wfc27_receiver_user_id', 0 );
global $wpdb;
$suffix = str_pad( (string) random_int( 1, 999999999 ), 15, '0', STR_PAD_LEFT );
$sf_id = 'a00' . $suffix;
$packet_id = 'a01' . $suffix;
$post_id = 0;
$test_user_id = 0;

try {
	update_option( 'wfc27_receiver_user_id', 0 );
	if ( ! wfc27_can_receive_train() ) {
		throw new RuntimeException( 'Administrator default access failed.' );
	}
	$test_user_id = wp_create_user( 'wfc27-test-' . $suffix, wp_generate_password(), 'wfc27-test-' . $suffix . '@example.invalid' );
	if ( is_wp_error( $test_user_id ) ) {
		throw new RuntimeException( $test_user_id->get_error_message() );
	}
	wp_set_current_user( $test_user_id );
	if ( wfc27_can_receive_train() ) {
		throw new RuntimeException( 'Unselected non-administrator was allowed.' );
	}
	update_option( 'wfc27_receiver_user_id', $test_user_id );
	if ( ! wfc27_can_receive_train() ) {
		throw new RuntimeException( 'Selected receiver was denied.' );
	}
	wp_set_current_user( (int) $admins[0]->ID );
	$payload = array(
		'summary' => array( 'packet_id' => $packet_id, 'posts' => 1, 'postmeta' => 1 ),
		'posts' => array( array( 'sf_id' => $sf_id, 'post_type' => 'post', 'post_title' => 'WFC27 train test', 'post_status' => 'draft' ) ),
		'postmeta' => array( array( 'sf_id' => $sf_id, 'meta_key' => 'wfc27_train_test', 'meta_value' => 'arrived' ) ),
	);
	$receipt = wfc27_test_train_request( $payload );
	$duplicate = wfc27_test_train_request( $payload );
	$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . wfc27_packets_table() . ' WHERE packet_id = %s', $packet_id ) );
	if ( $packet_id !== $receipt['summary']['received_packet_id'] || $receipt['completed'] || 1 !== $count || $packet_id !== $duplicate['summary']['received_packet_id'] ) {
		throw new RuntimeException( 'Receipt or duplicate handling failed.' );
	}
	if ( ! wp_next_scheduled( 'wfc27_process_inbox' ) ) {
		throw new RuntimeException( 'The one-minute worker is not scheduled.' );
	}
	do_action( 'wfc27_process_inbox' );
	$next = wfc27_test_train_request( array( 'summary' => array(), 'posts' => array(), 'postmeta' => array() ) );
	$completed = array_values( array_filter( $next['completed'], static function ( $item ) use ( $packet_id ) {
		return $packet_id === $item['packet_id'];
	} ) );
	if ( 1 !== count( $completed ) ) {
		throw new RuntimeException( 'The completed packet was not returned on the next train.' );
	}
	$post_id = (int) $completed[0]['posts'][0]['wp_id'];
	if ( ! $post_id || 'WFC27 train test' !== get_post( $post_id )->post_title || 'arrived' !== get_post_meta( $post_id, 'wfc27_train_test', true ) ) {
		throw new RuntimeException( 'The destination post or meta is missing.' );
	}
	$ack = wfc27_test_train_request( array( 'summary' => array( 'acknowledged_results' => array( $packet_id ) ), 'posts' => array(), 'postmeta' => array() ) );
	$still_pending = array_filter( $ack['completed'], static function ( $item ) use ( $packet_id ) {
		return $packet_id === $item['packet_id'];
	} );
	if ( $still_pending ) {
		throw new RuntimeException( 'Acknowledged result was returned again.' );
	}
	echo "WFC27 train test passed: authorization, receipt, duplicate, worker, delayed result, acknowledgement.\n";
} finally {
	if ( $post_id ) {
		$GLOBALS['wfc27_applying_packet'] = true;
		wp_delete_post( $post_id, true );
		$GLOBALS['wfc27_applying_packet'] = false;
	}
	$wpdb->delete( wfc27_identity_table(), array( 'sf_id' => $sf_id ), array( '%s' ) );
	$wpdb->delete( wfc27_packets_table(), array( 'packet_id' => $packet_id ), array( '%s' ) );
	update_option( 'wfc27_receiver_user_id', $original_receiver );
	wp_set_current_user( (int) $admins[0]->ID );
	if ( $test_user_id && ! is_wp_error( $test_user_id ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $test_user_id );
	}
}
