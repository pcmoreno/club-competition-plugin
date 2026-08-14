<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

enum PairingSystem: string
{
    case Manual            = 'manual';
    case Keizer            = 'keizer';
    case Swiss             = 'swiss';
    case RoundRobinFull   = 'round-robin-full';
    case RoundRobinGroups = 'round-robin-groups';

    // Scoring is derived from the pairing system (code-only map; only Keizer scores its own way).
    public function scoringSystem(): ScoringSystem
    {
        return match ($this) {
            self::Keizer => ScoringSystem::Keizer,
            default      => ScoringSystem::Standard,
        };
    }

    /**
     * Selectable for a new season: the system has to be able to score, and to
     * produce the tournament its name promises.
     *
     * Swiss has no pairing engine, so choosing it gives you Manual under another
     * label. The grouped round-robin runs an independent round-robin inside each
     * category — it is a sectioned individual tournament, not the team
     * competition (group A versus group B, board by board) the name describes,
     * and none of that exists: no team entity, no fixture spanning several
     * boards, no team standings.
     *
     * Manual pairs nothing either, but that is what it is for — see
     * generatesPairings(), which answers a different question.
     */
    public function isImplemented(): bool
    {
        return match ($this) {
            self::Swiss, self::RoundRobinGroups => false,
            default                             => $this->scoringSystem()->isImplemented(),
        };
    }

    /** @return list<string> the only values a season may be created or updated with */
    public static function implementedValues(): array
    {
        return array_values(array_map(
            static fn (self $system) => $system->value,
            array_filter(self::cases(), static fn (self $system) => $system->isImplemented())
        ));
    }

    // Pairing cadence, which drives the admin UI: 'manual' has no generator (the
    // board is hand-built), 'per-round' pairs the next round from the standings
    // (Keizer, Swiss), 'full' lays out the whole fixture up front (round-robin).
    public function cadence(): string
    {
        return match ($this) {
            self::Manual                                 => 'manual',
            self::RoundRobinFull, self::RoundRobinGroups => 'full',
            default                                      => 'per-round',
        };
    }

    // Whether an engine will actually produce a board, which is a different
    // question from cadence: Swiss is per-round like Keizer and has no engine.
    // Keep in step with PairingEngineResolver::resolve() — its default arm
    // refuses exactly the systems that answer false here.
    public function generatesPairings(): bool
    {
        return match ($this) {
            self::Keizer, self::RoundRobinFull, self::RoundRobinGroups => true,
            default                                                    => false,
        };
    }
}
