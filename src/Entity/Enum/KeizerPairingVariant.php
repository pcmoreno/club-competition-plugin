<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

/**
 * The number players are sorted by before boards are made.
 *
 * Separate from the Keizer score itself, which is why a Keizer tournament can
 * pair on something that isn't its own ranking metric. Percentage exists because
 * a ladder rewards turning up: someone who has played twice and won both sits
 * above a regular with fifteen games, and pairing them against the leader is a
 * poor evening for everyone. The correction constants damp exactly that.
 */
enum KeizerPairingVariant: string
{
    case Score      = 'score';
    case Percentage = 'percentage';

    public function label(): string
    {
        return match ($this) {
            self::Score      => 'Keizer score',
            self::Percentage => 'Score percentage, damped',
        };
    }
}
