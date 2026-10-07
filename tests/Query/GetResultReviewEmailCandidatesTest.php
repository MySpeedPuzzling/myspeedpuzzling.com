<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Query\GetResultReviewEmailCandidates;
use SpeedPuzzling\Web\Results\ResultReviewCandidate;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Verification notices make a player a candidate for the "Your results" e-mail
 * (docs/features/suspicious-time-review.md, "Where they see it"): a mark still in force that no e-mail carried and the
 * player has not reacted to, or a moderator's answer no e-mail carried - notices of the notice run only.
 */
final class GetResultReviewEmailCandidatesTest extends KernelTestCase
{
    private const string MIA = SuspiciousTimesFixture::PLAYER_MARKED;
    private const string NOTICE = SuspiciousTimesFixture::NOTICE_MARKED;
    private const string CASE = SuspiciousTimesFixture::CASE_MARKED;
    private const string TIME = SuspiciousTimesFixture::TIME_MARKED;

    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new UserAccount(Uuid::uuid7(), 'auth0|marked1', 'mia@example.com', new DateTimeImmutable()));
        $entityManager->flush();
    }

    public function testMarkNotEmailedYetMakesTheMarkedPlayerACandidate(): void
    {
        $mia = $this->candidateOf(self::MIA);

        self::assertNotNull($mia);
        self::assertSame([self::NOTICE], $mia->suspiciousNoticeIds);
        self::assertSame([], $mia->strongCaseIds);
        self::assertSame([], $mia->possibleCaseIds);
        self::assertSame([], $mia->removalIds);
        self::assertNull($mia->lastSentAt);
    }

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function provideNoticeStates(): iterable
    {
        $notice = "UPDATE suspicious_time_notice SET %s WHERE id = '" . self::NOTICE . "'";
        $otherContact = Uuid::uuid7()->toString();

        yield 'e-mailed by hand' => [[sprintf($notice, "via = 'manual_email'")], false];
        yield 'the mark was e-mailed' => [[sprintf($notice, "contact_id = '{$otherContact}'")], false];
        yield 'the player left it as it is' => [[sprintf($notice, "response = 'left_as_is', responded_at = NOW()")], false];
        yield 'the player says the time is correct' => [[sprintf($notice, "response = 'says_correct', responded_at = NOW()")], false];
        yield 'trusted meanwhile' => [["UPDATE suspicious_time_case SET status = 'trusted' WHERE id = '" . self::CASE . "'", "UPDATE puzzle_solving_time SET suspicious = false WHERE id = '" . self::TIME . "'"], false];
        yield 'the flag was cleared by SQL' => [["UPDATE puzzle_solving_time SET suspicious = false WHERE id = '" . self::TIME . "'"], false];
        yield 'a newer mark of the time' => [["UPDATE suspicious_time_case SET marked_at = marked_at + INTERVAL '1 hour' WHERE id = '" . self::CASE . "'"], false];
        yield 'a moderator answered, not e-mailed' => [[sprintf($notice, "contact_id = '{$otherContact}', response = 'says_correct', responded_at = NOW(), answer = 'kept', answer_note = 'Hmm', answered_at = NOW()")], true];
        yield 'a moderator answered an unsent mark' => [[sprintf($notice, "response = 'says_correct', responded_at = NOW(), answer = 'trusted', answered_at = NOW()")], true];
        yield 'the answer was e-mailed' => [[sprintf($notice, "contact_id = '{$otherContact}', response = 'says_correct', responded_at = NOW(), answer = 'trusted', answered_at = NOW(), answer_contact_id = '{$otherContact}'")], false];
        // The mark was told by hand, the moderator's answer to the player's reply is new: told like any other
        yield 'an answer to a time e-mailed by hand' => [[sprintf($notice, "via = 'manual_email', response = 'says_correct', responded_at = NOW(), answer = 'trusted', answered_at = NOW()")], true];
        yield 'the answer to a time e-mailed by hand was e-mailed' => [[sprintf($notice, "via = 'manual_email', response = 'says_correct', responded_at = NOW(), answer = 'trusted', answered_at = NOW(), answer_contact_id = '{$otherContact}'")], false];
        yield 'the player is not in the result any more' => [["UPDATE puzzle_solving_time SET player_id = '" . SuspiciousTimesFixture::PLAYER_STEADY . "' WHERE id = '" . self::TIME . "'"], false];
        yield 'the e-mails are switched off' => [["UPDATE player SET result_emails_enabled = false WHERE id = '" . self::MIA . "'"], false];
    }

    /**
     * @param list<string> $statements
     */
    #[DataProvider('provideNoticeStates')]
    public function testWhichNoticesAreStillToTell(array $statements, bool $toTell): void
    {
        foreach ($statements as $statement) {
            $this->database->executeStatement($statement);
        }

        $mia = $this->candidateOf(self::MIA);

        if ($toTell) {
            self::assertNotNull($mia);
            self::assertSame([self::NOTICE], $mia->suspiciousNoticeIds);
        } else {
            self::assertNull($mia);
        }
    }

    public function testAPlayerWithAnEmailWaitingIsNoCandidate(): void
    {
        $this->database->executeStatement(
            "INSERT INTO result_review_contact (id, player_id, type, priority, case_ids, removal_ids, suspicious_notice_ids, planned_at, status) VALUES (:id, :playerId, 'first', 2, '[]', '[]', :notices, NOW(), 'planned')",
            ['id' => Uuid::uuid7()->toString(), 'playerId' => self::MIA, 'notices' => json_encode([self::NOTICE])],
        );

        self::assertNull($this->candidateOf(self::MIA));
    }

    public function testAPlayerWithoutAnAccountIsNoCandidate(): void
    {
        $this->database->executeStatement("DELETE FROM user_account WHERE user_id = 'auth0|marked1'");

        self::assertNull($this->candidateOf(self::MIA));
    }

    private function candidateOf(string $playerId): null|ResultReviewCandidate
    {
        foreach (self::getContainer()->get(GetResultReviewEmailCandidates::class)->all() as $candidate) {
            if ($candidate->playerId === $playerId) {
                return $candidate;
            }
        }

        return null;
    }
}
