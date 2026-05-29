<?php

declare(strict_types=1);

namespace SCS\Entity;

use SCS\Entity\Enum\AttendanceStatus;
use SCS\Entity\Enum\ByeType;

class Attendance
{
    public function __construct(
        public readonly int $id,
        public readonly int $round_id,
        public readonly int $season_player_id,
        public readonly AttendanceStatus $status,
        public readonly ?ByeType $bye_type,
    ) {
    }
}
