<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\ImprovementRatioCalculator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ImprovementRatioCalculatorTest extends KernelTestCase
{
    private ImprovementRatioCalculator $calculator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->calculator = self::getContainer()->get(ImprovementRatioCalculator::class);
    }

    /**
     * The SQL snapshot of PredictionReconstructor must be the very ratios the batch recalculation
     * writes to global_improvement_ratio when nothing is cut off.
     */
    public function testGlobalRatiosBeforeTheFutureEqualTheBatchRatios(): void
    {
        $snapshot = $this->calculator->globalRatiosBefore(new DateTimeImmutable('+1 year'));
        self::assertNotSame([], $snapshot, 'fixtures have repeat solves');

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        /** @var list<int|string> $piecesCounts */
        $piecesCounts = $connection->fetchFirstColumn('SELECT DISTINCT pieces_count FROM puzzle');

        $this->calculator->preloadAllTransitions();
        $expected = [];

        foreach ($piecesCounts as $piecesCount) {
            foreach ($this->calculator->computeGlobalRatios((int) $piecesCount) as $ratio) {
                $expected[(int) $piecesCount][$ratio['from_attempt']][$ratio['gap_bucket']] = $ratio['median_ratio'];
            }
        }

        $this->calculator->clearPreloadedData();

        self::assertEqualsCanonicalizing($this->sorted($expected), $this->sorted($snapshot));
    }

    public function testGlobalRatiosBeforeAnyRepeatSolveAreEmpty(): void
    {
        self::assertSame([], $this->calculator->globalRatiosBefore(new DateTimeImmutable('2000-01-01')));
    }

    /**
     * A transition counts from its later attempt on: cut between two attempts, it is not there yet.
     */
    public function testATransitionCountsFromItsLaterAttempt(): void
    {
        $all = $this->calculator->globalRatiosBefore(new DateTimeImmutable('+1 year'));
        $monthAgo = $this->calculator->globalRatiosBefore(new DateTimeImmutable('-30 days'));

        self::assertNotEquals($all, $monthAgo, 'fixtures have repeat solves within the last 30 days');
    }

    public function testTransitionRejectsImplausibleRatiosAndCapsTheAttempt(): void
    {
        self::assertNull(ImprovementRatioCalculator::transition(0, 100, 1, 1.0));
        self::assertNull(ImprovementRatioCalculator::transition(1000, 99, 1, 1.0));
        self::assertNull(ImprovementRatioCalculator::transition(1000, 5001, 1, 1.0));
        self::assertSame(['from_attempt' => 4, 'ratio' => 0.9, 'gap_days' => 2.5], ImprovementRatioCalculator::transition(1000, 900, 7, 2.5));
    }

    /**
     * @param array<int, array<int, array<string, float>>> $ratios
     * @return array<int, array<int, array<string, float>>>
     */
    private function sorted(array $ratios): array
    {
        ksort($ratios);

        foreach ($ratios as &$byAttempt) {
            ksort($byAttempt);

            foreach ($byAttempt as &$byBucket) {
                ksort($byBucket);
            }
        }

        return $ratios;
    }
}
