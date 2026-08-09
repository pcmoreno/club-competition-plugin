<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\ColorRule;
use SCS\Entity\Enum\FieldType;

// What colour a player is owed when a board is made.
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
            'label'   => 'Colour preference',
            'type'    => FieldType::Select->value,
            'hint'    => 'Alternating looks only at the last game played; evening out looks at the whole season.',
            'default' => ColorRule::Alternating->value,
            'options' => array_map(
                static fn (ColorRule $rule) => [
                    'value'       => $rule->value,
                    'label'       => $rule->label(),
                    'implemented' => $rule->isImplemented(),
                ],
                ColorRule::cases()
            ),
        ];
    }

    public function normalise(mixed $raw): ColorRule
    {
        $rule = ColorRule::tryFrom(is_string($raw) ? $raw : '');

        return $rule !== null && $rule->isImplemented() ? $rule : ColorRule::Alternating;
    }
}
