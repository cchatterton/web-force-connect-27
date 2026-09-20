<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wfc27_activate() {
	wfc27_create_tables();
	add_role( 'wfc27_integration', 'WFC27 Integration', array( 'read' => true, 'wfc27_receive' => true ) );
	$role = get_role( 'administrator' );
	if ( $role ) {
		$role->add_cap( 'wfc27_receive' );
	}
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
function wfc27_schedule_worker() {
	if ( ! wp_next_scheduled( 'wfc27_process_inbox' ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'wfc27_minute', 'wfc27_process_inbox' );
	}
}

add_action( 'wfc27_process_inbox', 'wfc27_run_worker' );
function wfc27_run_worker() {
	wfc27_process_pending_packets( 20 );
}
