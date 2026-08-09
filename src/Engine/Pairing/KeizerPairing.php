<?php

declare(strict_types=1);

namespace SCS\Engine\Pairing;

use SCS\Engine\Settings\KeizerPairingSettings;
use SCS\Entity\Enum\ByeType;
use SCS\Entity\Enum\ColorPriority;
use SCS\Entity\Enum\ColorRule;
use SCS\Entity\Enum\GameResult;
use SCS\Entity\Enum\KeizerPairingVariant;
use SCS\Entity\Enum\PairingAlgorithm;
use SCS\Entity\Enum\PairingByeChoice;
use SCS\Entity\Game;
use SCS\Entity\Season;
use SCS\Entity\SeasonPlayer;
use SCS\Entity\StandingsSnapshot;

/**
 * Pairs one Keizer round from the standings.
 *
 * Neighbours in the ranking play each other, which is what makes the system
 * work: everyone gets opponents of their own strength, and the ladder sorts
 * itself out over a season rather than in any one round.
 *
 * The order boards are made in matters more than it looks. Working straight
 * down the list leaves whoever remains at the bottom with whatever is left, so
 * the weakest players get the strangest games. Pairing from both ends inward
 * pushes that remainder into the middle of the field, where an odd pairing
 * costs less. That is `bottomUpPairing`, and it is on by default.
 *
 * Rematches are not forbidden. Over a long season with a field this size they
 * are unavoidable, and the club's own history has 107 of them in 444 games —
 * the first only in round 12, because the ranking churns enough to keep
 * neighbours changing.
 */
final class KeizerPairing implements PerRoundPairing
{
    public function __construct(private readonly KeizerPairingSettings $settings)
    {
    }

    /**
     * @param list<SeasonPlayer>      $roster    present players for this round
     * @param list<Game>              $history   every game played so far
     * @param list<StandingsSnapshot> $standings the ranking to pair from
     */
    public function pairNextRound(Season $season, array $roster, array $history, array $standings): PairingResult
    {
        $order = $this->pairingOrder($roster, $history, $standings);
        if (count($order) < 2) {
            return PairingResult::empty();
        }

        $byes = [];
        if (count($order) % 2 === 1) {
            $bye   = $this->chooseBye($order, $history, $standings, $season);
            $order = array_values(array_filter($order, static fn (SeasonPlayer $p) => $p->id !== $bye->id));
            $byes[] = ['season_player_id' => $bye->id, 'bye_type' => ByeType::PairingBye->value];
        }

        $colours  = $this->colourHistory($history);
        $rank     = $this->rankIndex($order);
        $pairings = [];
        $board    = 1;

        foreach ($this->pairs($order, $colours) as [$a, $b]) {
            [$white, $black] = $this->assignColours($a, $b, $colours, $rank);

            $pairings[] = ['white' => $white->id, 'black' => $black->id, 'board' => $board++];
        }

        return new PairingResult($pairings, $byes);
    }

    /**
     * The ranking, best first, restricted to the players actually present.
     *
     * A player with no standing yet — a first-timer, or anyone the ranking
     * hasn't reached — goes to the bottom rather than being left out, so the
     * board still gets made.
     *
     * @param  list<SeasonPlayer>      $roster
     * @param  list<Game>              $history
     * @param  list<StandingsSnapshot> $standings
     * @return list<SeasonPlayer>
     */
    private function pairingOrder(array $roster, array $history, array $standings): array
    {
        $metric = $this->settings->variant() === KeizerPairingVariant::Percentage
            ? $this->percentages($roster, $history)
            : $this->scores($standings);

        $ordered = $roster;
        usort($ordered, static function (SeasonPlayer $a, SeasonPlayer $b) use ($metric): int {
            $byMetric = ($metric[$b->id] ?? -INF) <=> ($metric[$a->id] ?? -INF);

            return $byMetric !== 0 ? $byMetric : ($b->elo_rating <=> $a->elo_rating ?: $a->id <=> $b->id);
        });

        return $ordered;
    }

    /**
     * @param  list<StandingsSnapshot> $standings
     * @return array<int,float>
     */
    private function scores(array $standings): array
    {
        $scores = [];
        foreach ($standings as $snapshot) {
            $scores[$snapshot->season_player_id] = (float)($snapshot->keizer_score ?? $snapshot->classical_points);
        }

        return $scores;
    }

    /**
     * Sevilla's damped score rate: `(wins + ½ draws + SC) / (games + GC)`.
     *
     * The corrections stop someone who has played once and won from being
     * paired against the leader, which is the whole reason a club running a
     * ladder would choose this over the plain score.
     *
     * @param  list<SeasonPlayer> $roster
     * @param  list<Game>         $history
     * @return array<int,float>
     */
    private function percentages(array $roster, array $history): array
    {
        $wins = $draws = $games = [];
        foreach ($history as $game) {
            if ($game->result === null) {
                continue;
            }
            foreach ([$game->white_season_player_id, $game->black_season_player_id] as $id) {
                $games[$id] = ($games[$id] ?? 0) + 1;
            }
            if ($game->result === GameResult::Draw) {
                $draws[$game->white_season_player_id] = ($draws[$game->white_season_player_id] ?? 0) + 1;
                $draws[$game->black_season_player_id] = ($draws[$game->black_season_player_id] ?? 0) + 1;

                continue;
            }
            $winner        = $game->result === GameResult::White ? $game->white_season_player_id : $game->black_season_player_id;
            $wins[$winner] = ($wins[$winner] ?? 0) + 1;
        }

        $sc = $this->settings->scoreCorrection();
        $gc = $this->settings->gameCorrection();

        $percentages = [];
        foreach ($roster as $player) {
            $played = $games[$player->id] ?? 0;

            // Sevilla's own fallback for the degenerate case where a player has
            // no games and the correction doesn't supply any either.
            $percentages[$player->id] = ($played + $gc) === 0
                ? 0.5
                : (($wins[$player->id] ?? 0) + 0.5 * ($draws[$player->id] ?? 0) + $sc) / ($played + $gc);
        }

        return $percentages;
    }

    /**
     * Walk the ranking, taking players in the configured order and pairing each
     * with the best opponent still free.
     *
     * @param  list<SeasonPlayer>                       $order
     * @param  array<int,array{last:?bool,balance:int}> $colours
     * @return list<array{0:SeasonPlayer,1:SeasonPlayer}>
     */
    private function pairs(array $order, array $colours): array
    {
        $paired = [];
        $pairs  = [];

        foreach ($this->visitOrder(count($order)) as $index) {
            $player = $order[$index];
            if (isset($paired[$player->id])) {
                continue;
            }

            $opponent = $this->findOpponent($order, $index, $paired, $colours);
            if ($opponent === null) {
                continue;
            }

            $paired[$player->id]   = true;
            $paired[$opponent->id] = true;
            $pairs[]               = [$player, $opponent];
        }

        return $pairs;
    }

    /**
     * Which position to pair next.
     *
     * Both ends inward when bottom-up pairing is allowed and strict order is
     * off; the top half then the bottom half when strict order is on; straight
     * down the list otherwise.
     *
     * @return list<int>
     */
    private function visitOrder(int $count): array
    {
        if (!$this->settings->pairsFromBothEnds()) {
            return range(0, $count - 1);
        }

        if ($this->settings->strictOrder()) {
            $half = (int)ceil($count / 2);

            return array_merge(range(0, $half - 1), array_reverse(range($half, $count - 1)));
        }

        $order = [];
        for ($low = 0, $high = $count - 1; $low <= $high; $low++, $high--) {
            $order[] = $low;
            if ($high !== $low) {
                $order[] = $high;
            }
        }

        return $order;
    }

    /**
     * The nearest free opponent below this player, or the nearest above if the
     * field below is exhausted.
     *
     * The colour-aware algorithm may pass over the first few candidates when a
     * later one gives both players the colour they are owed — never further
     * than the configured limit, because past that the strength gap costs more
     * than the colour is worth.
     *
     * @param list<SeasonPlayer>                       $order
     * @param array<int,true>                          $paired
     * @param array<int,array{last:?bool,balance:int}> $colours
     */
    private function findOpponent(array $order, int $index, array $paired, array $colours): ?SeasonPlayer
    {
        $candidates = [];
        foreach ($order as $position => $candidate) {
            if ($position === $index || isset($paired[$candidate->id])) {
                continue;
            }
            $candidates[] = ['player' => $candidate, 'distance' => abs($position - $index), 'below' => $position > $index];
        }

        if ($candidates === []) {
            return null;
        }

        // Nearest first, preferring the player below so the ranking is walked
        // downwards rather than doubling back.
        usort($candidates, static fn (array $a, array $b) => $a['distance'] <=> $b['distance'] ?: ($b['below'] <=> $a['below']));

        if ($this->settings->algorithm() !== PairingAlgorithm::ColorAware) {
            return $candidates[0]['player'];
        }

        // Reach past the nearest few for an opponent who wants the opposite
        // colour, so neither player has to be overruled. The limit is what
        // stops this trading a sensible board for a colour nobody minds.
        $player = $order[$index];
        $wants  = $this->wantsWhite($player, $colours);

        if ($wants !== null) {
            foreach (array_slice($candidates, 0, $this->settings->limit() + 1) as $candidate) {
                if ($this->wantsWhite($candidate['player'], $colours) === !$wants) {
                    return $candidate['player'];
                }
            }
        }

        return $candidates[0]['player'];
    }

    /**
     * White and black for a board.
     *
     * Each player is owed a colour; when both want the same one, the configured
     * priority decides. With no history at all — the opening round — the higher
     * ranked player takes white, which is the convention an organiser expects
     * reading down the sheet.
     *
     * @param  array<int,array{last:?bool,balance:int}> $colours
     * @param  array<int,int>                           $rank
     * @return array{0:SeasonPlayer,1:SeasonPlayer}
     */
    private function assignColours(SeasonPlayer $a, SeasonPlayer $b, array $colours, array $rank): array
    {
        $wantsA = $this->wantsWhite($a, $colours);
        $wantsB = $this->wantsWhite($b, $colours);

        if ($wantsA !== $wantsB && $wantsA !== null && $wantsB !== null) {
            return $wantsA ? [$a, $b] : [$b, $a];
        }

        if ($wantsA !== null && $wantsB === null) {
            return $wantsA ? [$a, $b] : [$b, $a];
        }
        if ($wantsB !== null && $wantsA === null) {
            return $wantsB ? [$b, $a] : [$a, $b];
        }

        // Both want the same, or neither has a preference: priority decides who
        // is served, and the one served takes what they wanted.
        $preferred = $this->preferredPlayer($a, $b, $rank);
        $other     = $preferred->id === $a->id ? $b : $a;

        // Null on both sides means an opening round with no history at all, and
        // then the preferred player simply opens with white.
        $wants = ($preferred->id === $a->id ? $wantsA : $wantsB) ?? true;

        return $wants ? [$preferred, $other] : [$other, $preferred];
    }

    /**
     * True if this player is owed white, false if black, null if they have no
     * claim either way.
     *
     * @param array<int,array{last:?bool,balance:int}> $colours
     */
    private function wantsWhite(SeasonPlayer $player, array $colours): ?bool
    {
        $entry = $colours[$player->id] ?? null;
        if ($entry === null) {
            return null;
        }

        if ($this->settings->colorRule() === ColorRule::BalanceToZero) {
            return $entry['balance'] === 0 ? null : $entry['balance'] < 0;
        }

        // Alternating: whatever they had last time, they want the other.
        return $entry['last'] === null ? null : !$entry['last'];
    }

    /** @param array<int,int> $rank */
    private function preferredPlayer(SeasonPlayer $a, SeasonPlayer $b, array $rank): SeasonPlayer
    {
        return match ($this->settings->colorPriority()) {
            ColorPriority::HigherRated => $a->elo_rating >= $b->elo_rating ? $a : $b,
            ColorPriority::None        => $a,
            default                    => ($rank[$a->id] ?? PHP_INT_MAX) <= ($rank[$b->id] ?? PHP_INT_MAX) ? $a : $b,
        };
    }

    /**
     * Each player's last colour and their running balance.
     *
     * Absences never appear here, which is Sevilla's "ignore absent rounds for
     * history check": a player who missed three weeks still wants the opposite
     * of whatever they last actually played.
     *
     * @param  list<Game>                                $history
     * @return array<int,array{last:?bool,balance:int}>
     */
    private function colourHistory(array $history): array
    {
        $colours = [];
        foreach ($history as $game) {
            foreach ([[$game->white_season_player_id, true], [$game->black_season_player_id, false]] as [$id, $isWhite]) {
                $entry            = $colours[$id] ?? ['last' => null, 'balance' => 0];
                $entry['last']    = $isWhite;
                $entry['balance'] = $entry['balance'] + ($isWhite ? 1 : -1);
                $colours[$id]     = $entry;
            }
        }

        return $colours;
    }

    /**
     * @param  list<SeasonPlayer> $order
     * @return array<int,int>     season_player_id => position
     */
    private function rankIndex(array $order): array
    {
        $rank = [];
        foreach ($order as $position => $player) {
            $rank[$player->id] = $position;
        }

        return $rank;
    }

    /**
     * The odd player out.
     *
     * Nobody sits out twice until everyone has, which is the part that isn't
     * configurable — it is what stops the same regular losing two evenings
     * while others lose none.
     *
     * @param  list<SeasonPlayer>      $order
     * @param  list<Game>              $history
     * @param  list<StandingsSnapshot> $standings
     */
    private function chooseBye(array $order, array $history, array $standings, Season $season): SeasonPlayer
    {
        $had = [];
        foreach ($standings as $snapshot) {
            if ($snapshot->byes > 0) {
                $had[$snapshot->season_player_id] = true;
            }
        }

        $eligible = array_values(array_filter($order, static fn (SeasonPlayer $p) => !isset($had[$p->id])));
        if ($eligible === []) {
            $eligible = $order;
        }

        if ($this->settings->byeChoice() === PairingByeChoice::LowestRanked) {
            return $eligible[count($eligible) - 1];
        }

        // Drawn from the season and the round's field rather than the clock, so
        // regenerating a draft board gives the same player rather than quietly
        // moving the bye onto someone else.
        $seed = crc32($season->id . ':' . count($history) . ':' . implode(',', array_map(static fn (SeasonPlayer $p) => $p->id, $eligible)));

        return $eligible[$seed % count($eligible)];
    }
}
