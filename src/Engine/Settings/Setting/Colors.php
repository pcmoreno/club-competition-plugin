<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\ColorRule;
use SCS\Entity\Enum\FieldType;

// How colours go between two players who have met before.
final class Colors implements SettingInterface
{
    public const KEY = 'colorRule';

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Colours in a rematch',
            'type'    => FieldType::Select->value,
            'hint'    => 'Only for two players who have met before. Everyone else gets the colour that evens out their season.',
            'default' => ColorRule::Alternating->value,
            'options' => array_map(
                static fn (ColorRule $rule) => ['value' => $rule->value, 'label' => $rule->label()],
                ColorRule::cases()
            ),
        ];
    }

    public function normalise(mixed $raw): ColorRule
    {
        return ColorRule::tryFrom(is_string($raw) ? $raw : '') ?? ColorRule::Alternating;
    }
}
