<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\ConsoleCommands;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\ConsoleCommands\SandboxSeedConsoleCommand;
use SpeedPuzzling\Web\Message\CreateSandboxPlayers;
use SpeedPuzzling\Web\MessageHandler\CreateSandboxPlayersHandler;
use SpeedPuzzling\Web\Query\GetSandboxSimulationData;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Services\GenerateUniquePlayerCode;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use SpeedPuzzling\Web\Value\SandboxPuzzle;
use SpeedPuzzling\Web\Services\Sandbox\SandboxResultPlanner;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * What the seed and the activity cron log is something the add-time form's own message accepts - every planned result
 * is saved, solo, pair and team alike.
 */
final class SandboxSimulatedResultsTest extends KernelTestCase
{
    public function testEveryPlannedResultIsSaved(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $createPlayers = new CreateSandboxPlayersHandler(
            new SandboxMode(true),
            $container->get(UserAccountRepository::class),
            $container->get(PlayerRepository::class),
            $container->get(MembershipRepository::class),
            $container->get(UserPasswordHasherInterface::class),
            $container->get(GenerateUniquePlayerCode::class),
            $container->get(ClockInterface::class),
        );
        $createPlayers(new CreateSandboxPlayers(6, 'sandbox'));
        $container->get(EntityManagerInterface::class)->flush();

        $data = $container->get(GetSandboxSimulationData::class);
        $players = $data->players();
        self::assertCount(6, $players);

        $puzzles = array_map(
            static fn (string $id): SandboxPuzzle => new SandboxPuzzle($id, 500),
            [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_03, PuzzleFixture::PUZZLE_500_04],
        );

        $plan = $container->get(SandboxResultPlanner::class)->recent(
            $players,
            $puzzles,
            static fn (string $playerId): array => $data->solvedCounts($playerId),
            25,
            11,
        );

        $bus = $container->get(MessageBusInterface::class);

        foreach ($plan as $result) {
            $bus->dispatch(SandboxSeedConsoleCommand::message($result));
        }

        $database = $container->get(Connection::class);

        foreach ($plan as $result) {
            $saved = $database->fetchAssociative(
                'SELECT seconds_to_solve, first_attempt, player_id FROM puzzle_solving_time WHERE id = :id',
                ['id' => $result->timeId->toString()],
            );
            self::assertIsArray($saved, 'Not saved: ' . $result->timeId->toString());
            self::assertSame($result->seconds, $saved['seconds_to_solve']);
            self::assertSame($result->firstAttempt, $saved['first_attempt']);
            self::assertSame($result->player->id, $saved['player_id']);
        }
    }
}
