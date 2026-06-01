<?php

declare(strict_types=1);

/**
 * Add a board number to games.
 *
 * Pairings are played on numbered boards (board 1 = top pairing). The original
 * schema didn't model it, so this adds a nullable column — existing rows and
 * games created before a board is assigned simply have no board.
 *
 * Forward-only: 0001 is left untouched (editing an already-applied migration
 * doesn't reach migrated databases — see 0002).
 */
return function (wpdb $wpdb): void {
    $p = $wpdb->prefix . 'scs_';

    // MySQL has no "ADD COLUMN IF NOT EXISTS", so guard the add: if the
    // applied-marker write ever failed after a successful ALTER, the retry
    // would otherwise hit "Duplicate column name 'board'" and block the chain.
    $exists = (int)$wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        $p . 'games',
        'board'
    ));

    if ($exists === 0) {
        $wpdb->query("ALTER TABLE {$p}games ADD COLUMN board SMALLINT UNSIGNED DEFAULT NULL AFTER round_id");
    }
};
