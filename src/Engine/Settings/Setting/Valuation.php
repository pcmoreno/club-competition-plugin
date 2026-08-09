<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;
use SCS\Entity\Enum\ValuationMethod;

// How the ranking is turned into player values — the ladder's shape.
final class Valuation implements SettingInterface
{
    public const KEY = 'valuationMethod';

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Valuation method',
            'type'    => FieldType::Select->value,
            'hint'    => 'How a player’s position in the ranking becomes the value their opponents play for.',
            'default' => ValuationMethod::PositionRange->value,
            'options' => array_map(
                static fn (ValuationMethod $method) => [
                    'value'       => $method->value,
                    'label'       => $method->label(),
                    'implemented' => $method->isImplemented(),
                ],
                ValuationMethod::cases()
            ),
        ];
    }

    // An unimplemented method coerces back rather than being honoured: leaving
    // it stored would configure a ladder the engine can't build.
    public function normalise(mixed $raw): ValuationMethod
    {
        $method = ValuationMethod::tryFrom(is_string($raw) ? $raw : '');

        return $method !== null && $method->isImplemented() ? $method : ValuationMethod::PositionRange;
    }
}
