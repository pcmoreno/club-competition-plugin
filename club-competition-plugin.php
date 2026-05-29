<?php
/**
 * Plugin Name: Club Competition Manager
 * Plugin URI: https://www.schaakclubsantpoort.nl
 * Description: Manage chess competition pairings, standings, and results for Schaakclub Santpoort
 * Version: 1.0.0
 * Author: Paulo Moreno
 * Author URI: https://pcmoreno@yahoo.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: club-competition
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 8.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'SCS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SCS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SCS_VERSION', '1.0.0' );
define( 'SCS_DB_VERSION', '1.0.0' );

// Load Composer autoloader
require_once SCS_PLUGIN_PATH . 'vendor/autoload.php';

// Boot the DI container and initialize plugin
add_action( 'plugins_loaded', function() {
	require_once SCS_PLUGIN_PATH . 'src/Container.php';
	$container = \SCS\Container::boot();

	// Register activation/deactivation hooks
	register_activation_hook( __FILE__, [ $container->get( 'installer' ), 'activate' ] );
	register_deactivation_hook( __FILE__, [ $container->get( 'installer' ), 'deactivate' ] );

	// Initialize plugin
	$container->get( 'plugin' )->init();
}, 10 );

// Enqueue frontend assets
add_action( 'wp_enqueue_scripts', function() {
	require_once SCS_PLUGIN_PATH . 'src/Container.php';
	$container = \SCS\Container::boot();
	$container->get( 'assets' )->enqueue_frontend();
}, 10 );

// Register shortcode
add_shortcode( 'clubcompetitie', function() {
	require_once SCS_PLUGIN_PATH . 'src/Container.php';
	$container = \SCS\Container::boot();
	return $container->get( 'shortcode' )->render();
} );
