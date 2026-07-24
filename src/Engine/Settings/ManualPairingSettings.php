<?php

declare(strict_types=1);

namespace SCS\Engine\Settings;

// Manual pairing carries no settings — the admin enters every pairing by hand.
final class ManualPairingSettings implements TournamentPairingSettings
{
    /** @return array<string,mixed> */
    public function getSettings(): array
    {
        return [];
    }

    /** @return list<array<string,mixed>> */
    public function getSettingsFields(): array
    {
        return [];
    }

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values): static
    {
        return new self();
    }
}
