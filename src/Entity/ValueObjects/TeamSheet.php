<?php

declare(strict_types=1);

namespace SCS\Entity\ValueObjects;

// A team season's line-up, held whole in `seasons.categories`: which teams
// exist, who is in each, and in what order. Members are kept as an ordered
// list and the board numbers are written out on encode, so 1..n with one
// player per board holds by construction rather than by checking.
final class TeamSheet
{
    /** @param array<string,list<int>> $teams team name => player ids in board order */
    public function __construct(private readonly array $teams = [])
    {
    }

    // Accepts either shape the column can hold: a bare list of names (a team
    // season that has no assignments yet) or name => board => player id.
    /** @param array<mixed> $decoded */
    public static function fromColumn(array $decoded): self
    {
        $teams = [];

        foreach ($decoded as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $teams[$value] = [];

                continue;
            }

            if (!is_string($key)) {
                continue;
            }

            $members = [];
            if (is_array($value)) {
                // Board numbers order the members; a bare list is already in order.
                if (array_is_list($value)) {
                    $members = array_values(array_map(intval(...), array_filter($value, is_numeric(...))));
                } else {
                    ksort($value, SORT_NUMERIC);
                    $members = array_values(array_map(intval(...), array_filter($value, is_numeric(...))));
                }
            }

            $teams[$key] = array_values(array_unique($members));
        }

        return new self($teams);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->teams);
    }

    public function teamOf(int $player_id): ?string
    {
        foreach ($this->teams as $name => $members) {
            if (in_array($player_id, $members, true)) {
                return $name;
            }
        }

        return null;
    }

    public function boardOf(int $player_id): ?int
    {
        foreach ($this->teams as $members) {
            $index = array_search($player_id, $members, true);
            if ($index !== false) {
                return $index + 1;
            }
        }

        return null;
    }

    /** @return list<int> */
    public function membersOf(string $team): array
    {
        return $this->teams[$team] ?? [];
    }

    public function has(string $team): bool
    {
        return array_key_exists($team, $this->teams);
    }

    // Set the team list. Surviving teams keep their line-up; a team that goes
    // takes its assignments with it, and the given order is the list's order.
    /** @param list<string> $names */
    public function withNames(array $names): self
    {
        $teams = [];
        foreach ($names as $name) {
            $teams[$name] = $this->teams[$name] ?? [];
        }

        return new self($teams);
    }

    // Move a player to the bottom board of a team, or out of the sheet entirely.
    public function place(int $player_id, ?string $team): self
    {
        $teams = $this->removeFrom($this->teams, [ $player_id ]);

        if ($team !== null) {
            $teams[$team][] = $player_id;
        }

        return new self($teams);
    }

    // Swap one player for another on the board they already hold (a merge).
    public function replace(int $from_player_id, int $to_player_id): self
    {
        $teams = $this->teams;
        foreach ($teams as $name => $members) {
            $index = array_search($from_player_id, $members, true);
            if ($index !== false) {
                $members[$index] = $to_player_id;
                $teams[$name]    = $members;
            }
        }

        return new self($teams);
    }

    /** @param list<int> $player_ids */
    public function without(array $player_ids): self
    {
        return new self($this->removeFrom($this->teams, $player_ids));
    }

    // Replace one team's line-up. The caller has already checked that the ids
    // are exactly that team's members — this only refuses an outright mismatch.
    /** @param list<int> $player_ids */
    public function reorder(string $team, array $player_ids): self
    {
        $current = $this->membersOf($team);
        $wanted  = $player_ids;
        sort($current);
        sort($wanted);

        if ($current !== $wanted) {
            throw new \InvalidArgumentException(sprintf('Board order for "%s" must list that team\'s players exactly once each.', $team));
        }

        $teams        = $this->teams;
        $teams[$team] = $player_ids;

        return new self($teams);
    }

    // Rebuild every team from a player => team map, ordering each by the given
    // strength (highest first), which is what Auto Fill wants.
    /**
     * @param  array<int,?string> $assignments player id => team name or null
     * @param  array<int,int>     $strength    player id => rating
     */
    public function withAssignments(array $assignments, array $strength): self
    {
        $teams = $this->teams;
        foreach ($teams as $name => $_) {
            $teams[$name] = [];
        }

        foreach ($assignments as $playerId => $team) {
            if ($team === null || !array_key_exists($team, $teams)) {
                continue;
            }
            $teams[$team][] = $playerId;
        }

        foreach ($teams as $name => $members) {
            usort($members, fn (int $a, int $b): int => ($strength[$b] ?? 0) <=> ($strength[$a] ?? 0));
            $teams[$name] = $members;
        }

        return new self($teams);
    }

    // The column's shape: boards are the keys, so they can't drift from the order.
    /** @return array<string,array<int,int>> */
    public function toColumn(): array
    {
        $out = [];
        foreach ($this->teams as $name => $members) {
            $boards = [];
            foreach ($members as $index => $playerId) {
                $boards[$index + 1] = $playerId;
            }
            $out[$name] = $boards;
        }

        return $out;
    }

    /**
     * @param  array<string,list<int>> $teams
     * @param  list<int>               $player_ids
     * @return array<string,list<int>>
     */
    private function removeFrom(array $teams, array $player_ids): array
    {
        foreach ($teams as $name => $members) {
            $teams[$name] = array_values(array_diff($members, $player_ids));
        }

        return $teams;
    }
}
