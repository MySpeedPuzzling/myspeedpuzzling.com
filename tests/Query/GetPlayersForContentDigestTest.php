<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetPlayersForContentDigest;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\DigestPeriod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayersForContentDigestTest extends KernelTestCase
{
    private GetPlayersForContentDigest $query;
    private Connection $database;
    private string $periodKey;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->query = $container->get(GetPlayersForContentDigest::class);
        $this->database = $container->get(Connection::class);
        $this->periodKey = DigestPeriod::weeklyFor($container->get(ClockInterface::class)->now())->key;
    }

    public function testDefaultOnPlayersAreEligible(): void
    {
        $eligible = $this->query->weekly($this->periodKey);

        // All five fixture players have emails, notifications on and the weekly default.
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $eligible);
        self::assertContains(PlayerFixture::PLAYER_WITH_STRIPE, $eligible);
    }

    public function testPeriodLogExcludes(): void
    {
        $this->insertLog(PlayerFixture::PLAYER_REGULAR, $this->periodKey, hadActivity: true);

        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $this->query->weekly($this->periodKey));
    }

    public function testFrequencyNoneExcludes(): void
    {
        $this->database->executeStatement(
            "UPDATE player SET content_digest_frequency = 'none' WHERE id = :playerId",
            ['playerId' => PlayerFixture::PLAYER_REGULAR],
        );

        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $this->query->weekly($this->periodKey));
    }

    public function testExperienceOptOutExcludes(): void
    {
        $this->database->executeStatement(
            'UPDATE player SET experience_system_opted_out = true WHERE id = :playerId',
            ['playerId' => PlayerFixture::PLAYER_REGULAR],
        );

        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $this->query->weekly($this->periodKey));
    }

    public function testNeverTwoNoActivityDigestsInARow(): void
    {
        // Last week's digest was the no-activity variant and nothing was solved since →
        // skip this week entirely.
        $this->database->executeStatement('DELETE FROM puzzle_solving_time WHERE player_id = :playerId', [
            'playerId' => PlayerFixture::PLAYER_REGULAR,
        ]);
        $this->insertLog(PlayerFixture::PLAYER_REGULAR, '2026-W01', hadActivity: false);

        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $this->query->weekly($this->periodKey));

        // One solve after that send → digests resume.
        $this->insertSolve(PlayerFixture::PLAYER_REGULAR);

        self::assertContains(PlayerFixture::PLAYER_REGULAR, $this->query->weekly($this->periodKey));
    }

    public function testActivityDigestDoesNotBlockNextWeek(): void
    {
        $this->insertLog(PlayerFixture::PLAYER_REGULAR, '2026-W01', hadActivity: true);

        self::assertContains(PlayerFixture::PLAYER_REGULAR, $this->query->weekly($this->periodKey));
    }

    private function insertLog(string $playerId, string $periodKey, bool $hadActivity): void
    {
        $this->database->executeStatement(
            "INSERT INTO content_digest_log (id, player_id, digest_type, period_key, sent_at, had_activity, status)
             VALUES (:id, :playerId, 'weekly', :periodKey, NOW() - INTERVAL '7 days', :hadActivity, 'sent')",
            [
                'id' => Uuid::uuid7()->toString(),
                'playerId' => $playerId,
                'periodKey' => $periodKey,
                'hadActivity' => $hadActivity ? 'true' : 'false',
            ],
        );
    }

    private function insertSolve(string $playerId): void
    {
        $this->database->executeStatement(
            'INSERT INTO puzzle_solving_time
                (id, seconds_to_solve, player_id, puzzle_id, tracked_at, verified, team, finished_at,
                 comment, finished_puzzle_photo, first_attempt, unboxed, puzzlers_count, puzzling_type,
                 suspicious, pieces_count_snapshot)
             VALUES
                (:id, 3600, :playerId, :puzzleId, NOW(), true, NULL, NOW(),
                 NULL, NULL, false, false, 1, \'solo\', false, 500)',
            [
                'id' => Uuid::uuid7()->toString(),
                'playerId' => $playerId,
                'puzzleId' => PuzzleFixture::PUZZLE_500_04,
            ],
        );
    }
}
