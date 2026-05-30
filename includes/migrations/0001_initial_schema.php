<?php

declare(strict_types=1);

return function (wpdb $wpdb): void {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();
    $p       = $wpdb->prefix . 'scs_';

    dbDelta("CREATE TABLE {$p}players (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      name VARCHAR(255) NOT NULL,
      knsb_id VARCHAR(20) DEFAULT NULL,
      knsb_elo SMALLINT UNSIGNED DEFAULT NULL,
      gender VARCHAR(20) DEFAULT NULL,
      date_of_birth DATE DEFAULT NULL,
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY  (id),
      UNIQUE KEY knsb_id (knsb_id)
    ) {$charset};");

    dbDelta("CREATE TABLE {$p}seasons (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      name VARCHAR(255) NOT NULL,
      location VARCHAR(255) DEFAULT NULL,
      start_date DATE DEFAULT NULL,
      end_date DATE DEFAULT NULL,
      pairing_system VARCHAR(50) NOT NULL DEFAULT 'keizer',
      status VARCHAR(20) NOT NULL DEFAULT 'preparation',
      categories JSON DEFAULT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY  (id)
    ) {$charset};");

    dbDelta("CREATE TABLE {$p}season_players (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      season_id BIGINT UNSIGNED NOT NULL,
      player_id BIGINT UNSIGNED NOT NULL,
      category VARCHAR(100) DEFAULT NULL,
      elo_rating SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY  (id),
      UNIQUE KEY season_player (season_id, player_id),
      KEY season_id (season_id),
      KEY player_id (player_id)
    ) {$charset};");

    dbDelta("CREATE TABLE {$p}members (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      player_id BIGINT UNSIGNED NOT NULL,
      email VARCHAR(255) NOT NULL,
      password_hash VARCHAR(255) DEFAULT NULL,
      invite_token VARCHAR(255) DEFAULT NULL,
      invite_expires_at DATETIME DEFAULT NULL,
      reset_token VARCHAR(255) DEFAULT NULL,
      reset_expires_at DATETIME DEFAULT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'invited',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY  (id),
      UNIQUE KEY email (email),
      UNIQUE KEY player_id (player_id),
      KEY invite_token (invite_token),
      KEY reset_token (reset_token)
    ) {$charset};");

    dbDelta("CREATE TABLE {$p}admins (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      name VARCHAR(255) NOT NULL,
      email VARCHAR(255) NOT NULL,
      password_hash VARCHAR(255) NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'active',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY  (id),
      UNIQUE KEY email (email)
    ) {$charset};");

    dbDelta("CREATE TABLE {$p}rounds (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      season_id BIGINT UNSIGNED NOT NULL,
      round_number TINYINT UNSIGNED NOT NULL,
      date DATE DEFAULT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'draft',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY  (id),
      UNIQUE KEY season_round (season_id, round_number),
      KEY season_id (season_id)
    ) {$charset};");

    dbDelta("CREATE TABLE {$p}attendance (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      round_id BIGINT UNSIGNED NOT NULL,
      season_player_id BIGINT UNSIGNED NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'present',
      bye_type VARCHAR(20) DEFAULT NULL,
      PRIMARY KEY  (id),
      UNIQUE KEY round_player (round_id, season_player_id),
      KEY round_id (round_id),
      KEY season_player_id (season_player_id)
    ) {$charset};");

    dbDelta("CREATE TABLE {$p}games (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      round_id BIGINT UNSIGNED NOT NULL,
      white_season_player_id BIGINT UNSIGNED NOT NULL,
      black_season_player_id BIGINT UNSIGNED NOT NULL,
      result VARCHAR(20) DEFAULT NULL,
      PRIMARY KEY  (id),
      KEY round_id (round_id),
      KEY white_season_player_id (white_season_player_id),
      KEY black_season_player_id (black_season_player_id)
    ) {$charset};");
};
