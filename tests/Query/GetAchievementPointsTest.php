<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetAchievementPoints;
use SpeedPuzzling\Web\Query\GetXpLeaderboard;
use SpeedPuzzling\Web\Services\Badges\BadgeEvaluator;
use SpeedPuzzling\Web\Value\BadgeTier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * player.achievement_points is denormalized for fast leaderboards: BadgeEvaluator
 * re-anchors it (absolute set) on every evaluation, so the 15-minute recalc cron
 * self-heals any drift. These tests pin the whole loop: badge rows → evaluator →
 * column → AP query + AP ladder.
 */
final class GetAchievementPointsTest extends KernelTestCase
{
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->database = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testEvaluatorAnchorsColumnAndQueriesReadIt(): void
    {
        // A fresh player with no solves: the evaluator earns nothing new and purely
        // re-anchors the denormalized total from the existing badge rows.
        $playerId = $this->insertFreshPlayer(member: true);

        self::assertSame(0, $this->columnValue($playerId));

        $expected = 0;
        foreach (BadgeTier::cases() as $tier) {
            $this->insertBadge($playerId, 'puzzles_solved', $tier->value);
            $expected += $tier->points();
        }
        $this->insertBadge($playerId, 'supporter', null);
        $expected += BadgeTier::SINGLE_TIER_POINTS;

        // Full ladder (5+10+25+50+100) + Early Adopter (25) = 215 — the §1.6 locked values.
        self::assertSame(215, $expected);

        $this->evaluate($playerId);

        self::assertSame($expected, self::getContainer()->get(GetAchievementPoints::class)->forPlayer($playerId));
        self::assertSame($expected, $this->columnValue($playerId));

        // The AP ladder ranks straight off the column (member + public profile required).
        $mine = null;
        foreach (self::getContainer()->get(GetXpLeaderboard::class)->achievementPoints(null, null) as $row) {
            if ($row->playerId === $playerId) {
                $mine = $row;
            }
        }

        self::assertNotNull($mine);
        self::assertSame($expected, $mine->value);
        self::assertSame($expected, $mine->achievementPoints);
    }

    public function testEvaluationSelfHealsManualDrift(): void
    {
        $playerId = $this->insertFreshPlayer(member: false);
        $this->insertBadge($playerId, 'streak', BadgeTier::Gold->value);

        // Simulate drift (e.g. a badge granted via raw SQL without the evaluator).
        $this->database->executeStatement(
            'UPDATE player SET achievement_points = 999 WHERE id = :id',
            ['id' => $playerId],
        );

        $this->evaluate($playerId);

        self::assertSame(
            BadgeTier::Gold->points(),
            self::getContainer()->get(GetAchievementPoints::class)->forPlayer($playerId),
        );
    }

    private function columnValue(string $playerId): int
    {
        $value = $this->database->fetchOne(
            'SELECT achievement_points FROM player WHERE id = :id',
            ['id' => $playerId],
        );

        return is_numeric($value) ? (int) $value : -1;
    }

    private function evaluate(string $playerId): void
    {
        self::getContainer()->get(BadgeEvaluator::class)->recalculateForPlayer($playerId);
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function insertFreshPlayer(bool $member): string
    {
        $playerId = Uuid::uuid7()->toString();

        $this->database->executeStatement(
            "INSERT INTO player (id, code, name, email, registered_at) VALUES (:id, :code, 'AP Tester', :email, NOW())",
            ['id' => $playerId, 'code' => 'ap-' . substr($playerId, -8), 'email' => 'ap-' . substr($playerId, -8) . '@test.local'],
        );

        if ($member) {
            $this->database->executeStatement(
                "INSERT INTO membership (id, player_id, created_at, granted_until) VALUES (:id, :playerId, NOW(), NOW() + INTERVAL '30 days')",
                ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId],
            );
        }

        return $playerId;
    }

    private function insertBadge(string $playerId, string $type, null|int $tier): void
    {
        $this->database->executeStatement(
            'INSERT INTO badge (id, player_id, type, earned_at, tier) VALUES (:id, :playerId, :type, NOW(), :tier)',
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'type' => $type, 'tier' => $tier],
        );
    }
}
