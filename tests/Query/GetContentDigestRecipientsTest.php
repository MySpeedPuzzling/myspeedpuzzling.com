<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetContentDigestRecipients;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\ContentDigestFrequency;
use SpeedPuzzling\Web\Value\ContentDigestKind;
use SpeedPuzzling\Web\Value\DigestPeriod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Who gets the activity digest and who the no-activity e-mail (docs/features/content-digest/README.md §"Who gets
 * what"). The periods are in 2030, so fixture results never fall into them.
 */
final class GetContentDigestRecipientsTest extends KernelTestCase
{
    private Connection $database;
    private GetContentDigestRecipients $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
        $this->query = self::getContainer()->get(GetContentDigestRecipients::class);
    }

    public function testActivityIsAResultSolvedInThePeriodSoloOrInAGroup(): void
    {
        $period = DigestPeriod::fromKey('2030-W10');
        $this->insertSolve(PlayerFixture::PLAYER_REGULAR, '2030-03-05 12:00:00');
        $teamTimeTracker = PlayerFixture::PLAYER_PRIVATE;
        $this->insertTeamSolve($teamTimeTracker, [PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_WITH_FAVORITES], '2030-03-06 12:00:00');

        $recipients = $this->query->of(ContentDigestKind::Activity, ContentDigestFrequency::Weekly, $period);

        self::assertContains(PlayerFixture::PLAYER_REGULAR, $recipients);
        self::assertContains(PlayerFixture::PLAYER_PRIVATE, $recipients);
        self::assertContains(PlayerFixture::PLAYER_WITH_FAVORITES, $recipients, 'A registered member of the pair');
        self::assertNotContains(PlayerFixture::PLAYER_WITH_STRIPE, $recipients);
    }

    public function testAResultAwaitingVerificationOrSolvedOutsideThePeriodIsNoActivity(): void
    {
        $period = DigestPeriod::fromKey('2030-W10');
        $this->insertSolve(PlayerFixture::PLAYER_REGULAR, '2030-03-05 12:00:00', suspicious: true);
        // Solved the Sunday before, logged in the period: it belongs to the week it was solved in
        $this->insertSolve(PlayerFixture::PLAYER_WITH_STRIPE, '2030-03-03 12:00:00', trackedAt: '2030-03-05 12:00:00');
        // Sunday 23:30 Prague = 22:30 UTC: still the period
        $this->insertSolve(PlayerFixture::PLAYER_ADMIN, '2030-03-10 22:30:00');

        $recipients = $this->query->of(ContentDigestKind::Activity, ContentDigestFrequency::Weekly, $period);

        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $recipients);
        self::assertNotContains(PlayerFixture::PLAYER_WITH_STRIPE, $recipients);
        self::assertContains(PlayerFixture::PLAYER_ADMIN, $recipients);
    }

    public function testOnlyTheFrequencyOfThePeriodAndNobodyMailedAboutItAlready(): void
    {
        $period = DigestPeriod::fromKey('2030-W10');
        $this->insertSolve(PlayerFixture::PLAYER_REGULAR, '2030-03-05 12:00:00');
        $this->insertSolve(PlayerFixture::PLAYER_WITH_STRIPE, '2030-03-05 12:00:00');
        $this->insertSolve(PlayerFixture::PLAYER_ADMIN, '2030-03-05 12:00:00');
        $this->setFrequency(PlayerFixture::PLAYER_REGULAR, 'monthly');
        $this->insertLog(PlayerFixture::PLAYER_WITH_STRIPE, '2030-W10', hadActivity: true, sentAt: '2030-03-11 07:00:00');
        $this->insertLog(PlayerFixture::PLAYER_ADMIN, '2030-W10', hadActivity: true, sentAt: '2030-03-11 07:00:00', status: 'deferred');

        $recipients = $this->query->of(ContentDigestKind::Activity, ContentDigestFrequency::Weekly, $period);

        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $recipients);
        self::assertNotContains(PlayerFixture::PLAYER_WITH_STRIPE, $recipients);
        self::assertContains(PlayerFixture::PLAYER_ADMIN, $recipients, 'A deferred e-mail is tried again');

        self::assertContains(
            PlayerFixture::PLAYER_REGULAR,
            $this->query->of(ContentDigestKind::Activity, ContentDigestFrequency::Monthly, DigestPeriod::fromKey('2030-03')),
        );
    }

    public function testTheNoActivityEmailGoesOnceAndAgainOnlyAfterTheNextActivity(): void
    {
        $player = PlayerFixture::PLAYER_REGULAR;
        $this->database->executeStatement('DELETE FROM puzzle_solving_time WHERE player_id = :id', ['id' => $player]);
        $week10 = DigestPeriod::fromKey('2030-W10');
        $week11 = DigestPeriod::fromKey('2030-W11');
        $week13 = DigestPeriod::fromKey('2030-W13');

        self::assertTrue($this->query->isRecipient($player, ContentDigestKind::NoActivity, ContentDigestFrequency::Weekly, $week10));
        self::assertFalse($this->query->isRecipient($player, ContentDigestKind::Activity, ContentDigestFrequency::Weekly, $week10));

        // Told once (Tuesday after week 10): never again while nothing happens
        $this->insertLog($player, '2030-W10', hadActivity: false, sentAt: '2030-03-12 07:00:00');
        self::assertFalse($this->query->isRecipient($player, ContentDigestKind::NoActivity, ContentDigestFrequency::Weekly, $week11));

        // A result in week 12, then a quiet week 13: one more no-activity e-mail is fine
        $this->insertSolve($player, '2030-03-20 12:00:00');
        self::assertTrue($this->query->isRecipient($player, ContentDigestKind::NoActivity, ContentDigestFrequency::Weekly, $week13));
    }

    public function testNoNoActivityEmailToSomebodyWhoLoggedSinceThePeriodStarted(): void
    {
        $player = PlayerFixture::PLAYER_REGULAR;
        $period = DigestPeriod::fromKey('2030-W10');
        // Logged on the Monday after the period - a back-dated result counts as being active too
        $this->insertSolve($player, '2029-12-01 12:00:00', trackedAt: '2030-03-11 06:00:00');

        self::assertFalse($this->query->isRecipient($player, ContentDigestKind::NoActivity, ContentDigestFrequency::Weekly, $period));
        self::assertFalse($this->query->isRecipient($player, ContentDigestKind::Activity, ContentDigestFrequency::Weekly, $period));
    }

    public function testNoNoActivityEmailAboutAPeriodBeforeTheAccountExisted(): void
    {
        $player = PlayerFixture::PLAYER_REGULAR;
        $this->database->executeStatement('DELETE FROM puzzle_solving_time WHERE player_id = :id', ['id' => $player]);
        $this->database->executeStatement("UPDATE player SET registered_at = '2030-03-11 06:00:00' WHERE id = :id", ['id' => $player]);

        self::assertFalse($this->query->isRecipient($player, ContentDigestKind::NoActivity, ContentDigestFrequency::Weekly, DigestPeriod::fromKey('2030-W10')));
    }

    private function setFrequency(string $playerId, string $frequency): void
    {
        $this->database->executeStatement('UPDATE player SET content_digest_frequency = :frequency WHERE id = :id', ['frequency' => $frequency, 'id' => $playerId]);
    }

    private function insertLog(string $playerId, string $periodKey, bool $hadActivity, string $sentAt, string $status = 'sent'): void
    {
        $this->database->executeStatement(
            "INSERT INTO content_digest_log (id, player_id, frequency, period_key, sent_at, had_activity, status)
             VALUES (:id, :playerId, 'weekly', :periodKey, :sentAt, :hadActivity, :status)",
            [
                'id' => Uuid::uuid7()->toString(),
                'playerId' => $playerId,
                'periodKey' => $periodKey,
                'sentAt' => $sentAt . '+00',
                'hadActivity' => $hadActivity ? 'true' : 'false',
                'status' => $status,
            ],
        );
    }

    private function insertSolve(string $playerId, string $finishedAt, null|string $trackedAt = null, bool $suspicious = false): void
    {
        $this->database->executeStatement(
            "INSERT INTO puzzle_solving_time
                (id, seconds_to_solve, player_id, puzzle_id, tracked_at, verified, team, finished_at,
                 comment, finished_puzzle_photo, first_attempt, unboxed, puzzlers_count, puzzling_type,
                 suspicious, pieces_count_snapshot)
             VALUES
                (:id, 3600, :playerId, :puzzleId, :trackedAt, true, NULL, :finishedAt,
                 NULL, NULL, false, false, 1, 'solo', :suspicious, 500)",
            [
                'id' => Uuid::uuid7()->toString(),
                'playerId' => $playerId,
                'puzzleId' => PuzzleFixture::PUZZLE_500_04,
                'trackedAt' => $trackedAt ?? $finishedAt,
                'finishedAt' => $finishedAt,
                'suspicious' => $suspicious ? 'true' : 'false',
            ],
        );
    }

    /**
     * @param list<string> $memberIds
     */
    private function insertTeamSolve(string $trackerId, array $memberIds, string $at): void
    {
        $teamId = Uuid::uuid7()->toString();
        sort($memberIds);
        $this->database->executeStatement(
            'INSERT INTO puzzling_team (id, composition_key, size, created_at, name) VALUES (:id, :key, :size, NOW(), NULL)',
            ['id' => $teamId, 'key' => sha1($teamId), 'size' => count($memberIds)],
        );

        foreach ($memberIds as $position => $memberId) {
            $this->database->executeStatement(
                'INSERT INTO puzzling_team_member (id, team_id, member_key, player_id, guest_name, position) VALUES (:id, :teamId, :key, :playerId, NULL, :position)',
                ['id' => Uuid::uuid7()->toString(), 'teamId' => $teamId, 'key' => $memberId, 'playerId' => $memberId, 'position' => $position],
            );
        }

        $this->database->executeStatement(
            "INSERT INTO puzzle_solving_time
                (id, seconds_to_solve, player_id, puzzle_id, tracked_at, verified, team, finished_at,
                 comment, finished_puzzle_photo, first_attempt, unboxed, puzzlers_count, puzzling_type,
                 suspicious, pieces_count_snapshot, puzzling_team_id)
             VALUES
                (:id, 3600, :playerId, :puzzleId, :at, true, NULL, :at,
                 NULL, NULL, false, false, 2, 'duo', false, 500, :teamId)",
            [
                'id' => Uuid::uuid7()->toString(),
                'playerId' => $trackerId,
                'puzzleId' => PuzzleFixture::PUZZLE_500_04,
                'at' => $at,
                'teamId' => $teamId,
            ],
        );
    }
}
