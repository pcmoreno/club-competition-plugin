<?php

declare(strict_types=1);

namespace SCS\includes;

class Database {

    private const MIGRATIONS_DIR = __DIR__ . '/migrations';
    private const APPLIED_OPTION = 'scs_applied_migrations';

    public static function activate(): void {
        self::migrate();
    }

    public static function deactivate(): void {
        // Tables are preserved on deactivation; dropped only on uninstall
    }

    public static function migrate(): void {
        global $wpdb;

        $applied = get_option( self::APPLIED_OPTION, [] );

        foreach ( self::getMigrationFiles() as $number => $path ) {
            if ( in_array( $number, $applied, true ) ) {
                continue;
            }

            $migration = require $path;
            $migration( $wpdb );

            $applied[] = $number;
            update_option( self::APPLIED_OPTION, $applied );
        }
    }

    private static function getMigrationFiles(): array {
        $files = glob( self::MIGRATIONS_DIR . '/[0-9][0-9][0-9][0-9]_*.php' ) ?: [];
        sort( $files );

        $result = [];
        foreach ( $files as $path ) {
            $number          = (int) basename( $path );
            $result[$number] = $path;
        }

        return $result;
    }
}
