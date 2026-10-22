<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetContentDigestRecipients;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\ContentDigestFrequency;
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

        $recipients = $this->query->withActivity(ContentDigestFrequency::Weekly, $period);

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

        $recipients = $this->query->withActivity(ContentDigestFrequency::Weekly, $period);

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

        $recipients = $this->query->withActivity(ContentDigestFrequency::Weekly, $period);

        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $recipients);
        self::assertNotContains(PlayerFixture::PLAYER_WITH_STRIPE, $recipients);
        self::assertContains(PlayerFixture::PLAYER_ADMIN, $recipients, 'A deferred e-mail is tried again');

        self::assertContains(
            PlayerFixture::PLAYER_REGULAR,
            $this->query->withActivity(ContentDigestFrequency::Monthly, DigestPeriod::fromKey('2030-03')),
        );
    }

    public function testPlayersAreMissedAfterTwentyEightDaysWithoutAnything(): void
    {
        $player = PlayerFixture::PLAYER_REGULAR;
        $this->onlyResult($player, '2030-02-01 18:00:00');

        // 27 days 23 hours: not yet
        self::assertFalse($this->query->isMissed($player, ContentDigestFrequency::Weekly, $this->utc('2030-03-01 17:00:00')));
        // 28 days to the second: missed
        self::assertTrue($this->query->isMissed($player, ContentDigestFrequency::Weekly, $this->utc('2030-03-01 18:00:00')));
        self::assertContains($player, $this->query->missed(ContentDigestFrequency::Weekly, $this->utc('2030-03-05 08:00:00')));
        self::assertEquals($this->utc('2030-02-01 18:00:00'), $this->query->lastLoggedAt($player));
    }

    public function testDormantAccountsAreLeftToTheNewsletter(): void
    {
        $player = PlayerFixture::PLAYER_REGULAR;
        $this->onlyResult($player, '2029-06-01 12:00:00');

        // No result and no visit for 9 months: dormant from 1 March 2030
        self::assertTrue($this->query->isMissed($player, ContentDigestFrequency::Weekly, $this->utc('2030-02-28 12:00:00')));
        self::assertFalse($this->query->isMissed($player, ContentDigestFrequency::Weekly, $this->utc('2030-03-01 12:00:00')), 'Exactly 9 months: dormant');
    }

    public function testAVisitorWithAnOldResultIsMissed(): void
    {
        $now = $this->utc('2030-03-12 06:00:00');
        $dayVisitor = PlayerFixture::PLAYER_REGULAR;
        $signInVisitor = PlayerFixture::PLAYER_WITH_STRIPE;
        $this->onlyResult($dayVisitor, '2028-01-01 12:00:00');
        $this->onlyResult($signInVisitor, '2028-01-01 12:00:00');

        // A signed-in day on the site 8 months ago keeps the account in; one 9 months and a day ago does not
        $this->visitedOn($dayVisitor, '2029-07-12');
        self::assertTrue($this->query->isMissed($dayVisitor, ContentDigestFrequency::Weekly, $now));
        self::assertFalse($this->query->isMissed($dayVisitor, ContentDigestFrequency::Weekly, $this->utc('2030-04-13 06:00:00')));

        // A sign-in counts the same
        self::assertFalse($this->query->isMissed($signInVisitor, ContentDigestFrequency::Weekly, $now));
        $this->database->executeStatement(
            "UPDATE user_account SET last_login_at = '2029-06-13 08:00:00+00' WHERE user_id = (SELECT user_id FROM player WHERE id = :id)",
            ['id' => $signInVisitor],
        );
        self::assertTrue($this->query->isMissed($signInVisitor, ContentDigestFrequency::Weekly, $now));
        self::assertFalse($this->query->isMissed($signInVisitor, ContentDigestFrequency::Weekly, $this->utc('2030-03-14 08:00:00')));
    }

    private function visitedOn(string $playerId, string $day): void
    {
        $this->database->executeStatement(
            'INSERT INTO player_activity_day (id, player_id, day, first_seen_at) VALUES (:id, :playerId, :day, :day) ON CONFLICT DO NOTHING',
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'day' => $day],
        );
    }

    public function testTheFirstWaveCanBeSlicedByTheLastResult(): void
    {
        $this->onlyResult(PlayerFixture::PLAYER_REGULAR, '2030-01-20 12:00:00');
        $this->onlyResult(PlayerFixture::PLAYER_WITH_STRIPE, '2029-07-01 12:00:00');
        $now = $this->utc('2030-03-12 06:00:00');

        $recent = $this->query->missedWithLastLogged(ContentDigestFrequency::Weekly, $now, lastActivityAfter: $now->modify('-6 months'));
        self::assertArrayHasKey(PlayerFixture::PLAYER_REGULAR, $recent);
        self::assertArrayNotHasKey(PlayerFixture::PLAYER_WITH_STRIPE, $recent);
        self::assertEquals($this->utc('2030-01-20 12:00:00'), $recent[PlayerFixture::PLAYER_REGULAR]);

        $older = $this->query->missedWithLastLogged(ContentDigestFrequency::Weekly, $now, lastActivityBefore: $now->modify('-6 months'));
        self::assertArrayHasKey(PlayerFixture::PLAYER_WITH_STRIPE, $older);
        self::assertArrayNotHasKey(PlayerFixture::PLAYER_REGULAR, $older);
    }

    public function testOncePerSpellAndAgainOnlyAfterTheNextActivity(): void
    {
        $player = PlayerFixture::PLAYER_REGULAR;
        $this->onlyResult($player, '2030-02-01 18:00:00');

        // Told on 5 March: never again while nothing happens
        $this->insertLog($player, 'miss-you:2030-02-01T18:00:00', hadActivity: false, sentAt: '2030-03-05 07:00:00');
        self::assertFalse($this->query->isMissed($player, ContentDigestFrequency::Weekly, $this->utc('2030-03-12 08:00:00')));
        self::assertFalse($this->query->isMissed($player, ContentDigestFrequency::Weekly, $this->utc('2031-01-01 08:00:00')));

        // A result on 10 March restarts the clock: missed again from 7 April
        $this->insertSolve($player, '2030-03-10 12:00:00');
        self::assertFalse($this->query->isMissed($player, ContentDigestFrequency::Weekly, $this->utc('2030-04-06 08:00:00')));
        self::assertTrue($this->query->isMissed($player, ContentDigestFrequency::Weekly, $this->utc('2030-04-07 12:00:00')));
    }

    public function testAPairResultOrABackDatedOneIsActivityToo(): void
    {
        $player = PlayerFixture::PLAYER_WITH_FAVORITES;
        $this->onlyResult($player, '2030-01-01 12:00:00');
        $this->insertTeamSolve(PlayerFixture::PLAYER_PRIVATE, [PlayerFixture::PLAYER_PRIVATE, $player], '2030-02-20 12:00:00');
        // Logged on 25 February, solved long before
        $this->insertSolve($player, '2029-06-01 12:00:00', trackedAt: '2030-02-25 12:00:00');

        self::assertFalse($this->query->isMissed($player, ContentDigestFrequency::Weekly, $this->utc('2030-03-20 08:00:00')));
        self::assertTrue($this->query->isMissed($player, ContentDigestFrequency::Weekly, $this->utc('2030-03-26 08:00:00')));
    }

    public function testNobodyIsMissedWhoNeverLoggedAResultOrSwitchedTheEmailOff(): void
    {
        $never = PlayerFixture::PLAYER_REGULAR;
        $this->database->executeStatement('DELETE FROM puzzle_solving_time WHERE player_id = :id', ['id' => $never]);
        $this->database->executeStatement('DELETE FROM puzzling_team_member WHERE player_id = :id', ['id' => $never]);
        $off = PlayerFixture::PLAYER_WITH_STRIPE;
        $this->setFrequency($off, 'off');
        $now = $this->utc('2031-01-01 08:00:00');

        self::assertNull($this->query->lastLoggedAt($never));
        self::assertFalse($this->query->isMissed($never, ContentDigestFrequency::Weekly, $now));
        self::assertNotContains($off, $this->query->missed(ContentDigestFrequency::Weekly, $now));
    }

    private function onlyResult(string $playerId, string $at): void
    {
        $this->database->executeStatement('DELETE FROM puzzle_solving_time WHERE player_id = :id', ['id' => $playerId]);
        $this->database->executeStatement('DELETE FROM puzzling_team_member WHERE player_id = :id', ['id' => $playerId]);
        $this->insertSolve($playerId, $at);
    }

    private function utc(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
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
            'INSERT INTO puzzling_team (id, composition_key, size, created_at, name) VALUES (:id, :key, :size, ' . TestClock::SQL_NOW . ', NULL)',
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
