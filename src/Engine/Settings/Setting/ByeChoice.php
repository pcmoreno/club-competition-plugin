<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;
use SCS\Entity\Enum\PairingByeChoice;

// Who sits out when an odd number of players turn up.
final class ByeChoice implements SettingInterface
{
    public const KEY = 'byeChoice';

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Who takes the bye',
            'type'    => FieldType::Select->value,
            'hint'    => 'Nobody takes a second bye until everyone has had one, whichever rule is chosen.',
            'default' => PairingByeChoice::Random->value,
            'options' => array_map(
                static fn (PairingByeChoice $c) => [
                    'value'       => $c->value,
                    'label'       => $c->label(),
                    'implemented' => $c->isImplemented(),
                ],
                PairingByeChoice::cases()
            ),
        ];
    }

    public function normalise(mixed $raw): PairingByeChoice
    {
        $choice = PairingByeChoice::tryFrom(is_string($raw) ? $raw : '');

        return $choice !== null && $choice->isImplemented() ? $choice : PairingByeChoice::Random;
    }
}
