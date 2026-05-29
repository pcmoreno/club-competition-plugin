<?php

declare(strict_types=1);

namespace SCS\Services;

use SCS\Entity\Admin;
use SCS\Entity\Attendance;
use SCS\Entity\Game;
use SCS\Entity\Member;
use SCS\Entity\Player;
use SCS\Entity\Round;
use SCS\Entity\Season;
use SCS\Entity\SeasonPlayer;

class SerializerService
{
    public const GROUP_PUBLIC = 'public';
    public const GROUP_ADMIN  = 'admin';

    public function serialize(object $entity, string $group = self::GROUP_PUBLIC): array
    {
        return match(true) {
            $entity instanceof Player       => $this->player($entity),
            $entity instanceof Season       => $this->season($entity),
            $entity instanceof SeasonPlayer => $this->seasonPlayer($entity),
            $entity instanceof Round        => $this->round($entity),
            $entity instanceof Game         => $this->game($entity),
            $entity instanceof Attendance   => $this->attendance($entity),
            $entity instanceof Member       => $this->member($entity, $group),
            $entity instanceof Admin        => $this->admin($entity, $group),
            default => throw new \InvalidArgumentException('Cannot serialize ' . get_class($entity)),
        };
    }

    /**
     * @param iterable<object> $entities
     */
    public function serializeMany(iterable $entities, string $group = self::GROUP_PUBLIC): array
    {
        $out = [];
        foreach ($entities as $entity) {
            $out[] = $this->serialize($entity, $group);
        }

        return $out;
    }

    private function player(Player $p): array
    {
        return [
            'id'            => $p->id,
            'name'          => $p->name,
            'knsb_id'       => $p->knsb_id,
            'knsb_elo'      => $p->knsb_elo,
            'gender'        => $p->gender?->value,
            'date_of_birth' => $p->date_of_birth?->format('Y-m-d'),
            'active'        => $p->active,
        ];
    }

    private function season(Season $s): array
    {
        return [
            'id'             => $s->id,
            'name'           => $s->name,
            'location'       => $s->location,
            'start_date'     => $s->start_date?->format('Y-m-d'),
            'end_date'       => $s->end_date?->format('Y-m-d'),
            'pairing_system' => $s->pairing_system->value,
            'status'         => $s->status->value,
            'categories'     => $s->categories,
        ];
    }

    private function seasonPlayer(SeasonPlayer $sp): array
    {
        return [
            'id'          => $sp->id,
            'season_id'   => $sp->season_id,
            'player_id'   => $sp->player_id,
            'category'    => $sp->category,
            'elo_rating'  => $sp->elo_rating,
            'enrolled_at' => $sp->enrolled_at->format('Y-m-d'),
        ];
    }

    private function round(Round $r): array
    {
        return [
            'id'           => $r->id,
            'season_id'    => $r->season_id,
            'round_number' => $r->round_number,
            'date'         => $r->date?->format('Y-m-d'),
            'status'       => $r->status->value,
        ];
    }

    private function game(Game $g): array
    {
        return [
            'id'                     => $g->id,
            'round_id'               => $g->round_id,
            'white_season_player_id' => $g->white_season_player_id,
            'black_season_player_id' => $g->black_season_player_id,
            'result'                 => $g->result?->value,
        ];
    }

    private function attendance(Attendance $a): array
    {
        return [
            'id'               => $a->id,
            'round_id'         => $a->round_id,
            'season_player_id' => $a->season_player_id,
            'status'           => $a->status->value,
            'bye_type'         => $a->bye_type?->value,
        ];
    }

    private function member(Member $m, string $group): array
    {
        $data = [
            'id'        => $m->id,
            'player_id' => $m->player_id,
            'status'    => $m->status->value,
        ];

        if ($group === self::GROUP_ADMIN) {
            $data['email'] = $m->email;
        }

        return $data;
    }

    private function admin(Admin $a, string $group): array
    {
        $data = [
            'id'     => $a->id,
            'name'   => $a->name,
            'status' => $a->status->value,
        ];

        if ($group === self::GROUP_ADMIN) {
            $data['email'] = $a->email;
        }

        return $data;
    }
}
