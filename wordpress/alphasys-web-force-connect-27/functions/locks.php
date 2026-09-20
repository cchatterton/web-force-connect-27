<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'wp_insert_post_data', 'wfc27_preserve_bound_post_fields', 10, 2 );
function wfc27_preserve_bound_post_fields( $data, $postarr ) {
	if ( ! empty( $GLOBALS['wfc27_applying_packet'] ) || empty( $postarr['ID'] ) ) {
		return $data;
	}
	$identity = wfc27_identity_for_post( (int) $postarr['ID'] );
	if ( ! $identity ) {
		return $data;
	}
	$old = get_post( (int) $postarr['ID'] );
	if ( ! $old ) {
		return $data;
	}
	foreach ( (array) json_decode( $identity['bound_post_fields'] ?: '[]', true ) as $field ) {
		if ( 'post_status' === $field && ! empty( $GLOBALS['wfc27_local_trashing_post_id'] ) && (int) $GLOBALS['wfc27_local_trashing_post_id'] === (int) $postarr['ID'] && 'trash' === ( $data['post_status'] ?? '' ) ) {
			continue;
		}
		if ( property_exists( $old, $field ) && isset( $data[ $field ] ) ) {
			$data[ $field ] = $old->$field;
		}
	}
	return $data;
}

add_action( 'wp_trash_post', 'wfc27_allow_local_trash' );
function wfc27_allow_local_trash( $post_id ) {
	if ( wfc27_identity_for_post( (int) $post_id ) && empty( $GLOBALS['wfc27_applying_packet'] ) ) {
		$GLOBALS['wfc27_local_trashing_post_id'] = (int) $post_id;
	}
}

add_action( 'trashed_post', 'wfc27_record_local_trash' );
function wfc27_record_local_trash( $post_id ) {
	unset( $GLOBALS['wfc27_local_trashing_post_id'] );
	wfc27_record_local_deletion( (int) $post_id );
}

add_filter( 'update_post_metadata', 'wfc27_preserve_bound_meta', 10, 5 );
add_filter( 'add_post_metadata', 'wfc27_preserve_bound_meta', 10, 5 );
add_filter( 'delete_post_metadata', 'wfc27_preserve_bound_meta', 10, 5 );
function wfc27_preserve_bound_meta( $check, $post_id, $key ) {
	if ( ! empty( $GLOBALS['wfc27_applying_packet'] ) ) {
		return $check;
	}
	$identity = wfc27_identity_for_post( (int) $post_id );
	if ( $identity && in_array( $key, (array) json_decode( $identity['bound_meta_keys'] ?: '[]', true ), true ) ) {
		return true;
	}
	return $check;
}

add_action( 'admin_notices', 'wfc27_show_source_notice' );
function wfc27_show_source_notice() {
	$screen = get_current_screen();
	if ( ! $screen || 'post' !== $screen->base || empty( $_GET['post'] ) ) {
		return;
	}
	$identity = wfc27_identity_for_post( (int) $_GET['post'] );
	if ( ! $identity ) {
		return;
	}
	$fields = implode( ', ', (array) json_decode( $identity['bound_post_fields'] ?: '[]', true ) );
	echo '<div class="notice notice-info"><p>' . esc_html( 'WFC27 source: Salesforce ' . $identity['sf_id'] . '. Bound post fields are controlled in Salesforce: ' . $fields . '. Other fields remain editable here.' ) . '</p></div>';
}
