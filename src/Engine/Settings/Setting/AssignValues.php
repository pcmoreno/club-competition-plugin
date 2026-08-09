<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\AssignValuesOn;
use SCS\Entity\Enum\FieldType;

// Which ranking a player's value is read from.
final class AssignValues implements SettingInterface
{
    public const KEY = 'assignValuesOn';

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Take values from',
            'type'    => FieldType::Select->value,
            'hint'    => 'What decides how much a player is worth to whoever beats them.',
            'default' => AssignValuesOn::Score->value,
            'options' => array_map(
                static fn (AssignValuesOn $a) => [
                    'value'       => $a->value,
                    'label'       => $a->label(),
                    'implemented' => $a->isImplemented(),
                ],
                AssignValuesOn::cases()
            ),
        ];
    }

    public function normalise(mixed $raw): AssignValuesOn
    {
        $assign = AssignValuesOn::tryFrom(is_string($raw) ? $raw : '');

        return $assign !== null && $assign->isImplemented() ? $assign : AssignValuesOn::Score;
    }
}
