<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\GrantSandboxMembership;
use SpeedPuzzling\Web\MessageHandler\GrantSandboxMembershipHandler;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GrantSandboxMembershipHandlerTest extends KernelTestCase
{
    private GrantSandboxMembershipHandler $handler;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->handler = new GrantSandboxMembershipHandler(
            new SandboxMode(true),
            $container->get(PlayerRepository::class),
            $container->get(MembershipRepository::class),
            $container->get(ClockInterface::class),
        );
        $this->database = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testAPlayerWithoutMembershipBecomesAMember(): void
    {
        self::assertFalse($this->database->fetchOne('SELECT 1 FROM membership WHERE player_id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]));

        ($this->handler)(new GrantSandboxMembership(PlayerFixture::PLAYER_REGULAR_USER_ID));
        $this->entityManager->flush();

        self::assertSame(
            '2099-12-31 23:59:59',
            $this->database->fetchOne('SELECT granted_until FROM membership WHERE player_id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]),
        );
    }

    public function testAnExistingMembershipIsLeftAlone(): void
    {
        $before = $this->database->fetchAssociative('SELECT * FROM membership WHERE player_id = :id', ['id' => PlayerFixture::PLAYER_WITH_STRIPE]);
        self::assertIsArray($before);

        ($this->handler)(new GrantSandboxMembership(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID));
        $this->entityManager->flush();

        self::assertSame($before, $this->database->fetchAssociative('SELECT * FROM membership WHERE player_id = :id', ['id' => PlayerFixture::PLAYER_WITH_STRIPE]));
    }

    public function testAnUnknownAccountIsNoError(): void
    {
        ($this->handler)(new GrantSandboxMembership('msp|nobody'));
        $this->entityManager->flush();

        self::assertFalse($this->database->fetchOne(
            'SELECT 1 FROM membership m JOIN player p ON p.id = m.player_id WHERE p.user_id = :userId',
            ['userId' => 'msp|nobody'],
        ));
    }
}
