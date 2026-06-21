<?php

declare(strict_types=1);

namespace SCS\includes;

use SCS\Container;
use SCS\Services\SeasonImportService;

/**
 * Auto-imports the plugin-shipped season fixtures so a deploy populates the
 * site with no WP-CLI access and no admin login required (the production host
 * has neither readily available). The shipped fixtures are finished, historical
 * seasons, so seeding them is safe — nothing live is being overwritten.
 *
 * Gated like migrations: each fixture name is recorded in an option once seeded,
 * so every fixture imports EXACTLY ONCE and never again. The option is updated
 * after each fixture, so a slow/timed-out request resumes where it left off on
 * the next page load rather than redoing completed imports.
 *
 * Runs on `plugins_loaded` (not the activation hook): WordPress fires activation
 * only on a manual activate, never on the upload-and-replace update flow used to
 * deploy here, so a hook would miss updates that ship new fixtures.
 */
class FixtureSeeder
{
    private const SEEDED_OPTION = 'scs_seeded_fixtures';

    public static function seed(): void
    {
        $service = Container::boot()->get('season_import_service');
        if (! $service instanceof SeasonImportService) {
            return;
        }

        $seeded = get_option(self::SEEDED_OPTION, []);
        if (! is_array($seeded)) {
            $seeded = [];
        }

        // Cheap gate: a glob + diff, no JSON decoded. The common steady state is
        // "everything already seeded", so bail before touching any fixture.
        $pending = array_values(array_diff($service->fixtureNames(), $seeded));
        if ($pending === []) {
            return;
        }

        foreach ($pending as $name) {
            try {
                $service->import($name);
            } catch (\Throwable $e) {
                // A malformed fixture must not fatal the whole site. Skip it
                // (it'll be retried next load) and carry on with the rest.
                error_log('[scs] fixture seed failed for "' . $name . '": ' . $e->getMessage());
                continue;
            }

            // Record progress per fixture so a timeout resumes, never restarts.
            $seeded[] = $name;
            update_option(self::SEEDED_OPTION, array_values(array_unique($seeded)));
        }
    }
}
