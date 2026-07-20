<?php

declare(strict_types=1);

/**
 * Engine settings + open scores map.
 *
 * Adds three nullable JSON settings columns to seasons (pairing/scoring/display)
 * and an open `scores` JSON map to standings_snapshots, backfilled from the
 * existing typed columns so scraped history renders through the new path. Also
 * fixes the bye_type typo paring_bye -> pairing_bye.
 *
 * Forward-only; guarded on column existence and value, so a retry is a no-op.
 */
return function (wpdb $wpdb): void {
    $p = $wpdb->prefix . 'scs_';

    $has = static function (string $table, string $column) use ($wpdb, $p): bool {
        return (string)$wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $p . $table,
            $column
        )) !== '0';
    };

    foreach (['pairing_settings', 'scoring_settings', 'display_settings'] as $column) {
        if (!$has('seasons', $column)) {
            $wpdb->query("ALTER TABLE {$p}seasons ADD {$column} JSON DEFAULT NULL");
        }
    }

    if (!$has('standings_snapshots', 'scores')) {
        $wpdb->query("ALTER TABLE {$p}standings_snapshots ADD scores JSON DEFAULT NULL");

        // Backfill the scraped snapshots from their typed columns (runs once, right after the ADD).
        $wpdb->query("UPDATE {$p}standings_snapshots SET scores = JSON_OBJECT(
            'keizer_score',       keizer_score,
            'points',             classical_points,
            'wins',               wins,
            'draws',              draws,
            'losses',             losses,
            'games',              games,
            'byes',               byes,
            'color_balance',      color_balance,
            'performance_rating', tpr
        ) WHERE scores IS NULL");
    }

    if ($has('attendance', 'bye_type')) {
        $wpdb->query("UPDATE {$p}attendance SET bye_type = 'pairing_bye' WHERE bye_type = 'paring_bye'");
    }
};
