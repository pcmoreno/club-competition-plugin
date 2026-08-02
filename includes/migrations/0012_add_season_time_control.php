<?php

declare(strict_types=1);

/**
 * Add a time control to seasons (blitz / rapid / classical).
 *
 * The tournament's tempo, which its games inherit when they're paired — see
 * 0011, which added the same column to games. Every season so far is classical,
 * which is also the default, so the ALTER backfills the existing rows on its own.
 *
 * Forward-only and guarded on column existence, so a retry after a partial apply
 * is a no-op (see 0003 for why the guard is there).
 */
return function (wpdb $wpdb): void {
    $p = $wpdb->prefix . 'scs_';

    $exists = (int)$wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        $p . 'seasons',
        'time_control'
    ));

    if ($exists === 0) {
        $wpdb->query("ALTER TABLE {$p}seasons ADD COLUMN time_control VARCHAR(20) NOT NULL DEFAULT 'classical' AFTER location");
    }
};
