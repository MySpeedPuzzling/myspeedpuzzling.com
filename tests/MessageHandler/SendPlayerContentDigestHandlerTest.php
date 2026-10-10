<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\SendPlayerContentDigest;
use SpeedPuzzling\Web\MessageHandler\SendPlayerContentDigestHandler;
use SpeedPuzzling\Web\Query\GetContentDigestRecipients;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Repository\ContentDigestLogRepository;
use SpeedPuzzling\Web\Repository\ContentDigestRunRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\ContentDigestRunCounts;
use SpeedPuzzling\Web\Services\ContentDigestUnsubscribeUrl;
use SpeedPuzzling\Web\Services\Digest\PlayerPeriodActivityReader;
use SpeedPuzzling\Web\Services\EmailPreferencesLinkGenerator;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryLogger;
use SpeedPuzzling\Web\Tests\TestDouble\TransportSpy;
use SpeedPuzzling\Web\Value\ContentDigestKind;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One activity digest / no-activity e-mail (docs/features/content-digest/README.md §"Sending"). The period is far in
 * the future (2030-W10, Monday 4 - Sunday 10 March 2030), so fixture results never fall into it; the clock is the
 * Monday after it, 08:00 Prague.
 */
final class SendPlayerContentDigestHandlerTest extends KernelTestCase
{
    private const string PERIOD = '2030-W10';
    private const string MEMBER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string FREE_PLAYER = PlayerFixture::PLAYER_REGULAR;

    private Connection $database;
    private MockClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
        $this->clock = new MockClock(new DateTimeImmutable('2030-03-11 08:00:00', new DateTimeZone('Europe/Prague')));
    }

    public function testTheActivityDigestIsSentLoggedAndCounted(): void
    {
        $this->insertSolve(self::MEMBER, '2030-03-06 18:00:00');
        $runId = $this->insertRun();

        $transport = new TransportSpy();
        ($this->handler($transport))(new SendPlayerContentDigest(self::MEMBER, self::PERIOD, ContentDigestKind::Activity, $runId));

        self::assertCount(1, $transport->sent);
        $email = $transport->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame('emails/content_digest_activity.html.twig', $email->getHtmlTemplate());
        self::assertSame('Your week on MySpeedPuzzling', $email->getSubject());
        self::assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());
        self::assertSame('notifications', $email->getHeaders()->get('X-Transport')?->getBodyAsString());
        self::assertSame('bulk', $email->getHeaders()->get('Precedence')?->getBodyAsString());

        self::assertSame(['status' => 'sent', 'had_activity' => true], $this->logRow(self::MEMBER));
        self::assertSame(1, $this->runCounts($runId)->sent);
    }

    public function testTheEmailLinksEveryLineAndItsTextPartKeepsTheLinks(): void
    {
        $this->insertSolve(self::MEMBER, '2030-03-06 18:00:00');

        $transport = new TransportSpy();
        ($this->handler($transport))(new SendPlayerContentDigest(self::MEMBER, self::PERIOD, ContentDigestKind::Activity, $this->insertRun()));

        $email = $transport->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        $this->render($email);
        $html = (string) $email->getHtmlBody();
        $text = (string) $email->getTextBody();

        self::assertStringContainsString('/en/my/activity/2030-W10', $html, 'The button opens the "Your week" page');
        self::assertStringContainsString('/en/puzzle/' . PuzzleFixture::PUZZLE_500_04, $html, 'A result links its puzzle');
        self::assertStringContainsString('/en/email-preferences/', $html, 'Settings = the token preferences page, no sign-in');
        self::assertStringContainsString('/activity-digest/unsubscribe/' . self::MEMBER, $html);
        self::assertMatchesRegularExpression('/4\s*–\s*10 March/u', html_entity_decode($html), 'The period, in the e-mail\'s locale');
        self::assertStringNotContainsString(']1,Inf[', $html, 'A plural message rendered raw');

        self::assertStringContainsString('/en/my/activity/2030-W10', $text);
        self::assertStringContainsString('/en/email-preferences/', $text);
    }

    public function testANonMemberIsToldOnlyThatAnAchievementWasEarned(): void
    {
        $this->insertSolve(self::FREE_PLAYER, '2030-03-06 18:00:00');
        $this->insertBadge(self::FREE_PLAYER, '2030-03-06 18:00:01');

        $transport = new TransportSpy();
        ($this->handler($transport))(new SendPlayerContentDigest(self::FREE_PLAYER, self::PERIOD, ContentDigestKind::Activity, $this->insertRun()));

        $email = $transport->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        $this->render($email);
        $html = (string) $email->getHtmlBody();

        self::assertStringContainsString('Become a member to find out which', $html);
        self::assertStringContainsString('/en/membership', $html);
        self::assertStringNotContainsString('/img/badges/', $html, 'Which achievement is a member thing');
    }

    public function testAMemberSeesTheAchievementWithItsPoints(): void
    {
        $this->insertSolve(self::MEMBER, '2030-03-06 18:00:00');
        $this->insertBadge(self::MEMBER, '2030-03-06 18:00:01');

        $transport = new TransportSpy();
        ($this->handler($transport))(new SendPlayerContentDigest(self::MEMBER, self::PERIOD, ContentDigestKind::Activity, $this->insertRun()));

        $email = $transport->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        $this->render($email);
        $html = (string) $email->getHtmlBody();

        self::assertStringContainsString('/en/achievements/puzzles_solved', $html);
        self::assertStringContainsString(' AP', $html);
    }

    public function testNobodyGetsADigestForAPeriodWithoutActivity(): void
    {
        $transport = new TransportSpy();
        ($this->handler($transport))(new SendPlayerContentDigest(self::FREE_PLAYER, self::PERIOD, ContentDigestKind::Activity, $this->insertRun()));

        self::assertCount(0, $transport->sent);
        self::assertNull($this->logRow(self::FREE_PLAYER));
    }

    public function testWeMissYouGoesOncePerInactiveSpell(): void
    {
        $this->onlyResult(self::FREE_PLAYER, '2030-02-01 18:00:00');
        $this->clock = new MockClock(new DateTimeImmutable('2030-03-12 08:00:00', new DateTimeZone('Europe/Prague')));

        $transport = new TransportSpy();
        ($this->handler($transport))(new SendPlayerContentDigest(self::FREE_PLAYER, 'miss-you-2030-03-12', ContentDigestKind::MissYou, $this->insertRun()));

        self::assertCount(1, $transport->sent);
        $email = $transport->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame('emails/content_digest_miss_you.html.twig', $email->getHtmlTemplate());
        self::assertSame('Your puzzles miss you', $email->getSubject());
        self::assertNotNull($email->getHeaders()->get('List-Unsubscribe'), 'Rides on the activity e-mail and its unsubscribe');
        self::assertSame(['status' => 'sent', 'had_activity' => false], $this->logRow(self::FREE_PLAYER, 'miss-you:2030-02-01T18:00:00'));

        $this->render($email);
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('/en/puzzle-add', $html, 'Log the times they forgot');
        self::assertStringContainsString('/en/what-to-solve-next', $html);
        self::assertStringContainsString('/activity-digest/unsubscribe/' . self::FREE_PLAYER, $html);
        self::assertStringContainsString('/en/email-preferences/', $html);

        // The next Tuesday: still quiet, already told
        $this->clock = new MockClock(new DateTimeImmutable('2030-03-19 08:00:00', new DateTimeZone('Europe/Prague')));
        $nextWeek = new TransportSpy();
        ($this->handler($nextWeek))(new SendPlayerContentDigest(self::FREE_PLAYER, 'miss-you-2030-03-19', ContentDigestKind::MissYou, $this->insertRun()));
        self::assertCount(0, $nextWeek->sent);
    }

    public function testNobodyActiveInTheLastFourWeeksIsMissed(): void
    {
        $this->onlyResult(self::FREE_PLAYER, '2030-02-20 18:00:00');
        $this->clock = new MockClock(new DateTimeImmutable('2030-03-12 08:00:00', new DateTimeZone('Europe/Prague')));

        $transport = new TransportSpy();
        ($this->handler($transport))(new SendPlayerContentDigest(self::FREE_PLAYER, 'miss-you-2030-03-12', ContentDigestKind::MissYou, $this->insertRun()));

        self::assertCount(0, $transport->sent);
    }

    public function testWeMissYouNeverRunsBeforeTwentiethOctober(): void
    {
        $this->onlyResult(self::FREE_PLAYER, '2026-08-01 18:00:00');
        $this->clock = new MockClock(new DateTimeImmutable('2026-10-13 08:00:00', new DateTimeZone('Europe/Prague')));

        $transport = new TransportSpy();
        ($this->handler($transport))(new SendPlayerContentDigest(self::FREE_PLAYER, 'miss-you-2026-10-13', ContentDigestKind::MissYou, $this->insertRun()));
        self::assertCount(0, $transport->sent);

        // A stale message of a valid run is dropped too
        $this->clock = new MockClock(new DateTimeImmutable('2026-10-22 08:00:00', new DateTimeZone('Europe/Prague')));
        ($this->handler($transport))(new SendPlayerContentDigest(self::FREE_PLAYER, 'miss-you-2026-10-20', ContentDigestKind::MissYou, $this->insertRun()));
        self::assertCount(0, $transport->sent);
    }

    public function testASwitchedOffPlayerIsNotMissed(): void
    {
        $this->onlyResult(self::FREE_PLAYER, '2030-02-01 18:00:00');
        $this->database->executeStatement("UPDATE player SET content_digest_frequency = 'off' WHERE id = :id", ['id' => self::FREE_PLAYER]);
        $this->clock = new MockClock(new DateTimeImmutable('2030-03-12 08:00:00', new DateTimeZone('Europe/Prague')));

        $transport = new TransportSpy();
        ($this->handler($transport))(new SendPlayerContentDigest(self::FREE_PLAYER, 'miss-you-2030-03-12', ContentDigestKind::MissYou, $this->insertRun()));

        self::assertCount(0, $transport->sent);
    }

    public function testNothingIsSentWhileTheLaunchSwitchIsOff(): void
    {
        $this->insertSolve(self::MEMBER, '2030-03-06 18:00:00');

        $transport = new TransportSpy();
        ($this->handler($transport, launchReady: false))(new SendPlayerContentDigest(self::MEMBER, self::PERIOD, ContentDigestKind::Activity, $this->insertRun()));

        self::assertCount(0, $transport->sent);
        self::assertNull($this->logRow(self::MEMBER));
    }

    public function testAPeriodBeforeTheFirstDigestPeriodIsNeverSent(): void
    {
        // 2026-W41 = 5-11 October 2026, the launch pause; the clock on its send day
        $this->clock = new MockClock(new DateTimeImmutable('2026-10-12 08:00:00', new DateTimeZone('Europe/Prague')));
        $this->insertSolve(self::MEMBER, '2026-10-08 18:00:00');

        $transport = new TransportSpy();
        ($this->handler($transport))(new SendPlayerContentDigest(self::MEMBER, '2026-W41', ContentDigestKind::Activity, $this->insertRun()));

        self::assertCount(0, $transport->sent);
    }

    public function testAStaleMessageIsDropped(): void
    {
        $this->insertSolve(self::MEMBER, '2030-03-06 18:00:00');
        $this->clock = new MockClock(new DateTimeImmutable('2030-03-14 00:00:00', new DateTimeZone('Europe/Prague')));

        $transport = new TransportSpy();
        ($this->handler($transport))(new SendPlayerContentDigest(self::MEMBER, self::PERIOD, ContentDigestKind::Activity, $this->insertRun()));

        self::assertCount(0, $transport->sent);
    }

    public function testAPlayerWhoChangedTheFrequencyOrSwitchedItOffGetsNothing(): void
    {
        $this->insertSolve(self::MEMBER, '2030-03-06 18:00:00');
        $transport = new TransportSpy();

        foreach (['monthly', 'off'] as $frequency) {
            $this->database->executeStatement('UPDATE player SET content_digest_frequency = :frequency WHERE id = :id', ['frequency' => $frequency, 'id' => self::MEMBER]);
            ($this->handler($transport))(new SendPlayerContentDigest(self::MEMBER, self::PERIOD, ContentDigestKind::Activity, $this->insertRun()));
        }

        self::assertCount(0, $transport->sent);
    }

    public function testASecondDeliveryOfTheSameMessageSendsNothing(): void
    {
        $this->insertSolve(self::MEMBER, '2030-03-06 18:00:00');
        $runId = $this->insertRun();
        $transport = new TransportSpy();
        $message = new SendPlayerContentDigest(self::MEMBER, self::PERIOD, ContentDigestKind::Activity, $runId);

        ($this->handler($transport))($message);
        ($this->handler($transport))($message);

        self::assertCount(1, $transport->sent);
    }

    public function testAPermanentRecipientFailureIsLoggedAndNeverRetried(): void
    {
        $this->insertSolve(self::MEMBER, '2030-03-06 18:00:00');
        $runId = $this->insertRun();

        $transport = new TransportSpy(new UnexpectedResponseException('550 mailbox unavailable', 550));
        ($this->handler($transport))(new SendPlayerContentDigest(self::MEMBER, self::PERIOD, ContentDigestKind::Activity, $runId));

        self::assertSame(['status' => 'failed_permanent', 'had_activity' => true], $this->logRow(self::MEMBER));
        self::assertSame(1, $this->runCounts($runId)->failedPermanently);
        self::assertSame([], $this->queuedDelays());
    }

    public function testATemporaryFailureIsDeferredAndRetriedLater(): void
    {
        $this->insertSolve(self::MEMBER, '2030-03-06 18:00:00');
        $runId = $this->insertRun();

        $transport = new TransportSpy(new UnexpectedResponseException('421 try again later', 421));
        ($this->handler($transport))(new SendPlayerContentDigest(self::MEMBER, self::PERIOD, ContentDigestKind::Activity, $runId));

        self::assertSame(['status' => 'deferred', 'had_activity' => true], $this->logRow(self::MEMBER));
        self::assertSame(1, $this->runCounts($runId)->deferred);
        self::assertSame([600_000], $this->queuedDelays(), 'Our own retry, 10 minutes later');

        // The retry takes the deferred row over
        $retry = new TransportSpy();
        ($this->handler($retry))(new SendPlayerContentDigest(self::MEMBER, self::PERIOD, ContentDigestKind::Activity, $runId, attempt: 1));
        self::assertCount(1, $retry->sent);
        self::assertSame(['status' => 'sent', 'had_activity' => true], $this->logRow(self::MEMBER));
    }

    public function testTheLastAttemptIsNotRetried(): void
    {
        $this->insertSolve(self::MEMBER, '2030-03-06 18:00:00');

        $transport = new TransportSpy(new TransportException('Connection refused'));
        ($this->handler($transport))(new SendPlayerContentDigest(self::MEMBER, self::PERIOD, ContentDigestKind::Activity, $this->insertRun(), attempt: SendPlayerContentDigestHandler::MAX_ATTEMPTS - 1));

        self::assertSame(['status' => 'deferred', 'had_activity' => true], $this->logRow(self::MEMBER));
        self::assertSame([], $this->queuedDelays());
    }

    public function testTheCircuitBreakerStopsTheRunWithOneWarning(): void
    {
        $this->insertSolve(self::MEMBER, '2030-03-06 18:00:00');
        $this->insertSolve(self::FREE_PLAYER, '2030-03-06 18:00:00');
        // 19 failures and 100 sent so far: the next failure is the 20th and 20 / 120 > 5 %
        $runId = $this->insertRun(sent: 100, deferred: 19);
        $logger = new InMemoryLogger();

        $failing = new TransportSpy(new UnexpectedResponseException('451 throttled', 451));
        ($this->handler($failing, logger: $logger))(new SendPlayerContentDigest(self::MEMBER, self::PERIOD, ContentDigestKind::Activity, $runId));

        self::assertTrue($logger->hasRecord('warning', 'circuit breaker'));
        self::assertSame([], $this->queuedDelays(), 'No retry once the run is stopped');

        // The rest of the run is dropped without sending
        $healthy = new TransportSpy();
        ($this->handler($healthy))(new SendPlayerContentDigest(self::FREE_PLAYER, self::PERIOD, ContentDigestKind::Activity, $runId));
        self::assertCount(0, $healthy->sent);
        self::assertNull($this->logRow(self::FREE_PLAYER), 'A later run of the period may still send to them');
    }

    public function testTheBreakerNeedsBothEnoughFailuresAndAShare(): void
    {
        self::assertFalse(SendPlayerContentDigestHandler::breakerTrips(new ContentDigestRunCounts(sent: 0, failedPermanently: 0, deferred: 19)));
        self::assertTrue(SendPlayerContentDigestHandler::breakerTrips(new ContentDigestRunCounts(sent: 300, failedPermanently: 5, deferred: 15)));
        self::assertFalse(SendPlayerContentDigestHandler::breakerTrips(new ContentDigestRunCounts(sent: 1000, failedPermanently: 10, deferred: 15)));
        // Stricter for "We miss you": 10 failures, 3 %
        self::assertTrue(SendPlayerContentDigestHandler::breakerTrips(new ContentDigestRunCounts(sent: 300, failedPermanently: 10, deferred: 0), ContentDigestKind::MissYou));
        self::assertFalse(SendPlayerContentDigestHandler::breakerTrips(new ContentDigestRunCounts(sent: 300, failedPermanently: 9, deferred: 0), ContentDigestKind::MissYou));
    }

    /**
     * What the mailer does before SMTP: Twig in the e-mail's locale, Inky, the text part.
     */
    private function render(TemplatedEmail $email): void
    {
        self::getContainer()->get('twig.mime_body_renderer')->render($email);
    }

    private function handler(TransportInterface $transport, bool $launchReady = true, null|InMemoryLogger $logger = null): SendPlayerContentDigestHandler
    {
        $container = self::getContainer();

        return new SendPlayerContentDigestHandler(
            playerRepository: $container->get(PlayerRepository::class),
            getPlayerProfile: $container->get(GetPlayerProfile::class),
            getContentDigestRecipients: $container->get(GetContentDigestRecipients::class),
            logRepository: $container->get(ContentDigestLogRepository::class),
            runRepository: $container->get(ContentDigestRunRepository::class),
            activityReader: $container->get(PlayerPeriodActivityReader::class),
            transport: $transport,
            translator: $container->get(TranslatorInterface::class),
            unsubscribeUrl: $container->get(ContentDigestUnsubscribeUrl::class),
            emailPreferencesLinkGenerator: $container->get(EmailPreferencesLinkGenerator::class),
            messageBus: $container->get(MessageBusInterface::class),
            clock: $this->clock,
            logger: $logger ?? new NullLogger(),
            playerAccountEmail: $container->get(PlayerAccountEmail::class),
            xpFeatureGate: new XpFeatureGate(adminOnly: $launchReady === false),
        );
    }

    /**
     * @return null|array{status: string, had_activity: bool}
     */
    private function logRow(string $playerId, string $periodKey = self::PERIOD): null|array
    {
        /** @var false|array{status: string, had_activity: bool} $row */
        $row = $this->database->fetchAssociative(
            'SELECT status, had_activity FROM content_digest_log WHERE player_id = :playerId AND period_key = :periodKey',
            ['playerId' => $playerId, 'periodKey' => $periodKey],
        );

        return $row === false ? null : $row;
    }

    private function runCounts(string $runId): ContentDigestRunCounts
    {
        /** @var array{sent: int, failed_permanently: int, deferred: int} $row */
        $row = $this->database->fetchAssociative('SELECT sent, failed_permanently, deferred FROM content_digest_run WHERE id = :id', ['id' => $runId]);

        return new ContentDigestRunCounts($row['sent'], $row['failed_permanently'], $row['deferred']);
    }

    /**
     * @return list<int>
     */
    private function queuedDelays(): array
    {
        $transport = self::getContainer()->get('messenger.transport.content_digest_emails');
        assert($transport instanceof InMemoryTransport);

        return array_map(
            static fn ($envelope): int => $envelope->last(DelayStamp::class)?->getDelay() ?? 0,
            iterator_to_array($transport->getSent(), false),
        );
    }

    private function insertRun(int $sent = 0, int $deferred = 0): string
    {
        $id = Uuid::uuid7()->toString();

        $this->database->executeStatement(
            "INSERT INTO content_digest_run (id, kind, frequency, period_key, recipients, started_at, spacing_seconds, sent, failed_permanently, deferred)
             VALUES (:id, 'activity', 'weekly', :periodKey, 1000, NOW(), 4, :sent, 0, :deferred)",
            ['id' => $id, 'periodKey' => self::PERIOD, 'sent' => $sent, 'deferred' => $deferred],
        );

        return $id;
    }

    private function insertBadge(string $playerId, string $earnedAtUtc): void
    {
        $this->database->executeStatement(
            "INSERT INTO badge (id, player_id, type, tier, earned_at) VALUES (:id, :playerId, 'puzzles_solved', 1, :earnedAt)",
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'earnedAt' => $earnedAtUtc],
        );
    }

    private function onlyResult(string $playerId, string $atUtc): void
    {
        $this->database->executeStatement('DELETE FROM puzzle_solving_time WHERE player_id = :id', ['id' => $playerId]);
        $this->database->executeStatement('DELETE FROM puzzling_team_member WHERE player_id = :id', ['id' => $playerId]);
        $this->insertSolve($playerId, $atUtc);
    }

    private function insertSolve(string $playerId, string $atUtc): void
    {
        $this->database->executeStatement(
            'INSERT INTO puzzle_solving_time
                (id, seconds_to_solve, player_id, puzzle_id, tracked_at, verified, team, finished_at,
                 comment, finished_puzzle_photo, first_attempt, unboxed, puzzlers_count, puzzling_type,
                 suspicious, pieces_count_snapshot)
             VALUES
                (:id, 3600, :playerId, :puzzleId, :at, true, NULL, :at,
                 NULL, NULL, false, false, 1, \'solo\', false, 500)',
            [
                'id' => Uuid::uuid7()->toString(),
                'playerId' => $playerId,
                'puzzleId' => PuzzleFixture::PUZZLE_500_04,
                'at' => $atUtc,
            ],
        );
    }
}
