<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\Insights\InsightsPuzzleRef;
use SpeedPuzzling\Web\Results\Insights\PaceHistoryRow;
use SpeedPuzzling\Web\Services\Insights\ImprovementCurve;
use SpeedPuzzling\Web\Tests\InsightsPaceSeeding;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The improvement curve from the pace history (docs/features/insights/computation.md §4c.5).
 */
final class ImprovementCurveTest extends KernelTestCase
{
    use InsightsPaceSeeding;

    public function testCurveJumpsPlateauAndTheTypicalCurve(): void
    {
        $start = new DateTimeImmutable('2025-01-01 10:00:00');
        $rows = [];
        $pace = 4000;

        // 5..15: a 5 % jump at 8 and a 4 % one at 12, small steps otherwise; two rows on the same day at 9
        for ($count = 5; $count <= 15; $count++) {
            $step = match ($count) {
                5 => null,
                8 => log(0.95),
                12 => log(0.96),
                default => log(0.995),
            };
            $pace = $step === null ? $pace : (int) round($pace * exp($step));
            $day = $count === 10 ? 9 : $count;
            $rows[] = $this->row(500, $start->modify("+{$day} days"), $pace, $count, $step);
        }

        // Then 25 first tries over 90 days within ±1 % of the last pace: a plateau
        $flat = $pace;

        for ($count = 16; $count <= 40; $count++) {
            $rows[] = $this->row(500, $start->modify('+' . (20 + ($count - 16) * 4) . ' days'), $flat + ($count % 2 === 0 ? 10 : -10), $count, 0.0);
        }

        $rows[] = $this->row(1000, $start, 9000, 5, null);

        $curve = ImprovementCurve::fromRows($rows);

        self::assertCount(2, $curve->sizes);
        $size = $curve->sizes[0];
        self::assertSame(500, $size->piecesCount);
        self::assertSame(4000, $size->firstPaceSeconds);
        self::assertSame(40, $size->firstTries);
        self::assertCount(count($rows) - 2, $size->points, 'One point per day');
        self::assertSame(2, $size->jumpsCount);
        self::assertSame(35, $size->stepsCount);
        self::assertEqualsWithDelta(-0.05, $size->biggestJumps[0]->change, 1e-12);
        self::assertSame(8, (int) $size->biggestJumps[0]->date->diff($start)->days);
        self::assertEqualsWithDelta(-0.04, $size->biggestJumps[1]->change, 1e-12);
        self::assertNull($size->biggestJumps[0]->puzzle, 'A secret puzzle is not shown');
        self::assertNotNull($size->biggestJumps[1]->puzzle);

        self::assertNotNull($size->plateau);
        self::assertGreaterThanOrEqual(25, $size->plateau->firstTries);

        // Typical: −4.5 % at 25, −7.6 % at 50; 40 lies between (log-linear)
        self::assertSame(-0.045, ImprovementCurve::typicalChange(25));
        self::assertEqualsWithDelta(-0.045 + (log(40) - log(25)) / (log(50) - log(25)) * (-0.031), $size->typicalChange, 1e-12);
        self::assertNull(ImprovementCurve::typicalChange(301));
        self::assertSame([5, 25], array_map(static fn ($point): int => $point->firstTries, $size->typicalCurve));
        self::assertSame(4000, $size->typicalCurve[0]->paceSeconds);

        self::assertSame(1000, $curve->sizes[1]->piecesCount);
        self::assertSame([], $curve->sizes[1]->typicalCurve, 'Typical curve at 500 pieces only');
    }

    public function testNoPlateauWhileImproving(): void
    {
        $start = new DateTimeImmutable('2025-01-01');
        $rows = [];

        for ($count = 5; $count <= 60; $count++) {
            $rows[] = $this->row(500, $start->modify('+' . ($count * 3) . ' days'), (int) round(4000 * 0.995 ** $count), $count, $count === 5 ? null : log(0.995));
        }

        self::assertNull(ImprovementCurve::fromRows($rows)->sizes[0]->plateau);
    }

    public function testOneStatementFromThePaceHistory(): void
    {
        self::bootKernel();
        $now = new DateTimeImmutable('today 12:00:00');
        self::getContainer()->set(ClockInterface::class, new MockClock($now));

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $player = Uuid::uuid7()->toString();
        $connection->executeStatement("INSERT INTO player (id, code, name, registered_at) VALUES (:id, 'curve', 'Curve', NOW())", ['id' => $player]);

        $this->paceRow($connection, $player, 500, $now->modify('-100 days'), 4000, 5);
        $this->paceRow($connection, $player, 500, $now->modify('-90 days'), 3800, 6, log(0.95));

        /** @var DebugDataHolder $holder */
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        /** @var ImprovementCurve $builder */
        $builder = self::getContainer()->get(ImprovementCurve::class);
        $curve = $builder->build($player);

        $queries = $holder->getData()['default'] ?? [];
        self::assertCount(1, is_array($queries) ? $queries : []);
        self::assertSame(1, $curve->sizes[0]->jumpsCount);
        self::assertSame('Pace puzzle', $curve->sizes[0]->biggestJumps[0]->puzzle?->name);
    }

    private function row(int $piecesCount, DateTimeImmutable $at, int $pace, int $count, null|float $step): PaceHistoryRow
    {
        return new PaceHistoryRow(
            piecesCount: $piecesCount,
            validFrom: $at,
            baselineSeconds: $pace,
            qualifyingCount: $count,
            step: $step,
            timeId: 'time-' . $piecesCount . '-' . $count,
            solveSeconds: $pace,
            puzzle: $count === 8 ? null : new InsightsPuzzleRef('puzzle-' . $count, 'Puzzle ' . $count, null, null, $piecesCount),
        );
    }
}
