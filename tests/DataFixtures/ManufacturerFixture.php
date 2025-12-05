<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Player;

final class ManufacturerFixture extends Fixture implements DependentFixtureInterface
{
    public const string MANUFACTURER_RAVENSBURGER = '018d0002-0000-0000-0000-000000000001';
    public const string MANUFACTURER_TREFL = '018d0002-0000-0000-0000-000000000002';
    public const string MANUFACTURER_UNAPPROVED = '018d0002-0000-0000-0000-000000000003';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $adminPlayer = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);

        $ravensburger = $this->createManufacturer(
            id: self::MANUFACTURER_RAVENSBURGER,
            name: 'Ravensburger',
            approved: true,
            addedByUser: $adminPlayer,
        );
        $manager->persist($ravensburger);
        $this->addReference(self::MANUFACTURER_RAVENSBURGER, $ravensburger);

        $trefl = $this->createManufacturer(
            id: self::MANUFACTURER_TREFL,
            name: 'Trefl',
            approved: true,
            addedByUser: $adminPlayer,
        );
        $manager->persist($trefl);
        $this->addReference(self::MANUFACTURER_TREFL, $trefl);

        $regularPlayer = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);

        $unapproved = $this->createManufacturer(
            id: self::MANUFACTURER_UNAPPROVED,
            name: 'Unknown Brand',
            approved: false,
            addedByUser: $regularPlayer,
        );
        $manager->persist($unapproved);
        $this->addReference(self::MANUFACTURER_UNAPPROVED, $unapproved);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
        ];
    }

    private function createManufacturer(
        string $id,
        string $name,
        bool $approved,
        Player $addedByUser,
    ): Manufacturer {
        return new Manufacturer(
            id: Uuid::fromString($id),
            name: $name,
            approved: $approved,
            addedByUser: $addedByUser,
            addedAt: $this->clock->now(),
        );
    }
}
