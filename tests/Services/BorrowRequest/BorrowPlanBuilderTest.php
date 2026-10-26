<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\BorrowRequest;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\BorrowCandidate;
use SpeedPuzzling\Web\Services\BorrowRequest\BorrowPlanBuilder;

final class BorrowPlanBuilderTest extends TestCase
{
    public function testTheFewestPeopleCoverTheMostPuzzles(): void
    {
        $plan = (new BorrowPlanBuilder())->plan([
            self::candidate('p1', 'anna', 'Anna'),
            self::candidate('p2', 'anna', 'Anna'),
            self::candidate('p3', 'anna', 'Anna'),
            self::candidate('p2', 'petr', 'Petr'),
            self::candidate('p4', 'petr', 'Petr'),
            self::candidate('p4', 'marta', 'Marta'),
            self::candidate('p5', 'marta', 'Marta'),
            self::candidate('p1', 'zoe', 'Zoe'),
        ]);

        self::assertSame(['anna' => ['p1', 'p2', 'p3'], 'marta' => ['p4', 'p5']], $plan->puzzlesByOwner);
        self::assertSame([], $plan->unassigned);
        self::assertSame('marta', $plan->ownerOf('p4'));
        self::assertSame(['p1', 'p2', 'p3', 'p4', 'p5'], $plan->assignedPuzzleIds());
    }

    public function testATieGoesToWhoOwnsMoreThenByName(): void
    {
        $plan = (new BorrowPlanBuilder())->plan([
            self::candidate('p1', 'zoe', 'Zoe'),
            self::candidate('p1', 'bob', 'Bob'),
            self::candidate('p2', 'carl', 'Carl'),
            self::candidate('p3', 'carl', 'Carl'),
            self::candidate('p2', 'dana', 'Dana'),
        ]);

        self::assertSame(['carl' => ['p2', 'p3'], 'bob' => ['p1']], $plan->puzzlesByOwner);
    }

    public function testALentOutPuzzleIsNeverPlannedAndOnlyPickedPuzzlesCount(): void
    {
        $plan = (new BorrowPlanBuilder())->plan([
            self::candidate('p1', 'anna', 'Anna', lentOut: true),
            self::candidate('p2', 'anna', 'Anna'),
            self::candidate('p3', 'petr', 'Petr'),
        ], ['p1', 'p2', 'p9']);

        self::assertSame(['anna' => ['p2']], $plan->puzzlesByOwner);
        self::assertSame(['p1', 'p9'], $plan->unassigned);
    }

    public function testNobodyToAskIsAnEmptyPlan(): void
    {
        $plan = (new BorrowPlanBuilder())->plan([]);

        self::assertSame([], $plan->puzzlesByOwner);
        self::assertSame([], $plan->assignedPuzzleIds());
    }

    private static function candidate(string $puzzleId, string $ownerId, string $name, bool $lentOut = false): BorrowCandidate
    {
        return new BorrowCandidate($puzzleId, $ownerId, $name, strtolower($name), null, null, $lentOut);
    }
}
