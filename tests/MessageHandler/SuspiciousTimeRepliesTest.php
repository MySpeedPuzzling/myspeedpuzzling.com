<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\ResultReviewContact;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeNoticeNotFound;
use SpeedPuzzling\Web\Message\LeaveSuspiciousTimeAsItIs;
use SpeedPuzzling\Web\Message\ReplySuspiciousTimeIsCorrect;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use SpeedPuzzling\Web\Value\ResultReviewContactType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

/**
 * "The time is correct" and "Leave it as it is" on a result awaiting verification
 * (docs/features/suspicious-time-review.md, "Where they see it").
 */
final class SuspiciousTimeRepliesTest extends KernelTestCase
{
    private const string MIA = SuspiciousTimesFixture::PLAYER_MARKED;

    private MessageBusInterface $messageBus;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testTheTimeIsCorrectIsSentOncePerMark(): void
    {
        self::assertTrue($this->reply(str_repeat('a', 600)));

        $notice = $this->notice();
        self::assertSame('says_correct', $notice['response']);
        self::assertSame(ReplySuspiciousTimeIsCorrect::MAX_TEXT_LENGTH, mb_strlen((string) $notice['response_text']));
        self::assertNotNull($notice['responded_at']);

        // Neither a second reply nor "Leave it as it is" changes what the moderators got
        self::assertFalse($this->reply('Something else'));
        $this->leave();

        self::assertSame($notice, $this->notice());
    }

    public function testAnEmptyMessageIsNoMessage(): void
    {
        self::assertTrue($this->reply('   '));

        self::assertSame('says_correct', $this->notice()['response']);
        self::assertNull($this->notice()['response_text']);
    }

    public function testLeaveItAsItIsThenTheTimeIsCorrect(): void
    {
        $this->leave();
        self::assertSame('left_as_is', $this->notice()['response']);

        // The player can still change their mind
        self::assertTrue($this->reply(null));
        self::assertSame('says_correct', $this->notice()['response']);
    }

    public function testOnlySomebodyToldAboutTheMarkInForceMayAnswer(): void
    {
        // Somebody else
        $this->assertNotFound(new ReplySuspiciousTimeIsCorrect(SuspiciousTimesFixture::CASE_MARKED, SuspiciousTimesFixture::PLAYER_STEADY, null));
        $this->assertNotFound(new LeaveSuspiciousTimeAsItIs(SuspiciousTimesFixture::CASE_MARKED, SuspiciousTimesFixture::PLAYER_STEADY));
        // A case that is not marked
        $this->assertNotFound(new LeaveSuspiciousTimeAsItIs(SuspiciousTimesFixture::CASE_PENDING_FAST, SuspiciousTimesFixture::PLAYER_STEADY));
        $this->assertNotFound(new LeaveSuspiciousTimeAsItIs('not-an-id', self::MIA));

        // A notice of an earlier mark
        $this->database->executeStatement("UPDATE suspicious_time_notice SET marked_at = marked_at - INTERVAL '1 day' WHERE id = :id", ['id' => SuspiciousTimesFixture::NOTICE_MARKED]);
        $this->assertNotFound(new LeaveSuspiciousTimeAsItIs(SuspiciousTimesFixture::CASE_MARKED, self::MIA));

        // ... and an unmarked time
        $this->database->executeStatement("UPDATE suspicious_time_notice SET marked_at = marked_at + INTERVAL '1 day' WHERE id = :id", ['id' => SuspiciousTimesFixture::NOTICE_MARKED]);
        $this->database->executeStatement("UPDATE suspicious_time_case SET status = 'trusted' WHERE id = :id", ['id' => SuspiciousTimesFixture::CASE_MARKED]);
        $this->assertNotFound(new ReplySuspiciousTimeIsCorrect(SuspiciousTimesFixture::CASE_MARKED, self::MIA, null));

        self::assertNull($this->notice()['response']);
    }

    public function testAnAnswerIsAReactionToTheLatestEmail(): void
    {
        $contactId = $this->sentResultReviewEmail(self::MIA);

        $this->leave();

        self::assertNotNull($this->database->fetchOne('SELECT reacted_at FROM result_review_contact WHERE id = :id', ['id' => $contactId]));
    }

    public function testTheTimeIsCorrectIsAReactionToo(): void
    {
        $contactId = $this->sentResultReviewEmail(self::MIA);

        $this->reply('Right');

        self::assertNotNull($this->database->fetchOne('SELECT reacted_at FROM result_review_contact WHERE id = :id', ['id' => $contactId]));
    }

    private function reply(null|string $text): bool
    {
        $this->entityManager->clear();
        $recorded = $this->messageBus->dispatch(new ReplySuspiciousTimeIsCorrect(SuspiciousTimesFixture::CASE_MARKED, self::MIA, $text))
            ->last(HandledStamp::class)?->getResult();
        self::assertIsBool($recorded);

        return $recorded;
    }

    private function leave(): void
    {
        $this->entityManager->clear();
        $this->messageBus->dispatch(new LeaveSuspiciousTimeAsItIs(SuspiciousTimesFixture::CASE_MARKED, self::MIA));
    }

    private function assertNotFound(object $message): void
    {
        $this->entityManager->clear();

        try {
            $this->messageBus->dispatch($message);
        } catch (Throwable $e) {
            self::assertInstanceOf(SuspiciousTimeNoticeNotFound::class, $e);

            return;
        }

        self::fail('The answer of somebody without a notice of the mark was accepted.');
    }

    /**
     * @return array{response: null|string, response_text: null|string, responded_at: null|string}
     */
    private function notice(): array
    {
        /** @var array{response: null|string, response_text: null|string, responded_at: null|string} $row */
        $row = $this->database->fetchAssociative(
            'SELECT response, response_text, responded_at FROM suspicious_time_notice WHERE id = :id',
            ['id' => SuspiciousTimesFixture::NOTICE_MARKED],
        );

        return $row;
    }

    private function sentResultReviewEmail(string $playerId): string
    {
        $player = $this->entityManager->find(Player::class, $playerId);
        self::assertNotNull($player);

        $contact = new ResultReviewContact(
            id: Uuid::uuid7(),
            player: $player,
            type: ResultReviewContactType::Weekly,
            priority: 0,
            lastActiveOn: null,
            caseIds: [],
            removalIds: [],
            plannedAt: new DateTimeImmutable('-1 day'),
        );
        $contact->sent([], [], new DateTimeImmutable('-1 day'));
        $this->entityManager->persist($contact);
        $this->entityManager->flush();

        return $contact->id->toString();
    }
}
