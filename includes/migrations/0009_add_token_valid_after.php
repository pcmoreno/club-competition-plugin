<?php

declare(strict_types=1);

/**
 * JWTs are stateless and carry a 24h TTL, so a password reset alone doesn't
 * invalidate a token an attacker already holds. This column is a floor:
 * bumped to "now" whenever a member/admin's password changes, and checked
 * against the token's `iat` claim on every authenticated request (NULL means
 * no floor — accept any `iat`). See AuthContextService.
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

    if (!$has('members', 'token_valid_after')) {
        $wpdb->query("ALTER TABLE {$p}members ADD token_valid_after DATETIME DEFAULT NULL");
    }

    if (!$has('admins', 'token_valid_after')) {
        $wpdb->query("ALTER TABLE {$p}admins ADD token_valid_after DATETIME DEFAULT NULL");
    }
};
