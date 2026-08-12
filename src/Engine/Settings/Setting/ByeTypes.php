<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;
use SCS\Entity\Enum\ScoringSettingsGroup;

/**
 * What each kind of non-game evening is worth.
 *
 * Like GameOutcomes this is Sevilla's Par: added to a total under standard
 * scoring, multiplied by the player's *own* value under Keizer. Which is why
 * the defaults belong to the composing settings class — the pairing bye is
 * worth a full point in a Swiss event and two thirds in a ladder one.
 *
 * A reserved type is one the engine assigns itself, so it can never be deleted.
 */
final class ByeTypes implements SettingInterface
{
    public const KEY = 'byeTypes';

    /** @param list<array<string,mixed>> $defaults each {key,label,points,reserved?} */
    public function __construct(private readonly array $defaults)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'group'        => ScoringSettingsGroup::ByeTypes->value,
            'type'         => FieldType::KeyedNumberList->value,
            'reservedKeys' => $this->reservedKeys(),
            'min'          => 0,
            'default'      => $this->defaults,
        ];
    }

    /** @return list<string> */
    public function reservedKeys(): array
    {
        return array_column(
            array_filter(
                $this->defaults,
                static fn (array $bye): bool => ($bye['reserved'] ?? false) === true
            ),
            'key'
        );
    }

    // Clamped for the same reason as the game outcomes: no bye deducts either.
    /** @return list<array<string,mixed>> */
    public function normalise(mixed $raw): array
    {
        if (!is_array($raw)) {
            return $this->defaults;
        }

        return array_values(array_map(
            static function (mixed $bye): mixed {
                if (is_array($bye) && isset($bye['points']) && is_numeric($bye['points'])) {
                    $bye['points'] = max(0, $bye['points'] + 0);
                }

                return $bye;
            },
            $raw
        ));
    }
}
