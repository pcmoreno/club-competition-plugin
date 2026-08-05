<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * Whether even legs invert the schedule's colours.
 *
 * On, this is the ordinary colours-reversed double round-robin, and it stays
 * right for any number of legs: odd legs take the table as it stands, even ones
 * flip it. Off, every leg repeats the same colours — rarely wanted, but it is
 * the only way to express a series where one side is fixed.
 */
final class AlternateColoursPerLeg implements SettingInterface
{
    public const KEY = 'alternateColoursPerLeg';

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
            'label'   => 'Alternate colours each leg',
            'type'    => FieldType::Toggle->value,
            'hint'    => 'Even legs are played with the colours reversed. With a single leg this has no effect.',
            'default' => self::DEFAULT,
        ];
    }

    // filter_var reads null and '' as an explicit false, but here they mean the
    // value was never set — a season saved before this knob existed, or a payload
    // that simply omits it — which has to land on the default rather than off.
    public function normalise(mixed $raw): bool
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? self::DEFAULT;
    }
}
