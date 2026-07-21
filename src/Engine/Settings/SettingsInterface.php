<?php

declare(strict_types=1);

namespace SCS\Engine\Settings;

// A settings blob is both its stored values and the schema its admin form renders from.
interface SettingsInterface
{
    /** @return array<string,mixed> */
    public function getSettings(): array;

    /** @return list<array<string,mixed>> */
    public function getSettingsFields(): array;

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values): static;
}
