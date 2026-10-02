<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ResultReviewContact;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\ConfirmDuplicateIsReal;
use SpeedPuzzling\Web\Message\DismissFirstTryReview;
use SpeedPuzzling\Web\Message\PlanResultReviewEmails;
use SpeedPuzzling\Web\Message\SendPlannedResultReviewEmails;
use SpeedPuzzling\Web\MessageHandler\SendPlannedResultReviewEmailsHandler;
use SpeedPuzzling\Web\Query\GetResultReviewEmails;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\ResultAutoRemovalRepository;
use SpeedPuzzling\Web\Repository\ResultReviewContactRepository;
use SpeedPuzzling\Web\Results\ResultReviewSendingSummary;
use SpeedPuzzling\Web\Services\DelayedEmailQueue;
use SpeedPuzzling\Web\Services\DuplicateResults\DailyDuplicateDetection;
use SpeedPuzzling\Web\Services\DuplicateResults\ResultReviewEmailComposer;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use SpeedPuzzling\Web\Tests\DataFixtures\DuplicateResultsFixture;
use SpeedPuzzling\Web\Value\DuplicateDetectedBy;
use SpeedPuzzling\Web\Value\ResultReviewContactType;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Planning and paced sending of the "Your results" e-mails (docs/features/duplicate-results.md, "Telling players").
 * The fixtures come with Dana Twin's four open cases (A, two B, C) and Tom Twin's case of the pair they both saved;
 * neither has an account, so each test gives them one (an e-mail address).
 */
final class ResultReviewEmailsTest extends KernelTestCase
{
    private const string DANA = DuplicateResultsFixture::PLAYER_TWINS;
    private const string TOM = DuplicateResultsFixture::PLAYER_TWINS_TEAMMATE;

    private MessageBusInterface $messageBus;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $this->entityManager->persist(new UserAccount(Uuid::uuid7(), 'auth0|twins001', 'dana@example.com', new DateTimeImmutable()));
        $this->entityManager->persist(new UserAccount(Uuid::uuid7(), 'auth0|twins002', 'tom@example.com', new DateTimeImmutable()));
        $this->entityManager->flush();
    }

    public function testTheFirstEmailIsPlannedForEverybodyActiveOrNotActiveOnesFirst(): void
    {
        $this->activeDaysAgo(self::DANA, 1);
        // Tom was last seen half a year ago
        $this->activeDaysAgo(self::TOM, 180);

        self::assertSame(2, $this->plan());

        $dana = $this->contactsOf(self::DANA);
        self::assertCount(1, $dana);
        self::assertSame('first', $dana[0]['type']);
        self::assertSame(1, $dana[0]['priority']);
        self::assertEqualsCanonicalizing($this->openCaseIdsOf(self::DANA), $this->jsonList($dana[0]['case_ids']));

        $tom = $this->contactsOf(self::TOM);
        self::assertCount(1, $tom);
        self::assertSame(2, $tom[0]['priority'], 'Dormant players come last in the waves');

        self::assertSame(
            [$dana[0]['id'], $tom[0]['id']],
            self::getContainer()->get(GetResultReviewEmails::class)->plannedIdsInSendOrder(),
        );
    }

    public function testPlanningTwiceTheSameDayPlansNothingNew(): void
    {
        self::assertSame(2, $this->plan());
        self::assertSame(0, $this->plan());

        self::assertCount(1, $this->contactsOf(self::DANA));
    }

    public function testNobodyWithTheSwitchOffOrWithoutAnAddressIsPlanned(): void
    {
        $this->database->executeStatement('UPDATE player SET result_emails_enabled = false WHERE id = :id', ['id' => self::DANA]);
        $this->database->executeStatement("DELETE FROM user_account WHERE user_id = 'auth0|twins002'");

        self::assertSame(0, $this->plan());
    }

    public function testTierCAloneIsNoReasonToWrite(): void
    {
        // Everything of Dana's but the practice-session case is decided
        $this->database->executeStatement(
            "UPDATE result_duplicate_case SET status = 'both_real' WHERE player_id = :playerId AND tier <> 'possible'",
            ['playerId' => self::DANA],
        );

        $this->plan();

        self::assertSame([], $this->contactsOf(self::DANA));
    }

    public function testAfterTheFirstEmailOnlyRemovalsAreToldAndEachCaseOnlyOnce(): void
    {
        $this->activeDaysAgo(self::DANA, 1);
        $this->plan();
        $this->send();
        // The first e-mail went out 10 days ago and Dana did not react
        $this->database->executeStatement("UPDATE result_review_contact SET sent_at = NOW() - INTERVAL '10 days'");

        // Nothing new: every case was in the first e-mail already
        self::assertSame(0, $this->plan());

        // The Tier A copy is removed automatically - that is told even to somebody who ignored us
        self::getContainer()->get(DailyDuplicateDetection::class)->run(DuplicateDetectedBy::Cron);
        self::assertSame(1, $this->plan());

        $weekly = $this->contactsOf(self::DANA)[1];
        self::assertSame('weekly', $weekly['type']);
        self::assertSame([], $this->jsonList($weekly['case_ids']));
        self::assertCount(1, $this->jsonList($weekly['removal_ids']));

        $this->send();

        self::assertNotNull($this->database->fetchOne('SELECT reported_at FROM result_auto_removal WHERE player_id = :id', ['id' => self::DANA]));

        // Told once: no further e-mail about it
        $this->database->executeStatement("UPDATE result_review_contact SET sent_at = NOW() - INTERVAL '10 days'");
        self::assertSame(0, $this->plan());
    }

    public function testDormantPlayersGetOnlyTheFirstEmail(): void
    {
        $this->plan();
        $this->send();
        $this->database->executeStatement("UPDATE result_review_contact SET sent_at = NOW() - INTERVAL '10 days'");

        self::getContainer()->get(DailyDuplicateDetection::class)->run(DuplicateDetectedBy::Cron);

        // Dana was never active - the removal waits on the banner and the review page
        self::assertSame(0, $this->plan());
    }

    public function testNewCasesAfterAReaction(): void
    {
        $this->activeDaysAgo(self::DANA, 1);
        $this->plan();
        $this->send();
        $this->database->executeStatement(
            "UPDATE result_review_contact SET sent_at = NOW() - INTERVAL '40 days', reacted_at = NOW() - INTERVAL '39 days' WHERE player_id = :id",
            ['id' => self::DANA],
        );
        // A case nobody told her about yet
        $this->database->executeStatement(
            "UPDATE result_review_contact SET case_ids = '[]' WHERE player_id = :id",
            ['id' => self::DANA],
        );

        self::assertSame(1, $this->plan());
        $weekly = $this->contactsOf(self::DANA)[1];
        self::assertSame('weekly', $weekly['type']);
        self::assertEqualsCanonicalizing($this->openCaseIdsOf(self::DANA), $this->jsonList($weekly['case_ids']));
    }

    public function testSendingThroughTheNotificationsTransportWithOneClickUnsubscribe(): void
    {
        $this->database->executeStatement("UPDATE player SET locale = 'cs' WHERE id = :id", ['id' => self::TOM]);
        $this->plan();

        $summary = $this->send();

        self::assertSame(2, $summary->sent);
        self::assertSame(0, $summary->stillPlanned);

        $danaContact = $this->contactsOf(self::DANA)[0];
        self::assertSame('sent', $danaContact['status']);
        self::assertNotNull($danaContact['sent_at']);

        $danaEmail = $this->emailTo('dana@example.com');
        self::assertSame('notifications', $danaEmail->getHeaders()->get('X-Transport')?->getBodyAsString());
        self::assertSame('List-Unsubscribe=One-Click', $danaEmail->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());
        self::assertMatchesRegularExpression(
            '~^<https?://[^/]+/en/result-emails/unsubscribe/' . self::DANA . '\?_hash=[^>]+>$~',
            (string) $danaEmail->getHeaders()->get('List-Unsubscribe')?->getBodyAsString(),
        );
        self::assertSame('Could you check a few of your results?', $danaEmail->getSubject());

        $html = (string) $danaEmail->getHtmlBody();
        self::assertStringContainsString('Twins Puzzle', $html);
        self::assertStringContainsString('/en/review-results?from=rc-' . $danaContact['id'], $html);
        self::assertStringContainsString('and 1 more', $html, 'Up to 3 cases are listed');
        // A real document for phones: viewport + mobile rules (EmailDocumentTwigExtension)
        self::assertStringContainsString('name="viewport"', $html);

        // No action links - only the review page
        self::assertStringNotContainsString('/keep', $html);
        self::assertStringNotContainsString('/undo', $html);

        // Document semantics from the rendered message (docs/features/transactional-emails.md): the language of the
        // e-mail on <html> and on the body wrapper, the subject as <title>, the inbox preview line hidden on top
        self::assertStringStartsWith("<!DOCTYPE html>\n<html lang=\"en\" dir=\"ltr\">", $html);
        self::assertStringContainsString('<title>Could you check a few of your results?</title>', $html);
        self::assertStringContainsString('<div class="preheader" style="display: none;', $html);
        self::assertStringContainsString('A few of your results may show up twice', $html);
        self::assertStringNotContainsString('A few of your results may show up twice', (string) $danaEmail->getTextBody(), 'The hidden preview line is not in the text part');

        $tomEmail = $this->emailTo('tom@example.com');
        self::assertSame('Zkontroluješ prosím pár svých výsledků?', $tomEmail->getSubject());
        self::assertStringContainsString('/cs/review-results?from=rc-', (string) $tomEmail->getHtmlBody());
        self::assertStringContainsString('<div lang="cs" dir="ltr" role="article"', (string) $tomEmail->getHtmlBody());
        self::assertStringContainsString('<title>Zkontroluješ prosím pár svých výsledků?</title>', (string) $tomEmail->getHtmlBody());
        self::assertStringContainsString('uložil ho i někdo další z tvé dvojice/týmu', (string) $tomEmail->getHtmlBody());
    }

    public function testAResultSavedThreeTimesIsOneLine(): void
    {
        // A third copy of the Tier B pair - three cases of one result
        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: Uuid::uuid7(),
            userId: 'auth0|twins001',
            puzzleId: DuplicateResultsFixture::PUZZLE_TWINS,
            competitionId: null,
            time: '00:37:02',
            comment: 'Third copy',
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: self::getContainer()->get(ClockInterface::class)->now()->modify('-41 days')->setTime(0, 0),
            firstAttempt: false,
            unboxed: false,
        ));
        self::assertCount(6, $this->openCaseIdsOf(self::DANA));

        $this->plan();
        $this->send();

        $html = (string) $this->emailTo('dana@example.com')->getHtmlBody();
        self::assertSame(1, substr_count($html, 'Twins Puzzle – 00:37:02'));
        self::assertStringContainsString('and 1 more', $html, 'Four results, three listed - counted in results, not cases');
        // Every case went out with it, listed or not
        self::assertCount(6, $this->jsonList($this->contactsOf(self::DANA)[0]['case_ids']));
    }

    public function testAnEmailWithRemovalsOnlyTellsWhatWasRemoved(): void
    {
        $this->activeDaysAgo(self::DANA, 1);
        $this->plan();
        $this->send();
        $this->database->executeStatement("UPDATE result_review_contact SET sent_at = NOW() - INTERVAL '10 days'");
        self::getContainer()->get(DailyDuplicateDetection::class)->run(DuplicateDetectedBy::Cron);
        $this->plan();

        $this->send();

        $emails = array_values(array_filter(
            self::getMailerMessages(),
            static fn (object $message): bool => $message instanceof TemplatedEmail && $message->getTo()[0]->getAddress() === 'dana@example.com',
        ));
        self::assertCount(2, $emails);
        $removalsOnly = $emails[1];

        self::assertSame('We removed results that showed up twice', $removalsOnly->getSubject());
        $html = (string) $removalsOnly->getHtmlBody();
        self::assertStringContainsString('Already fixed – we were sure', $html);
        self::assertStringContainsString('We removed the extra copy', $html);
        self::assertStringNotContainsString("Please check – we weren't sure", $html);
        self::assertStringNotContainsString('keep one copy, or tell us both are real', $html);
    }

    public function testWhatChangedMeanwhileIsCheckedAgainAtSendTime(): void
    {
        $this->plan();

        $this->database->executeStatement('UPDATE player SET result_emails_enabled = false WHERE id = :id', ['id' => self::DANA]);
        $this->database->executeStatement(
            "UPDATE result_duplicate_case SET status = 'both_real' WHERE player_id = :id",
            ['id' => self::TOM],
        );
        // The sending runs in a process of its own
        $this->entityManager->clear();

        $summary = $this->send();

        self::assertSame(0, $summary->sent);
        self::assertSame(2, $summary->skipped);
        self::assertCount(0, self::getMailerMessages());
        self::assertSame(['skipped', 'switched_off'], [$this->contactsOf(self::DANA)[0]['status'], $this->contactsOf(self::DANA)[0]['skipped_reason']]);
        self::assertSame(['skipped', 'nothing_left'], [$this->contactsOf(self::TOM)[0]['status'], $this->contactsOf(self::TOM)[0]['skipped_reason']]);
    }

    public function testResolvedCasesAreLeftOutOfTheEmail(): void
    {
        $this->plan();
        $strongCaseId = $this->caseId(self::DANA, DuplicateResultsFixture::TIME_STRONG_A);
        $this->database->executeStatement("UPDATE result_duplicate_case SET status = 'both_real' WHERE id = :id", ['id' => $strongCaseId]);

        $this->send();

        $caseIds = $this->jsonList($this->contactsOf(self::DANA)[0]['case_ids']);
        self::assertCount(3, $caseIds);
        self::assertNotContains($strongCaseId, $caseIds);
    }

    public function testCapsPerRunAndPerPragueDay(): void
    {
        $dana = self::getContainer()->get(PlayerRepository::class)->get(self::DANA);
        $this->planForDana(4);
        // Sent at 23:00 Prague time the day before - not today's
        $this->entityManager->persist($yesterday = new ResultReviewContact(Uuid::uuid7(), $dana, ResultReviewContactType::First, 1, null, [], [], new DateTimeImmutable()));
        $yesterday->sent([], [], new DateTimeImmutable('2026-10-01 21:00:00'));
        $this->entityManager->flush();

        // 10:00 Prague time
        $handler = $this->handler(new MockClock(new DateTimeImmutable('2026-10-02 08:00:00')), perRun: 1, perDay: 2);

        self::assertSame(1, $handler(new SendPlannedResultReviewEmails())->sent);
        $this->entityManager->flush();
        self::assertSame(1, $handler(new SendPlannedResultReviewEmails())->sent);
        $this->entityManager->flush();

        $third = $handler(new SendPlannedResultReviewEmails());
        self::assertSame(0, $third->sent, 'Two today already');
        self::assertSame(2, $third->sentTodayBefore);
        self::assertSame(2, $third->stillPlanned);
    }

    public function testTheEmailsOfARunLeaveSpacedOut(): void
    {
        $this->planForDana(5);

        $handler = $this->handler(new MockClock(new DateTimeImmutable('2026-10-02 08:00:00')), perRun: 3, perDay: 1000, spacingSeconds: 60);

        self::assertSame(3, $handler(new SendPlannedResultReviewEmails())->sent);
        self::assertSame([0, 60, 120], $this->queuedEmailDelays());
        self::assertQueuedEmailCount(3);

        // The next run starts from 0 again - the cron's gap spaces the runs
        $this->entityManager->flush();
        self::assertSame(2, $handler(new SendPlannedResultReviewEmails())->sent);
        self::assertSame([0, 60, 120, 0, 60], $this->queuedEmailDelays());
    }

    public function testASkippedEmailTakesNoSlotOfTheSpacing(): void
    {
        // Active Dana goes before dormant Tom
        $this->activeDaysAgo(self::DANA, 1);
        $this->plan();
        // Dana's e-mail is skipped: nothing left to tell
        $this->database->executeStatement("UPDATE result_duplicate_case SET status = 'both_real' WHERE player_id = :id", ['id' => self::DANA]);
        $this->entityManager->clear();

        $summary = $this->send();

        self::assertSame(1, $summary->sent);
        self::assertSame(1, $summary->skipped);
        // The default spacing (result_review_email_spacing_seconds = 60) - Tom's e-mail leaves right away
        self::assertSame([0], $this->queuedEmailDelays());
        self::assertSame('notifications', $this->emailTo('tom@example.com')->getHeaders()->get('X-Transport')?->getBodyAsString());
    }

    public function testARunLocksThePlannedEmailsSoAnOverlappingRunSkipsThem(): void
    {
        $this->plan();

        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $debugDataHolder->reset();

        $this->send();

        /** @var list<array{sql: string}> $executed */
        $executed = $debugDataHolder->getData()['default'] ?? [];
        $plannedQueries = array_values(array_filter(
            array_column($executed, 'sql'),
            static fn (string $sql): bool => str_starts_with($sql, 'SELECT id FROM result_review_contact WHERE status'),
        ));

        // Held until the run commits; another run skips the locked rows instead of mailing them as well
        self::assertCount(1, $plannedQueries);
        self::assertStringEndsWith('FOR UPDATE SKIP LOCKED', $plannedQueries[0]);
    }

    public function testReactionsAreCreditedToTheLatestEmailOnly(): void
    {
        $this->plan();
        $this->send();
        $contactId = $this->contactsOf(self::DANA)[0]['id'];

        $this->messageBus->dispatch(new ConfirmDuplicateIsReal($this->caseId(self::DANA, DuplicateResultsFixture::TIME_PRACTICE_A), self::DANA));

        $reactedAt = $this->database->fetchOne('SELECT reacted_at FROM result_review_contact WHERE id = :id', ['id' => $contactId]);
        self::assertNotNull($reactedAt);

        // A later resolution keeps the first reaction
        $this->messageBus->dispatch(new DismissFirstTryReview(self::DANA, DuplicateResultsFixture::TIME_STRONG_A));
        self::assertSame($reactedAt, $this->database->fetchOne('SELECT reacted_at FROM result_review_contact WHERE id = :id', ['id' => $contactId]));

        // Tom's e-mail knows nothing of Dana's decisions
        self::assertNull($this->database->fetchOne('SELECT reacted_at FROM result_review_contact WHERE player_id = :id', ['id' => self::TOM]));
    }

    private function plan(): int
    {
        $envelope = $this->messageBus->dispatch(new PlanResultReviewEmails());
        $result = $envelope->last(HandledStamp::class)?->getResult();
        assert(is_int($result));

        return $result;
    }

    private function send(): ResultReviewSendingSummary
    {
        $envelope = $this->messageBus->dispatch(new SendPlannedResultReviewEmails());
        $result = $envelope->last(HandledStamp::class)?->getResult();
        assert($result instanceof ResultReviewSendingSummary);

        return $result;
    }

    private function handler(MockClock $clock, int $perRun, int $perDay, int $spacingSeconds = 60): SendPlannedResultReviewEmailsHandler
    {
        $container = self::getContainer();

        return new SendPlannedResultReviewEmailsHandler(
            getResultReviewEmails: $container->get(GetResultReviewEmails::class),
            contactRepository: $container->get(ResultReviewContactRepository::class),
            autoRemovalRepository: $container->get(ResultAutoRemovalRepository::class),
            playerAccountEmail: $container->get(PlayerAccountEmail::class),
            emailComposer: $container->get(ResultReviewEmailComposer::class),
            emailQueue: $container->get(DelayedEmailQueue::class),
            clock: $clock,
            resultReviewEmailsPerRun: $perRun,
            resultReviewEmailsPerDay: $perDay,
            resultReviewEmailSpacingSeconds: $spacingSeconds,
        );
    }

    /**
     * The delays (in seconds) of the e-mails queued on the async transport so far, in dispatch order.
     *
     * @return list<null|int>
     */
    private function queuedEmailDelays(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $delays = [];

        foreach ($transport->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof SendEmailMessage) {
                $delay = $envelope->last(DelayStamp::class)?->getDelay();
                $delays[] = $delay === null ? null : intdiv($delay, 1000);
            }
        }

        return $delays;
    }

    private function planForDana(int $count): void
    {
        $dana = self::getContainer()->get(PlayerRepository::class)->get(self::DANA);
        $caseId = $this->caseId(self::DANA, DuplicateResultsFixture::TIME_STRONG_A);

        foreach (range(1, $count) as $ignored) {
            $this->entityManager->persist(new ResultReviewContact(
                id: Uuid::uuid7(),
                player: $dana,
                type: ResultReviewContactType::First,
                priority: 1,
                lastActiveOn: null,
                caseIds: [$caseId],
                removalIds: [],
                plannedAt: new DateTimeImmutable(),
            ));
        }

        $this->entityManager->flush();
    }

    private function activeDaysAgo(string $playerId, int $days): void
    {
        $this->database->executeStatement(
            "INSERT INTO player_activity_day (id, player_id, day, first_seen_at) VALUES (:id, :playerId, CURRENT_DATE - :days * INTERVAL '1 day', NOW())",
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'days' => $days],
        );
    }

    /**
     * @return list<array{id: string, type: string, status: string, priority: int, case_ids: string, removal_ids: string, sent_at: null|string, skipped_reason: null|string}>
     */
    private function contactsOf(string $playerId): array
    {
        /** @var list<array{id: string, type: string, status: string, priority: int, case_ids: string, removal_ids: string, sent_at: null|string, skipped_reason: null|string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT id, type, status, priority, case_ids, removal_ids, sent_at, skipped_reason FROM result_review_contact WHERE player_id = :id ORDER BY planned_at, id',
            ['id' => $playerId],
        );

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function openCaseIdsOf(string $playerId): array
    {
        /** @var list<string> $ids */
        $ids = $this->database->fetchFirstColumn(
            "SELECT id FROM result_duplicate_case WHERE player_id = :id AND status = 'open'",
            ['id' => $playerId],
        );

        return $ids;
    }

    private function caseId(string $playerId, string $timeAId): string
    {
        $id = $this->database->fetchOne(
            'SELECT id FROM result_duplicate_case WHERE player_id = :playerId AND time_a_id = :timeAId',
            ['playerId' => $playerId, 'timeAId' => $timeAId],
        );
        assert(is_string($id));

        return $id;
    }

    /**
     * @return list<string>
     */
    private function jsonList(string $json): array
    {
        /** @var list<string> $list */
        $list = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $list;
    }

    private function emailTo(string $address): TemplatedEmail
    {
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof TemplatedEmail && $message->getTo()[0]->getAddress() === $address) {
                return $message;
            }
        }

        self::fail('No e-mail to ' . $address);
    }
}
