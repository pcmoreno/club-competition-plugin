<?php

declare(strict_types=1);

namespace SCS\Tests\Unit\Entity\ValueObjects;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SCS\Entity\ValueObjects\TeamSheet;

// The sheet's whole job is that boards are 1..n with one player each, whatever
// the caller does — nobody names a board number, so nobody can get one wrong.
class TeamSheetTest extends TestCase
{
    #[Test]
    public function it_reads_a_line_up_back_out_of_the_column(): void
    {
        $sheet = TeamSheet::fromColumn([
            'Team A' => ['1' => 44, '2' => 67, '3' => 9],
            'Team B' => ['1' => 8],
        ]);

        self::assertSame(['Team A', 'Team B'], $sheet->names());
        self::assertSame('Team A', $sheet->teamOf(67));
        self::assertSame(2, $sheet->boardOf(67));
        self::assertNull($sheet->teamOf(999));
        self::assertNull($sheet->boardOf(999));
    }

    #[Test]
    public function it_accepts_a_bare_list_of_names(): void
    {
        // What the create dialog sends before anyone is assigned.
        $sheet = TeamSheet::fromColumn(['Team A', 'Team B']);

        self::assertSame(['Team A', 'Team B'], $sheet->names());
        self::assertSame([], $sheet->membersOf('Team A'));
    }

    #[Test]
    public function boards_are_read_in_numeric_order_not_string_order(): void
    {
        $sheet = TeamSheet::fromColumn(['Team A' => ['10' => 5, '2' => 3, '1' => 7]]);

        self::assertSame([7, 3, 5], $sheet->membersOf('Team A'));
        self::assertSame(3, $sheet->boardOf(5));
    }

    #[Test]
    public function joining_takes_the_bottom_board_and_leaving_gives_it_up(): void
    {
        $sheet = TeamSheet::fromColumn(['Team A' => ['1' => 1, '2' => 2], 'Team B' => []]);

        $moved = $sheet->place(3, 'Team A');
        self::assertSame(3, $moved->boardOf(3));

        // Moving out of A closes the gap rather than leaving boards 1 and 3.
        $across = $moved->place(2, 'Team B');
        self::assertSame([1, 3], $across->membersOf('Team A'));
        self::assertSame(2, $across->boardOf(3));
        self::assertSame(1, $across->boardOf(2));
    }

    #[Test]
    public function removing_a_player_renumbers_the_boards_below_them(): void
    {
        $sheet = TeamSheet::fromColumn(['Team A' => ['1' => 1, '2' => 2, '3' => 3]]);

        $after = $sheet->without([1]);

        self::assertSame([2, 3], $after->membersOf('Team A'));
        self::assertSame(['Team A' => [1 => 2, 2 => 3]], $after->toColumn());
    }

    #[Test]
    public function dropping_a_team_takes_its_line_up_with_it(): void
    {
        $sheet = TeamSheet::fromColumn(['Team A' => ['1' => 1], 'Team B' => ['1' => 2]]);

        $after = $sheet->withNames(['Team A', 'Team C']);

        self::assertSame(['Team A', 'Team C'], $after->names());
        self::assertSame([1], $after->membersOf('Team A'));
        self::assertNull($after->teamOf(2));
    }

    #[Test]
    public function reordering_refuses_an_order_that_is_not_the_team(): void
    {
        $sheet = TeamSheet::fromColumn(['Team A' => ['1' => 1, '2' => 2, '3' => 3]]);

        $this->expectException(\InvalidArgumentException::class);
        $sheet->reorder('Team A', [3, 2]);
    }

    #[Test]
    public function reordering_refuses_a_repeated_player(): void
    {
        $sheet = TeamSheet::fromColumn(['Team A' => ['1' => 1, '2' => 2]]);

        $this->expectException(\InvalidArgumentException::class);
        $sheet->reorder('Team A', [1, 1]);
    }

    #[Test]
    public function auto_fill_orders_each_team_by_rating(): void
    {
        $sheet = TeamSheet::fromColumn(['Team A' => [], 'Team B' => []]);

        $after = $sheet->withAssignments(
            [1 => 'Team A', 2 => 'Team B', 3 => 'Team A', 4 => null],
            [1 => 1500, 2 => 1800, 3 => 1900, 4 => 2200]
        );

        self::assertSame([3, 1], $after->membersOf('Team A'));
        self::assertSame(1, $after->boardOf(3));
        self::assertNull($after->teamOf(4));
    }

    #[Test]
    public function a_merged_player_keeps_the_board_they_held(): void
    {
        $sheet = TeamSheet::fromColumn(['Team A' => ['1' => 1, '2' => 2, '3' => 3]]);

        $after = $sheet->replace(2, 99);

        self::assertSame([1, 99, 3], $after->membersOf('Team A'));
        self::assertSame(2, $after->boardOf(99));
    }

    #[Test]
    public function the_column_round_trips(): void
    {
        $column = ['Team A' => [1 => 44, 2 => 67], 'Team B' => [1 => 8]];

        $encoded = json_encode(TeamSheet::fromColumn($column)->toColumn());
        $decoded = TeamSheet::fromColumn(json_decode((string)$encoded, true));

        self::assertSame($column, $decoded->toColumn());
    }
}
