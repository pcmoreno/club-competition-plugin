<?php
/**
 * Plugin Name: Club Competition Manager
 * Plugin URI: https://www.schaakclubsantpoort.nl
 * Description: Manage chess competition pairings, standings, and results for Schaakclub Santpoort
 * Version: 1.0.0
 * Author: Paulo Moreno
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: club-competition
 * Requires at least: 5.0
 * Requires PHP: 8.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SCS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SCS_VERSION', '1.0.0' );
define( 'SCS_DB_VERSION', '1.0.0' );

require_once SCS_PLUGIN_PATH . 'vendor/autoload.php';

register_activation_hook( __FILE__, [ \SCS\includes\Database::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \SCS\includes\Database::class, 'deactivate' ] );

add_action( 'plugins_loaded', function () {
	\SCS\Container::boot();
}, 10 );
