<?php

declare(strict_types=1);

/**
 * Add a time control to games (blitz / rapid / classical).
 *
 * Every game played so far is classical, which is also what the column defaults
 * to — so the ALTER itself backfills the existing rows and no separate UPDATE is
 * needed. NOT NULL because a game is always played at some tempo; "unknown" is
 * not a state worth modelling.
 *
 * Forward-only and guarded on column existence, so a retry after a partial apply
 * is a no-op (see 0003 for why the guard is there).
 */
return function (wpdb $wpdb): void {
    $p = $wpdb->prefix . 'scs_';

    $exists = (int)$wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        $p . 'games',
        'time_control'
    ));

    if ($exists === 0) {
        $wpdb->query("ALTER TABLE {$p}games ADD COLUMN time_control VARCHAR(20) NOT NULL DEFAULT 'classical' AFTER result");
    }
};
