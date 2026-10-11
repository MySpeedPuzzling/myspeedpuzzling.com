<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\PlanContentDigestRuns;
use SpeedPuzzling\Web\Message\SendPlayerContentDigest;
use SpeedPuzzling\Web\MessageHandler\PlanContentDigestRunsHandler;
use SpeedPuzzling\Web\Query\GetContentDigestRecipients;
use SpeedPuzzling\Web\Repository\ContentDigestRunRepository;
use SpeedPuzzling\Web\Results\ContentDigestRunPlan;
use SpeedPuzzling\Web\Services\Digest\DigestPacing;
use SpeedPuzzling\Web\Services\Digest\MissYouHoldout;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Tests\TestDouble\XpLaunchReadinessStub;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\ContentDigestKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Planning a Monday / Tuesday (docs/features/content-digest/README.md §"Sending").
 */
final class PlanContentDigestRunsHandlerTest extends KernelTestCase
{
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
        $this->database->executeStatement('DELETE FROM puzzle_solving_time WHERE tracked_at >= :from', ['from' => '2030-01-01']);
    }

    public function testMondayQueuesTheWeekMostEngagedFirstSpacedOverTheDay(): void
    {
        $this->insertSolve(PlayerFixture::PLAYER_REGULAR, '2030-03-05 12:00:00');
        $this->insertSolve(PlayerFixture::PLAYER_WITH_STRIPE, '2030-03-06 12:00:00');
        $this->seenOn(PlayerFixture::PLAYER_WITH_STRIPE, '2030-03-10');
        $this->seenOn(PlayerFixture::PLAYER_REGULAR, '2030-03-01');

        $plans = $this->plan(ContentDigestKind::Activity, '2030-03-11 07:01');

        // 11 March 2030 is also a two-week send day (2026-10-12 + 178 weeks): its run has nobody with that frequency
        self::assertSame(['weekly' => 'planned', 'every_two_weeks' => 'planned', 'monthly' => 'stale'], $this->outcomes($plans));
        self::assertSame('2030-W10', $plans[0]->periodKey);
        self::assertSame('2030-W09-W10', $plans[1]->periodKey);
        self::assertSame(0, $plans[1]->recipients);
        self::assertSame(2, $plans[0]->recipients);

        $queued = $this->queued();
        self::assertCount(2, $queued);
        $first = $queued[0]->getMessage();
        self::assertInstanceOf(SendPlayerContentDigest::class, $first);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $first->playerId, 'Seen most recently = first');
        self::assertSame(ContentDigestKind::Activity, $first->kind);

        $delays = array_map(static fn (Envelope $envelope): int => $envelope->last(DelayStamp::class)?->getDelay() ?? -1, $queued);
        self::assertSame(0, $delays[0]);
        self::assertSame(21_570_000, $delays[1], '11 h 59 min shared by two');

        self::assertSame(1, $this->database->fetchOne("SELECT COUNT(*) FROM content_digest_run WHERE period_key = '2030-W10' AND recipients = 2"));
    }

    public function testNothingIsPlannedBeforeTheFirstDigestPeriod(): void
    {
        // Monday 12 October 2026: the week before is the launch pause
        $plans = $this->plan(ContentDigestKind::Activity, '2026-10-12 07:01');

        self::assertSame(['weekly' => 'paused', 'every_two_weeks' => 'paused', 'monthly' => 'paused'], $this->outcomes($plans));
        self::assertSame([], $this->queued());
    }

    public function testTheFirstSendIsMondayNineteenthOctober(): void
    {
        $plans = $this->plan(ContentDigestKind::Activity, '2026-10-19 07:01', dryRun: true);

        self::assertSame('2026-W42', $plans[0]->periodKey);
        self::assertSame('dry_run', $plans[0]->outcome);
        self::assertSame([], $this->queued(), 'A dry run queues nothing');
    }

    public function testNothingIsPlannedWhileTheLaunchSwitchIsOff(): void
    {
        $this->insertSolve(PlayerFixture::PLAYER_REGULAR, '2030-03-05 12:00:00');

        $plans = $this->plan(ContentDigestKind::Activity, '2030-03-11 07:01', launchReady: false);

        self::assertSame(['weekly' => 'not_ready', 'every_two_weeks' => 'not_ready', 'monthly' => 'not_ready'], $this->outcomes($plans));
        self::assertSame([], $this->queued());
    }

    public function testTheFirstMissYouRunIsTuesdayTwentySeventhOctober(): void
    {
        $before = $this->plan(ContentDigestKind::MissYou, '2026-10-20 07:01', dryRun: true);
        self::assertSame(['weekly' => 'paused', 'every_two_weeks' => 'paused', 'monthly' => 'paused'], $this->outcomes($before));

        $first = $this->plan(ContentDigestKind::MissYou, '2026-10-27 07:01', dryRun: true);
        self::assertSame('dry_run', $first[0]->outcome);
        self::assertSame('miss-you-2026-10-27', $first[0]->periodKey);
    }

    public function testMissYouIsQueuedMostRecentlyActiveFirstAndCanBeCapped(): void
    {
        $this->onlyResult(PlayerFixture::PLAYER_REGULAR, '2030-01-20 12:00:00');
        $this->onlyResult(PlayerFixture::PLAYER_WITH_STRIPE, '2030-02-01 12:00:00');
        $this->onlyResult(PlayerFixture::PLAYER_ADMIN, '2029-12-01 12:00:00');

        $plans = $this->plan(ContentDigestKind::MissYou, '2030-03-12 07:01', maxRecipients: 2);

        self::assertSame(2, array_sum(array_map(static fn (ContentDigestRunPlan $plan): int => $plan->recipients, $plans)));
        $queued = array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $this->queued());
        self::assertCount(2, $queued);
        self::assertInstanceOf(SendPlayerContentDigest::class, $queued[0]);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $queued[0]->playerId, 'Most recently active first');
        self::assertSame(ContentDigestKind::MissYou, $queued[0]->kind);
        self::assertSame('miss-you-2030-03-12', $queued[0]->periodKey);
    }

    public function testMissYouCanBeSlicedByRecencyAndNeverReachesDormantAccounts(): void
    {
        $this->onlyResult(PlayerFixture::PLAYER_REGULAR, '2030-01-20 12:00:00');      // 7 weeks
        $this->onlyResult(PlayerFixture::PLAYER_WITH_STRIPE, '2029-07-01 12:00:00'); // 8 months
        $this->onlyResult(PlayerFixture::PLAYER_ADMIN, '2028-09-01 12:00:00');       // 18 months, visited last month
        $this->seenOn(PlayerFixture::PLAYER_ADMIN, '2030-02-10');
        $this->onlyResult(PlayerFixture::PLAYER_PRIVATE, '2028-01-01 12:00:00');     // dormant - never
        $now = new DateTimeImmutable('2030-03-12 07:01', new DateTimeZone('Europe/Prague'));

        $all = $this->plan(ContentDigestKind::MissYou, '2030-03-12 07:01', dryRun: true);
        self::assertSame(['28d-3m' => 1, '3-9m' => 1, 'older-visited' => 1], $all[0]->buckets);

        $firstSlice = $this->plan(ContentDigestKind::MissYou, '2030-03-12 07:01', dryRun: true, lastActivityAfter: $now->modify('-3 months'));
        self::assertSame(['28d-3m' => 1, '3-9m' => 0, 'older-visited' => 0], $firstSlice[0]->buckets);

        $visitors = $this->plan(ContentDigestKind::MissYou, '2030-03-12 07:01', dryRun: true, lastActivityBefore: $now->modify('-9 months'));
        self::assertSame(1, $visitors[0]->recipients);
        self::assertSame(['28d-3m' => 0, '3-9m' => 0, 'older-visited' => 1], $visitors[0]->buckets);
    }

    public function testTheHoldoutIsQueuedWithoutTakingAPacingSlot(): void
    {
        $this->onlyResult(PlayerFixture::PLAYER_REGULAR, '2030-01-20 12:00:00');
        $this->onlyResult(PlayerFixture::PLAYER_WITH_STRIPE, '2030-02-01 12:00:00');

        $plans = $this->plan(ContentDigestKind::MissYou, '2030-03-12 07:01', holdoutPercent: 100);

        self::assertSame(2, $plans[0]->recipients);
        self::assertSame(2, $plans[0]->heldOut);
        $delays = array_map(static fn (Envelope $envelope): null|int => $envelope->last(DelayStamp::class)?->getDelay(), $this->queued());
        self::assertSame([null, null], $delays);
    }

    public function testTheFirstMondayOfTheMonthPlansTheMonth(): void
    {
        // Monday 4 March 2030: the first Monday of March, not a two-week send day (2026-10-12 + 177 weeks)
        $plans = $this->plan(ContentDigestKind::Activity, '2030-03-04 07:01', dryRun: true);

        self::assertSame(['weekly' => 'dry_run', 'every_two_weeks' => 'stale', 'monthly' => 'dry_run'], $this->outcomes($plans));
        self::assertSame(['2030-W09', '2030-W07-W08', '2030-02'], array_map(static fn (ContentDigestRunPlan $plan): string => $plan->periodKey, $plans));
    }

    /**
     * @return list<ContentDigestRunPlan>
     */
    private function plan(ContentDigestKind $kind, string $praguetime, bool $dryRun = false, bool $launchReady = true, null|int $maxRecipients = null, null|DateTimeImmutable $lastActivityAfter = null, null|DateTimeImmutable $lastActivityBefore = null, int $holdoutPercent = 0): array
    {
        $container = self::getContainer();
        $handler = new PlanContentDigestRunsHandler(
            getContentDigestRecipients: $container->get(GetContentDigestRecipients::class),
            runRepository: $container->get(ContentDigestRunRepository::class),
            pacing: new DigestPacing(),
            messageBus: $container->get(MessageBusInterface::class),
            clock: new MockClock(new DateTimeImmutable($praguetime, new DateTimeZone('Europe/Prague'))),
            xpFeatureGate: new XpFeatureGate(new XpLaunchReadinessStub($launchReady)),
            missYouHoldout: new MissYouHoldout($holdoutPercent),
        );

        $plans = $handler(new PlanContentDigestRuns($kind, $dryRun, $maxRecipients, $lastActivityAfter, $lastActivityBefore));
        $container->get('doctrine.orm.entity_manager')->flush();

        return $plans;
    }

    /**
     * @param list<ContentDigestRunPlan> $plans
     * @return array<string, string>
     */
    private function outcomes(array $plans): array
    {
        $outcomes = [];

        foreach ($plans as $plan) {
            $outcomes[$plan->frequency->value] = $plan->outcome;
        }

        return $outcomes;
    }

    /**
     * @return list<Envelope>
     */
    private function queued(): array
    {
        $transport = self::getContainer()->get('messenger.transport.content_digest_emails');
        assert($transport instanceof InMemoryTransport);

        return iterator_to_array($transport->getSent(), false);
    }

    private function seenOn(string $playerId, string $day): void
    {
        $this->database->executeStatement(
            'INSERT INTO player_activity_day (id, player_id, day, first_seen_at) VALUES (:id, :playerId, :day, NOW()) ON CONFLICT DO NOTHING',
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'day' => $day],
        );
    }

    private function onlyResult(string $playerId, string $at): void
    {
        $this->database->executeStatement('DELETE FROM puzzle_solving_time WHERE player_id = :id', ['id' => $playerId]);
        $this->database->executeStatement('DELETE FROM puzzling_team_member WHERE player_id = :id', ['id' => $playerId]);
        $this->insertSolve($playerId, $at);
    }

    private function insertSolve(string $playerId, string $at): void
    {
        $this->database->executeStatement(
            "INSERT INTO puzzle_solving_time
                (id, seconds_to_solve, player_id, puzzle_id, tracked_at, verified, team, finished_at,
                 comment, finished_puzzle_photo, first_attempt, unboxed, puzzlers_count, puzzling_type,
                 suspicious, pieces_count_snapshot)
             VALUES
                (:id, 3600, :playerId, :puzzleId, :at, true, NULL, :at,
                 NULL, NULL, false, false, 1, 'solo', false, 500)",
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'puzzleId' => PuzzleFixture::PUZZLE_500_04, 'at' => $at],
        );
    }
}
