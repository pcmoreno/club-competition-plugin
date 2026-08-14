<?php

declare(strict_types=1);

/**
 * Mark a tournament as team play.
 *
 * The groups themselves reuse `categories` and `season_players.category` — a
 * team competition has no use for categories as well, so the flag decides what
 * the same column means and which tab edits it.
 */
return function (wpdb $wpdb): void {
    $p = $wpdb->prefix . 'scs_';

    $exists = (int)$wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        $p . 'seasons',
        'is_team'
    ));

    if ($exists === 0) {
        $wpdb->query("ALTER TABLE {$p}seasons ADD COLUMN is_team TINYINT(1) NOT NULL DEFAULT 0 AFTER pairing_system");
    }
};
