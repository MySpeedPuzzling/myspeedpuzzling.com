<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\LentPuzzle;
use SpeedPuzzling\Web\Entity\LentPuzzleTransfer;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Value\TransferType;

final class LentPuzzleTransferFixture extends Fixture implements DependentFixtureInterface
{
    public const string TRANSFER_01 = '018d000d-0000-0000-0000-000000000001';
    public const string TRANSFER_02 = '018d000d-0000-0000-0000-000000000002';
    public const string TRANSFER_03 = '018d000d-0000-0000-0000-000000000003';
    public const string TRANSFER_04 = '018d000d-0000-0000-0000-000000000004';
    public const string TRANSFER_05 = '018d000d-0000-0000-0000-000000000005';
    public const string TRANSFER_06 = '018d000d-0000-0000-0000-000000000006';
    public const string TRANSFER_07 = '018d000d-0000-0000-0000-000000000007';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $player1 = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $player4 = $this->getReference(PlayerFixture::PLAYER_WITH_FAVORITES, Player::class);
        $player5 = $this->getReference(PlayerFixture::PLAYER_WITH_STRIPE, Player::class);

        $lent01 = $this->getReference(LentPuzzleFixture::LENT_01, LentPuzzle::class);
        $lent02 = $this->getReference(LentPuzzleFixture::LENT_02, LentPuzzle::class);
        $lent03 = $this->getReference(LentPuzzleFixture::LENT_03, LentPuzzle::class);
        $lent04 = $this->getReference(LentPuzzleFixture::LENT_04, LentPuzzle::class);
        $lent05 = $this->getReference(LentPuzzleFixture::LENT_05, LentPuzzle::class);

        // LENT_01: Initial lend from owner (player5) to player1
        $transfer01 = $this->createLentPuzzleTransfer(
            id: self::TRANSFER_01,
            lentPuzzle: $lent01,
            fromPlayer: $player5,
            fromPlayerName: null,
            toPlayer: $player1,
            toPlayerName: null,
            transferType: TransferType::InitialLend,
            daysAgo: 30,
        );
        $manager->persist($transfer01);
        $this->addReference(self::TRANSFER_01, $transfer01);

        // LENT_02: Initial lend from owner (player5) to non-registered person
        $transfer02 = $this->createLentPuzzleTransfer(
            id: self::TRANSFER_02,
            lentPuzzle: $lent02,
            fromPlayer: $player5,
            fromPlayerName: null,
            toPlayer: null,
            toPlayerName: 'Jane Doe',
            transferType: TransferType::InitialLend,
            daysAgo: 20,
        );
        $manager->persist($transfer02);
        $this->addReference(self::TRANSFER_02, $transfer02);

        // LENT_03: Initial lend from owner (player5) to player1
        $transfer03 = $this->createLentPuzzleTransfer(
            id: self::TRANSFER_03,
            lentPuzzle: $lent03,
            fromPlayer: $player5,
            fromPlayerName: null,
            toPlayer: $player1,
            toPlayerName: null,
            transferType: TransferType::InitialLend,
            daysAgo: 45,
        );
        $manager->persist($transfer03);
        $this->addReference(self::TRANSFER_03, $transfer03);

        // LENT_03: Return from player1 back to owner (player5)
        $transfer04 = $this->createLentPuzzleTransfer(
            id: self::TRANSFER_04,
            lentPuzzle: $lent03,
            fromPlayer: $player1,
            fromPlayerName: null,
            toPlayer: $player5,
            toPlayerName: null,
            transferType: TransferType::Return,
            daysAgo: 40,
        );
        $manager->persist($transfer04);
        $this->addReference(self::TRANSFER_04, $transfer04);

        // LENT_04: Initial lend from owner (player5) to player1
        $transfer05 = $this->createLentPuzzleTransfer(
            id: self::TRANSFER_05,
            lentPuzzle: $lent04,
            fromPlayer: $player5,
            fromPlayerName: null,
            toPlayer: $player1,
            toPlayerName: null,
            transferType: TransferType::InitialLend,
            daysAgo: 10,
        );
        $manager->persist($transfer05);
        $this->addReference(self::TRANSFER_05, $transfer05);

        // LENT_04: Pass from player1 to player4 (PLAYER_WITH_FAVORITES)
        $transfer06 = $this->createLentPuzzleTransfer(
            id: self::TRANSFER_06,
            lentPuzzle: $lent04,
            fromPlayer: $player1,
            fromPlayerName: null,
            toPlayer: $player4,
            toPlayerName: null,
            transferType: TransferType::Pass,
            daysAgo: 5,
        );
        $manager->persist($transfer06);
        $this->addReference(self::TRANSFER_06, $transfer06);

        // LENT_05: Initial lend from owner (player1) to player5 (PLAYER_WITH_STRIPE)
        $transfer07 = $this->createLentPuzzleTransfer(
            id: self::TRANSFER_07,
            lentPuzzle: $lent05,
            fromPlayer: $player1,
            fromPlayerName: null,
            toPlayer: $player5,
            toPlayerName: null,
            transferType: TransferType::InitialLend,
            daysAgo: 15,
        );
        $manager->persist($transfer07);
        $this->addReference(self::TRANSFER_07, $transfer07);

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            LentPuzzleFixture::class,
        ];
    }

    private function createLentPuzzleTransfer(
        string $id,
        LentPuzzle $lentPuzzle,
        null|Player $fromPlayer,
        null|string $fromPlayerName,
        null|Player $toPlayer,
        null|string $toPlayerName,
        TransferType $transferType,
        int $daysAgo,
    ): LentPuzzleTransfer {
        $transferredAt = $this->clock->now()->modify("-{$daysAgo} days");

        return new LentPuzzleTransfer(
            id: Uuid::fromString($id),
            lentPuzzle: $lentPuzzle,
            fromPlayer: $fromPlayer,
            fromPlayerName: $fromPlayerName,
            toPlayer: $toPlayer,
            toPlayerName: $toPlayerName,
            transferredAt: $transferredAt,
            transferType: $transferType,
        );
    }
}
