<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wfc27_activate() {
	wfc27_create_tables();
	wfc27_schedule_worker();
}

function wfc27_deactivate() {
	wp_clear_scheduled_hook( 'wfc27_process_inbox' );
}

add_filter( 'cron_schedules', 'wfc27_cron_schedules' );
function wfc27_cron_schedules( $schedules ) {
	$schedules['wfc27_minute'] = array( 'interval' => MINUTE_IN_SECONDS, 'display' => 'Every minute (WFC27)' );
	return $schedules;
}

add_action( 'init', 'wfc27_schedule_worker' );
add_action( 'init', 'wfc27_maybe_upgrade_tables', 5 );
function wfc27_maybe_upgrade_tables() {
	if ( get_option( 'wfc27_schema_version' ) !== WFC27_VERSION ) {
		wfc27_create_tables();
		update_option( 'wfc27_schema_version', WFC27_VERSION, false );
	}
}
function wfc27_schedule_worker() {
	if ( ! wp_next_scheduled( 'wfc27_process_inbox' ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'wfc27_minute', 'wfc27_process_inbox' );
	}
}

add_action( 'wfc27_process_inbox', 'wfc27_run_worker' );
function wfc27_run_worker() {
	wfc27_process_pending_packets( 20 );
}
