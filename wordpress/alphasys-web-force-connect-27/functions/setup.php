<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wfc27_activate() {
	wfc27_create_tables();
}

function wfc27_deactivate() {
	wp_clear_scheduled_hook( 'wfc27_process_inbox' );
}

add_action( 'init', 'wfc27_maybe_upgrade_tables', 5 );
function wfc27_maybe_upgrade_tables() {
	if ( get_option( 'wfc27_schema_version' ) !== WFC27_VERSION ) {
		wfc27_create_tables();
		wp_clear_scheduled_hook( 'wfc27_process_inbox' );
		update_option( 'wfc27_schema_version', WFC27_VERSION, false );
	}
}
