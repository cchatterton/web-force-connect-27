<?php
/**
 * Plugin Name: AlphaSys Web Force Connect 27
 * Description: Receives Salesforce content packets and maintains their WordPress posts.
 * Version: 0.1.3
 * Author: AlphaSys
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: alphasys-web-force-connect-27
 * Update URI: https://github.com/cchatterton/web-force-connect-27
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WFC27_VERSION', '0.1.3' );
define( 'WFC27_FILE', __FILE__ );
define( 'WFC27_DIR', plugin_dir_path( __FILE__ ) );

require_once WFC27_DIR . 'functions/setup.php';
require_once WFC27_DIR . 'functions/storage.php';
require_once WFC27_DIR . 'functions/worker.php';
require_once WFC27_DIR . 'functions/rest.php';
require_once WFC27_DIR . 'functions/admin.php';
require_once WFC27_DIR . 'functions/locks.php';
require_once WFC27_DIR . 'functions/github-updater.php';

register_activation_hook( __FILE__, 'wfc27_activate' );
register_deactivation_hook( __FILE__, 'wfc27_deactivate' );
