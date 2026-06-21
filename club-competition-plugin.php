<?php

declare(strict_types=1);
/**
 * Plugin Name: Club Competition Manager
 * Plugin URI: https://github.com/pcmoreno/club-competition-plugin
 * Description: Manage chess competition pairings, standings, and results for Schaakclub Santpoort
 * Version: 0.2.0
 * Author: Paulo Moreno
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: club-competition
 * Requires at least: 5.0
 * Requires PHP: 8.2
 */

if (! defined('ABSPATH')) {
    exit;
}

define('SCS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SCS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SCS_VERSION', '0.2.0');
define('SCS_DB_VERSION', '0.1.0');

// The plugin's tables share the site's WordPress table prefix (which is not
// always "wp_" — SiteGround and many hosts randomise it). Migrations create
// them as "{$wpdb->prefix}scs_*", so the data layer must reference them through
// this same prefix rather than a hardcoded "wp_scs_".
global $wpdb;
define('SCS_TABLE_PREFIX', $wpdb->prefix . 'scs_');

require_once SCS_PLUGIN_PATH . 'vendor/autoload.php';

register_activation_hook(__FILE__, [ \SCS\includes\Database::class, 'activate' ]);
register_deactivation_hook(__FILE__, [ \SCS\includes\Database::class, 'deactivate' ]);

// Apply any pending schema migrations on load. WordPress only fires the
// activation hook on manual activation, never on update, so updates that ship
// new migration files (e.g. via the GitHub-Releases update path) would
// otherwise leave the schema stale. migrate() self-gates via the
// scs_applied_migrations option, so this is a cheap no-op once everything is
// applied. Runs before Container::boot so services see the current schema.
add_action('plugins_loaded', function () {
    \SCS\includes\Database::migrate();
}, 5);

add_action('plugins_loaded', function () {
    \SCS\Container::boot();
}, 10);

// Seed the shipped season fixtures once each. Runs after the container is built
// (priority 10) and is gated by the scs_seeded_fixtures option, so it's a cheap
// no-op once everything is imported. On plugins_loaded rather than activation
// because the deploy flow (upload + replace) never fires the activation hook.
add_action('plugins_loaded', function () {
    \SCS\includes\FixtureSeeder::seed();
}, 15);
