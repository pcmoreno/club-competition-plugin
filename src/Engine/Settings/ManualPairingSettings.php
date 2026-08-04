<?php

declare(strict_types=1);

namespace SCS\Engine\Settings;

use SCS\Engine\Settings\Setting\NumberOfRounds;

/**
 * Manual pairing builds no boards of its own, so its only knob is how far the
 * tournament runs — the admin decides everything else board by board.
 */
final class ManualPairingSettings implements TournamentPairingSettings
{
    public function __construct(private readonly ?int $numberOfRounds = null)
    {
    }

    /** @return array<string,mixed> */
    public function getSettings(): array
    {
        return [NumberOfRounds::KEY => $this->numberOfRounds];
    }

    /** @return list<array<string,mixed>> */
    public function getSettingsFields(): array
    {
        return [(new NumberOfRounds())->field()];
    }

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values): static
    {
        return new self(
            (new NumberOfRounds())->normalise($values[NumberOfRounds::KEY] ?? null)
        );
    }
}
