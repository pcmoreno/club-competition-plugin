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
        'name'
    ));

    if ($exists === 0) {
        $wpdb->query("ALTER TABLE {$p}players ADD UNIQUE KEY name (name)");
    }
};
