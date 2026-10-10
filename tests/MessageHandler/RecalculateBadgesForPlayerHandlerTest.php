<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\Badge;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\MessageHandler\RecalculateBadgesForPlayerHandler;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\FakeBadgeEvaluator;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Achievements are persisted and never mailed - there is no per-achievement e-mail any more
 * (docs/features/xp-levels/launch-2026-10.md).
 */
final class RecalculateBadgesForPlayerHandlerTest extends KernelTestCase
{
    public function testNewAchievementsAreNotMailed(): void
    {
        self::bootKernel();
        $player = self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR);
        $now = new DateTimeImmutable('2026-04-16 12:00:00');

        $handler = new RecalculateBadgesForPlayerHandler(new FakeBadgeEvaluator([
            Badge::earn($player, BadgeType::PuzzlesSolved, $now, BadgeTier::Bronze),
            Badge::earn($player, BadgeType::Streak, $now, BadgeTier::Bronze),
        ]));

        $handler(new RecalculateBadgesForPlayer(PlayerFixture::PLAYER_REGULAR));

        // The handler has no bus to send anything with - the evaluator's persistence is all it does
        $constructor = new \ReflectionMethod(RecalculateBadgesForPlayerHandler::class, '__construct');

        foreach ($constructor->getParameters() as $parameter) {
            self::assertNotSame(MessageBusInterface::class, (string) $parameter->getType());
        }
    }
}
