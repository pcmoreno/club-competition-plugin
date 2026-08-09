<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;
use SCS\Entity\Enum\RevaluationMode;

// How far the recalculation of values is carried each round.
final class Revaluation implements SettingInterface
{
    public const KEY = 'revaluation';

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Revaluation',
            'type'    => FieldType::Select->value,
            'hint'    => 'Values are worked out from the previous round, then the season is scored again with them.',
            'default' => RevaluationMode::Classic->value,
            'options' => array_map(
                static fn (RevaluationMode $mode) => [
                    'value'       => $mode->value,
                    'label'       => $mode->label(),
                    'implemented' => $mode->isImplemented(),
                ],
                RevaluationMode::cases()
            ),
        ];
    }

    public function normalise(mixed $raw): RevaluationMode
    {
        $mode = RevaluationMode::tryFrom(is_string($raw) ? $raw : '');

        return $mode !== null && $mode->isImplemented() ? $mode : RevaluationMode::Classic;
    }
}
