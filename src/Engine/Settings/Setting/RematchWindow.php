<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * How many rounds must pass before two players may meet again.
 *
 * A preference, not a prohibition. Over a long season with a field this size
 * rematches are unavoidable, so the pairing prefers an opponent who satisfies
 * the window and takes one who doesn't when the field leaves nothing else — the
 * club's own history holds to it in 97 of 110 rematches and breaks it in 13.
 *
 * Zero allows an immediate rematch.
 */
final class RematchWindow implements SettingInterface
{
    public const KEY = 'roundsBetweenSamePairing';

    public const DEFAULT = 10;

    public const MAX = 100;

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Rounds between rematches',
            'type'    => FieldType::Number->value,
            'hint'    => 'How long before the same two players may be paired again. Respected where the field allows it.',
            'default' => self::DEFAULT,
            'min'     => 0,
            'max'     => self::MAX,
            'step'    => 1,
        ];
    }

    public function normalise(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return self::DEFAULT;
        }

        return max(0, min((int)$raw, self::MAX));
    }
}
