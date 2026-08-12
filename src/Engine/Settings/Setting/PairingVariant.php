<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;
use SCS\Entity\Enum\KeizerPairingVariant;

// What players are sorted by before boards are made.
final class PairingVariant implements SettingInterface
{
    public const KEY = 'variant';

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Pair on',
            'type'    => FieldType::Select->value,
            'hint'    => 'The order players are matched up in. The score is the plain ranking; the percentage damps players who have hardly played.',
            'default' => KeizerPairingVariant::Score->value,
            'options' => array_map(
                static fn (KeizerPairingVariant $v) => ['value' => $v->value, 'label' => $v->label()],
                KeizerPairingVariant::cases()
            ),
        ];
    }

    public function normalise(mixed $raw): KeizerPairingVariant
    {
        return KeizerPairingVariant::tryFrom(is_string($raw) ? $raw : '') ?? KeizerPairingVariant::Score;
    }
}
