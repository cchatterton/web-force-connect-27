<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'wfc27_register_admin_page' );
function wfc27_register_admin_page() {
	add_menu_page( 'Web Force Connect 27', 'WFC27', 'manage_options', 'wfc27', 'wfc27_render_admin_page', 'dashicons-update', 65 );
}

add_action( 'admin_post_wfc27_run_inbox', 'wfc27_admin_run_inbox' );
add_action( 'admin_post_wfc27_save_receiver', 'wfc27_admin_save_receiver' );
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
	$counts = $wpdb->get_results( 'SELECT status, COUNT(*) AS total FROM ' . wfc27_packets_table() . ' GROUP BY status', ARRAY_A );
	$parts = array();
	foreach ( $counts as $count ) {
		$parts[] = ucfirst( $count['status'] ) . ': ' . $count['total'];
	}
	wp_send_json_success( array(
		'last' => $last ? gmdate( 'c', strtotime( $last . ' UTC' ) ) : null,
		'state' => get_option( 'wfc27_train_state', 'running' ),
		'counts' => implode( ' · ', $parts ),
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
function wfc27_admin_run_inbox() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'wfc27_run_inbox' );
	wfc27_process_pending_packets( 20 );
	wp_safe_redirect( admin_url( 'admin.php?page=wfc27' ) );
	exit;
}

function wfc27_render_admin_page() {
	global $wpdb;
	$packets = wfc27_packets_table();
	$identity = wfc27_identity_table();
	$counts = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$packets} GROUP BY status", ARRAY_A );
	$rows = $wpdb->get_results( "SELECT id,packet_id,status,received_at,processed_at,error_text FROM {$packets} ORDER BY id DESC LIMIT 50", ARRAY_A );
	$mapped = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$identity} WHERE wp_post_id > 0" );
	$last = get_option( 'wfc27_last_heartbeat', '' );
	$average = $wpdb->get_var( "SELECT AVG(TIMESTAMPDIFF(SECOND,received_at,processed_at)) FROM {$packets} WHERE status IN ('completed','reported') AND processed_at IS NOT NULL AND received_at > UTC_TIMESTAMP() - INTERVAL 1 DAY" );
	$source_id = isset( $_GET['sf_id'] ) ? sanitize_text_field( wp_unslash( $_GET['sf_id'] ) ) : '';
	$journey = array();
	$source_identity = null;
	if ( preg_match( '/^[A-Za-z0-9]{15,18}$/', $source_id ) ) {
		$source_identity = wfc27_identity_for_sf( $source_id );
		$journey = $wpdb->get_results( $wpdb->prepare( "SELECT packet_id,status,received_at,processed_at,error_text FROM {$packets} WHERE payload LIKE %s OR result_json LIKE %s ORDER BY id DESC LIMIT 25", '%' . $wpdb->esc_like( $source_id ) . '%', '%' . $wpdb->esc_like( $source_id ) . '%' ), ARRAY_A );
	}
	?>
	<div class="wrap">
		<h1>Web Force Connect 27</h1>
		<p>Salesforce packets are received here, then applied to this site's posts and postmeta.</p>
		<section class="wfc27-train-widget" aria-label="WordPress sync train">
			<h2>Sync Train Arriving</h2>
			<div id="wfc27-train-countdown" class="wfc27-train-countdown" aria-live="off">01:00</div>
			<p id="wfc27-heartbeat-label" aria-live="polite">Waiting for train status</p>
			<div class="wfc27-heartbeat" role="progressbar" aria-label="Time until next train" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-state="<?php echo esc_attr( get_option( 'wfc27_train_state', 'running' ) ); ?>" data-last="<?php echo esc_attr( $last ? gmdate( 'c', strtotime( $last . ' UTC' ) ) : '' ); ?>"><div class="wfc27-heartbeat-fill"></div></div>
		</section>
		<table class="widefat striped"><tbody>
		<tr><th>Receive endpoint</th><td><code><?php echo esc_html( rest_url( 'wfc27/v1/train' ) ); ?></code></td></tr>
		<tr><th>Mapped posts</th><td><?php echo esc_html( (string) $mapped ); ?></td></tr>
		<tr><th>Average processing wait, last 24 hours</th><td><?php echo esc_html( null === $average ? 'No completed packets' : round( (float) $average ) . ' seconds' ); ?></td></tr>
		</tbody></table>
		<h2>Queue</h2>
		<p id="wfc27-queue-counts"><?php foreach ( $counts as $count ) { echo esc_html( ucfirst( $count['status'] ) . ': ' . $count['total'] ) . ' &nbsp; '; } ?></p>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="wfc27">
			<label for="wfc27-sf-id">Trace Salesforce record ID</label>
			<input id="wfc27-sf-id" name="sf_id" value="<?php echo esc_attr( $source_id ); ?>" pattern="[A-Za-z0-9]{15,18}">
			<?php submit_button( 'Show journey', 'secondary', 'submit', false ); ?>
		</form>
		<?php if ( $source_id ) : ?>
			<p><?php echo esc_html( $source_identity ? 'Mapped WordPress post ID: ' . $source_identity['wp_post_id'] : 'No current identity mapping.' ); ?></p>
			<table class="widefat striped"><thead><tr><th>Packet</th><th>Status</th><th>Received (UTC)</th><th>Processed (UTC)</th><th>Error</th></tr></thead><tbody>
			<?php foreach ( $journey as $row ) : ?><tr><td><?php echo esc_html( $row['packet_id'] ); ?></td><td><?php echo esc_html( $row['status'] ); ?></td><td><?php echo esc_html( $row['received_at'] ); ?></td><td><?php echo esc_html( $row['processed_at'] ?: '—' ); ?></td><td><?php echo esc_html( $row['error_text'] ?: '—' ); ?></td></tr><?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wfc27_run_inbox">
			<?php wp_nonce_field( 'wfc27_run_inbox' ); ?>
			<?php submit_button( 'Process pending packets now', 'secondary', 'submit', false ); ?>
		</form>
		<table class="widefat striped"><thead><tr><th>Packet</th><th>Status</th><th>Received (UTC)</th><th>Processed (UTC)</th><th>Error</th></tr></thead><tbody>
		<?php foreach ( $rows as $row ) : ?>
		<tr><td><code><?php echo esc_html( $row['packet_id'] ); ?></code></td><td><?php echo esc_html( $row['status'] ); ?></td><td><?php echo esc_html( $row['received_at'] ); ?></td><td><?php echo esc_html( $row['processed_at'] ?: '—' ); ?></td><td><?php echo esc_html( $row['error_text'] ?: '—' ); ?></td></tr>
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
