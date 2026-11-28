<?php

declare(strict_types=1);

namespace SCS\Entity;

use SCS\Entity\Enum\AttendanceStatus;

class Attendance
{
    public function __construct(
        public readonly int $id,
        public readonly int $round_id,
        public readonly int $season_player_id,
        public readonly AttendanceStatus $status,
        // A key from the tournament's configured bye types (see ByeType for the reserved ones).
        public readonly ?string $bye_type,
    ) {
    }
}
