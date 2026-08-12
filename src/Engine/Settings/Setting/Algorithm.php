<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;
use SCS\Entity\Enum\PairingAlgorithm;

// How hard the pairing works to satisfy colour preferences.
final class Algorithm implements SettingInterface
{
    public const KEY = 'algorithm';

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Pairing algorithm',
            'type'    => FieldType::Select->value,
            'hint'    => 'Standard follows the ranking. Colour aware may reach past a neighbour to give both players the colour they are owed.',
            'default' => PairingAlgorithm::Standard->value,
            'options' => array_map(
                static fn (PairingAlgorithm $a) => [
                    'value'       => $a->value,
                    'label'       => $a->label(),
                    'implemented' => $a->isImplemented(),
                ],
                PairingAlgorithm::cases()
            ),
        ];
    }

    public function normalise(mixed $raw): PairingAlgorithm
    {
        $algorithm = PairingAlgorithm::tryFrom(is_string($raw) ? $raw : '');

        return $algorithm !== null && $algorithm->isImplemented() ? $algorithm : PairingAlgorithm::Standard;
    }
}
