<?php

declare(strict_types=1);

/**
 * Add players.knsb_synced_at — when the KNSB rating sync last refreshed this
 * player's rating. NULL = never synced. It will be stamped by the (forthcoming)
 * KNSB sync; the admin Full Club Players List surfaces it as the "Synced"
 * column. Distinct from created_at: it tracks rating syncs only, not row
 * creation or edits.
 *
 * Forward-only and guarded on column existence, so a retry after a partial
 * apply is a no-op (the runner issues raw SQL, not dbDelta).
 */
return function (wpdb $wpdb): void {
    $p = $wpdb->prefix . 'scs_';

    $exists = (string)$wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        $p . 'players',
        'knsb_synced_at'
    ));

    if ($exists === '0') {
        $wpdb->query("ALTER TABLE {$p}players ADD knsb_synced_at DATETIME DEFAULT NULL");
    }
};
