<?php

declare(strict_types=1);

namespace SCS\Engine\Settings;

// A settings blob is both its stored values and the schema its admin form renders from.
interface SettingsInterface
{
    public function getSettings(): array;

    public function getSettingsFields(): array;

    public static function fromArray(array $values): static;
}
