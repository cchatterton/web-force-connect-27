<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'wfc27_register_admin_page' );
function wfc27_register_admin_page() {
	add_menu_page( 'Web Force Connect 27', 'WFC27', 'manage_options', 'wfc27', 'wfc27_render_admin_page', 'dashicons-update', 65 );
}

add_action( 'admin_post_wfc27_save_receiver', 'wfc27_admin_save_receiver' );
add_action( 'admin_post_wfc27_stage_json', 'wfc27_admin_stage_json' );
add_action( 'wp_ajax_wfc27_status', 'wfc27_admin_ajax_status' );
add_action( 'admin_enqueue_scripts', 'wfc27_admin_enqueue_status' );
function wfc27_admin_enqueue_status( $hook ) {
	if ( 'toplevel_page_wfc27' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'wfc27-admin-status', plugins_url( 'assets/admin-status.css', WFC27_FILE ), array(), WFC27_VERSION );
	wp_enqueue_script( 'wfc27-admin-status', plugins_url( 'assets/admin-status.js', WFC27_FILE ), array(), WFC27_VERSION, true );
	wp_localize_script( 'wfc27-admin-status', 'wfc27Status', array(
		'url' => admin_url( 'admin-ajax.php' ),
		'nonce' => wp_create_nonce( 'wfc27_status' ),
	) );
}
function wfc27_admin_ajax_status() {
	check_ajax_referer( 'wfc27_status', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
	}
	global $wpdb;
	$last = get_option( 'wfc27_last_heartbeat', '' );
	$counts = $wpdb->get_results( 'SELECT status, COUNT(*) AS total FROM ' . wfc27_station_table() . ' GROUP BY status', ARRAY_A );
	$parts = array();
	$filter = isset( $_POST['trip_filter'] ) ? sanitize_key( wp_unslash( $_POST['trip_filter'] ) ) : 'hour';
	$day = isset( $_POST['trip_day'] ) ? sanitize_text_field( wp_unslash( $_POST['trip_day'] ) ) : '';
	$hour = isset( $_POST['trip_hour'] ) ? absint( wp_unslash( $_POST['trip_hour'] ) ) : 0;
	$since = 'day' === $filter ? gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) : gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
	$until = gmdate( 'Y-m-d H:i:s' );
	if ( 'date' === $filter && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
		$since = $day . ' 00:00:00';
		$until = gmdate( 'Y-m-d H:i:s', strtotime( $since . ' UTC' ) + DAY_IN_SECONDS );
	} elseif ( 'date_hour' === $filter && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) && $hour < 24 ) {
		$since = $day . ' ' . sprintf( '%02d', $hour ) . ':00:00';
		$until = gmdate( 'Y-m-d H:i:s', strtotime( $since . ' UTC' ) + HOUR_IN_SECONDS );
	}
	$trips = $wpdb->get_results( $wpdb->prepare( 'SELECT id,sent_count,received_count,occurred_at FROM ' . wfc27_trips_table() . ' WHERE occurred_at >= %s AND occurred_at < %s ORDER BY id DESC LIMIT 120', $since, $until ), ARRAY_A );
	foreach ( $counts as $count ) {
		$parts[] = ucfirst( $count['status'] ) . ': ' . $count['total'];
	}
	wp_send_json_success( array(
		'last' => $last ? gmdate( 'c', strtotime( $last . ' UTC' ) ) : null,
		'state' => get_option( 'wfc27_train_state', 'running' ),
		'counts' => implode( ' · ', $parts ),
		'trips' => $trips,
	) );
}
function wfc27_admin_save_receiver() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'wfc27_save_receiver' );
	$user_id = isset( $_POST['receiver_user_id'] ) ? absint( wp_unslash( $_POST['receiver_user_id'] ) ) : 0;
	if ( $user_id && ! get_userdata( $user_id ) ) {
		wp_die( 'The selected WordPress user does not exist.' );
	}
	update_option( 'wfc27_receiver_user_id', $user_id, false );
	wp_safe_redirect( admin_url( 'admin.php?page=wfc27&receiver_saved=1' ) );
	exit;
}
function wfc27_admin_stage_json() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'wfc27_stage_json' );
	$json = isset( $_POST['station_json'] ) ? wp_unslash( $_POST['station_json'] ) : '';
	$result = wfc27_stage_outbound( $json );
	if ( is_wp_error( $result ) ) {
		wp_die( esc_html( $result->get_error_message() ) );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=wfc27&staged=' . rawurlencode( $result ) ) );
	exit;
}
function wfc27_render_admin_page() {
	global $wpdb;
	$station = wfc27_station_table();
	$counts = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$station} GROUP BY status", ARRAY_A );
	$rows = $wpdb->get_results( "SELECT envelope_id,status,json FROM {$station} ORDER BY envelope_id DESC LIMIT 50", ARRAY_A );
	$last = get_option( 'wfc27_last_heartbeat', '' );
	?>
	<div class="wrap">
		<h1>Web Force Connect 27</h1>
		<p>WFC27 carries JSON between two stations. Processing engines are not installed; received items remain staged.</p>
		<section class="wfc27-train-widget" aria-label="WordPress sync train">
			<h2>Sync Train Arriving</h2>
			<div id="wfc27-train-countdown" class="wfc27-train-countdown" aria-live="off">01:00</div>
			<p id="wfc27-heartbeat-label" aria-live="polite">Waiting for train status</p>
			<div class="wfc27-heartbeat" role="progressbar" aria-label="Time until next train" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-state="<?php echo esc_attr( get_option( 'wfc27_train_state', 'running' ) ); ?>" data-last="<?php echo esc_attr( $last ? gmdate( 'c', strtotime( $last . ' UTC' ) ) : '' ); ?>"><div class="wfc27-heartbeat-fill"></div></div>
		</section>
		<h2>Recent train trips</h2>
		<div class="wfc27-trip-filters">
			<label>Show <select id="wfc27-trip-filter"><option value="hour">Last hour</option><option value="day">Last 24 hours</option><option value="date">Day (UTC)</option><option value="date_hour">Hour in day (UTC)</option></select></label>
			<label>Date <input id="wfc27-trip-day" type="date" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>"></label>
			<label>Hour <select id="wfc27-trip-hour"><?php for ( $hour = 0; $hour < 24; $hour++ ) : ?><option value="<?php echo esc_attr( (string) $hour ); ?>"><?php echo esc_html( sprintf( '%02d:00', $hour ) ); ?></option><?php endfor; ?></select></label>
		</div>
		<table class="widefat striped"><thead><tr><th>Arrived (UTC)</th><th>Sent</th><th>Received</th></tr></thead><tbody id="wfc27-trip-rows"><tr><td colspan="3">Loading trips…</td></tr></tbody></table>
		<p><strong>Receive endpoint:</strong> <code><?php echo esc_html( rest_url( 'wfc27/v1/train' ) ); ?></code></p>
		<h2>Station</h2>
		<?php if ( isset( $_GET['staged'] ) ) : ?><div class="notice notice-success"><p>JSON staged for the next train.</p></div><?php endif; ?>
		<p id="wfc27-queue-counts"><?php foreach ( $counts as $count ) { echo esc_html( ucfirst( $count['status'] ) . ': ' . $count['total'] ) . ' &nbsp; '; } ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wfc27_stage_json">
			<?php wp_nonce_field( 'wfc27_stage_json' ); ?>
			<label for="wfc27-station-json">Stage outbound JSON for a train test</label><br>
			<textarea id="wfc27-station-json" name="station_json" rows="4" cols="80" required></textarea><br>
			<?php submit_button( 'Stage JSON', 'secondary', 'submit', false ); ?>
		</form>
		<table class="widefat striped"><thead><tr><th>ID</th><th>Status</th><th>JSON</th></tr></thead><tbody>
		<?php foreach ( $rows as $row ) : ?>
		<tr><td><code><?php echo esc_html( $row['envelope_id'] ); ?></code></td><td><?php echo esc_html( $row['status'] ); ?></td><td><code><?php echo esc_html( wp_html_excerpt( $row['json'], 160, '…' ) ); ?></code></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<h2>Connection setup</h2>
		<p>By default, a WordPress administrator can send trains using an Application Password. Optionally, choose another user to authorize a dedicated connection. Store the Application Password in Salesforce's credential settings. No special WordPress role or capability is required.</p>
		<?php if ( isset( $_GET['receiver_saved'] ) ) : ?><div class="notice notice-success"><p>Train receiver saved.</p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wfc27_save_receiver">
			<?php wp_nonce_field( 'wfc27_save_receiver' ); ?>
			<label for="wfc27-receiver-user">WordPress train receiver</label>
			<select id="wfc27-receiver-user" name="receiver_user_id">
				<option value="0">Administrators only (default)</option>
				<?php foreach ( get_users( array( 'orderby' => 'display_name' ) ) as $user ) : ?>
					<option value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php selected( (int) get_option( 'wfc27_receiver_user_id', 0 ), (int) $user->ID ); ?>><?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( 'Save receiver', 'primary', 'submit', false ); ?>
		</form>
	</div>
	<?php
}
