<?php

declare(strict_types=1);

/**
 * Make players.name UNIQUE.
 *
 * A player is the global "person" that members couple to and that seasons
 * enrol. The season import treats name as that person's stable identity
 * (upsert-by-name, never renumber), so the registry must not hold two players
 * with the same name. This enforces it.
 *
 * Forward-only and guarded: MySQL has no "ADD ... IF NOT EXISTS" for indexes,
 * so skip if the index already exists (a retry after a failed applied-marker
 * write would otherwise hit "Duplicate key name").
 */
return function (wpdb $wpdb): void {
    $p = $wpdb->prefix . 'scs_';

    $exists = (int)$wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
        $p . 'players',
        'uq_players_name'
    ));

    if ($exists > 0) {
        return;
    }

    // Pre-existing installs (ran 0001–0004 with no upsert-by-name workflow) can
    // legitimately hold duplicate names. Adding the UNIQUE key on those would
    // fail with MySQL 1062 and abort the whole migration run *without* advancing
    // the applied-marker — half-migrated on every retry. Surface the dupes with
    // a clear message instead so the admin can dedupe deliberately.
    $duplicates = $wpdb->get_col("SELECT name FROM {$p}players GROUP BY name HAVING COUNT(*) > 1");
    if (!empty($duplicates)) {
        throw new RuntimeException(sprintf(
            'Cannot add UNIQUE(players.name): duplicate player names exist (%s). '
            . 'Merge or rename them, then re-run the migration.',
            implode(', ', $duplicates)
        ));
    }

    $wpdb->query("ALTER TABLE {$p}players ADD UNIQUE KEY uq_players_name (name)");
};
