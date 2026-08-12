<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * Whether a player's score opens with their own value each round.
 *
 * On, a player who loses every game still scores their own value, so the table
 * broadly reflects standing and one bad night doesn't drop a strong player
 * through the field. Off, a score is only what was taken off opponents, which
 * is a different competition — and the reason a loss then scores exactly zero.
 */
final class AddInitialValue implements SettingInterface
{
    public const KEY = 'addInitialValue';

    public const DEFAULT = true;

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Add own value to the score',
            'type'    => FieldType::Toggle->value,
            'hint'    => 'Each player’s score starts from their own value, before anything they take off opponents.',
            'default' => self::DEFAULT,
        ];
    }

    // Null and '' mean the knob was never set, not that it was set to off.
    public function normalise(mixed $raw): bool
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? self::DEFAULT;
    }
}
