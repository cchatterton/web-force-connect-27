<?php
/**
 * Run with wp eval-file on a disposable local WordPress site with WFC27 active.
 */
if ( ! defined( 'ABSPATH' ) || 'local' !== wp_get_environment_type() || ! function_exists( 'wfc27_process_pending_packets' ) ) {
	throw new RuntimeException( 'Run only on a local WordPress site with WFC27 active.' );
}

function wfc27_smoke_train( $payload ) {
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
update_option( 'wfc27_receiver_user_id', (int) $admins[0]->ID );
$suffix = str_pad( (string) random_int( 1, 999999999 ), 15, '0', STR_PAD_LEFT );
$sf_id = 'a00' . $suffix;
$packet_one = 'a01' . $suffix;
$packet_two = 'a02' . $suffix;
$first = wfc27_smoke_train( array(
	'summary' => array( 'packet_id' => $packet_one, 'posts' => 1, 'postmeta' => 1 ),
	'posts' => array( array( 'sf_id' => $sf_id, 'post_type' => 'post', 'post_title' => 'WFC27 Alpha', 'post_content' => 'Outline', 'post_status' => 'draft' ) ),
	'postmeta' => array( array( 'sf_id' => $sf_id, 'meta_key' => 'duration', 'meta_value' => 'Two days' ) ),
) );
if ( $first['summary']['received_packet_id'] !== $packet_one || $first['completed'] ) {
	throw new RuntimeException( 'Receipt was not separate from completion.' );
}
wfc27_process_pending_packets( 20 );
$second = wfc27_smoke_train( array( 'summary' => array(), 'posts' => array(), 'postmeta' => array() ) );
if ( 1 !== count( $second['completed'] ) ) {
	throw new RuntimeException( 'The delayed completion was missing.' );
}
$wp_id = (int) $second['completed'][0]['posts'][0]['wp_id'];
if ( 'WFC27 Alpha' !== get_post( $wp_id )->post_title || 'Two days' !== get_post_meta( $wp_id, 'duration', true ) ) {
	throw new RuntimeException( 'The post or meta was not applied.' );
}
wp_update_post( array( 'ID' => $wp_id, 'post_title' => 'Local override', 'post_excerpt' => 'Local excerpt' ) );
if ( 'WFC27 Alpha' !== get_post( $wp_id )->post_title || 'Local excerpt' !== get_post( $wp_id )->post_excerpt ) {
	throw new RuntimeException( 'Bound-field protection or local excerpt failed.' );
}
wfc27_smoke_train( array(
	'summary' => array( 'packet_id' => $packet_two, 'posts' => 1, 'postmeta' => 0, 'acknowledged_results' => array( $packet_one ) ),
	'posts' => array( array( 'sf_id' => $sf_id, 'post_type' => 'post', 'post_title' => 'WFC27 Beta', 'post_content' => 'Updated outline', 'post_status' => 'publish' ) ),
	'postmeta' => array(),
) );
wfc27_process_pending_packets( 20 );
$fourth = wfc27_smoke_train( array( 'summary' => array(), 'posts' => array(), 'postmeta' => array() ) );
if ( 1 !== count( $fourth['completed'] ) || 'WFC27 Beta' !== get_post( $wp_id )->post_title || 'Local excerpt' !== get_post( $wp_id )->post_excerpt ) {
	throw new RuntimeException( 'The update or local-field preservation failed.' );
}
wp_trash_post( $wp_id );
$fifth = wfc27_smoke_train( array( 'summary' => array( 'acknowledged_results' => array( $packet_two ) ), 'posts' => array(), 'postmeta' => array() ) );
if ( 1 !== count( $fifth['deleted'] ) || $wp_id !== (int) $fifth['deleted'][0]['wp_id'] ) {
	throw new RuntimeException( 'The local deletion was not reported.' );
}
update_option( 'wfc27_receiver_user_id', $original_receiver );
echo "WFC27 WordPress smoke passed.\n";
