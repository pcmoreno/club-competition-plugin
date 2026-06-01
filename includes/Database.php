<?php

declare(strict_types=1);

namespace SCS\includes;

class Database
{
    private const MIGRATIONS_DIR = __DIR__ . '/migrations';
    private const APPLIED_OPTION = 'scs_applied_migrations';

    public static function activate(): void
    {
        self::migrate();
    }

    public static function deactivate(): void
    {
        // Tables are preserved on deactivation; dropped only on uninstall
    }

    public static function migrate(): void
    {
        global $wpdb;

        $applied = get_option(self::APPLIED_OPTION, []);

        foreach (self::getMigrationFiles() as $number => $path) {
            if (in_array($number, $applied, true)) {
                continue;
            }

            $migration = require $path;

            // dbDelta() swallows SQL errors into $wpdb->last_error instead of
            // throwing, so clear it first and inspect it after the migration to
            // catch silent failures alongside any thrown exception.
            $wpdb->last_error = '';

            try {
                $migration($wpdb);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    sprintf('Migration "%s" failed: %s', basename($path), $e->getMessage()),
                    0,
                    $e
                );
            }

            if ($wpdb->last_error !== '') {
                throw new \RuntimeException(
                    sprintf('Migration "%s" failed: %s', basename($path), $wpdb->last_error)
                );
            }

            // Mark applied only after the migration completed without error. The
            // marker is what prevents re-runs — these migrations issue raw SQL
            // (not dbDelta), so idempotency is each migration's own job: if the
            // update_option below ever failed after a partial apply, the retry
            // must tolerate re-running. Guard non-idempotent statements (e.g.
            // ADD COLUMN) accordingly.
            $applied[] = $number;
            update_option(self::APPLIED_OPTION, $applied);
        }
    }

    private static function getMigrationFiles(): array
    {
        $files = glob(self::MIGRATIONS_DIR . '/[0-9][0-9][0-9][0-9]_*.php') ?: [];
        sort($files);

        $result = [];
        foreach ($files as $path) {
            $number          = (int)basename($path);
            $result[$number] = $path;
        }

        return $result;
    }
}
