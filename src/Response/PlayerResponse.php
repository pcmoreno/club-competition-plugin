<?php

declare(strict_types=1);

namespace SCS\Response;

class PlayerResponse {
    public int $id;
    public string $name;
    public ?string $knsb_id = null;
    public ?float $elo_rating = null;
    public ?string $category = null;
    public ?\DateTime $created_at = null;

    public function __construct(
        int $id,
        string $name,
        ?string $knsb_id = null,
        ?float $elo_rating = null,
        ?string $category = null,
        ?\DateTime $created_at = null
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->knsb_id = $knsb_id;
        $this->elo_rating = $elo_rating;
        $this->category = $category;
        $this->created_at = $created_at;
    }
}
