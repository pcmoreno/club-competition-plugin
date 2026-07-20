<?php

declare(strict_types=1);

namespace SCS\Engine\Settings;

// Manual pairing carries no settings — the admin enters every pairing by hand.
final class ManualPairingSettings implements TournamentPairingSettings
{
    public function getSettings(): array
    {
        return [];
    }

    public function getSettingsFields(): array
    {
        return [];
    }

    public static function fromArray(array $values): static
    {
        return new self();
    }
}
