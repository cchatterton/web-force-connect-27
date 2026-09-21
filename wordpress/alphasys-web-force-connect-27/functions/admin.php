<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'wfc27_register_admin_page' );
function wfc27_register_admin_page() {
	add_menu_page( 'Web Force Connect 27', 'WFC27', 'manage_options', 'wfc27', 'wfc27_render_admin_page', 'dashicons-update', 65 );
}

add_action( 'admin_post_wfc27_save_receiver', 'wfc27_admin_save_receiver' );
add_action( 'admin_post_wfc27_save_retention', 'wfc27_admin_save_retention' );
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
		'syncUrl' => admin_url( 'admin.php?page=wfc27&sync=' ),
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
	$waiting = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . wfc27_station_table() . ' WHERE status = %s', 'outbound_ready' ) );
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
	wp_send_json_success( array(
		'last' => $last ? gmdate( 'c', strtotime( $last . ' UTC' ) ) : null,
		'state' => get_option( 'wfc27_train_state', 'running' ),
		'waiting' => $waiting,
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
function wfc27_admin_save_retention() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'wfc27_save_retention' );
	$empty_days = isset( $_POST['empty_retention_days'] ) ? absint( wp_unslash( $_POST['empty_retention_days'] ) ) : 7;
	$packet_days = isset( $_POST['packet_retention_days'] ) ? absint( wp_unslash( $_POST['packet_retention_days'] ) ) : 90;
	if ( $empty_days < 1 || $empty_days > 3650 || $packet_days < 1 || $packet_days > 3650 ) {
		wp_die( 'Retention must be from 1 to 3650 days.' );
	}
	update_option( 'wfc27_empty_retention_days', $empty_days, false );
	update_option( 'wfc27_packet_retention_days', $packet_days, false );
	wp_safe_redirect( admin_url( 'admin.php?page=wfc27&retention_saved=1' ) );
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
	if ( isset( $_GET['sync'] ) ) {
		wfc27_render_sync_detail( absint( $_GET['sync'] ) );
		return;
	}
	global $wpdb;
	$last = get_option( 'wfc27_last_heartbeat', '' );
	$waiting = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . wfc27_station_table() . ' WHERE status = %s', 'outbound_ready' ) );
	$receiver_id = (int) get_option( 'wfc27_receiver_user_id', 0 );
	$credentials_url = $receiver_id ? get_edit_user_link( $receiver_id ) : admin_url( 'profile.php' );
	$empty_retention_days = (int) get_option( 'wfc27_empty_retention_days', 7 );
	$packet_retention_days = (int) get_option( 'wfc27_packet_retention_days', 90 );
	?>
	<div class="wrap">
		<h1>Web Force Connect 27</h1>
		<p>WFC27 carries raw payloads between two stations. Processing engines are not installed; received items remain staged.</p>
		<div class="wfc27-top-widgets">
		<section class="wfc27-train-widget" aria-label="WordPress sync train">
			<h2>Sync Train Arriving</h2>
			<div id="wfc27-train-countdown" class="wfc27-train-countdown" aria-live="off">01:00</div>
			<p id="wfc27-heartbeat-label" aria-live="polite">Waiting for train status</p>
			<div class="wfc27-heartbeat" role="progressbar" aria-label="Time until next train" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-state="<?php echo esc_attr( get_option( 'wfc27_train_state', 'running' ) ); ?>" data-last="<?php echo esc_attr( $last ? gmdate( 'c', strtotime( $last . ' UTC' ) ) : '' ); ?>"><div class="wfc27-heartbeat-fill"></div></div>
			<p>Packets waiting to send: <strong id="wfc27-station-waiting"><?php echo esc_html( (string) $waiting ); ?></strong></p>
		</section>
		<section class="wfc27-endpoint-widget" aria-label="Receive endpoint">
			<h2>Receive endpoint</h2>
			<p>Copy this WordPress address into the Salesforce Named Credential.</p>
			<code class="wfc27-endpoint"><?php echo esc_html( rest_url( 'wfc27/v1/train' ) ); ?></code>
		</section>
		<section class="wfc27-integration-widget" aria-label="Integration user">
			<h2>Integration user</h2>
			<p>Choose the WordPress user authorized to receive syncs. Create an Application Password in that user's profile.</p>
			<?php if ( isset( $_GET['receiver_saved'] ) ) : ?><div class="notice notice-success"><p>Train receiver saved.</p></div><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wfc27_save_receiver">
				<?php wp_nonce_field( 'wfc27_save_receiver' ); ?>
				<label for="wfc27-receiver-user">Integration user</label>
				<select id="wfc27-receiver-user" name="receiver_user_id">
					<option value="0">Administrators (default)</option>
					<?php foreach ( get_users( array( 'orderby' => 'display_name' ) ) as $user ) : ?>
						<option value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php selected( $receiver_id, (int) $user->ID ); ?>><?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( 'Save integration user', 'secondary', 'submit', false ); ?>
			</form>
			<p><a href="<?php echo esc_url( $credentials_url . '#application-passwords-section' ); ?>">Open Application Password settings ↗</a></p>
		</section>
		<section class="wfc27-trip-filter-widget" aria-label="Sync filters">
		<h2>Sync filters</h2>
		<div class="wfc27-trip-filters">
			<label>Show <select id="wfc27-trip-filter"><option value="hour">Last hour</option><option value="day">Last 24 hours</option><option value="date">Day (UTC)</option><option value="date_hour">Hour in day (UTC)</option></select></label>
			<label>Date <input id="wfc27-trip-day" type="date" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>"></label>
			<label>Hour <select id="wfc27-trip-hour"><?php for ( $hour = 0; $hour < 24; $hour++ ) : ?><option value="<?php echo esc_attr( (string) $hour ); ?>"><?php echo esc_html( sprintf( '%02d:00', $hour ) ); ?></option><?php endfor; ?></select></label>
		</div>
		</section>
		<section class="wfc27-retention-widget" aria-label="Sync retention">
		<h2>Sync retention</h2>
		<p>Daily cleanup removes sync history and keeps the packets.</p>
		<?php if ( isset( $_GET['retention_saved'] ) ) : ?><div class="notice notice-success inline"><p>Retention settings saved.</p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wfc27_save_retention">
			<?php wp_nonce_field( 'wfc27_save_retention' ); ?>
			<label>Empty syncs (days)<input type="number" name="empty_retention_days" min="1" max="3650" value="<?php echo esc_attr( (string) $empty_retention_days ); ?>"></label>
			<label>Syncs with packets (days)<input type="number" name="packet_retention_days" min="1" max="3650" value="<?php echo esc_attr( (string) $packet_retention_days ); ?>"></label>
			<?php submit_button( 'Save retention', 'secondary', 'submit', false ); ?>
		</form>
		</section>
		</div>
		<h2>Recent Sync</h2>
		<table class="widefat striped"><thead><tr><th>Arrived (UTC)</th><th>Packets sent</th><th>Packets received</th></tr></thead><tbody id="wfc27-trip-rows"><tr><td colspan="3">Loading syncs…</td></tr></tbody></table>
	</div>
	<?php
}

function wfc27_render_sync_detail( $id ) {
	global $wpdb;
	$trip = $wpdb->get_row( $wpdb->prepare( 'SELECT id,sent_count,received_count,occurred_at FROM ' . wfc27_trips_table() . ' WHERE id = %d', $id ), ARRAY_A );
	if ( ! $trip ) {
		wp_die( 'Sync not found.' );
	}
	$packets = $wpdb->get_results( $wpdb->prepare( 'SELECT envelope_id,direction FROM ' . wfc27_trip_packets_table() . ' WHERE trip_id = %d ORDER BY id ASC', $id ), ARRAY_A );
	?>
	<div class="wrap"><h1>Sync #<?php echo esc_html( (string) $id ); ?></h1>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wfc27' ) ); ?>">← Recent Sync</a></p>
		<p><strong>Arrived (UTC):</strong> <?php echo esc_html( $trip['occurred_at'] ); ?> · <strong>Packets sent:</strong> <?php echo esc_html( $trip['sent_count'] ); ?> · <strong>Packets received:</strong> <?php echo esc_html( $trip['received_count'] ); ?></p>
		<h2>Sync Packets</h2>
		<table class="widefat striped"><thead><tr><th>Direction</th><th>Packet</th></tr></thead><tbody>
		<?php foreach ( $packets as $packet ) : ?><tr><td><?php echo esc_html( ucfirst( $packet['direction'] ) ); ?></td><td><a href="<?php echo esc_url( wfc27_station_url( $packet['envelope_id'] ) ); ?>"><code><?php echo esc_html( $packet['envelope_id'] ); ?></code></a></td></tr><?php endforeach; ?>
		<?php if ( ! $packets ) : ?><tr><td colspan="2"><?php echo ( (int) $trip['sent_count'] + (int) $trip['received_count'] ) > 0 ? 'Packet membership was not recorded for this historical sync.' : 'This sync had no packets.'; ?></td></tr><?php endif; ?>
		</tbody></table>
	</div>
	<?php
}
