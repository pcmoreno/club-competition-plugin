<?php

declare(strict_types=1);

/**
 * Make standings_snapshots.keizer_score nullable.
 *
 * keizer_score is the season's ranking metric — but not every season ranks by a
 * Keizer value. Some historical seasons were ranked by classical points instead
 * (their export carries no Keizer "Score" column at all). For those, the score
 * is left NULL and the standings order by classical_points; the viewer hides the
 * Score column when it's empty. So the column must allow NULL.
 *
 * Existing rows keep their values (a NOT NULL DEFAULT 0 column relaxing to NULL
 * loses nothing). Forward-only and guarded on current nullability.
 */
return function (wpdb $wpdb): void {
    $p = $wpdb->prefix . 'scs_';

    $nullable = (string)$wpdb->get_var($wpdb->prepare(
        'SELECT IS_NULLABLE FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        $p . 'standings_snapshots',
        'keizer_score'
    ));

    if ($nullable === 'NO') {
        $wpdb->query("ALTER TABLE {$p}standings_snapshots MODIFY keizer_score INT UNSIGNED DEFAULT NULL");
    }
};
