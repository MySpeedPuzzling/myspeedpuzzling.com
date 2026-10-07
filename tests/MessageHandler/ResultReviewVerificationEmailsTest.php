<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ResultReviewContact;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Message\PlanResultReviewEmails;
use SpeedPuzzling\Web\Message\SendPlannedResultReviewEmails;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\ResultReviewSendingSummary;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use SpeedPuzzling\Web\Value\ResultReviewContactType;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Times awaiting verification and the moderators' answers in the "Your results" e-mail
 * (docs/features/suspicious-time-review.md, "Where they see it"): told like automatic removals - always and once.
 *
 * The fixtures come with Mia Marked's marked time (Silent Pier, 21:40) and its notice of the notice run, not
 * e-mailed yet. Mia has no account, so each test gives her one (an e-mail address).
 */
final class ResultReviewVerificationEmailsTest extends KernelTestCase
{
    private const string MIA = SuspiciousTimesFixture::PLAYER_MARKED;
    private const string NOTICE = SuspiciousTimesFixture::NOTICE_MARKED;
    private const string MIA_EMAIL = 'mia@example.com';

    private MessageBusInterface $messageBus;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $this->entityManager->persist(new UserAccount(Uuid::uuid7(), 'auth0|marked1', self::MIA_EMAIL, new DateTimeImmutable()));
        $this->entityManager->flush();
    }

    public function testMarkIsToldInTheFirstEmailAndOnlyOnce(): void
    {
        self::assertSame(1, $this->plan());

        $contact = $this->contactsOf(self::MIA)[0];
        self::assertSame('first', $contact['type']);
        // Never active - a dormant player still gets the first e-mail
        self::assertSame(2, $contact['priority']);
        self::assertSame([self::NOTICE], $this->jsonList($contact['suspicious_notice_ids']));
        self::assertSame([], $this->jsonList($contact['case_ids']));
        self::assertSame([], $this->jsonList($contact['removal_ids']));

        self::assertSame(1, $this->send()->sent);

        $contact = $this->contactsOf(self::MIA)[0];
        self::assertSame('sent', $contact['status']);
        self::assertSame([self::NOTICE], $this->jsonList($contact['suspicious_notice_ids']));
        self::assertSame($contact['id'], $this->notice()['contact_id'], 'The notice knows the e-mail that told it');
        self::assertNull($this->notice()['answer_contact_id']);

        $email = $this->emailTo(self::MIA_EMAIL);
        self::assertSame('One of your results is awaiting verification', $email->getSubject());
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('Awaiting verification', $html);
        self::assertStringContainsString('Silent Pier – 00:21:40', $html);
        self::assertStringContainsString('/en/review-results?from=rc-' . $contact['id'] . '#awaiting-verification', $html);
        // Nothing about results saved twice
        self::assertStringNotContainsString('Please check – we weren', $html);
        self::assertStringNotContainsString('Already fixed – we were sure', $html);
        self::assertNoWordSuspicious($email);

        // Told once - also to an active player a week later
        $this->activeDaysAgo(self::MIA, 1);
        $this->database->executeStatement("UPDATE result_review_contact SET sent_at = NOW() - INTERVAL '10 days'");
        self::assertSame(0, $this->plan());
    }

    public function testMarkIsToldEvenToAPlayerWhoIgnoredTheLastEmail(): void
    {
        $this->activeDaysAgo(self::MIA, 1);
        $this->sentContactDaysAgo(10);

        self::assertSame(1, $this->plan());

        $weekly = $this->contactsOf(self::MIA)[1];
        self::assertSame('weekly', $weekly['type']);
        self::assertSame(0, $weekly['priority']);
        self::assertSame([self::NOTICE], $this->jsonList($weekly['suspicious_notice_ids']));
    }

    public function testLaterEmailsFollowTheRulesOfRemovals(): void
    {
        // Not active in the last 3 months: only the banner tells
        $this->sentContactDaysAgo(10);
        self::assertSame(0, $this->plan());

        // Active, but the last e-mail is not a week old yet
        $this->activeDaysAgo(self::MIA, 1);
        $this->database->executeStatement("UPDATE result_review_contact SET sent_at = NOW() - INTERVAL '3 days'");
        self::assertSame(0, $this->plan());
    }

    public function testTimesEmailedByHandAreNeverMailed(): void
    {
        $this->database->executeStatement(
            "UPDATE suspicious_time_notice SET via = 'manual_email' WHERE id = :id",
            ['id' => self::NOTICE],
        );

        self::assertSame(0, $this->plan());

        // Not even when an e-mail somehow lists it
        $this->plannedContactWithTheNotice();
        $summary = $this->send();

        self::assertSame(0, $summary->sent);
        self::assertSame(['skipped', 'nothing_left'], [$this->contactsOf(self::MIA)[0]['status'], $this->contactsOf(self::MIA)[0]['skipped_reason']]);
        self::assertNull($this->notice()['contact_id']);
        self::assertCount(0, self::getMailerMessages());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideWhatHappensBeforeTheEmailLeaves(): iterable
    {
        yield 'a moderator trusted the time' => ["UPDATE suspicious_time_case SET status = 'trusted' WHERE id = '" . SuspiciousTimesFixture::CASE_MARKED . "'; UPDATE puzzle_solving_time SET suspicious = false WHERE id = '" . SuspiciousTimesFixture::TIME_MARKED . "'"];
        yield 'the flag was cleared by SQL' => ["UPDATE puzzle_solving_time SET suspicious = false WHERE id = '" . SuspiciousTimesFixture::TIME_MARKED . "'"];
        yield 'the player left it as it is' => ["UPDATE suspicious_time_notice SET response = 'left_as_is', responded_at = NOW() WHERE id = '" . SuspiciousTimesFixture::NOTICE_MARKED . "'"];
        yield 'the player fixed it and the case was corrected' => ["UPDATE suspicious_time_case SET status = 'corrected' WHERE id = '" . SuspiciousTimesFixture::CASE_MARKED . "'"];
        yield 'unmarked and marked again - a new mark with its own notice' => ["UPDATE suspicious_time_case SET marked_at = marked_at + INTERVAL '1 hour' WHERE id = '" . SuspiciousTimesFixture::CASE_MARKED . "'"];
        yield 'another e-mail told it meanwhile' => ["UPDATE suspicious_time_notice SET contact_id = '" . Uuid::uuid7()->toString() . "' WHERE id = '" . SuspiciousTimesFixture::NOTICE_MARKED . "'"];
    }

    #[DataProvider('provideWhatHappensBeforeTheEmailLeaves')]
    public function testAStaleMarkIsDroppedAtSendTime(string $meanwhile): void
    {
        self::assertSame(1, $this->plan());

        foreach (explode('; ', $meanwhile) as $statement) {
            $this->database->executeStatement($statement);
        }
        // The sending runs in a process of its own
        $this->entityManager->clear();

        $summary = $this->send();

        self::assertSame(0, $summary->sent);
        self::assertSame(1, $summary->skipped);
        self::assertSame(['skipped', 'nothing_left'], [$this->contactsOf(self::MIA)[0]['status'], $this->contactsOf(self::MIA)[0]['skipped_reason']]);
        self::assertCount(0, self::getMailerMessages());
        self::assertNotSame($this->contactsOf(self::MIA)[0]['id'], $this->notice()['contact_id'], 'A skipped e-mail told nothing');
    }

    public function testAModeratorsAnswerIsToldOnceInItsOwnEmail(): void
    {
        $this->activeDaysAgo(self::MIA, 1);
        // The mark went out 10 days ago; Mia said the time is correct, a moderator kept it marked with a note
        $markContactId = $this->sentContactDaysAgo(10, [self::NOTICE]);
        $this->database->executeStatement(
            "UPDATE suspicious_time_notice SET contact_id = :contactId, response = 'says_correct', responded_at = NOW() - INTERVAL '5 days', answer = 'kept', answer_note = 'The box in your photo is the 500-piece edition.', answered_at = NOW() - INTERVAL '1 day' WHERE id = :id",
            ['contactId' => $markContactId, 'id' => self::NOTICE],
        );
        $this->entityManager->clear();

        self::assertSame(1, $this->plan());
        self::assertSame(1, $this->send()->sent);

        $answerContact = $this->contactsOf(self::MIA)[1];
        self::assertSame('weekly', $answerContact['type']);
        self::assertSame([self::NOTICE], $this->jsonList($answerContact['suspicious_notice_ids']));
        self::assertSame($markContactId, $this->notice()['contact_id'], 'The e-mail that carried the mark stays');
        self::assertSame($answerContact['id'], $this->notice()['answer_contact_id']);

        $email = $this->emailTo(self::MIA_EMAIL);
        self::assertSame('Your result was checked', $email->getSubject());
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('Your time 00:21:40 on Silent Pier was checked – it stays set aside: The box in your photo is the 500-piece edition.', $html);
        // The mark itself was told already
        self::assertStringNotContainsString('is often a typo', $html);
        self::assertNoWordSuspicious($email);

        // Told once
        $this->database->executeStatement("UPDATE result_review_contact SET sent_at = NOW() - INTERVAL '10 days'");
        self::assertSame(0, $this->plan());
    }

    public function testAnAnswerToldMeanwhileIsDroppedAtSendTime(): void
    {
        $this->database->executeStatement(
            "UPDATE suspicious_time_notice SET contact_id = :contactId, response = 'says_correct', responded_at = NOW(), answer = 'trusted', answered_at = NOW() WHERE id = :id",
            ['contactId' => Uuid::uuid7()->toString(), 'id' => self::NOTICE],
        );
        $this->database->executeStatement("UPDATE suspicious_time_case SET status = 'trusted' WHERE id = :id", ['id' => SuspiciousTimesFixture::CASE_MARKED]);
        $this->database->executeStatement('UPDATE puzzle_solving_time SET suspicious = false WHERE id = :id', ['id' => SuspiciousTimesFixture::TIME_MARKED]);

        self::assertSame(1, $this->plan());

        $this->database->executeStatement('UPDATE suspicious_time_notice SET answer_contact_id = :contactId WHERE id = :id', [
            'contactId' => Uuid::uuid7()->toString(),
            'id' => self::NOTICE,
        ]);
        $this->entityManager->clear();

        self::assertSame(0, $this->send()->sent);
        self::assertSame('nothing_left', $this->contactsOf(self::MIA)[0]['skipped_reason']);
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

    /**
     * An earlier "Your results" e-mail Mia got and did not react to.
     *
     * @param list<string> $suspiciousNoticeIds
     */
    private function sentContactDaysAgo(int $days, array $suspiciousNoticeIds = []): string
    {
        $contact = new ResultReviewContact(
            id: Uuid::uuid7(),
            player: self::getContainer()->get(PlayerRepository::class)->get(self::MIA),
            type: ResultReviewContactType::First,
            priority: 2,
            lastActiveOn: null,
            caseIds: [],
            removalIds: [],
            plannedAt: new DateTimeImmutable("-{$days} days"),
            suspiciousNoticeIds: $suspiciousNoticeIds,
        );
        $contact->sent([], [], new DateTimeImmutable("-{$days} days"), $suspiciousNoticeIds);
        $this->entityManager->persist($contact);
        $this->entityManager->flush();

        return $contact->id->toString();
    }

    private function plannedContactWithTheNotice(): void
    {
        $this->entityManager->persist(new ResultReviewContact(
            id: Uuid::uuid7(),
            player: self::getContainer()->get(PlayerRepository::class)->get(self::MIA),
            type: ResultReviewContactType::First,
            priority: 2,
            lastActiveOn: null,
            caseIds: [],
            removalIds: [],
            plannedAt: new DateTimeImmutable(),
            suspiciousNoticeIds: [self::NOTICE],
        ));
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function activeDaysAgo(string $playerId, int $days): void
    {
        $this->database->executeStatement(
            "INSERT INTO player_activity_day (id, player_id, day, first_seen_at) VALUES (:id, :playerId, CURRENT_DATE - :days * INTERVAL '1 day', NOW())",
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'days' => $days],
        );
    }

    /**
     * @return array{contact_id: null|string, answer_contact_id: null|string}
     */
    private function notice(): array
    {
        /** @var array{contact_id: null|string, answer_contact_id: null|string} $row */
        $row = $this->database->fetchAssociative(
            'SELECT contact_id, answer_contact_id FROM suspicious_time_notice WHERE id = :id',
            ['id' => self::NOTICE],
        );

        return $row;
    }

    /**
     * @return list<array{id: string, type: string, status: string, priority: int, case_ids: string, removal_ids: string, suspicious_notice_ids: string, skipped_reason: null|string}>
     */
    private function contactsOf(string $playerId): array
    {
        /** @var list<array{id: string, type: string, status: string, priority: int, case_ids: string, removal_ids: string, suspicious_notice_ids: string, skipped_reason: null|string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT id, type, status, priority, case_ids, removal_ids, suspicious_notice_ids, skipped_reason FROM result_review_contact WHERE player_id = :id ORDER BY planned_at, id',
            ['id' => $playerId],
        );

        return $rows;
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

    private static function assertNoWordSuspicious(TemplatedEmail $email): void
    {
        foreach ([(string) $email->getSubject(), (string) $email->getHtmlBody(), (string) $email->getTextBody()] as $part) {
            self::assertDoesNotMatchRegularExpression('~suspic|suspect~i', $part, 'Nothing a player reads says "suspicious"');
        }
    }
}
