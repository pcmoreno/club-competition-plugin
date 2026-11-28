<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\ByeType;
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
 * A reserved type is one the app writes itself (ByeType), so it can never be
 * deleted or re-keyed; every other type is the tournament's own.
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
        return array_column(ByeType::cases(), 'value');
    }

    // Clamped for the same reason as the game outcomes: no bye deducts either.
    // Reserved flags follow the enum, and a reserved row a stored list lacks comes
    // back at 0, which is what byePoints() already gave it.
    /** @return list<array<string,mixed>> */
    public function normalise(mixed $raw): array
    {
        if (!is_array($raw)) {
            return $this->defaults;
        }

        $reserved = $this->reservedKeys();
        $rows     = array_values(array_map(
            static function (mixed $bye) use ($reserved): mixed {
                if (!is_array($bye)) {
                    return $bye;
                }
                if (isset($bye['key']) && is_string($bye['key'])) {
                    $bye['key'] = trim($bye['key']);
                }
                if (isset($bye['points']) && is_numeric($bye['points'])) {
                    $bye['points'] = max(0, $bye['points'] + 0);
                }
                unset($bye['reserved']);
                if (in_array($bye['key'] ?? null, $reserved, true)) {
                    $bye['reserved'] = true;
                }

                return $bye;
            },
            $raw
        ));

        $present = array_column(array_filter($rows, 'is_array'), 'key');
        foreach ($this->defaults as $default) {
            if (in_array($default['key'], $reserved, true) && !in_array($default['key'], $present, true)) {
                $rows[] = ['key' => $default['key'], 'label' => $default['label'], 'points' => 0, 'reserved' => true];
            }
        }

        return $rows;
    }
}
