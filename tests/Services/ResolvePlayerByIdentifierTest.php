<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Services\ResolvePlayerByIdentifier;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The ops-side lookup behind `myspeedpuzzling:player:delete`: whatever the
 * operator has at hand - UUID, player code, e-mail - must land on the right
 * player, and the login e-mail must win over the free-text profile e-mail.
 */
final class ResolvePlayerByIdentifierTest extends KernelTestCase
{
    private ResolvePlayerByIdentifier $resolver;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resolver = self::getContainer()->get(ResolvePlayerByIdentifier::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testResolvesByUuid(): void
    {
        $player = $this->resolver->resolve(PlayerFixture::PLAYER_REGULAR);

        self::assertSame(PlayerFixture::PLAYER_REGULAR, $player->id->toString());
    }

    public function testResolvesByCodeWithOrWithoutHashAndCaseInsensitively(): void
    {
        $regular = $this->entityManager->find(Player::class, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($regular);

        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->resolver->resolve($regular->code)->id->toString());
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->resolver->resolve('#' . strtoupper($regular->code))->id->toString());
    }

    public function testResolvesByProfileEmailWhenThereIsNoUserAccount(): void
    {
        $player = $this->resolver->resolve(PlayerFixture::PLAYER_REGULAR_EMAIL);

        self::assertSame(PlayerFixture::PLAYER_REGULAR, $player->id->toString());
    }

    public function testTheLoginEmailWinsOverAProfileEmailPointingElsewhere(): void
    {
        // Login e-mail X belongs to account A; a different player B has typed X into
        // their profile contact field. "X" must mean A.
        $loginEmail = sprintf('login+%s@example.com', bin2hex(random_bytes(4)));
        $userId = 'msp|' . bin2hex(random_bytes(4));

        $owner = new Player(Uuid::uuid7(), 'OWN' . bin2hex(random_bytes(2)), $userId, 'contact@example.com', 'Owner', new DateTimeImmutable());
        $impostor = new Player(Uuid::uuid7(), 'IMP' . bin2hex(random_bytes(2)), 'msp|' . bin2hex(random_bytes(4)), $loginEmail, 'Impostor', new DateTimeImmutable());

        $this->entityManager->persist($owner);
        $this->entityManager->persist($impostor);
        $this->entityManager->persist(new UserAccount(Uuid::uuid7(), $userId, $loginEmail, new DateTimeImmutable()));
        $this->entityManager->flush();

        self::assertSame($owner->id->toString(), $this->resolver->resolve(strtoupper($loginEmail))->id->toString());
    }

    public function testThrowsForAnUnknownIdentifier(): void
    {
        $this->expectException(PlayerNotFound::class);

        $this->resolver->resolve('nobody-' . bin2hex(random_bytes(4)));
    }

    public function testThrowsForAnUnknownEmail(): void
    {
        $this->expectException(PlayerNotFound::class);

        $this->resolver->resolve(sprintf('nobody+%s@example.com', bin2hex(random_bytes(4))));
    }
}
