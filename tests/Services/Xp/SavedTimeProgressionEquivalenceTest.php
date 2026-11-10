<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Xp;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Events\PuzzleSolvingTimeSuspicionChanged;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AddPuzzleTracking;
use SpeedPuzzling\Web\Message\AwardXpForSolvingTime;
use SpeedPuzzling\Web\Message\CompensateXpForDeletedSolve;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\RecalculateXpChainForSolve;
use SpeedPuzzling\Web\Message\RecalculateXpForPlayer;
use SpeedPuzzling\Web\Services\Xp\SavedTimeProgression;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestDouble\FixedXpLaunchReadiness;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Who settles a saved time's progression does not matter (docs/features/xp-levels/README.md §Surfaces): the queue
 * alone, the request right after the save, or the recap after the queue was done - in any order, any number of
 * times - leaves the same XP ledger, level, xp_solve_tier rows, achievements, Achievement Points and karma, and the
 * recap ends on the same text. Both writers are recomputations from the records under PlayerProgressionLock, so a
 * second run finds nothing to do. A real race (two connections) is serialized by that lock (PlayerProgressionLockTest);
 * here every interleaving of the two writers on one connection is played through.
 *
 * Each path starts from the same savepoint, so they all see the same records and the same clock.
 */
final class SavedTimeProgressionEquivalenceTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string PLAYER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string USER = PlayerFixture::PLAYER_WITH_STRIPE_USER_ID;
    private const string PARTNER = PlayerFixture::PLAYER_WITH_FAVORITES;

    private const array DRAINED = [
        AwardXpForSolvingTime::class,
        RecalculateXpChainForSolve::class,
        CompensateXpForDeletedSolve::class,
        RecalculateXpForPlayer::class,
        RecalculateBadgesForPlayer::class,
        PuzzleSolvingTimeSuspicionChanged::class,
    ];

    /** The path the save took until now: settled in the request, the queue's copies afterwards */
    private const string REFERENCE_PATH = 'request_then_queue';

    private const array PATHS = [
        'queue_only',
        'request_then_queue',
        'queue_then_recap',
        'queue_badges_then_recap_then_queue_xp',
        'queue_xp_then_recap_then_queue_badges',
        'recap_twice_then_queue',
        'queue_then_recap_twice',
    ];

    private KernelBrowser $browser;
    private MessageBusInterface $messageBus;
    private Connection $database;
    private EntityManagerInterface $entityManager;
    private InMemoryTransport $asyncTransport;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        // One container for the whole test: the savepoints and the services must outlive the recap's requests
        $this->browser->disableReboot();

        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->database = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->now = $container->get(ClockInterface::class)->now();

        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->asyncTransport = $transport;

        // Fixtures carry results without a ledger - start every player from their fresh recomputation
        foreach ([self::PLAYER, self::PARTNER, PlayerFixture::PLAYER_REGULAR] as $playerId) {
            $this->entityManager->clear();
            $this->messageBus->dispatch(new RecalculateXpForPlayer($playerId), [new TransportNamesStamp(['sync'])]);
            $this->messageBus->dispatch(new RecalculateBadgesForPlayer($playerId), [new TransportNamesStamp(['sync'])]);
        }

        $this->entityManager->clear();
        $this->asyncTransport->reset();
    }

    public function testSoloTimedFirstTry(): void
    {
        $state = $this->assertEveryPathEndsTheSame(
            fn (UuidInterface $id) => $this->timed($id, PuzzleFixture::PUZZLE_1000_04, '01:10:00', firstAttempt: true),
            viewers: [self::PLAYER],
        );

        self::assertNotSame([], $state[self::PLAYER]['solve_lines'], 'The save earned XP');
        self::assertStringContainsString('XP', $state[self::PLAYER]['recap']);
    }

    public function testTimedRepeat(): void
    {
        $this->settledByQueue(fn (UuidInterface $id) => $this->timed($id, PuzzleFixture::PUZZLE_1000_05, '01:30:00', finishedAt: $this->now->modify('-2 days')));

        $state = $this->assertEveryPathEndsTheSame(
            fn (UuidInterface $id) => $this->timed($id, PuzzleFixture::PUZZLE_1000_05, '01:20:00'),
            viewers: [self::PLAYER],
        );

        self::assertNotSame([], $state[self::PLAYER]['solve_lines']);
    }

    public function testRelaxFirst(): void
    {
        $state = $this->assertEveryPathEndsTheSame(
            fn (UuidInterface $id) => $this->relax($id, PuzzleFixture::PUZZLE_1000_03),
            viewers: [self::PLAYER],
        );

        self::assertNotSame([], $state[self::PLAYER]['solve_lines']);
    }

    public function testRelaxRepeatEarnsNothingOnPurposeInEveryPath(): void
    {
        $this->settledByQueue(fn (UuidInterface $id) => $this->relax($id, PuzzleFixture::PUZZLE_1500_02, $this->now->modify('-3 days')));

        $state = $this->assertEveryPathEndsTheSame(
            fn (UuidInterface $id) => $this->relax($id, PuzzleFixture::PUZZLE_1500_02),
            viewers: [self::PLAYER],
        );

        self::assertSame([], $state[self::PLAYER]['solve_lines'], 'A relax repeat is worth nothing');
        self::assertNotSame('', $state[self::PLAYER]['recap']);
        self::assertStringNotContainsString('Counting your XP', $state[self::PLAYER]['recap']);
    }

    public function testPairWithARegisteredPartner(): void
    {
        $state = $this->assertEveryPathEndsTheSame(
            fn (UuidInterface $id) => $this->timed($id, PuzzleFixture::PUZZLE_2000, '03:00:00', groupPlayers: ['#player3']),
            viewers: [self::PLAYER, self::PARTNER],
        );

        self::assertNotSame([], $state[self::PLAYER]['solve_lines']);
        self::assertNotSame([], $state[self::PARTNER]['solve_lines'], 'The partner earns with the pair');
        self::assertNotSame('', $state[self::PARTNER]['recap']);
    }

    public function testASaveThatEarnsATierAndALevel(): void
    {
        $before = $this->progression(self::PLAYER);

        // One 2000+ piece result in the fixtures; three more here, the save below makes five = Marathoner Bronze
        foreach ([PuzzleFixture::PUZZLE_3000, PuzzleFixture::PUZZLE_4000, PuzzleFixture::PUZZLE_5000] as $index => $puzzleId) {
            $this->settledByQueue(fn (UuidInterface $id) => $this->timed($id, $puzzleId, '08:00:00', finishedAt: $this->now->modify('-' . ($index + 2) . ' days')));
        }

        $beforeSave = $this->progression(self::PLAYER);
        self::assertNotContains('marathoner:1', $beforeSave['badges']);

        $state = $this->assertEveryPathEndsTheSame(
            fn (UuidInterface $id) => $this->timed($id, PuzzleFixture::PUZZLE_6000, '08:00:00'),
            viewers: [self::PLAYER],
        );

        self::assertContains('marathoner:1', $state[self::PLAYER]['badges'], 'The save earned a tier');
        self::assertGreaterThan($beforeSave['achievement_points'], $state[self::PLAYER]['achievement_points']);
        self::assertGreaterThan($before['level'], $state[self::PLAYER]['level'], 'The saves crossed a level');
    }

    public function testBeforeTheLaunchTheStoredProgressionIsTheSameAndTheRecapShowsNone(): void
    {
        FixedXpLaunchReadiness::$ready = false;

        $state = $this->assertEveryPathEndsTheSame(
            fn (UuidInterface $id) => $this->timed($id, PuzzleFixture::PUZZLE_1000_04, '01:10:00'),
            viewers: [self::PLAYER],
        );

        self::assertNotSame([], $state[self::PLAYER]['solve_lines'], 'Stored before the launch too');
        self::assertSame('', $state[self::PLAYER]['recap']);
    }

    public function testAnOptedOutPlayerGetsTheSameStoredProgressionAndNoRecap(): void
    {
        $this->database->executeStatement('UPDATE player SET experience_system_opted_out = true WHERE id = :id', ['id' => self::PLAYER]);

        $state = $this->assertEveryPathEndsTheSame(
            fn (UuidInterface $id) => $this->timed($id, PuzzleFixture::PUZZLE_1000_04, '01:10:00'),
            viewers: [self::PLAYER],
        );

        self::assertSame('', $state[self::PLAYER]['recap']);
    }

    /**
     * @param \Closure(UuidInterface): void $save
     * @param list<string> $viewers the participants whose recap is rendered (all of them are compared)
     * @return array<string, array{xp_total: int, level: int, per_solve: array<string, int>, solve_lines: list<string>, solve_tiers: list<string>, badges: list<string>, achievement_points: int, karma: int, recap: string}>
     */
    private function assertEveryPathEndsTheSame(\Closure $save, array $viewers): array
    {
        $timeId = Uuid::uuid7();
        $participants = array_values(array_unique([...$viewers, self::PLAYER]));
        $states = [];

        $this->database->executeStatement('SAVEPOINT equivalence_base');

        foreach (self::PATHS as $path) {
            $this->entityManager->clear();
            $this->asyncTransport->reset();

            $save($timeId);
            $this->settle($path, $timeId->toString());

            $state = [];
            foreach ($participants as $playerId) {
                $state[$playerId] = $this->progression($playerId, $timeId->toString());
                $state[$playerId]['recap'] = in_array($playerId, $viewers, true) ? $this->finalRecapText($playerId, $timeId->toString()) : '';
            }

            $states[$path] = $state;

            $this->database->executeStatement('ROLLBACK TO SAVEPOINT equivalence_base');
        }

        $this->entityManager->clear();
        $this->asyncTransport->reset();

        foreach (self::PATHS as $path) {
            self::assertSame($states[self::REFERENCE_PATH], $states[$path], sprintf('Path "%s" ends elsewhere than "%s"', $path, self::REFERENCE_PATH));
        }

        return $states[self::REFERENCE_PATH];
    }

    private function settle(string $path, string $timeId): void
    {
        $steps = match ($path) {
            'queue_only' => ['queue'],
            'request_then_queue' => ['recap', 'queue'],
            'queue_then_recap' => ['queue', 'recap'],
            'queue_badges_then_recap_then_queue_xp' => ['queue_badges', 'recap', 'queue'],
            'queue_xp_then_recap_then_queue_badges' => ['queue_xp', 'recap', 'queue'],
            'recap_twice_then_queue' => ['recap', 'recap', 'queue'],
            'queue_then_recap_twice' => ['queue', 'recap', 'recap'],
            default => self::fail('Unknown path ' . $path),
        };

        foreach ($steps as $step) {
            match ($step) {
                'queue' => $this->drain(),
                'queue_badges' => $this->handleQueued(RecalculateBadgesForPlayer::class),
                'queue_xp' => $this->handleQueued(AwardXpForSolvingTime::class),
                'recap' => $this->recapSettle($timeId),
            };
        }
    }

    /**
     * What the save's request ran until now, and what the recap runs when the queue is late: the tracker's
     * achievements, then the XP of the solve
     */
    private function recapSettle(string $timeId): void
    {
        $this->entityManager->clear();
        self::getContainer()->get(SavedTimeProgression::class)->settleNow(self::PLAYER, $timeId);
        $this->entityManager->clear();
    }

    /**
     * The recap as the participant finally sees it: the first render, and when that one asks to settle (a fresh
     * recap whose XP is not counted yet), the render after that action.
     */
    private function finalRecapText(string $playerId, string $timeId): string
    {
        TestingLogin::asPlayer($this->browser, $playerId);
        $this->entityManager->clear();

        $component = $this->createLiveComponent('XpRecapCelebration', ['solvingTimeId' => $timeId, 'celebrate' => true], $this->browser);
        $component->setRouteLocale('en');
        $crawler = $component->render()->crawler();

        $poll = $crawler->filter('[data-poll]');
        if ($poll->count() > 0 && str_ends_with((string) $poll->attr('data-poll'), '|settle')) {
            $component->call('settle');
            $crawler = $component->render()->crawler();
        }

        $receipt = $crawler->filter('#xp-solve-receipt');

        if ($receipt->count() === 0) {
            return '';
        }

        return trim((string) preg_replace('/\s+/', ' ', $receipt->text()));
    }

    /**
     * @param \Closure(UuidInterface): void $save
     */
    private function settledByQueue(\Closure $save): void
    {
        $save(Uuid::uuid7());
        $this->drain();
        $this->asyncTransport->reset();
    }

    /**
     * @param list<string> $groupPlayers
     */
    private function timed(
        UuidInterface $timeId,
        string $puzzleId,
        string $time,
        bool $firstAttempt = false,
        array $groupPlayers = [],
        null|DateTimeImmutable $finishedAt = null,
    ): void {
        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: self::USER,
            puzzleId: $puzzleId,
            competitionId: null,
            time: $time,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: $finishedAt ?? $this->now,
            firstAttempt: $firstAttempt,
            unboxed: false,
        ));
    }

    private function relax(UuidInterface $trackingId, string $puzzleId, null|DateTimeImmutable $finishedAt = null): void
    {
        $this->messageBus->dispatch(new AddPuzzleTracking(
            trackingId: $trackingId,
            userId: self::USER,
            puzzleId: $puzzleId,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: $finishedAt ?? $this->now,
        ));
    }

    /**
     * Handles the queued progression messages like the consumer: each on a fresh entity manager, until none is left
     */
    private function drain(): void
    {
        for ($pass = 0; $pass < 10; $pass++) {
            if ($this->handleQueued(...self::DRAINED) === false) {
                return;
            }
        }

        self::fail('The XP messages kept queueing each other');
    }

    /**
     * @param class-string ...$classes
     */
    private function handleQueued(string ...$classes): bool
    {
        $handled = false;
        $envelopes = $this->asyncTransport->getSent();
        $this->asyncTransport->reset();

        foreach ($envelopes as $envelope) {
            $message = $envelope->getMessage();

            if (in_array($message::class, $classes, true) === false) {
                // Not handled now - stays queued for a later step
                $this->asyncTransport->send(Envelope::wrap($message));

                continue;
            }

            $this->entityManager->clear();
            $this->messageBus->dispatch($message, [new ReceivedStamp('async')]);
            $handled = true;
        }

        $this->entityManager->clear();

        return $handled;
    }

    /**
     * @return array{xp_total: int, level: int, per_solve: array<string, int>, solve_lines: list<string>, solve_tiers: list<string>, badges: list<string>, achievement_points: int, karma: int}
     */
    private function progression(string $playerId, null|string $timeId = null): array
    {
        /** @var array<string, int> $perSolve */
        $perSolve = [];

        foreach (
            $this->database->fetchAllAssociative(
                'SELECT solving_time_id, SUM(amount) AS net FROM xp_entry WHERE player_id = :playerId GROUP BY solving_time_id HAVING SUM(amount) != 0 ORDER BY solving_time_id',
                ['playerId' => $playerId],
            ) as $row
        ) {
            $perSolve[(string) $row['solving_time_id']] = self::int($row['net']);
        }

        /** @var list<string> $solveLines */
        $solveLines = $timeId === null ? [] : $this->database->fetchFirstColumn(
            "SELECT reason || ':' || amount || ':' || in_weekly_delta || ':' || earned_at FROM xp_entry WHERE player_id = :playerId AND solving_time_id = :timeId ORDER BY 1",
            ['playerId' => $playerId, 'timeId' => $timeId],
        );

        /** @var list<string> $solveTiers */
        $solveTiers = $this->database->fetchFirstColumn(
            "SELECT solving_time_id || ':' || puzzle_id || ':' || difficulty_tier FROM xp_solve_tier WHERE player_id = :playerId ORDER BY 1",
            ['playerId' => $playerId],
        );

        /** @var list<string> $badges */
        $badges = $this->database->fetchFirstColumn(
            "SELECT type || ':' || COALESCE(CAST(tier AS TEXT), '-') FROM badge WHERE player_id = :playerId ORDER BY 1",
            ['playerId' => $playerId],
        );

        $player = $this->database->fetchAssociative('SELECT xp_total, level, achievement_points, karma FROM player WHERE id = :id', ['id' => $playerId]);
        self::assertIsArray($player);

        $ledger = $this->database->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM xp_entry WHERE player_id = :id', ['id' => $playerId]);
        self::assertSame(self::int($ledger), self::int($player['xp_total']), 'player.xp_total must equal the ledger sum');

        return [
            'xp_total' => self::int($player['xp_total']),
            'level' => self::int($player['level']),
            'per_solve' => $perSolve,
            'solve_lines' => $solveLines,
            'solve_tiers' => $solveTiers,
            'badges' => $badges,
            'achievement_points' => self::int($player['achievement_points']),
            'karma' => self::int($player['karma']),
        ];
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
