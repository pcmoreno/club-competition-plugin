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
    public function serialize(object $entity): array
    {
        return match(true) {
            $entity instanceof Player       => $this->player($entity),
            $entity instanceof Season       => $this->season($entity),
            $entity instanceof SeasonPlayer => $this->seasonPlayer($entity),
            $entity instanceof Round        => $this->round($entity),
            $entity instanceof Game         => $this->game($entity),
            $entity instanceof Attendance   => $this->attendance($entity),
            $entity instanceof Member       => $this->member($entity),
            $entity instanceof Admin        => $this->admin($entity),
            default => throw new \InvalidArgumentException('Cannot serialize ' . get_class($entity)),
        };
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

    private function member(Member $m): array
    {
        return [
            'id'        => $m->id,
            'player_id' => $m->player_id,
            'email'     => $m->email,
            'status'    => $m->status->value,
        ];
    }

    private function admin(Admin $a): array
    {
        return [
            'id'     => $a->id,
            'name'   => $a->name,
            'email'  => $a->email,
            'status' => $a->status->value,
        ];
    }
}
