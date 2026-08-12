<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * Whether a weak colour claim is allowed to decide anything.
 *
 * A claim is treated as mild when a player's colours are already even and all
 * they have is the alternation — they had white last week, so they would like
 * black. It is treated as strong once they are actually out of balance, and as
 * binding once they have reached a cap.
 *
 * On (the default), a mild claim still chooses the colour when the opponent has
 * no claim at all, but never overrules one. That stops a preference nobody
 * would notice from pushing a genuinely lopsided player further out.
 *
 * The mild/strong boundary is our reading — Sevilla documents the setting but
 * not where it draws the line.
 */
final class IgnoreMildColourPrefs implements SettingInterface
{
    public const KEY = 'ignoreMildColorPrefs';

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
            'label'   => 'Ignore weak colour preferences',
            'type'    => FieldType::Toggle->value,
            'hint'    => 'A player whose colours are already even only gets their preference when nobody else wants it.',
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
