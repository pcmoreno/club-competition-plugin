<?php

declare(strict_types=1);

/**
 * A player's board within their team.
 *
 * Only meaningful on a team tournament: it is the slot that decides who plays
 * whom when two teams meet, so it sits beside the team itself
 * (`season_players.category`) rather than in a map of its own, which would hold
 * membership a second time.
 */
return function (wpdb $wpdb): void {
    $p = $wpdb->prefix . 'scs_';

    $exists = (int)$wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        $p . 'season_players',
        'board_number'
    ));

    if ($exists === 0) {
        $wpdb->query("ALTER TABLE {$p}season_players ADD COLUMN board_number SMALLINT UNSIGNED DEFAULT NULL AFTER category");
    }
};
