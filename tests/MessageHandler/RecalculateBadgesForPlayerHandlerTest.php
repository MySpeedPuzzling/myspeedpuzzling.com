<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\Badge;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\SendBadgeNotificationEmail;
use SpeedPuzzling\Web\MessageHandler\RecalculateBadgesForPlayerHandler;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\FakeBadgeEvaluator;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RecalculateBadgesForPlayerHandlerTest extends KernelTestCase
{
    private Connection $connection;
    private PlayerRepository $playerRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->connection = $container->get(Connection::class);
        $this->playerRepository = $container->get(PlayerRepository::class);

        $this->connection->executeStatement('DELETE FROM badge WHERE player_id IN (:players)', [
            'players' => [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE],
        ], ['players' => ArrayParameterType::STRING]);
    }

    public function testDoesNothingWhenEvaluatorReturnsNoNewBadges(): void
    {
        $busSpy = new MessageBusSpy();
        $handler = new RecalculateBadgesForPlayerHandler(
            badgeEvaluator: new FakeBadgeEvaluator([]),
            commandBus: $busSpy,
        );

        $handler(new RecalculateBadgesForPlayer(PlayerFixture::PLAYER_REGULAR));

        self::assertCount(0, $busSpy->dispatched);
    }

    public function testDispatchesNotificationEmailWithHighestTierPerType(): void
    {
        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        $now = new DateTimeImmutable('2026-04-16 12:00:00');

        $badges = [
            Badge::earn($player, BadgeType::PuzzlesSolved, $now, BadgeTier::Bronze),
            Badge::earn($player, BadgeType::PuzzlesSolved, $now, BadgeTier::Silver),
            Badge::earn($player, BadgeType::PuzzlesSolved, $now, BadgeTier::Gold),
            Badge::earn($player, BadgeType::Streak, $now, BadgeTier::Bronze),
        ];

        $busSpy = new MessageBusSpy();
        $handler = new RecalculateBadgesForPlayerHandler(
            badgeEvaluator: new FakeBadgeEvaluator($badges),
            commandBus: $busSpy,
        );

        $handler(new RecalculateBadgesForPlayer(PlayerFixture::PLAYER_REGULAR));

        self::assertCount(1, $busSpy->dispatched);

        $message = $busSpy->dispatched[0];
        self::assertInstanceOf(SendBadgeNotificationEmail::class, $message);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $message->playerId);
        self::assertCount(2, $message->badgeSummary);

        $byType = [];
        foreach ($message->badgeSummary as $entry) {
            $byType[$entry['type']->value] = $entry['tier']?->value;
        }
        self::assertSame(3, $byType['puzzles_solved']);
        self::assertSame(1, $byType['streak']);
    }
}

final class MessageBusSpy implements MessageBusInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->dispatched[] = $message;

        return new Envelope($message);
    }
}
