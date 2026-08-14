<?php

declare(strict_types=1);
/**
 * Plugin Name: Club Competition Manager
 * Plugin URI: https://github.com/pcmoreno/club-competition-plugin
 * Description: Manage chess competition pairings, standings, and results for Schaakclub Santpoort
 * Version: 0.5.2
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
// Read back from the header above rather than repeated, so the version has one
// place to be edited. WordPress parses that header out of the file text and it
// can't be generated, so the literal has to live there; a second copy here would
// be free to drift, and a version that lies is worse than none. Shown to
// visitors in the app footer, so it is now load-bearing rather than decorative.
define('SCS_VERSION', get_file_data(__FILE__, ['Version' => 'Version'], 'plugin')['Version'] ?: '0.0.0');
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

/**
 * Run one boot step, converting a fatal into an admin notice.
 *
 * plugins_loaded fires on every request — front end, REST, cron and wp-admin —
 * so an uncaught throw here white-screens the whole site, including the screen
 * the admin would use to deactivate the plugin; recovery then needs SSH or DB
 * access. Migrations throw deliberately (0005 refuses to add UNIQUE(name) while
 * duplicates exist), and the deploy path is a git pull straight to production
 * with no staging, so this is the most likely way to take the club site down.
 *
 * The steps are ordered — migrate, then boot the container, then seed — and each
 * assumes the previous one succeeded. So the first failure halts the rest rather
 * than letting the container compile and RestApi register against a schema that
 * was never migrated, which would serve requests that fail one query at a time
 * instead of stopping cleanly.
 *
 * The CLI keeps the loud failure: `wp scs migrate` still throws, which is
 * correct where someone is watching the output.
 */
function scs_boot_step(string $label, callable $step): void
{
    static $failed = false;

    if ($failed) {
        return;
    }

    try {
        $step();
    } catch (\Throwable $e) {
        $failed = true;

        error_log(sprintf('[SCS] %s failed: %s', $label, $e->getMessage()));

        $notice = sprintf(
            'Club Competition Manager: %s failed — %s. The plugin has stopped loading, so its pages and API will not work until this is resolved; the rest of the site is unaffected. See the PHP error log.',
            $label,
            $e->getMessage()
        );
        add_action('admin_notices', static function () use ($notice) {
            printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($notice));
        });
    }
}

// Apply any pending schema migrations on load. WordPress only fires the
// activation hook on manual activation, never on update, so updates that ship
// new migration files (e.g. via the GitHub-Releases update path) would
// otherwise leave the schema stale. migrate() self-gates via the
// scs_applied_migrations option, so this is a cheap no-op once everything is
// applied. Runs before Container::boot so services see the current schema.
add_action('plugins_loaded', function () {
    scs_boot_step('Database migration', [ \SCS\includes\Database::class, 'migrate' ]);
}, 5);

add_action('plugins_loaded', function () {
    scs_boot_step('Service container boot', [ \SCS\Container::class, 'boot' ]);
}, 10);

// Seed the shipped season fixtures once each. Runs after the container is built
// (priority 10) and is gated by the scs_seeded_fixtures option, so it's a cheap
// no-op once everything is imported. On plugins_loaded rather than activation
// because the deploy flow (upload + replace) never fires the activation hook.
add_action('plugins_loaded', function () {
    scs_boot_step('Fixture seeding', [ \SCS\includes\FixtureSeeder::class, 'seed' ]);
}, 15);
