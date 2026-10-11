<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Events\PuzzleSolvingTimeSuspicionChanged;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AnswerGuestLink;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\AwardXpForSolvingTime;
use SpeedPuzzling\Web\Message\CompensateXpForDeletedSolve;
use SpeedPuzzling\Web\Message\DeletePuzzleSolvingTime;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\MarkSolvingTimeSuspiciousDirectly;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\RecalculateXpChainForSolve;
use SpeedPuzzling\Web\Message\RecalculateXpForPlayer;
use SpeedPuzzling\Web\Message\RequestGuestLink;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Message\UndoAutoRemoval;
use SpeedPuzzling\Web\Message\UnmarkSolvingTimeSuspiciousDirectly;
use SpeedPuzzling\Web\Query\GetProgressionDrift;
use SpeedPuzzling\Web\Services\DuplicateResults\DailyDuplicateDetection;
use SpeedPuzzling\Web\Tests\ClonesSolvingTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\DuplicateResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\DuplicateDetectedBy;
use SpeedPuzzling\Web\Value\FirstTryResolution;
use SpeedPuzzling\Web\Value\LevelTable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Anti-farming (docs/features/xp-levels/README.md §Anti-farming): after ANY sequence of adds, edits, deletions,
 * re-adds, moves, duplicate removals and their Undo, guest links, merges and suspicious marks, a player's XP ledger,
 * level, achievements and Achievement Points equal a fresh recomputation from their current records - repeating an
 * action earns nothing. Every step goes through the real message handlers; the async XP / achievement messages they
 * queue are then handled like a consumer would (fresh entity manager each).
 */
final class ProgressionPathIndependenceTest extends KernelTestCase
{
    use ClonesSolvingTimes;

    private const string PLAYER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string USER = PlayerFixture::PLAYER_WITH_STRIPE_USER_ID;

    private const array DRAINED = [
        AwardXpForSolvingTime::class,
        RecalculateXpChainForSolve::class,
        CompensateXpForDeletedSolve::class,
        RecalculateXpForPlayer::class,
        RecalculateBadgesForPlayer::class,
        PuzzleSolvingTimeSuspicionChanged::class,
    ];

    private MessageBusInterface $messageBus;
    private Connection $database;
    private EntityManagerInterface $entityManager;
    private InMemoryTransport $asyncTransport;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->database = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->asyncTransport = $transport;

        // Fixtures carry results without a ledger - start every player from their fresh recomputation
        foreach ([self::PLAYER, PlayerFixture::PLAYER_WITH_FAVORITES, DuplicateResultsFixture::PLAYER_TWINS, DuplicateResultsFixture::PLAYER_TWINS_TEAMMATE] as $playerId) {
            $this->recompute($playerId);
        }

        $this->asyncTransport->reset();
    }

    public function testAddingDeletingAndReAddingTheSamePuzzleEarnsNothingExtra(): void
    {
        $at = new DateTimeImmutable('2026-08-05 12:00:00');
        $this->addTime(PuzzleFixture::PUZZLE_1000_04, '01:10:00', $at);
        $earned = $this->xpTotal();

        for ($round = 0; $round < 4; $round++) {
            $timeId = $this->solveIdsOf(PuzzleFixture::PUZZLE_1000_04)[0];
            $this->deleteTime($timeId);
            $this->addTime(PuzzleFixture::PUZZLE_1000_04, '01:10:00', $at);

            self::assertSame($earned, $this->xpTotal(), 'Delete + re-add must land where the first add did');
            $this->assertMatchesFreshRecomputation([self::PLAYER]);
        }
    }

    public function testRepeatsAndEditsFasterSlowerAndBackLandWhereTheyStarted(): void
    {
        $at = new DateTimeImmutable('2026-08-12 10:00:00');
        $first = $this->addTime(PuzzleFixture::PUZZLE_1000_05, '01:30:00', $at);
        $this->addTime(PuzzleFixture::PUZZLE_1000_05, '01:20:00', $at->modify('+1 day'));
        $this->addTime(PuzzleFixture::PUZZLE_1000_05, '01:15:00', $at->modify('+2 days'));
        $this->assertMatchesFreshRecomputation([self::PLAYER]);
        $before = $this->xpTotal();

        foreach (['00:40:00', '02:30:00', null, '01:30:00'] as $time) {
            $this->editTime($first, $time, $at);
            $this->assertMatchesFreshRecomputation([self::PLAYER]);
        }

        // Moving the first solve after the repeats and back
        $this->editTime($first, '01:30:00', $at->modify('+3 days'));
        $this->assertMatchesFreshRecomputation([self::PLAYER]);
        $this->editTime($first, '01:30:00', $at);
        $this->assertMatchesFreshRecomputation([self::PLAYER]);

        self::assertSame($before, $this->xpTotal());
    }

    public function testBackDatingIntoAWeekNeverCreatesMoreThanFiveWeeklyBoosts(): void
    {
        $monday = new DateTimeImmutable('2026-08-17 00:00:00');
        $puzzles = [
            PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_03,
            PuzzleFixture::PUZZLE_500_05, PuzzleFixture::PUZZLE_1000_01, PuzzleFixture::PUZZLE_1000_02,
            PuzzleFixture::PUZZLE_1000_03, PuzzleFixture::PUZZLE_300,
        ];

        // Five solves on Friday afternoon, then three more logged into the past of the same week, each one earlier
        // than everything before it - the slot counter used to see "nothing before me" every time
        foreach (array_slice($puzzles, 0, 5) as $index => $puzzleId) {
            $this->addTime($puzzleId, '00:50:00', $monday->modify('+4 days')->setTime(13 + $index, 0));
        }

        foreach (array_slice($puzzles, 5) as $index => $puzzleId) {
            $this->addTime($puzzleId, '00:50:00', $monday->setTime(9 - $index, 0));
        }

        self::assertSame(5, $this->linesInWeek('solve_weekly_boost', $monday));
        $this->assertMatchesFreshRecomputation([self::PLAYER]);

        // Deleting the earliest solve and logging it again at the same moment hands nothing out twice
        $earliest = $this->solveIdsOf(PuzzleFixture::PUZZLE_300)[0];
        $this->deleteTime($earliest);
        $this->addTime(PuzzleFixture::PUZZLE_300, '00:50:00', $monday->setTime(7, 0));

        self::assertSame(5, $this->linesInWeek('solve_weekly_boost', $monday));
        $this->assertMatchesFreshRecomputation([self::PLAYER]);
    }

    public function testLoggingRepeatsIntoThePastNeverMakesThemFirstSolves(): void
    {
        $at = new DateTimeImmutable('2026-08-14 12:00:00');
        $before = $this->xpTotal();
        $this->addTime(PuzzleFixture::PUZZLE_9000, '20:00:00', $at);
        $firstSolve = $this->xpTotal() - $before;

        // Each one dated before everything logged so far - the award used to see "nothing before me" every time
        for ($daysEarlier = 1; $daysEarlier <= 3; $daysEarlier++) {
            $this->addTime(PuzzleFixture::PUZZLE_9000, '20:00:00', $at->modify("-{$daysEarlier} days"));
            $this->assertMatchesFreshRecomputation([self::PLAYER]);
        }

        // One first solve, one repeat at 50 %, two at 25 % - never four first solves
        self::assertLessThan($before + 3 * $firstSolve, $this->xpTotal());
    }

    public function testRelaxAndTimedEditsOfOneChainStayConsistent(): void
    {
        $at = new DateTimeImmutable('2026-08-24 10:00:00');
        $timed = $this->addTime(PuzzleFixture::PUZZLE_1500_01, '02:00:00', $at);
        $second = $this->addTime(PuzzleFixture::PUZZLE_1500_01, '01:50:00', $at->modify('+1 day'));
        $third = $this->addTime(PuzzleFixture::PUZZLE_1500_01, '01:45:00', $at->modify('+2 days'));

        foreach ([[$second, null], [$timed, null], [$second, '01:50:00'], [$third, null], [$timed, '02:00:00'], [$third, '01:45:00']] as [$timeId, $time]) {
            $this->editTime($timeId, $time, null);
            $this->assertMatchesFreshRecomputation([self::PLAYER]);
        }

        // Deleting a relax repeat that earned nothing still moves the later solve up
        $this->editTime($second, null, null);
        $this->deleteTime($second);
        $this->assertMatchesFreshRecomputation([self::PLAYER]);
    }

    public function testTeamMembershipEditsFollowTheMembers(): void
    {
        $member = PlayerFixture::PLAYER_WITH_FAVORITES;
        $memberBefore = $this->xpTotal($member);
        $at = new DateTimeImmutable('2026-08-26 18:00:00');

        $pair = $this->addTime(PuzzleFixture::PUZZLE_2000, '03:00:00', $at, ['#player3']);
        self::assertGreaterThan($memberBefore, $this->xpTotal($member));
        $this->assertMatchesFreshRecomputation([self::PLAYER, $member]);

        for ($round = 0; $round < 3; $round++) {
            $this->editTime($pair, '03:00:00', $at, []);
            self::assertSame($memberBefore, $this->xpTotal($member), 'Left the group - the XP goes');
            $this->assertMatchesFreshRecomputation([self::PLAYER, $member]);

            $this->editTime($pair, '03:00:00', $at, ['#player3']);
            $this->assertMatchesFreshRecomputation([self::PLAYER, $member]);
        }
    }

    public function testAResultIdThatComesBackAfterDeletionEarnsLikeANewResult(): void
    {
        $at = new DateTimeImmutable('2026-08-27 20:00:00');
        $timeId = Uuid::uuid7();
        $before = $this->xpTotal();

        $this->addTime(PuzzleFixture::PUZZLE_3000, '04:00:00', $at, timeId: $timeId);
        $earned = $this->xpTotal() - $before;
        self::assertGreaterThan(0, $earned);

        for ($round = 0; $round < 3; $round++) {
            $this->deleteTime($timeId->toString());
            self::assertSame($before, $this->xpTotal());

            // The form (or the API's Idempotency-Key) sends the same id again
            $this->addTime(PuzzleFixture::PUZZLE_3000, '04:00:00', $at, timeId: $timeId);
            self::assertSame($before + $earned, $this->xpTotal(), 'The id has compensated history - it must earn again, once');
            $this->assertMatchesFreshRecomputation([self::PLAYER]);
        }
    }

    public function testAutomaticDuplicateRemovalAndItsUndo(): void
    {
        $players = [DuplicateResultsFixture::PLAYER_TWINS, DuplicateResultsFixture::PLAYER_TWINS_TEAMMATE];

        self::getContainer()->get(DailyDuplicateDetection::class)->run(DuplicateDetectedBy::Cron);
        $this->drain();
        $this->assertMatchesFreshRecomputation($players);

        $removalId = $this->database->fetchOne(
            'SELECT id FROM result_auto_removal WHERE removed_time_id = :id',
            ['id' => DuplicateResultsFixture::TIME_CERTAIN_B],
        );
        self::assertIsString($removalId);

        $this->messageBus->dispatch(new UndoAutoRemoval($removalId, DuplicateResultsFixture::PLAYER_TWINS));
        $this->drain();
        $this->assertMatchesFreshRecomputation($players);
    }

    public function testAMarkAfterTheAwardTakesTheXpAwayAndClearingItGivesItBack(): void
    {
        $before = $this->xpTotal();
        $timeId = $this->addTime(PuzzleFixture::PUZZLE_4000, '05:00:00', new DateTimeImmutable('2026-08-28 09:00:00'));
        $earned = $this->xpTotal();
        self::assertGreaterThan($before, $earned);

        $this->messageBus->dispatch(new MarkSolvingTimeSuspiciousDirectly($timeId, PlayerFixture::PLAYER_ADMIN, null, null, true));
        $this->drain();
        self::assertSame($before, $this->xpTotal());
        $this->assertMatchesFreshRecomputation([self::PLAYER]);

        $this->messageBus->dispatch(new UnmarkSolvingTimeSuspiciousDirectly($timeId, PlayerFixture::PLAYER_ADMIN, null));
        $this->drain();
        self::assertSame($earned, $this->xpTotal());
        $this->assertMatchesFreshRecomputation([self::PLAYER]);

        // Set by SQL, then reconciled by the scan, which hands the time's events to the bus
        $this->database->executeStatement('UPDATE puzzle_solving_time SET suspicious = true WHERE id = :id', ['id' => $timeId]);
        $this->messageBus->dispatch(new PuzzleSolvingTimeSuspicionChanged(Uuid::fromString($timeId)));
        $this->drain();
        self::assertSame($before, $this->xpTotal());
        $this->assertMatchesFreshRecomputation([self::PLAYER]);
    }

    public function testMovingAResultToAnotherPuzzleAndMergingPuzzles(): void
    {
        $at = new DateTimeImmutable('2026-08-31 15:00:00');
        $this->addTime(PuzzleFixture::PUZZLE_500_04, '00:45:00', $at);
        $moved = $this->addTime(PuzzleFixture::PUZZLE_500_05, '00:44:00', $at->modify('+1 hour'));

        $this->editTime($moved, '00:44:00', $at->modify('+1 hour'), puzzleId: PuzzleFixture::PUZZLE_500_04);
        $this->assertMatchesFreshRecomputation([self::PLAYER]);

        $this->editTime($moved, '00:44:00', $at->modify('+1 hour'), puzzleId: PuzzleFixture::PUZZLE_500_05);
        $this->assertMatchesFreshRecomputation([self::PLAYER]);

        $mergeRequestId = Uuid::uuid7()->toString();
        $this->messageBus->dispatch(new SubmitPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            sourcePuzzleId: PuzzleFixture::PUZZLE_500_04,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            duplicatePuzzleIds: [PuzzleFixture::PUZZLE_500_05],
        ));
        $this->messageBus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
            mergedName: 'Merged Puzzle Name',
            mergedEans: null,
            mergedBrandCodes: null,
            mergedPiecesCount: 500,
            mergedManufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            selectedImagePuzzleId: null,
        ));
        $this->drain();

        // Two first solves became a first solve and a repeat
        $this->assertMatchesFreshRecomputation([self::PLAYER]);
    }

    public function testAnAcceptedGuestLink(): void
    {
        $member = PlayerFixture::PLAYER_WITH_FAVORITES;
        $this->addTime(PuzzleFixture::PUZZLE_1500_02, '02:10:00', new DateTimeImmutable('2026-09-01 19:00:00'), ['Michael']);

        $requestId = Uuid::uuid7();
        $this->messageBus->dispatch(new RequestGuestLink($requestId, self::PLAYER, 'g:michael', 'player3'));
        $this->messageBus->dispatch(new AnswerGuestLink($requestId->toString(), $member, true));
        $this->drain();

        $this->assertMatchesFreshRecomputation([self::PLAYER, $member]);
    }

    public function testAchievementsEarnedWithFakeTimesGoWithTheTimesAndComeBackOnce(): void
    {
        $at = new DateTimeImmutable('2026-08-03 08:00:00');
        // The fixture player has one 2000+ piece result (no Marathoner tier yet); four more make five = Bronze
        self::assertSame([], $this->tiersOf('marathoner'));
        $marathons = [PuzzleFixture::PUZZLE_3000, PuzzleFixture::PUZZLE_4000, PuzzleFixture::PUZZLE_5000, PuzzleFixture::PUZZLE_6000];
        $pointsBefore = $this->achievementPoints();

        $timeIds = [];
        foreach ($marathons as $index => $puzzleId) {
            $timeIds[] = $this->addTime($puzzleId, '08:00:00', $at->modify("+{$index} hours"));
        }

        self::assertSame([BadgeTier::Bronze->value], $this->tiersOf('marathoner'));
        self::assertSame($pointsBefore + BadgeTier::Bronze->points(), $this->achievementPoints());

        // The fake times go - so does the tier they were needed for, silently, with its points
        foreach ($timeIds as $timeId) {
            $this->deleteTime($timeId);
            self::assertSame([], $this->tiersOf('marathoner'));
            self::assertSame($pointsBefore, $this->achievementPoints());
        }

        $this->assertMatchesFreshRecomputation([self::PLAYER]);

        // Earned again - the points count once, however often it went back and forth
        for ($round = 0; $round < 2; $round++) {
            $timeIds = [];
            foreach ($marathons as $index => $puzzleId) {
                $timeIds[] = $this->addTime($puzzleId, '08:00:00', $at->modify("+{$index} hours"));
            }

            self::assertSame([BadgeTier::Bronze->value], $this->tiersOf('marathoner'));
            self::assertSame($pointsBefore + BadgeTier::Bronze->points(), $this->achievementPoints());
            $this->assertMatchesFreshRecomputation([self::PLAYER]);

            foreach ($timeIds as $timeId) {
                $this->deleteTime($timeId);
            }
        }
    }

    public function testADuplicateRemovalLowersATierTheCopyWasNeededFor(): void
    {
        $at = new DateTimeImmutable('2026-08-04 08:00:00');

        // One 2000+ piece result in the fixtures, three more (the last one below) make four
        foreach ([PuzzleFixture::PUZZLE_3000, PuzzleFixture::PUZZLE_4000] as $index => $puzzleId) {
            $this->addTime($puzzleId, '08:00:00', $at->modify("+{$index} hours"));
        }

        self::assertSame([], $this->tiersOf('marathoner'));

        // The same form sent twice (two requests racing past the save's twin check) - the copy makes it five
        $original = $this->addTime(PuzzleFixture::PUZZLE_6000, '08:00:00', $at->modify('+5 hours'));
        $trackedAt = $this->database->fetchOne('SELECT tracked_at FROM puzzle_solving_time WHERE id = :id', ['id' => $original]);
        self::assertIsString($trackedAt);
        $copy = $this->cloneSolvingTime($original, ['tracked_at' => (new DateTimeImmutable($trackedAt))->modify('+3 seconds')->format('Y-m-d H:i:s')]);
        $this->messageBus->dispatch(new AwardXpForSolvingTime($copy));
        $this->messageBus->dispatch(new RecalculateBadgesForPlayer(self::PLAYER));
        $this->drain();
        self::assertSame([BadgeTier::Bronze->value], $this->tiersOf('marathoner'));

        self::getContainer()->get(DailyDuplicateDetection::class)->run(DuplicateDetectedBy::Cron);
        $this->drain();

        self::assertCount(1, $this->solveIdsOf(PuzzleFixture::PUZZLE_6000), 'The copy was removed automatically');
        self::assertSame([], $this->tiersOf('marathoner'));
        $this->assertMatchesFreshRecomputation([self::PLAYER]);
    }

    /**
     * @param list<string> $groupPlayers
     */
    private function addTime(
        string $puzzleId,
        string $time,
        DateTimeImmutable $finishedAt,
        array $groupPlayers = [],
        null|UuidInterface $timeId = null,
    ): string {
        $timeId ??= Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: self::USER,
            puzzleId: $puzzleId,
            competitionId: null,
            time: $time,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: $finishedAt,
            firstAttempt: false,
            unboxed: false,
        ));
        $this->drain();

        return $timeId->toString();
    }

    /**
     * @param null|list<string> $groupPlayers null = as it is
     */
    private function editTime(
        string $timeId,
        null|string $time,
        null|DateTimeImmutable $finishedAt,
        null|array $groupPlayers = null,
        null|string $puzzleId = null,
    ): void {
        if ($groupPlayers === null) {
            $groupPlayers = $this->currentGroupCodes($timeId);
        }

        if ($finishedAt === null) {
            $value = $this->database->fetchOne('SELECT finished_at FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
            self::assertIsString($value);
            $finishedAt = new DateTimeImmutable($value);
        }

        $this->messageBus->dispatch(new EditPuzzleSolvingTime(
            currentUserId: self::USER,
            puzzleSolvingTimeId: $timeId,
            competitionId: null,
            time: $time,
            comment: null,
            groupPlayers: $groupPlayers,
            finishedAt: $finishedAt,
            finishedPuzzlesPhoto: null,
            firstAttempt: false,
            unboxed: false,
            firstTryResolution: FirstTryResolution::None,
            puzzleId: $puzzleId,
        ));
        $this->drain();
    }

    private function deleteTime(string $timeId): void
    {
        $this->messageBus->dispatch(new DeletePuzzleSolvingTime(self::USER, $timeId));
        $this->drain();
    }

    /**
     * Handles the queued XP / achievement messages like the consumer: each on a fresh entity manager, until nothing
     * of them is left. Everything else queued (notifications...) is not this test's business.
     */
    private function drain(): void
    {
        for ($pass = 0; $pass < 10; $pass++) {
            $envelopes = $this->asyncTransport->getSent();
            $this->asyncTransport->reset();
            $handled = false;

            foreach ($envelopes as $envelope) {
                $message = $envelope->getMessage();

                if (in_array($message::class, self::DRAINED, true) === false) {
                    continue;
                }

                $this->entityManager->clear();
                $this->messageBus->dispatch($message, [new ReceivedStamp('async')]);
                $handled = true;
            }

            if ($handled === false) {
                $this->entityManager->clear();

                return;
            }
        }

        self::fail('The XP messages kept queueing each other');
    }

    private function recompute(string $playerId): void
    {
        $this->entityManager->clear();
        $this->messageBus->dispatch(new RecalculateXpForPlayer($playerId), [new TransportNamesStamp(['sync'])]);
        $this->messageBus->dispatch(new RecalculateBadgesForPlayer($playerId), [new TransportNamesStamp(['sync'])]);
        $this->entityManager->clear();
    }

    /**
     * The ledger, the level, the achievements and their points are exactly what a fresh recomputation of the current
     * records writes.
     *
     * @param list<string> $playerIds
     */
    private function assertMatchesFreshRecomputation(array $playerIds): void
    {
        $live = [];

        foreach ($playerIds as $playerId) {
            $this->assertTotalsMatchLedger($playerId);
            $live[$playerId] = $this->progressionState($playerId);
        }

        // The nightly safety net's set-based checks agree with the replay
        $drift = self::getContainer()->get(GetProgressionDrift::class)->find();
        self::assertSame([], array_values(array_intersect($playerIds, [...$drift->xpPlayerIds(), ...$drift->achievementPlayerIds()])), 'The safety net sees drift: ' . json_encode($drift->counts()));

        foreach ($playerIds as $playerId) {
            $this->recompute($playerId);
            self::assertSame($this->progressionState($playerId), $live[$playerId], 'Live wiring and a fresh recomputation disagree for ' . $playerId);
        }
    }

    /**
     * @return array{xp_total: int, level: int, per_solve: array<string, int>, badges: list<string>, achievement_points: int}
     */
    private function progressionState(string $playerId): array
    {
        /** @var array<string, int> $perSolve */
        $perSolve = [];

        // Solves that net to zero (deleted ones with their compensation) are history, not XP
        foreach (
            $this->database->fetchAllAssociative(
                'SELECT solving_time_id, SUM(amount) AS net FROM xp_entry WHERE player_id = :playerId GROUP BY solving_time_id HAVING SUM(amount) != 0 ORDER BY solving_time_id',
                ['playerId' => $playerId],
            ) as $row
        ) {
            self::assertIsString($row['solving_time_id']);
            $perSolve[$row['solving_time_id']] = self::int($row['net']);
        }

        /** @var list<string> $badges */
        $badges = $this->database->fetchFirstColumn(
            "SELECT type || ':' || COALESCE(CAST(tier AS TEXT), '-') FROM badge WHERE player_id = :playerId ORDER BY 1",
            ['playerId' => $playerId],
        );

        $player = $this->database->fetchAssociative('SELECT xp_total, level, achievement_points FROM player WHERE id = :id', ['id' => $playerId]);
        self::assertIsArray($player);

        return [
            'xp_total' => self::int($player['xp_total']),
            'level' => self::int($player['level']),
            'per_solve' => $perSolve,
            'badges' => $badges,
            'achievement_points' => self::int($player['achievement_points']),
        ];
    }

    private function assertTotalsMatchLedger(string $playerId): void
    {
        $sum = $this->database->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM xp_entry WHERE player_id = :id', ['id' => $playerId]);
        $xpTotal = $this->xpTotal($playerId);

        self::assertSame(self::int($sum), $xpTotal, 'player.xp_total must equal the ledger sum');
        self::assertSame(LevelTable::levelForXp($xpTotal), self::int($this->database->fetchOne('SELECT level FROM player WHERE id = :id', ['id' => $playerId])));

        $points = 0;
        foreach ($this->database->fetchFirstColumn('SELECT tier FROM badge WHERE player_id = :id', ['id' => $playerId]) as $tier) {
            $points += $tier === null ? BadgeTier::SINGLE_TIER_POINTS : BadgeTier::from(self::int($tier))->points();
        }

        self::assertSame($points, $this->achievementPoints($playerId), 'player.achievement_points must equal the achievements held');
    }

    private function xpTotal(string $playerId = self::PLAYER): int
    {
        return self::int($this->database->fetchOne('SELECT xp_total FROM player WHERE id = :id', ['id' => $playerId]));
    }

    private function achievementPoints(string $playerId = self::PLAYER): int
    {
        return self::int($this->database->fetchOne('SELECT achievement_points FROM player WHERE id = :id', ['id' => $playerId]));
    }

    /**
     * @return list<int>
     */
    private function tiersOf(string $type): array
    {
        return array_map(
            static fn (mixed $tier): int => self::int($tier),
            $this->database->fetchFirstColumn(
                'SELECT tier FROM badge WHERE player_id = :id AND type = :type ORDER BY tier',
                ['id' => self::PLAYER, 'type' => $type],
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function solveIdsOf(string $puzzleId): array
    {
        /** @var list<string> $ids */
        $ids = $this->database->fetchFirstColumn(
            'SELECT id FROM puzzle_solving_time WHERE player_id = :playerId AND puzzle_id = :puzzleId ORDER BY COALESCE(finished_at, tracked_at), id',
            ['playerId' => self::PLAYER, 'puzzleId' => $puzzleId],
        );

        return $ids;
    }

    private function linesInWeek(string $reason, DateTimeImmutable $monday): int
    {
        return self::int($this->database->fetchOne(
            'SELECT COUNT(*) FROM xp_entry e
             JOIN puzzle_solving_time pst ON pst.id = e.solving_time_id
             WHERE e.player_id = :playerId AND e.reason = :reason
               AND e.earned_at >= :from AND e.earned_at < :to',
            [
                'playerId' => self::PLAYER,
                'reason' => $reason,
                'from' => $monday->format('Y-m-d H:i:s'),
                'to' => $monday->modify('+7 days')->format('Y-m-d H:i:s'),
            ],
        ));
    }

    /**
     * @return list<string>
     */
    private function currentGroupCodes(string $timeId): array
    {
        $team = $this->database->fetchOne('SELECT team FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);

        if (!is_string($team)) {
            return [];
        }

        $codes = [];
        $decoded = json_decode($team, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $puzzlers = $decoded['puzzlers'] ?? [];
        self::assertIsArray($puzzlers);

        foreach ($puzzlers as $puzzler) {
            if (!is_array($puzzler) || ($puzzler['player_id'] ?? null) === self::PLAYER) {
                continue;
            }

            $code = $puzzler['player_code'] ?? null;
            $name = $puzzler['player_name'] ?? null;
            $codes[] = is_string($code) ? '#' . $code : (is_string($name) ? $name : '');
        }

        return $codes;
    }

    private static function int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        self::fail('Not a number: ' . get_debug_type($value));
    }
}
