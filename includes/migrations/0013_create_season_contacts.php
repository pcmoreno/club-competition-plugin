<?php

declare(strict_types=1);

/**
 * Tournament contacts — the admins a tournament's notifications go to.
 *
 * A link table rather than a column on seasons: a tournament has any number of
 * contacts, and the creating admin is only seeded as the first one. An empty
 * list means "every active admin" (see SeasonContactService), so the seasons
 * that already exist keep notifying everyone without a backfill.
 *
 * Forward-only; CREATE TABLE IF NOT EXISTS keeps it idempotent.
 */
return function (wpdb $wpdb): void {
    $p       = $wpdb->prefix . 'scs_';
    $charset = $wpdb->get_charset_collate();

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}season_contacts (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      season_id BIGINT UNSIGNED NOT NULL,
      admin_id BIGINT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY  (id),
      UNIQUE KEY season_admin (season_id, admin_id),
      KEY admin_id (admin_id)
    ) {$charset}");
};
