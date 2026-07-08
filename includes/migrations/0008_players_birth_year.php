<?php

declare(strict_types=1);

/**
 * Replace players.date_of_birth (DATE) with players.birth_year (SMALLINT). The
 * KNSB/FIDE rating lists only expose a birth *year*, so a full date was more
 * precision than we can ever populate. Existing dates are down-converted with
 * YEAR() so any hand-entered value survives; the DATE column is then dropped.
 *
 * Forward-only and guarded on column existence, so a retry after a partial
 * apply is a no-op (the runner issues raw SQL, not dbDelta).
 */
return function (wpdb $wpdb): void {
    $p = $wpdb->prefix . 'scs_';

    $has = static function (string $column) use ($wpdb, $p): bool {
        return (string)$wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $p . 'players',
            $column
        )) !== '0';
    };

    if (!$has('birth_year')) {
        $wpdb->query("ALTER TABLE {$p}players ADD birth_year SMALLINT UNSIGNED DEFAULT NULL");

        if ($has('date_of_birth')) {
            $wpdb->query("UPDATE {$p}players SET birth_year = YEAR(date_of_birth) WHERE date_of_birth IS NOT NULL");
        }
    }

    if ($has('date_of_birth')) {
        $wpdb->query("ALTER TABLE {$p}players DROP COLUMN date_of_birth");
    }
};
