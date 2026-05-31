<?php

declare(strict_types=1);

/**
 * Standings snapshots — the official standings frozen when a round completes.
 *
 * One row per (round, season_player). ScoringService writes these on round
 * completion; they are the source of truth for the standings as they stood
 * after that round, immutable thereafter (engine changes affect only future
 * snapshots). See the StandingsSnapshot entity.
 *
 * `rank_position` rather than `rank` because RANK is a reserved word in MySQL 8.
 *
 * Forward-only; CREATE TABLE IF NOT EXISTS keeps it idempotent.
 */
return function (wpdb $wpdb): void {
    $p       = $wpdb->prefix . 'scs_';
    $charset = $wpdb->get_charset_collate();

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}standings_snapshots (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      season_id BIGINT UNSIGNED NOT NULL,
      round_id BIGINT UNSIGNED NOT NULL,
      season_player_id BIGINT UNSIGNED NOT NULL,
      rank_position SMALLINT UNSIGNED NOT NULL,
      keizer_score INT UNSIGNED NOT NULL DEFAULT 0,
      classical_points DECIMAL(6,1) NOT NULL DEFAULT 0,
      wins SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      draws SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      losses SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      games SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      byes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      color_balance SMALLINT NOT NULL DEFAULT 0,
      tpr SMALLINT UNSIGNED DEFAULT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY  (id),
      UNIQUE KEY round_player (round_id, season_player_id),
      KEY season_round (season_id, round_id),
      KEY season_player_id (season_player_id)
    ) {$charset}");
};
