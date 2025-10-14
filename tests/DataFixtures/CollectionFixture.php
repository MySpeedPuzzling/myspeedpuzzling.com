<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Value\CollectionVisibility;

final class CollectionFixture extends Fixture implements DependentFixtureInterface
{
    public const string COLLECTION_PUBLIC = '018d0008-0000-0000-0000-000000000001';
    public const string COLLECTION_PRIVATE = '018d0008-0000-0000-0000-000000000002';
    public const string COLLECTION_FAVORITES = '018d0008-0000-0000-0000-000000000003';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $player1 = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $player5 = $this->getReference(PlayerFixture::PLAYER_WITH_STRIPE, Player::class);

        $publicCollection = $this->createCollection(
            id: self::COLLECTION_PUBLIC,
            player: $player5,
            name: 'My Ravensburger Collection',
            description: 'All my favorite Ravensburger puzzles',
            visibility: CollectionVisibility::Public,
            daysAgo: 90,
        );
        $manager->persist($publicCollection);
        $this->addReference(self::COLLECTION_PUBLIC, $publicCollection);

        $privateCollection = $this->createCollection(
            id: self::COLLECTION_PRIVATE,
            player: $player1,
            name: 'Wishlist',
            description: 'Puzzles I want to buy',
            visibility: CollectionVisibility::Private,
            daysAgo: 60,
        );
        $manager->persist($privateCollection);
        $this->addReference(self::COLLECTION_PRIVATE, $privateCollection);

        $favoritesCollection = $this->createCollection(
            id: self::COLLECTION_FAVORITES,
            player: $player1,
            name: 'Completed Favorites',
            description: null,
            visibility: CollectionVisibility::Private,
            daysAgo: 45,
        );
        $manager->persist($favoritesCollection);
        $this->addReference(self::COLLECTION_FAVORITES, $favoritesCollection);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
        ];
    }

    private function createCollection(
        string $id,
        Player $player,
        string $name,
        null|string $description,
        CollectionVisibility $visibility,
        int $daysAgo,
    ): Collection {
        $createdAt = $this->clock->now()->modify("-{$daysAgo} days");

        return new Collection(
            id: Uuid::fromString($id),
            player: $player,
            name: $name,
            description: $description,
            visibility: $visibility,
            createdAt: $createdAt,
        );
    }
}
