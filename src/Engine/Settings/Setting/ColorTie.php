<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\ColorTieAward;
use SCS\Entity\Enum\ColorTieCriterion;
use SCS\Entity\Enum\FieldType;
use SCS\Entity\Enum\FirstBoardColour;

// Who is favoured when nothing else decides a board's colours.
final class ColorTie implements SettingInterface
{
    public const KEY = 'colorTieCriterion';

    public const AWARD_KEY = 'colorTieAward';

    public const FIRST_BOARD_KEY = 'firstBoardColor';

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'When nothing decides the colours',
            'type'    => FieldType::Select->value,
            'hint'    => 'Only used when neither player has any colour claim — the opening round, mostly.',
            'default' => ColorTieCriterion::LowerPairingNumber->value,
            'options' => array_map(
                static fn (ColorTieCriterion $c) => [
                    'value'       => $c->value,
                    'label'       => $c->label(),
                    'implemented' => $c->isImplemented(),
                ],
                ColorTieCriterion::cases()
            ),
        ];
    }

    public function normalise(mixed $raw): ColorTieCriterion
    {
        $criterion = ColorTieCriterion::tryFrom(is_string($raw) ? $raw : '');

        return $criterion !== null && $criterion->isImplemented()
            ? $criterion
            : ColorTieCriterion::LowerPairingNumber;
    }

    /** @return array<string,mixed> */
    public function awardField(): array
    {
        return [
            'key'     => self::AWARD_KEY,
            'label'   => '…and that player gets',
            'type'    => FieldType::Select->value,
            'hint'    => 'Alternating spreads white evenly down the sheet rather than giving it to every favoured player.',
            'default' => ColorTieAward::Alternate->value,
            'options' => array_map(
                static fn (ColorTieAward $a) => ['value' => $a->value, 'label' => $a->label()],
                ColorTieAward::cases()
            ),
        ];
    }

    public function normaliseAward(mixed $raw): ColorTieAward
    {
        return ColorTieAward::tryFrom(is_string($raw) ? $raw : '') ?? ColorTieAward::Alternate;
    }

    /** @return array<string,mixed> */
    public function firstBoardField(): array
    {
        return [
            'key'     => self::FIRST_BOARD_KEY,
            'label'   => '…starting on board one with',
            'type'    => FieldType::Select->value,
            'hint'    => 'Which way the alternation starts. No effect unless colours alternate.',
            'default' => FirstBoardColour::Automatic->value,
            'options' => array_map(
                static fn (FirstBoardColour $c) => ['value' => $c->value, 'label' => $c->label()],
                FirstBoardColour::cases()
            ),
        ];
    }

    public function normaliseFirstBoard(mixed $raw): FirstBoardColour
    {
        return FirstBoardColour::tryFrom(is_string($raw) ? $raw : '') ?? FirstBoardColour::Automatic;
    }
}
