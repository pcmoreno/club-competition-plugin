<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\ColorPriority;
use SCS\Entity\Enum\FieldType;

// Who gets their way when both players are owed the same colour.
final class ColorTiebreak implements SettingInterface
{
    public const KEY = 'colorPriority';

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Colour goes to',
            'type'    => FieldType::Select->value,
            'hint'    => 'When both players want the same colour, this decides who gets it.',
            'default' => ColorPriority::HigherRanked->value,
            'options' => array_map(
                static fn (ColorPriority $p) => ['value' => $p->value, 'label' => $p->label()],
                ColorPriority::cases()
            ),
        ];
    }

    public function normalise(mixed $raw): ColorPriority
    {
        return ColorPriority::tryFrom(is_string($raw) ? $raw : '') ?? ColorPriority::HigherRanked;
    }
}
