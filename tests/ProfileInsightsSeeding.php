<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Badge;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PlayerElo;
use SpeedPuzzling\Web\Entity\PlayerSkill;
use SpeedPuzzling\Web\Value\BadgeType;
use SpeedPuzzling\Web\Value\MetricConfidence;
use SpeedPuzzling\Web\Value\SkillTier;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Seeds the rows behind the profile insights of the public API (GET /me,
 * GET /players/{id}): MSP Rating (player_elo), skill tiers (player_skill) and
 * badges. The fixtures carry none of them - the puzzle-intelligence
 * recalculation is a batch job and the fixture solves do not reach its
 * qualification thresholds - so every test seeds exactly what it asserts on.
 * DAMA rolls the rows back with the test.
 */
trait ProfileInsightsSeeding
{
    protected function seedRating(KernelBrowser $browser, string $playerId, int $piecesCount, float $eloRating): void
    {
        $entityManager = $this->insightsEntityManager($browser);

        $player = $entityManager->find(Player::class, $playerId);
        self::assertNotNull($player);

        $entityManager->persist(new PlayerElo(
            id: Uuid::uuid7(),
            player: $player,
            piecesCount: $piecesCount,
            eloRating: $eloRating,
            computedAt: new DateTimeImmutable(),
        ));
        $entityManager->flush();
        $entityManager->clear();
    }

    protected function seedSkill(
        KernelBrowser $browser,
        string $playerId,
        int $piecesCount,
        SkillTier $tier,
        float $percentile,
        MetricConfidence $confidence,
        int $qualifyingPuzzlesCount,
    ): void {
        $entityManager = $this->insightsEntityManager($browser);

        $player = $entityManager->find(Player::class, $playerId);
        self::assertNotNull($player);

        $entityManager->persist(new PlayerSkill(
            id: Uuid::uuid7(),
            player: $player,
            piecesCount: $piecesCount,
            skillScore: 1.0,
            skillTier: $tier->value,
            skillPercentile: $percentile,
            confidence: $confidence->value,
            qualifyingPuzzlesCount: $qualifyingPuzzlesCount,
            computedAt: new DateTimeImmutable(),
        ));
        $entityManager->flush();
        $entityManager->clear();
    }

    protected function seedBadge(KernelBrowser $browser, string $playerId, BadgeType $type): void
    {
        $entityManager = $this->insightsEntityManager($browser);

        $player = $entityManager->find(Player::class, $playerId);
        self::assertNotNull($player);

        $entityManager->persist(new Badge(
            id: Uuid::uuid7(),
            player: $player,
            type: $type,
            earnedAt: new DateTimeImmutable(),
        ));
        $entityManager->flush();
        $entityManager->clear();
    }

    protected function optOutOfRankings(KernelBrowser $browser, string $playerId): void
    {
        $entityManager = $this->insightsEntityManager($browser);

        $player = $entityManager->find(Player::class, $playerId);
        self::assertNotNull($player);

        $player->changeRankingOptedOut(true);
        $entityManager->flush();
        $entityManager->clear();
    }

    private function insightsEntityManager(KernelBrowser $browser): EntityManagerInterface
    {
        /** @var ContainerInterface $container */
        $container = $browser->getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get('doctrine.orm.entity_manager');

        return $entityManager;
    }
}
