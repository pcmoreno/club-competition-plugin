<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * Whether the more lopsided player wins a colour contest.
 *
 * Off (the default, and the club's setting), two players wanting the same
 * colour are settled by the configured priority — normally the higher ranked —
 * regardless of who needs it more. On, the player further out of balance takes
 * it instead, which distributes colours more evenly but makes the board harder
 * to explain, since the reason lives in a history nobody is looking at.
 *
 * A player who has reached a cap outranks both rules either way.
 */
final class StrongerPreferenceWins implements SettingInterface
{
    public const KEY = 'pickColorOnStrongerPreference';

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
            'label'   => 'Colour goes to whoever needs it most',
            'type'    => FieldType::Toggle->value,
            'hint'    => 'Off, the priority above settles it. On, the player further out of colour balance wins.',
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
