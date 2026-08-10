<?php

declare(strict_types=1);

/**
 * Admin accounts can be invited by email instead of only being created with a
 * password by `wp scs create-admin`. That needs the same two columns members
 * already carry, and a nullable password_hash — an invited admin has no
 * password until they follow their link.
 */
return function (wpdb $wpdb): void {
    $p = $wpdb->prefix . 'scs_';

    $has = static function (string $table, string $column) use ($wpdb, $p): bool {
        return (string)$wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $p . $table,
            $column
        )) !== '0';
    };

    $hasIndex = static function (string $table, string $index) use ($wpdb, $p): bool {
        return (string)$wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            $p . $table,
            $index
        )) !== '0';
    };

    if (!$has('admins', 'invite_token')) {
        $wpdb->query("ALTER TABLE {$p}admins ADD invite_token VARCHAR(255) DEFAULT NULL");
    }

    if (!$has('admins', 'invite_expires_at')) {
        $wpdb->query("ALTER TABLE {$p}admins ADD invite_expires_at DATETIME DEFAULT NULL");
    }

    if (!$hasIndex('admins', 'invite_token')) {
        $wpdb->query("ALTER TABLE {$p}admins ADD KEY invite_token (invite_token)");
    }

    // MODIFY is idempotent on its own — re-running it lands on the same column
    // definition — so this needs no guard.
    $wpdb->query("ALTER TABLE {$p}admins MODIFY password_hash VARCHAR(255) DEFAULT NULL");
};
