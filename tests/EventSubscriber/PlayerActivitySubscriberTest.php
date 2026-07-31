<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * User ids are randomized per run: the Redis dedup marker is not rolled back
 * by DAMA (same caveat as the login rate limiter), so a fixture player would
 * be silently short-circuited on every run after the first.
 */
final class PlayerActivitySubscriberTest extends WebTestCase
{
    public function testAuthenticatedRequestRecordsActivityOncePerDay(): void
    {
        $browser = self::createClient();
        [$userAccount, $player] = $this->seedAccount($browser);

        $browser->loginUser($userAccount, 'main');
        $browser->request('GET', '/en/edit-profile');
        self::assertResponseIsSuccessful();

        self::assertSame(1, $this->countRowsFor($browser, $player));

        // Second request the same day: the marker (and the upsert underneath) dedup it
        $browser->request('GET', '/en/edit-profile');

        self::assertSame(1, $this->countRowsFor($browser, $player));
    }

    public function testAnonymousRequestRecordsNothing(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle');
        self::assertResponseIsSuccessful();

        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);
        /** @var int|string $count */
        $count = $connection->fetchOne('SELECT COUNT(*) FROM player_activity_day');

        self::assertSame(0, (int) $count);
    }

    /**
     * @return array{UserAccount, Player}
     */
    private function seedAccount(KernelBrowser $browser): array
    {
        $email = sprintf('activity-sub+%s@example.com', bin2hex(random_bytes(4)));
        $userId = 'msp|' . bin2hex(random_bytes(8));

        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());
        $player = new Player(Uuid::uuid7(), 'PASU' . bin2hex(random_bytes(2)), $userId, $email, null, new DateTimeImmutable());

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->persist($player);
        $entityManager->flush();

        return [$userAccount, $player];
    }

    private function countRowsFor(KernelBrowser $browser, Player $player): int
    {
        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);

        /** @var int|string $count */
        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM player_activity_day WHERE player_id = :id',
            ['id' => $player->id->toString()],
        );

        return (int) $count;
    }
}
