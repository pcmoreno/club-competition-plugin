<?php

declare(strict_types=1);

/**
 * Make season_players.category nullable.
 *
 * The original 0001 schema declared `category VARCHAR(100) NOT NULL`. Categories
 * are optional — a season may run as one undivided pool — so the column must be
 * nullable. 0001 was later edited in place to fix this, but the migration runner
 * skips an already-applied migration, so any database migrated before that edit
 * kept the NOT NULL column. This migration converges those databases; it is a
 * harmless no-op where 0001 already created the column nullable.
 */
return function (wpdb $wpdb): void {
    $p = $wpdb->prefix . 'scs_';

    $wpdb->query("ALTER TABLE {$p}season_players MODIFY category VARCHAR(100) DEFAULT NULL");
};
