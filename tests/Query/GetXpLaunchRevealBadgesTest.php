<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetXpLaunchRevealBadges;
use SpeedPuzzling\Web\Results\XpLaunchRevealBadge;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetXpLaunchRevealBadgesTest extends KernelTestCase
{
    private GetXpLaunchRevealBadges $query;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetXpLaunchRevealBadges::class);
        $this->connection = $container->get(Connection::class);
    }

    public function testEveryEarnedTierFoldsIntoOneAchievementWithTheXpTheLedgerGranted(): void
    {
        $this->earn(BadgeType::PuzzlesSolved, 1);
        $this->earn(BadgeType::PuzzlesSolved, 2);
        $goldId = $this->earn(BadgeType::PuzzlesSolved, 3);

        $badges = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertCount(1, $badges);
        self::assertSame(BadgeType::PuzzlesSolved, $badges[0]->type);
        self::assertSame(BadgeTier::Gold, $badges[0]->tier);
        self::assertSame(5 + 10 + 25, $badges[0]->points);
        self::assertSame($goldId, $badges[0]->badgeId, 'The highest tier row is the one the reveal endpoint flips');
        self::assertFalse($badges[0]->revealed);
        self::assertSame('badges.requirement.puzzles_solved_3', $badges[0]->requirementTranslationKey());
    }

    public function testCrescendoPutsASingleTierAchievementRightBeforeTheTopTier(): void
    {
        foreach ([1, 2, 3, 4, 5] as $tier) {
            $this->earn(BadgeType::FirstTry, $tier);
        }

        $this->earn(BadgeType::Marathoner, 1);
        $this->earn(BadgeType::Marathoner, 2);
        $this->earn(BadgeType::TeamPlayer, 1);
        $this->earn(BadgeType::TeamPlayer, 2);
        $this->earn(BadgeType::Supporter, null);
        $this->earn(BadgeType::Streak, 1);

        $badges = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        // tier by tier, catalogue order within a tier (Team Spirit before Marathoner), Early Adopter before the Diamond
        self::assertSame(
            ['streak', 'team_player', 'marathoner', 'supporter', 'first_try'],
            array_map(static fn (XpLaunchRevealBadge $badge): string => $badge->type->value, $badges),
        );
        self::assertSame(
            [5, 15, 15, BadgeTier::SINGLE_TIER_POINTS, 190],
            array_map(static fn (XpLaunchRevealBadge $badge): int => $badge->points, $badges),
        );
        self::assertSame('badges.description.supporter', $badges[3]->requirementTranslationKey());
    }

    public function testASingleTierAchievementAloneIsStillShown(): void
    {
        $this->earn(BadgeType::Supporter, null);

        $badges = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertCount(1, $badges);
        self::assertNull($badges[0]->tier);
        self::assertSame(BadgeTier::SINGLE_TIER_POINTS, $badges[0]->points);
    }

    public function testRevealedFollowsTheHighestTier(): void
    {
        $this->earn(BadgeType::Streak, 1, revealed: true);
        $this->earn(BadgeType::Streak, 2);
        $this->earn(BadgeType::TeamPlayer, 1, revealed: true);

        $badges = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertSame([BadgeType::TeamPlayer, BadgeType::Streak], [$badges[0]->type, $badges[1]->type]);
        self::assertTrue($badges[0]->revealed);
        self::assertFalse($badges[1]->revealed, 'Silver is new, though Bronze was revealed before');
    }

    public function testUnknownAchievementsAndTiersAreLeftOut(): void
    {
        $this->insert('removed_experimental_badge', 1);
        $this->insert(BadgeType::Streak->value, 9);
        $this->earn(BadgeType::Unboxed, 1);

        $badges = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertCount(1, $badges);
        self::assertSame(BadgeType::Unboxed, $badges[0]->type);
    }

    public function testNoAchievementsNoCards(): void
    {
        self::assertSame([], $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR));
    }

    private function earn(BadgeType $type, null|int $tier, bool $revealed = false): string
    {
        return $this->insert($type->value, $tier, $revealed);
    }

    private function insert(string $type, null|int $tier, bool $revealed = false): string
    {
        $id = Uuid::uuid7()->toString();

        $this->connection->executeStatement(
            'INSERT INTO badge (id, player_id, type, earned_at, tier, revealed_at) VALUES (:id, :player, :type, NOW(), :tier, :revealed)',
            [
                'id' => $id,
                'player' => PlayerFixture::PLAYER_REGULAR,
                'type' => $type,
                'tier' => $tier,
                'revealed' => $revealed ? '2026-10-01 12:00:00' : null,
            ],
        );

        return $id;
    }
}
