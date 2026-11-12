<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Message\DetectSuspiciousTimes;
use SpeedPuzzling\Web\Query\GetFastestGroups;
use SpeedPuzzling\Web\Query\GetFastestPairs;
use SpeedPuzzling\Web\Query\GetParticipantsSheetState;
use SpeedPuzzling\Web\Query\GetPuzzleSolvers;
use SpeedPuzzling\Web\Query\GetRecentActivity;
use SpeedPuzzling\Web\Query\GetSolveTimeDistribution;
use SpeedPuzzling\Web\Query\GetSolvingTimePrediction;
use SpeedPuzzling\Web\Query\GetStatistics;
use SpeedPuzzling\Web\Query\GetSuspiciousTimeCandidates;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Results\RecentActivityItem;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Services\Community\CommunityStatsCalculator;
use SpeedPuzzling\Web\Services\ParticipantImport\SiteSnapshotReader;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\PuzzlesSorter;
use SpeedPuzzling\Web\Services\PuzzleStatisticsCalculator;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeClassifier;
use SpeedPuzzling\Web\Services\Xp\XpChainRecomputer;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\SolvingTimeDeletedVia;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Imported event results nobody has claimed yet - team times without a tracker (docs/features/event-results-import/README.md,
 * trackerless-audit.md) - with two anonymous teams of four on one puzzle: every page that lists times of the puzzle, the
 * round, the team and the event shows them as two results, the statistics count them, the global boards and the Hub's
 * feed leave them out until claimed (D4), and no background job trips over the missing tracker.
 * TrackerlessTimeQueryCoverageTest keeps every reader of puzzle_solving_time decided.
 */
final class TrackerlessTimeCanaryTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use TrackerlessTimeSeeding;

    private const string PUZZLE = PuzzleFixture::PUZZLE_1000_01;
    private const string PALS = 'Puzzle Pals';
    private const string CREW = 'Corner Crew';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testPagesShowEveryAnonymousTeamAsItsOwnResult(): void
    {
        [$pals, $crew] = $this->seed();

        foreach ([null, PlayerFixture::PLAYER_REGULAR] as $viewer) {
            if ($viewer !== null) {
                TestingLogin::asPlayer($this->browser, $viewer);
            }

            $this->page('/en/puzzle/' . self::PUZZLE);

            $teams = $this->createLiveComponent('PuzzleTimes', [
                'puzzleId' => self::PUZZLE,
                'piecesCount' => 1000,
                'category' => 'group',
            ], $this->browser);
            $teams->setRouteLocale('en');
            $html = $teams->render()->toString();
            self::assertStringContainsString(self::PALS, $html);
            self::assertStringContainsString(self::CREW, $html);
            self::assertStringContainsString('4 puzzlers', $html);
            self::assertSame(2, substr_count($html, 'data-testid="anonymous-team"'), 'Two anonymous teams are two rows.');

            $round = $this->page('/en/events/' . self::TRACKERLESS_EVENT_SLUG . '/results/' . self::TRACKERLESS_ROUND_SLUG);
            self::assertStringContainsString(self::PALS, $round);
            self::assertStringContainsString(self::CREW, $round);
            self::assertSame(2, substr_count($round, 'data-testid="anonymous-team"'), 'Two anonymous teams are two results.');

            $detail = $this->page('/en/result/' . $pals->id->toString());
            self::assertStringContainsString(self::PALS, $detail);
            self::assertStringContainsString('4 puzzlers', $detail);

            $team = $this->page('/en/teams/' . $this->teamIdOf($crew));
            self::assertStringContainsString(self::CREW, $team);
            self::assertStringContainsString('Team of 4', $team);
            self::assertStringContainsString(self::TRACKERLESS_EVENT_NAME, $this->page('/en/events/' . self::TRACKERLESS_EVENT_SLUG));
        }
    }

    public function testThePuzzleLeaderboardKeepsTwoAnonymousTeamsApart(): void
    {
        [$pals, $crew] = $this->seed();

        $rows = self::getContainer()->get(GetPuzzleSolvers::class)->teamByPuzzleId(self::PUZZLE);
        $ours = array_values(array_filter($rows, fn ($row): bool => in_array($row->timeId, self::idsOf($pals, $crew), true)));
        self::assertCount(2, $ours);
        self::assertNull($ours[0]->addedByPlayerId);
        self::assertSame(self::CREW, $ours[0]->teamName);

        $grouped = self::getContainer()->get(PuzzlesSorter::class)->groupPlayers($ours);
        self::assertCount(2, $grouped, 'Two anonymous teams of four are two results, not one.');
    }

    public function testStatisticsCountThem(): void
    {
        $statistics = self::getContainer()->get(GetStatistics::class);
        $calculator = self::getContainer()->get(PuzzleStatisticsCalculator::class);
        $distribution = self::getContainer()->get(GetSolveTimeDistribution::class);

        $before = $statistics->globally();
        $puzzleBefore = $calculator->calculateForPuzzle(Uuid::fromString(self::PUZZLE));
        $teamsBefore = $distribution->byPiecesCounts([1000], PuzzlingType::Team)[1000] ?? null;

        $this->seed();

        $after = $statistics->globally();
        self::assertSame($before->solvedPuzzlesCount + 2, $after->solvedPuzzlesCount);
        self::assertSame($before->totalSeconds + 3600 + 3000, $after->totalSeconds);
        self::assertSame($before->totalPieces + 2000, $after->totalPieces);

        $puzzleAfter = $calculator->calculateForPuzzle(Uuid::fromString(self::PUZZLE));
        self::assertSame($puzzleBefore->totalCount + 2, $puzzleAfter->totalCount);
        self::assertSame(0, $puzzleBefore->teamCount);
        self::assertSame(2, $puzzleAfter->teamCount);
        self::assertSame(3000, $puzzleAfter->fastestTimeTeam);
        self::assertSame(3600, $puzzleAfter->slowestTimeTeam);
        // Two teams, two best times - not one group of "nobody" with the faster one
        self::assertSame(3300, $puzzleAfter->averageTimeTeam);
        self::assertSame(3300, $puzzleAfter->medianTimeTeam);

        // The tracked results of the puzzle count exactly as before
        foreach (['soloCount', 'fastestTimeSolo', 'averageTimeSolo', 'medianTimeSolo', 'slowestTimeSolo', 'duoCount', 'fastestTimeDuo', 'averageTimeDuo', 'medianTimeDuo', 'slowestTimeDuo', 'averageTimeFirstAttempt', 'fastestTimeFirstAttempt'] as $field) {
            self::assertSame($puzzleBefore->{$field}, $puzzleAfter->{$field}, $field);
        }

        $teamsAfter = $distribution->byPiecesCounts([1000], PuzzlingType::Team)[1000] ?? null;
        self::assertNotNull($teamsAfter);
        self::assertSame(($teamsBefore->playersCount ?? 0) + 2, $teamsAfter->playersCount, 'Each anonymous team is one solver.');
    }

    public function testGlobalBoardsAndTheHubFeedLeaveThemOutUntilClaimed(): void
    {
        [$pals, $crew] = $this->seed();
        $pairRound = $this->seedTrackerlessEvent(PuzzleFixture::PUZZLE_1000_02, 'Maple Street Pairs Night', 'maple-street-pairs-night', RoundCategory::Duo);
        $pair = $this->seedTrackerlessTime($pairRound, PuzzleFixture::PUZZLE_1000_02, 'Edge Twins', 1500, size: 2);
        $ours = [...self::idsOf($pals, $crew), $pair->id->toString()];

        $groups = self::getContainer()->get(GetFastestGroups::class)->perPiecesCount(1000, 10000, null);
        $pairs = self::getContainer()->get(GetFastestPairs::class)->perPiecesCount(1000, 10000, null);
        $boardIds = array_map(static fn (SolvedPuzzle $row): string => $row->timeId, [...$groups, ...$pairs]);
        self::assertSame([], array_values(array_intersect($ours, $boardIds)));

        $feed = self::getContainer()->get(GetRecentActivity::class)->latest(10000);
        $feedIds = array_map(static fn (RecentActivityItem $item): string => $item->id, $feed);
        self::assertNotSame([], $feedIds);
        self::assertSame([], array_values(array_intersect($ours, $feedIds)));
    }

    public function testBackgroundJobsSkipThem(): void
    {
        [$pals, $crew] = $this->seed();
        $container = self::getContainer();

        // The suspicious time scan: never a candidate, the run goes through
        $candidates = $container->get(GetSuspiciousTimeCandidates::class)->batches(SuspiciousTimeClassifier::VERSION, TestClock::now()->modify('-1 year'), 1000, self::PUZZLE);
        foreach ($candidates as $batch) {
            foreach ($batch as $candidate) {
                self::assertNotContains($candidate->timeId, self::idsOf($pals, $crew));
            }
        }
        $container->get(MessageBusInterface::class)->dispatch(new DetectSuspiciousTimes(onlyPuzzleId: self::PUZZLE));

        $container->get(PuzzleIntelligenceRecalculator::class)->recalculate();

        $xp = $container->get(XpChainRecomputer::class);
        $xp->awardForNewSolve($pals->id->toString());
        $xp->rebuildChainForEditedSolve($pals->id->toString());

        self::assertNull($container->get(GetSolvingTimePrediction::class)->resultForTime($pals->id->toString()));

        // A linked participant makes the sheet read who has results in the event's rounds
        $entityManager = $container->get(EntityManagerInterface::class);
        $player = $entityManager->find(Player::class, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($player);
        $participant = new CompetitionParticipant(Uuid::uuid7(), 'Regular Puzzler', 'cz', $pals->competition ?? throw new \LogicException());
        $participant->connect($player, TestClock::now());
        $entityManager->persist($participant);
        $entityManager->flush();

        $competitionId = $participant->competition->id->toString();
        $container->get(GetParticipantsSheetState::class)->forCompetition($competitionId, PlayerFixture::PLAYER_REGULAR);

        $snapshot = $container->get(SiteSnapshotReader::class)->read($competitionId, [PlayerFixture::PLAYER_REGULAR], 'v1');
        self::assertArrayNotHasKey('', $snapshot->results);

        $container->get(CommunityStatsCalculator::class)->recalculate(TestClock::now());
    }

    public function testDeletingOneBuriesItWithoutAPlayer(): void
    {
        [$pals] = $this->seed();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $timeId = $pals->id->toString();

        self::getContainer()->get(PuzzleSolvingTimeRepository::class)->delete($pals, SolvingTimeDeletedVia::Web);
        $entityManager->flush();

        $connection = $entityManager->getConnection();
        self::assertFalse($connection->fetchOne('SELECT 1 FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));
        self::assertSame([['player_id' => null]], $connection->fetchAllAssociative('SELECT player_id FROM solving_time_graveyard WHERE solving_time_id = :id', ['id' => $timeId]));
        self::assertEquals(0, $connection->fetchOne('SELECT COUNT(*) FROM solving_time_tombstone WHERE solving_time_id = :id', ['id' => $timeId]));
    }

    /**
     * Two anonymous teams of four in the round: Puzzle Pals 1:00:00, Corner Crew 50:00
     *
     * @return array{PuzzleSolvingTime, PuzzleSolvingTime}
     */
    private function seed(): array
    {
        $round = $this->seedTrackerlessEvent(self::PUZZLE);

        return [
            $this->seedTrackerlessTime($round, self::PUZZLE, self::PALS, 3600),
            $this->seedTrackerlessTime($round, self::PUZZLE, self::CREW, 3000),
        ];
    }

    /**
     * @return list<string>
     */
    private static function idsOf(PuzzleSolvingTime ...$times): array
    {
        return array_values(array_map(static fn (PuzzleSolvingTime $time): string => $time->id->toString(), $times));
    }

    private function teamIdOf(PuzzleSolvingTime $time): string
    {
        self::assertNotNull($time->puzzlingTeam);

        return $time->puzzlingTeam->id->toString();
    }

    private function page(string $url): string
    {
        $this->browser->request('GET', $url);
        self::assertResponseStatusCodeSame(200, $url);

        return (string) $this->browser->getResponse()->getContent();
    }
}
