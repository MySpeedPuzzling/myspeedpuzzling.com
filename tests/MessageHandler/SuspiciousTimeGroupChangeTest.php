<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeNoticeNotFound;
use SpeedPuzzling\Web\Message\DetectSuspiciousTimes;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\NotifySuspiciousTimes;
use SpeedPuzzling\Web\Query\GetPlayerReviewCounts;
use SpeedPuzzling\Web\Query\GetPlayerSuspiciousTimes;
use SpeedPuzzling\Web\Query\GetResultReviewEmailCandidates;
use SpeedPuzzling\Web\Query\GetResultReviewEmails;
use SpeedPuzzling\Web\Repository\SuspiciousTimeNoticeRepository;
use SpeedPuzzling\Web\Results\ResultReviewCandidate;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/suspicious-time-review.md, "The notice run" and "Where they see it": a notice concerns its person only
 * while they are one of the time's people - the tracker or a registered member of its pair/team. A member an edit takes
 * out of the group keeps the row but is no longer shown, counted, answered or e-mailed anything about the result; a
 * member an edit adds is told by the next notice run.
 *
 * SuspiciousTimesFixture: Fay saved a pair result with Pat, flagged by "SQL" - the scan gives it a marked case and the
 * notice run tells both.
 */
final class SuspiciousTimeGroupChangeTest extends KernelTestCase
{
    private const string FAY_USER_ID = 'auth0|flagged1';

    private MessageBusInterface $messageBus;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        // Addresses for the "Your results" e-mail
        foreach (['auth0|flagged1' => 'fay@example.com', 'auth0|partner1' => 'pat@example.com', 'auth0|steady1' => 'sam@example.com'] as $userId => $email) {
            $this->entityManager->persist(new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable()));
        }

        $this->entityManager->flush();

        $this->messageBus->dispatch(new DetectSuspiciousTimes());
        $this->entityManager->clear();
        $this->messageBus->dispatch(new NotifySuspiciousTimes());
        $this->entityManager->clear();
    }

    public function testAMemberTakenOutOfTheGroupIsToldNothingMore(): void
    {
        self::assertTrue($this->isToldAnything(SuspiciousTimesFixture::PLAYER_PARTNER));

        // Fay puzzled with somebody else - two people still, so the entry (and the mark) stays as it was
        $this->editGroup(['Gil Guest']);

        self::assertFalse($this->isToldAnything(SuspiciousTimesFixture::PLAYER_PARTNER));
        $this->assertCannotReply(SuspiciousTimesFixture::PLAYER_PARTNER);

        // A moderator's answer to a reply Pat sent while he was in it is not his to receive any more either
        $this->database->executeStatement(
            "UPDATE suspicious_time_notice SET response = 'says_correct', responded_at = NOW(), answer = 'kept', answer_note = 'Stays', answered_at = NOW() WHERE player_id = :playerId",
            ['playerId' => SuspiciousTimesFixture::PLAYER_PARTNER],
        );
        self::assertNull($this->candidateOf(SuspiciousTimesFixture::PLAYER_PARTNER));
        self::assertSame([], self::getContainer()->get(GetResultReviewEmails::class)->verificationAnswersOf(SuspiciousTimesFixture::PLAYER_PARTNER, $this->noticeIdsOf(SuspiciousTimesFixture::PLAYER_PARTNER)));
        self::assertSame([], self::getContainer()->get(GetPlayerSuspiciousTimes::class)->recentlyAnsweredOf(SuspiciousTimesFixture::PLAYER_PARTNER));

        // Fay, the tracker, is still asked
        self::assertTrue($this->isToldAnything(SuspiciousTimesFixture::PLAYER_FLAGGED));
    }

    public function testAMemberAddedByAnEditIsToldByTheNextRun(): void
    {
        $this->editGroup(['#steady1']);
        self::assertSame([], $this->noticeIdsOf(SuspiciousTimesFixture::PLAYER_STEADY));

        $this->messageBus->dispatch(new NotifySuspiciousTimes());
        $this->entityManager->clear();

        self::assertCount(1, $this->noticeIdsOf(SuspiciousTimesFixture::PLAYER_STEADY));
        self::assertTrue($this->isToldAnything(SuspiciousTimesFixture::PLAYER_STEADY));
        self::assertFalse($this->isToldAnything(SuspiciousTimesFixture::PLAYER_PARTNER));
    }

    /**
     * The review page, the banner, the e-mail planning and the e-mail itself.
     *
     * @phpstan-impure
     */
    private function isToldAnything(string $playerId): bool
    {
        $open = self::getContainer()->get(GetPlayerSuspiciousTimes::class)->openOf($playerId);
        $banner = self::getContainer()->get(GetPlayerReviewCounts::class)->forPlayer($playerId, withFirstTryConflicts: false)->suspiciousTimes;
        $candidate = $this->candidateOf($playerId);
        $mailed = self::getContainer()->get(GetResultReviewEmails::class)->markedTimesOf($playerId, $this->noticeIdsOf($playerId));

        $told = [$open !== [], $banner > 0, $candidate !== null, $mailed !== []];
        self::assertSame(array_fill(0, 4, $told[0]), $told, 'Every place says the same');

        return $told[0];
    }

    private function assertCannotReply(string $playerId): void
    {
        $caseId = $this->database->fetchOne('SELECT id FROM suspicious_time_case WHERE time_id = :timeId', ['timeId' => SuspiciousTimesFixture::TIME_SQL_FLAGGED]);
        assert(is_string($caseId));

        $found = true;

        try {
            self::getContainer()->get(SuspiciousTimeNoticeRepository::class)->getCurrentOf($caseId, $playerId);
        } catch (SuspiciousTimeNoticeNotFound) {
            $found = false;
        }

        self::assertFalse($found, 'A person no longer in the result must not answer for it.');
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

    /**
     * @phpstan-impure
     * @return list<string>
     */
    private function noticeIdsOf(string $playerId): array
    {
        /** @var list<string> $ids */
        $ids = $this->database->fetchFirstColumn('SELECT id FROM suspicious_time_notice WHERE player_id = :playerId', ['playerId' => $playerId]);

        return $ids;
    }

    /**
     * @param list<string> $groupPlayers
     */
    private function editGroup(array $groupPlayers): void
    {
        /** @var array{finished_at: string, seconds_to_solve: int} $time */
        $time = $this->database->fetchAssociative(
            'SELECT finished_at, seconds_to_solve FROM puzzle_solving_time WHERE id = :id',
            ['id' => SuspiciousTimesFixture::TIME_SQL_FLAGGED],
        );

        $this->messageBus->dispatch(new EditPuzzleSolvingTime(
            currentUserId: self::FAY_USER_ID,
            puzzleSolvingTimeId: SuspiciousTimesFixture::TIME_SQL_FLAGGED,
            competitionId: null,
            time: gmdate('H:i:s', $time['seconds_to_solve']),
            comment: null,
            groupPlayers: $groupPlayers,
            finishedAt: new DateTimeImmutable($time['finished_at']),
            finishedPuzzlesPhoto: null,
            firstAttempt: true,
            unboxed: false,
        ));
        $this->entityManager->clear();
    }
}
