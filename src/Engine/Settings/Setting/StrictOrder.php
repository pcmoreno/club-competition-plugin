<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * Which end of the ranking the pairing works from.
 *
 * Off, it alternates: the top player is paired, then the bottom one, then the
 * highest still waiting, then the lowest, closing inward. On, the whole top
 * half is paired first and the bottom half afterwards from the lowest up.
 *
 * Only meaningful while bottom-up pairing is allowed — with that off there is
 * only one direction to work in.
 */
final class StrictOrder implements SettingInterface
{
    public const KEY = 'strictOrder';

    public const DEFAULT = false;

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Pair the top half first',
            'type'    => FieldType::Toggle->value,
            'hint'    => 'Off, pairing alternates between the top and bottom of the ranking and closes inward.',
            'default' => self::DEFAULT,
        ];
    }

    public function normalise(mixed $raw): bool
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? self::DEFAULT;
    }
}
