<?php
/**
 * Plugin Name: AlphaSys Web Force Connect 27
 * Description: Moves opaque text between durable Salesforce and WordPress stations.
 * Version: 0.3.9
 * Author: AlphaSys
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Author URI: https://alphasys.com.au
 * AlphaSys Controller API: 1
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Text Domain: alphasys-web-force-connect-27
 * Update URI: https://github.com/cchatterton/web-force-connect-27
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WFC27_VERSION', '0.3.9' );
define( 'WFC27_FILE', __FILE__ );
define( 'WFC27_DIR', plugin_dir_path( __FILE__ ) );

require_once WFC27_DIR . 'functions/setup.php';
require_once WFC27_DIR . 'functions/storage.php';
require_once WFC27_DIR . 'functions/rest.php';
require_once WFC27_DIR . 'functions/admin.php';
require_once WFC27_DIR . 'functions/station-admin.php';

register_activation_hook( __FILE__, 'wfc27_activate' );
register_deactivation_hook( __FILE__, 'wfc27_deactivate' );

require_once __DIR__ . '/functions/controller-client.php';
asuc_client_register(__FILE__, 'web-force-connect-27');
