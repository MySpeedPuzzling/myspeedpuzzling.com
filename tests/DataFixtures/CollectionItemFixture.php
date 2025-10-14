<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Entity\CollectionItem;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;

final class CollectionItemFixture extends Fixture implements DependentFixtureInterface
{
    public const string ITEM_01 = '018d0009-0000-0000-0000-000000000001';
    public const string ITEM_02 = '018d0009-0000-0000-0000-000000000002';
    public const string ITEM_03 = '018d0009-0000-0000-0000-000000000003';
    public const string ITEM_04 = '018d0009-0000-0000-0000-000000000004';
    public const string ITEM_05 = '018d0009-0000-0000-0000-000000000005';
    public const string ITEM_06 = '018d0009-0000-0000-0000-000000000006';
    public const string ITEM_07 = '018d0009-0000-0000-0000-000000000007';
    public const string ITEM_08 = '018d0009-0000-0000-0000-000000000008';
    public const string ITEM_09 = '018d0009-0000-0000-0000-000000000009';
    public const string ITEM_10 = '018d0009-0000-0000-0000-000000000010';
    public const string ITEM_11 = '018d0009-0000-0000-0000-000000000011';
    public const string ITEM_12 = '018d0009-0000-0000-0000-000000000012';
    public const string ITEM_13 = '018d0009-0000-0000-0000-000000000013';
    public const string ITEM_14 = '018d0009-0000-0000-0000-000000000014';
    public const string ITEM_15 = '018d0009-0000-0000-0000-000000000015';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $player1 = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $player5 = $this->getReference(PlayerFixture::PLAYER_WITH_STRIPE, Player::class);

        $publicCollection = $this->getReference(CollectionFixture::COLLECTION_PUBLIC, Collection::class);
        $privateCollection = $this->getReference(CollectionFixture::COLLECTION_PRIVATE, Collection::class);
        $favoritesCollection = $this->getReference(CollectionFixture::COLLECTION_FAVORITES, Collection::class);

        $puzzle500_01 = $this->getReference(PuzzleFixture::PUZZLE_500_01, Puzzle::class);
        $puzzle500_02 = $this->getReference(PuzzleFixture::PUZZLE_500_02, Puzzle::class);
        $puzzle500_03 = $this->getReference(PuzzleFixture::PUZZLE_500_03, Puzzle::class);
        $puzzle1000_01 = $this->getReference(PuzzleFixture::PUZZLE_1000_01, Puzzle::class);
        $puzzle1000_02 = $this->getReference(PuzzleFixture::PUZZLE_1000_02, Puzzle::class);
        $puzzle1500_01 = $this->getReference(PuzzleFixture::PUZZLE_1500_01, Puzzle::class);
        $puzzle2000 = $this->getReference(PuzzleFixture::PUZZLE_2000, Puzzle::class);
        $puzzle3000 = $this->getReference(PuzzleFixture::PUZZLE_3000, Puzzle::class);

        // Items in publicCollection (player5)
        $item01 = $this->createCollectionItem(
            id: self::ITEM_01,
            player: $player5,
            puzzle: $puzzle500_01,
            collection: $publicCollection,
            daysAgo: 85,
            comment: 'Beautiful landscape puzzle, one of my favorites!',
        );
        $manager->persist($item01);
        $this->addReference(self::ITEM_01, $item01);

        $item02 = $this->createCollectionItem(
            id: self::ITEM_02,
            player: $player5,
            puzzle: $puzzle500_02,
            collection: $publicCollection,
            daysAgo: 80,
        );
        $manager->persist($item02);
        $this->addReference(self::ITEM_02, $item02);

        $item03 = $this->createCollectionItem(
            id: self::ITEM_03,
            player: $player5,
            puzzle: $puzzle1000_01,
            collection: $publicCollection,
            daysAgo: 75,
            comment: 'Challenging but fun!',
        );
        $manager->persist($item03);
        $this->addReference(self::ITEM_03, $item03);

        // Items in privateCollection (player1 - wishlist)
        $item04 = $this->createCollectionItem(
            id: self::ITEM_04,
            player: $player1,
            puzzle: $puzzle1500_01,
            collection: $privateCollection,
            daysAgo: 55,
            comment: 'Want to buy this one next',
        );
        $manager->persist($item04);
        $this->addReference(self::ITEM_04, $item04);

        $item05 = $this->createCollectionItem(
            id: self::ITEM_05,
            player: $player1,
            puzzle: $puzzle2000,
            collection: $privateCollection,
            daysAgo: 50,
        );
        $manager->persist($item05);
        $this->addReference(self::ITEM_05, $item05);

        $item06 = $this->createCollectionItem(
            id: self::ITEM_06,
            player: $player1,
            puzzle: $puzzle3000,
            collection: $privateCollection,
            daysAgo: 45,
            comment: 'Dream puzzle!',
        );
        $manager->persist($item06);
        $this->addReference(self::ITEM_06, $item06);

        // Items in favoritesCollection (player1)
        $item07 = $this->createCollectionItem(
            id: self::ITEM_07,
            player: $player1,
            puzzle: $puzzle500_01,
            collection: $favoritesCollection,
            daysAgo: 40,
        );
        $manager->persist($item07);
        $this->addReference(self::ITEM_07, $item07);

        $item08 = $this->createCollectionItem(
            id: self::ITEM_08,
            player: $player1,
            puzzle: $puzzle500_02,
            collection: $favoritesCollection,
            daysAgo: 35,
            comment: 'Solved this 3 times, love it!',
        );
        $manager->persist($item08);
        $this->addReference(self::ITEM_08, $item08);

        // Items WITHOUT collection (null) - player1's general collection
        $item09 = $this->createCollectionItem(
            id: self::ITEM_09,
            player: $player1,
            puzzle: $puzzle500_03,
            collection: null,
            daysAgo: 30,
        );
        $manager->persist($item09);
        $this->addReference(self::ITEM_09, $item09);

        $item10 = $this->createCollectionItem(
            id: self::ITEM_10,
            player: $player1,
            puzzle: $puzzle1000_01,
            collection: null,
            daysAgo: 25,
            comment: 'Great quality pieces',
        );
        $manager->persist($item10);
        $this->addReference(self::ITEM_10, $item10);

        $item11 = $this->createCollectionItem(
            id: self::ITEM_11,
            player: $player1,
            puzzle: $puzzle1000_02,
            collection: null,
            daysAgo: 20,
        );
        $manager->persist($item11);
        $this->addReference(self::ITEM_11, $item11);

        // Items WITHOUT collection - player5's general collection
        $item12 = $this->createCollectionItem(
            id: self::ITEM_12,
            player: $player5,
            puzzle: $puzzle500_03,
            collection: null,
            daysAgo: 15,
            comment: 'Nice colors',
        );
        $manager->persist($item12);
        $this->addReference(self::ITEM_12, $item12);

        $item13 = $this->createCollectionItem(
            id: self::ITEM_13,
            player: $player5,
            puzzle: $puzzle1000_02,
            collection: null,
            daysAgo: 10,
        );
        $manager->persist($item13);
        $this->addReference(self::ITEM_13, $item13);

        $item14 = $this->createCollectionItem(
            id: self::ITEM_14,
            player: $player5,
            puzzle: $puzzle1500_01,
            collection: null,
            daysAgo: 5,
        );
        $manager->persist($item14);
        $this->addReference(self::ITEM_14, $item14);

        $item15 = $this->createCollectionItem(
            id: self::ITEM_15,
            player: $player5,
            puzzle: $puzzle2000,
            collection: null,
            daysAgo: 2,
            comment: 'The biggest one I own!',
        );
        $manager->persist($item15);
        $this->addReference(self::ITEM_15, $item15);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            PuzzleFixture::class,
            CollectionFixture::class,
        ];
    }

    private function createCollectionItem(
        string $id,
        Player $player,
        Puzzle $puzzle,
        null|Collection $collection,
        int $daysAgo,
        null|string $comment = null,
    ): CollectionItem {
        $addedAt = $this->clock->now()->modify("-{$daysAgo} days");

        return new CollectionItem(
            id: Uuid::fromString($id),
            collection: $collection,
            player: $player,
            puzzle: $puzzle,
            comment: $comment,
            addedAt: $addedAt,
        );
    }
}
