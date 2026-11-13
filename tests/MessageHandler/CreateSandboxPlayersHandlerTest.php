<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\CreateSandboxPlayers;
use SpeedPuzzling\Web\MessageHandler\CreateSandboxPlayersHandler;
use SpeedPuzzling\Web\Query\GetSandboxSimulationData;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Services\GenerateUniquePlayerCode;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use SpeedPuzzling\Web\Services\Sandbox\SandboxPeople;
use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateSandboxPlayersHandlerTest extends KernelTestCase
{
    public function testCreatesMembersAndOneFreePlayerOnce(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $handler = new CreateSandboxPlayersHandler(
            new SandboxMode(true),
            $container->get(UserAccountRepository::class),
            $container->get(PlayerRepository::class),
            $container->get(MembershipRepository::class),
            $container->get(UserPasswordHasherInterface::class),
            $container->get(GenerateUniquePlayerCode::class),
            $container->get(ClockInterface::class),
        );
        $entityManager = $container->get(EntityManagerInterface::class);
        $database = $container->get(Connection::class);

        self::assertSame(4, $handler(new CreateSandboxPlayers(3, 'sandbox')));
        $entityManager->flush();

        $rows = $database->fetchAllAssociative(<<<SQL
SELECT ua.email, ua.email_verified_at IS NOT NULL AS verified, p.name, p.country, p.is_private, m.granted_until
FROM user_account ua
JOIN player p ON p.user_id = ua.user_id
JOIN membership m ON m.player_id = p.id
WHERE ua.email LIKE :like
ORDER BY ua.email
SQL, ['like' => SandboxPeople::EMAIL_LIKE]);

        self::assertCount(4, $rows);
        self::assertSame(['test-user+1@myspeedpuzzling.com', 'test-user+2@myspeedpuzzling.com', 'test-user+3@myspeedpuzzling.com', 'test-user+free@myspeedpuzzling.com'], array_column($rows, 'email'));

        foreach ($rows as $row) {
            self::assertTrue($row['verified']);
            self::assertFalse($row['is_private']);
            self::assertNotNull($row['country']);
            self::assertIsString($row['granted_until']);

            if ($row['email'] === SandboxPeople::FREE_PLAYER_EMAIL) {
                self::assertLessThan(TestClock::FROZEN_NOW, $row['granted_until']);
            } else {
                self::assertSame('2099-12-31 23:59:59', $row['granted_until']);
            }
        }

        // The simulation sees the three made-up players, never the free account
        self::assertCount(3, $container->get(GetSandboxSimulationData::class)->players());

        // Again: everybody exists already
        self::assertSame(0, $handler(new CreateSandboxPlayers(3, 'sandbox')));
    }
}
