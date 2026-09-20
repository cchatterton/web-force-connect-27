<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wfc27_process_pending_packets( $limit ) {
	global $wpdb;
	$limit = max( 1, min( 100, (int) $limit ) );
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wfc27_packets_table() . ' WHERE status = %s ORDER BY id ASC LIMIT %d', 'received', $limit ), ARRAY_A );
	foreach ( $rows as $row ) {
		$claimed = $wpdb->update( wfc27_packets_table(), array( 'status' => 'processing' ), array( 'id' => $row['id'], 'status' => 'received' ), array( '%s' ), array( '%d', '%s' ) );
		if ( 1 !== $claimed ) {
			continue;
		}
		try {
			$payload = json_decode( $row['payload'], true, 512, JSON_THROW_ON_ERROR );
			$result = wfc27_apply_packet( $payload );
			$wpdb->update(
				wfc27_packets_table(),
				array( 'status' => 'completed', 'result_json' => wp_json_encode( $result ), 'processed_at' => current_time( 'mysql', true ), 'error_text' => null ),
				array( 'id' => $row['id'] ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} catch ( Throwable $error ) {
			$wpdb->update(
				wfc27_packets_table(),
				array( 'status' => 'error', 'processed_at' => current_time( 'mysql', true ), 'error_text' => $error->getMessage() ),
				array( 'id' => $row['id'] ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
		} finally {
			$GLOBALS['wfc27_applying_packet'] = false;
		}
	}
}

function wfc27_apply_packet( $packet ) {
	if ( ! is_array( $packet ) || ! isset( $packet['summary']['packet_id'] ) ) {
		throw new RuntimeException( 'Invalid packet summary.' );
	}
	$GLOBALS['wfc27_applying_packet'] = true;
	$packet_id = (string) $packet['summary']['packet_id'];
	$result = array( 'packet_id' => $packet_id, 'posts' => array(), 'postmeta' => array() );
	foreach ( (array) ( $packet['posts'] ?? array() ) as $source ) {
		$result['posts'][] = wfc27_apply_post( $source );
	}
	foreach ( (array) ( $packet['postmeta'] ?? array() ) as $source ) {
		$result['postmeta'][] = wfc27_apply_meta( $source );
	}
	foreach ( (array) ( $packet['deletes'] ?? array() ) as $source ) {
		$deleted = wfc27_apply_delete( $source );
		if ( $deleted ) {
			$result['deleted'][] = $deleted;
		}
	}
	return $result;
}

function wfc27_apply_post( $source ) {
	if ( ! is_array( $source ) || ! isset( $source['sf_id'] ) || ! preg_match( '/^[A-Za-z0-9]{15,18}$/', (string) $source['sf_id'] ) ) {
		throw new RuntimeException( 'Post has no valid Salesforce ID.' );
	}
	$sf_id = (string) $source['sf_id'];
	$identity = wfc27_identity_for_sf( $sf_id );
	$post_id = $identity && $identity['wp_post_id'] && get_post( (int) $identity['wp_post_id'] ) ? (int) $identity['wp_post_id'] : 0;
	$allowed = array( 'post_type', 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_status', 'post_date', 'menu_order', 'post_parent' );
	$data = array_intersect_key( $source, array_flip( $allowed ) );
	if ( isset( $data['post_type'] ) ) {
		$data['post_type'] = sanitize_key( $data['post_type'] );
		if ( ! post_type_exists( $data['post_type'] ) ) {
			throw new RuntimeException( 'Destination post type is not registered: ' . $data['post_type'] );
		}
	}
	if ( isset( $data['post_status'] ) ) {
		$data['post_status'] = sanitize_key( $data['post_status'] );
		if ( ! in_array( $data['post_status'], array( 'publish', 'draft', 'private', 'pending', 'trash' ), true ) ) {
			throw new RuntimeException( 'Unsupported post status.' );
		}
	}
	if ( ! $post_id && empty( $data['post_type'] ) ) {
		throw new RuntimeException( 'New post requires post_type.' );
	}
	$old_status = $post_id ? get_post_status( $post_id ) : '';
	if ( $post_id ) {
		$data['ID'] = $post_id;
		$applied = wp_update_post( wp_slash( $data ), true );
	} else {
		$data['post_status'] = $data['post_status'] ?? 'draft';
		$applied = wp_insert_post( wp_slash( $data ), true );
	}
	if ( is_wp_error( $applied ) || ! $applied ) {
		throw new RuntimeException( is_wp_error( $applied ) ? $applied->get_error_message() : 'WordPress post write failed.' );
	}
	$post = get_post( (int) $applied );
	$status_entered_at = $identity['status_entered_at'] ?? null;
	if ( ! $status_entered_at || $old_status !== $post->post_status ) {
		$status_entered_at = current_time( 'mysql', true );
	}
	$fields = array_merge( $identity ? (array) json_decode( $identity['bound_post_fields'] ?: '[]', true ) : array(), array_keys( $data ) );
	$fields = array_diff( $fields, array( 'ID' ) );
	wfc27_store_identity( $sf_id, (int) $applied, $post->post_type, $fields, $identity ? (array) json_decode( $identity['bound_meta_keys'] ?: '[]', true ) : array(), $identity ? (array) json_decode( $identity['meta_ids'] ?: '{}', true ) : array(), $status_entered_at );
	return array( 'sf_id' => $sf_id, 'wp_id' => (int) $applied, 'post_status' => $post->post_status, 'status_entered_at' => $status_entered_at );
}

function wfc27_apply_meta( $source ) {
	global $wpdb;
	if ( ! is_array( $source ) || empty( $source['sf_id'] ) || ! isset( $source['meta_key'] ) || ! array_key_exists( 'meta_value', $source ) ) {
		throw new RuntimeException( 'Invalid postmeta item.' );
	}
	$sf_id = (string) $source['sf_id'];
	$identity = wfc27_identity_for_sf( $sf_id );
	if ( ! $identity || ! $identity['wp_post_id'] || ! get_post( (int) $identity['wp_post_id'] ) ) {
		throw new RuntimeException( 'Postmeta has no destination post for ' . $sf_id );
	}
	$key = (string) $source['meta_key'];
	if ( ! preg_match( '/^[A-Za-z0-9_\-]{1,255}$/', $key ) ) {
		throw new RuntimeException( 'Unsupported postmeta key.' );
	}
	$post_id = (int) $identity['wp_post_id'];
	$meta_ids = (array) json_decode( $identity['meta_ids'] ?: '{}', true );
	if ( null === $source['meta_value'] ) {
		delete_post_meta( $post_id, $key );
		unset( $meta_ids[ $key ] );
		$meta_id = 0;
	} else {
		$value = is_scalar( $source['meta_value'] ) ? (string) $source['meta_value'] : wp_json_encode( $source['meta_value'] );
		update_post_meta( $post_id, $key, wp_slash( $value ) );
		$meta_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1", $post_id, $key ) );
		$meta_ids[ $key ] = $meta_id;
	}
	$keys = array_merge( (array) json_decode( $identity['bound_meta_keys'] ?: '[]', true ), array( $key ) );
	wfc27_store_identity( $sf_id, $post_id, $identity['post_type'], (array) json_decode( $identity['bound_post_fields'] ?: '[]', true ), $keys, $meta_ids, $identity['status_entered_at'] );
	return array( 'sf_id' => $sf_id, 'meta_key' => $key, 'wp_meta_id' => $meta_id );
}

function wfc27_apply_delete( $source ) {
	global $wpdb;
	$sf_id = isset( $source['sf_id'] ) ? (string) $source['sf_id'] : '';
	$identity = wfc27_identity_for_sf( $sf_id );
	if ( ! $identity ) {
		return null;
	}
	$post_id = (int) $identity['wp_post_id'];
	if ( $post_id && get_post( $post_id ) && ! wp_delete_post( $post_id, true ) ) {
		throw new RuntimeException( 'WordPress could not permanently delete post ' . $post_id );
	}
	if ( false === $wpdb->delete( wfc27_identity_table(), array( 'sf_id' => $sf_id ), array( '%s' ) ) ) {
		throw new RuntimeException( 'WFC27 identity could not be cleared after deletion.' );
	}
	return array( 'sf_id' => $sf_id, 'wp_id' => $post_id );
}
