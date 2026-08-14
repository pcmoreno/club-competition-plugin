<?php

declare(strict_types=1);

// Standing absence: a flagged enrolment is given a personal bye on every round created from then on.
return function (wpdb $wpdb): void {
    $p = $wpdb->prefix . 'scs_';

    $has = (string)$wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        $p . 'season_players',
        'default_absent'
    )) !== '0';

    if (!$has) {
        $wpdb->query("ALTER TABLE {$p}season_players ADD default_absent TINYINT(1) NOT NULL DEFAULT 0");
    }
};
