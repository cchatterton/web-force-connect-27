<?php
/** Admin list and record screens for the transport station. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'wfc27_register_station_page' );
function wfc27_register_station_page() {
	add_submenu_page( 'wfc27', 'Sync Packets', 'Sync Packets', 'manage_options', 'wfc27-station', 'wfc27_render_station_page' );
}

add_action( 'admin_post_wfc27_save_station_item', 'wfc27_save_station_item' );
function wfc27_save_station_item() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'wfc27_save_station_item' );
	$json = isset( $_POST['station_json'] ) ? wp_unslash( $_POST['station_json'] ) : '';
	if ( strlen( $json ) > 120000 ) {
		wp_die( 'A station payload cannot exceed 120 KB.' );
	}
	$id = isset( $_POST['station_id'] ) ? sanitize_text_field( wp_unslash( $_POST['station_id'] ) ) : '';
	if ( '' === $id ) {
		$result = wfc27_stage_outbound( $json );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		$id = $result;
	} else {
		global $wpdb;
		$updated = $wpdb->update(
			wfc27_station_table(),
			array( 'json' => $json ),
			array( 'envelope_id' => $id, 'status' => 'outbound_ready' ),
			array( '%s' ),
			array( '%s', '%s' )
		);
		if ( false === $updated || ( 0 === $updated && 'outbound_ready' !== $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . wfc27_station_table() . ' WHERE envelope_id = %s', $id ) ) ) ) {
			wp_die( 'This item is no longer waiting to depart, or it was not found.' );
		}
	}
	wp_safe_redirect( add_query_arg( array( 'page' => 'wfc27-station', 'item' => $id, 'saved' => '1' ), admin_url( 'admin.php' ) ) );
	exit;
}

function wfc27_station_url( $id = '' ) {
	$args = array( 'page' => 'wfc27-station' );
	if ( '' !== $id ) {
		$args['item'] = $id;
	}
	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

function wfc27_render_station_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	$id = isset( $_GET['item'] ) ? sanitize_text_field( wp_unslash( $_GET['item'] ) ) : '';
	if ( '' !== $id ) {
		wfc27_render_station_item( $id );
	} else {
		wfc27_render_station_list();
	}
}

function wfc27_render_station_list() {
	global $wpdb;
	$table = wfc27_station_table();
	$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	$allowed = array( 'outbound_ready', 'outbound_delivered', 'inbound_received', 'inbound_acked' );
	if ( ! in_array( $status, $allowed, true ) ) {
		$status = '';
	}
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$page = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
	$where = array();
	$params = array();
	if ( $status ) {
		$where[] = 'status = %s';
		$params[] = $status;
	}
	if ( $search ) {
		$where[] = 'envelope_id LIKE %s';
		$params[] = '%' . $wpdb->esc_like( $search ) . '%';
	}
	$condition = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
	$count_sql = "SELECT COUNT(*) FROM {$table}{$condition}";
	$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );
	$params[] = 25;
	$params[] = ( $page - 1 ) * 25;
	$items = $wpdb->get_results( $wpdb->prepare( "SELECT envelope_id,status,json FROM {$table}{$condition} ORDER BY envelope_id DESC LIMIT %d OFFSET %d", $params ), ARRAY_A );
	$counts = $wpdb->get_results( "SELECT status,COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );
	$all = array_sum( array_map( static function ( $row ) { return (int) $row['total']; }, $counts ) );
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline">Sync Packets</h1>
		<a href="<?php echo esc_url( wfc27_station_url( 'new' ) ); ?>" class="page-title-action">Add New</a>
		<hr class="wp-header-end">
		<ul class="subsubsub">
			<li><a href="<?php echo esc_url( wfc27_station_url() ); ?>" <?php echo '' === $status ? 'class="current"' : ''; ?>>All <span class="count">(<?php echo esc_html( (string) $all ); ?>)</span></a></li>
			<?php foreach ( $counts as $count ) : ?>
			<li> | <a href="<?php echo esc_url( add_query_arg( 'status', $count['status'], wfc27_station_url() ) ); ?>" <?php echo $status === $count['status'] ? 'class="current"' : ''; ?>><?php echo esc_html( str_replace( '_', ' ', ucfirst( $count['status'] ) ) ); ?> <span class="count">(<?php echo esc_html( $count['total'] ); ?>)</span></a></li>
			<?php endforeach; ?>
		</ul>
		<form method="get"><input type="hidden" name="page" value="wfc27-station"><input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search envelope ID"><?php submit_button( 'Search Sync Packets', '', '', false ); ?></form>
		<table class="wp-list-table widefat fixed striped table-view-list"><thead><tr><th>Envelope ID</th><th>Status</th><th>Payload preview</th></tr></thead><tbody>
		<?php if ( ! $items ) : ?><tr><td colspan="3">No sync packets found.</td></tr><?php endif; ?>
		<?php foreach ( $items as $item ) : ?>
			<tr><td><strong><a class="row-title" href="<?php echo esc_url( wfc27_station_url( $item['envelope_id'] ) ); ?>"><?php echo esc_html( $item['envelope_id'] ); ?></a></strong><div class="row-actions"><span class="edit"><a href="<?php echo esc_url( wfc27_station_url( $item['envelope_id'] ) ); ?>">View details</a></span></div></td><td><?php echo esc_html( $item['status'] ); ?></td><td><code><?php echo esc_html( wp_html_excerpt( $item['json'], 120, '…' ) ); ?></code></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php if ( $total > 25 ) : ?><div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( paginate_links( array( 'base' => wfc27_station_url() . '&paged=%#%', 'current' => $page, 'total' => (int) ceil( $total / 25 ), 'add_args' => array_filter( array( 'status' => $status, 's' => $search ) ) ) ) ); ?></div></div><?php endif; ?>
	</div>
	<?php
}

function wfc27_render_station_item( $id ) {
	global $wpdb;
	$new = 'new' === $id;
	$item = $new ? null : $wpdb->get_row( $wpdb->prepare( 'SELECT envelope_id,status,json FROM ' . wfc27_station_table() . ' WHERE envelope_id = %s', $id ), ARRAY_A );
	if ( ! $new && ! $item ) {
		wp_die( 'Sync packet not found.' );
	}
	$editable = $new || 'outbound_ready' === $item['status'];
	$raw = $new ? '' : $item['json'];
	$payload = $new ? null : json_decode( $raw );
	$is_json = ! $new && JSON_ERROR_NONE === json_last_error();
	$pretty = $is_json ? wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : $raw;
	?>
	<div class="wrap">
		<h1><?php echo $new ? 'Add Sync Packet' : 'Sync Packet'; ?></h1>
		<p><a href="<?php echo esc_url( wfc27_station_url() ); ?>">← All Sync Packets</a></p>
		<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p>Sync packet saved.</p></div><?php endif; ?>
		<?php if ( ! $new ) : ?><table class="form-table"><tr><th>Envelope ID</th><td><code><?php echo esc_html( $item['envelope_id'] ); ?></code></td></tr><tr><th>Status</th><td><?php echo esc_html( $item['status'] ); ?></td></tr></table><?php endif; ?>
		<h2>Payload</h2>
		<?php if ( $editable ) : ?>
			<p>Enter any text. It will travel on the next train while its status is outbound_ready.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wfc27_save_station_item"><input type="hidden" name="station_id" value="<?php echo esc_attr( $new ? '' : $id ); ?>">
				<?php wp_nonce_field( 'wfc27_save_station_item' ); ?>
				<textarea name="station_json" rows="16" class="large-text code" aria-label="Payload"><?php echo esc_textarea( $raw ); ?></textarea>
				<?php submit_button( $new ? 'Add Sync Packet' : 'Save payload' ); ?>
			</form>
		<?php else : ?>
			<p>This item has left the outbound queue or arrived from Salesforce, so its transport payload is read-only.</p>
			<pre style="max-width:100%;overflow:auto;background:#fff;padding:16px;border:1px solid #c3c4c7"><?php echo esc_html( $pretty ); ?></pre>
		<?php endif; ?>
		<?php if ( ! $new ) : ?>
			<h2><?php echo $is_json ? 'JSON elements' : 'Text'; ?></h2>
			<table class="widefat striped"><thead><tr><th>Path</th><th>Type</th><th>Value</th></tr></thead><tbody>
			<?php if ( $is_json ) { wfc27_render_json_elements( $payload ); } else { wfc27_render_json_elements( $raw ); } ?>
			</tbody></table>
		<?php endif; ?>
	</div>
	<?php
}

function wfc27_render_json_elements( $value, $path = '$' ) {
	if ( is_object( $value ) || is_array( $value ) ) {
		if ( ! (array) $value ) {
			echo '<tr><td><code>' . esc_html( $path ) . '</code></td><td>' . ( is_array( $value ) ? 'array' : 'object' ) . '</td><td><code>' . ( is_array( $value ) ? '[]' : '{}' ) . '</code></td></tr>';
		}
		foreach ( $value as $key => $child ) {
			wfc27_render_json_elements( $child, $path . ( is_int( $key ) ? '[' . $key . ']' : '.' . $key ) );
		}
		return;
	}
	$type = is_null( $value ) ? 'null' : gettype( $value );
	$display = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : ( is_null( $value ) ? 'null' : (string) $value );
	echo '<tr><td><code>' . esc_html( $path ) . '</code></td><td>' . esc_html( $type ) . '</td><td><code>' . esc_html( $display ) . '</code></td></tr>';
}
