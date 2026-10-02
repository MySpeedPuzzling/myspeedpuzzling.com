<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\DetectDuplicateResults;
use SpeedPuzzling\Web\Query\GetDuplicateResultsOverview;
use SpeedPuzzling\Web\Tests\DataFixtures\DuplicateResultsFixture;
use SpeedPuzzling\Web\Value\DuplicateCaseListTab;
use SpeedPuzzling\Web\Value\DuplicateTier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class GetDuplicateResultsOverviewTest extends KernelTestCase
{
    private GetDuplicateResultsOverview $overview;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $container->get(MessageBusInterface::class)->dispatch(new DetectDuplicateResults());
        $this->overview = $container->get(GetDuplicateResultsOverview::class);
        $this->database = $container->get(Connection::class);
    }

    public function testCountsCasesPerPersonAndTwinsPerPair(): void
    {
        $now = self::getContainer()->get(ClockInterface::class)->now();

        $totals = $this->overview->totals($now);
        self::assertSame(5, $totals->openCases);
        self::assertSame(2, $totals->playersAffected);
        self::assertSame(0, $totals->resolvedLast30Days);

        self::assertSame(['open' => 2], $this->overview->casesByKindAndStatus()['teammate_copy']);
        self::assertSame(['open' => 3], $this->overview->casesByTierAndStatus()['strong']);

        // The teammate copy is one pair of results, two cases
        self::assertSame(4, array_sum($this->overview->gapClasses()));
        self::assertSame(2, $this->overview->gapClasses()['≤ 10 s']);
        self::assertSame(4, array_sum(array_column($this->overview->monthlyTrend($now), 'twins')));
    }

    public function testShareConfirmedRealPerTier(): void
    {
        $this->setStatus(DuplicateResultsFixture::TIME_STRONG_A, 'both_real');
        $this->setStatus(DuplicateResultsFixture::TIME_TEAMMATE_A, 'copy_deleted');

        $now = self::getContainer()->get(ClockInterface::class)->now();
        $shares = $this->overview->confirmedRealShareByTier();

        self::assertSame(['decided' => 3, 'confirmed_real' => 1, 'percent' => 33], $shares['strong']);
        self::assertSame(['decided' => 0, 'confirmed_real' => 0, 'percent' => null], $shares['certain']);
        self::assertSame(2, $this->overview->totals($now)->resolvedLast30Days);
        self::assertSame(1, $this->overview->totals($now)->confirmedReal);
    }

    public function testListsCasesOfATab(): void
    {
        self::assertSame(5, $this->overview->countCases(DuplicateCaseListTab::Open, null, null));
        self::assertSame(1, $this->overview->countCases(DuplicateCaseListTab::Open, DuplicateTier::Certain, null));
        self::assertSame(0, $this->overview->countCases(DuplicateCaseListTab::Gone, null, null));

        $cases = $this->overview->cases(DuplicateCaseListTab::Open, DuplicateTier::Certain, null, 1);

        self::assertCount(1, $cases);
        self::assertSame('Dana Twin', $cases[0]->playerName);
        self::assertSame(DuplicateResultsFixture::PUZZLE_TWINS, $cases[0]->puzzleId);
        self::assertSame(7, $cases[0]->gapSeconds);
    }

    private function setStatus(string $timeAId, string $status): void
    {
        $this->database->executeStatement(
            'UPDATE result_duplicate_case SET status = :status, resolved_at = NOW() WHERE time_a_id = :id',
            ['status' => $status, 'id' => $timeAId],
        );
    }
}
