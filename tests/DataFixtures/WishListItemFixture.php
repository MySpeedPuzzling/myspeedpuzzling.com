<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\WishListItem;

final class WishListItemFixture extends Fixture implements DependentFixtureInterface
{
    public const string WISHLIST_01 = '018d000a-0000-0000-0000-000000000001';
    public const string WISHLIST_02 = '018d000a-0000-0000-0000-000000000002';
    public const string WISHLIST_03 = '018d000a-0000-0000-0000-000000000003';
    public const string WISHLIST_04 = '018d000a-0000-0000-0000-000000000004';
    public const string WISHLIST_05 = '018d000a-0000-0000-0000-000000000005';
    public const string WISHLIST_06 = '018d000a-0000-0000-0000-000000000006';
    public const string WISHLIST_07 = '018d000a-0000-0000-0000-000000000007';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $player1 = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $player2 = $this->getReference(PlayerFixture::PLAYER_PRIVATE, Player::class);
        $player5 = $this->getReference(PlayerFixture::PLAYER_WITH_STRIPE, Player::class);

        $puzzle500_01 = $this->getReference(PuzzleFixture::PUZZLE_500_01, Puzzle::class);
        $puzzle3000 = $this->getReference(PuzzleFixture::PUZZLE_3000, Puzzle::class);
        $puzzle4000 = $this->getReference(PuzzleFixture::PUZZLE_4000, Puzzle::class);
        $puzzle5000 = $this->getReference(PuzzleFixture::PUZZLE_5000, Puzzle::class);
        $puzzle6000 = $this->getReference(PuzzleFixture::PUZZLE_6000, Puzzle::class);
        $puzzle9000 = $this->getReference(PuzzleFixture::PUZZLE_9000, Puzzle::class);

        // Player1 (PLAYER_REGULAR) wishlist items
        $item01 = $this->createWishListItem(
            id: self::WISHLIST_01,
            player: $player1,
            puzzle: $puzzle4000,
            removeOnCollectionAdd: true,
            daysAgo: 30,
        );
        $manager->persist($item01);
        $this->addReference(self::WISHLIST_01, $item01);

        $item02 = $this->createWishListItem(
            id: self::WISHLIST_02,
            player: $player1,
            puzzle: $puzzle5000,
            removeOnCollectionAdd: false,
            daysAgo: 25,
        );
        $manager->persist($item02);
        $this->addReference(self::WISHLIST_02, $item02);

        $item03 = $this->createWishListItem(
            id: self::WISHLIST_03,
            player: $player1,
            puzzle: $puzzle6000,
            removeOnCollectionAdd: true,
            daysAgo: 20,
        );
        $manager->persist($item03);
        $this->addReference(self::WISHLIST_03, $item03);

        // Player5 (PLAYER_WITH_STRIPE - has membership) wishlist items
        $item04 = $this->createWishListItem(
            id: self::WISHLIST_04,
            player: $player5,
            puzzle: $puzzle9000,
            removeOnCollectionAdd: true,
            daysAgo: 15,
        );
        $manager->persist($item04);
        $this->addReference(self::WISHLIST_04, $item04);

        $item05 = $this->createWishListItem(
            id: self::WISHLIST_05,
            player: $player5,
            puzzle: $puzzle3000,
            removeOnCollectionAdd: false,
            daysAgo: 10,
        );
        $manager->persist($item05);
        $this->addReference(self::WISHLIST_05, $item05);

        // WISHLIST_07: PLAYER_WITH_STRIPE + PUZZLE_500_01 (a solved puzzle on wishlist for testing)
        $item07 = $this->createWishListItem(
            id: self::WISHLIST_07,
            player: $player5,
            puzzle: $puzzle500_01,
            removeOnCollectionAdd: false,
            daysAgo: 3,
        );
        $manager->persist($item07);
        $this->addReference(self::WISHLIST_07, $item07);

        // Player2 (PLAYER_PRIVATE) wishlist item
        $item06 = $this->createWishListItem(
            id: self::WISHLIST_06,
            player: $player2,
            puzzle: $puzzle4000,
            removeOnCollectionAdd: true,
            daysAgo: 5,
        );
        $manager->persist($item06);
        $this->addReference(self::WISHLIST_06, $item06);

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            PuzzleFixture::class,
        ];
    }

    private function createWishListItem(
        string $id,
        Player $player,
        Puzzle $puzzle,
        bool $removeOnCollectionAdd,
        int $daysAgo,
    ): WishListItem {
        $addedAt = $this->clock->now()->modify("-{$daysAgo} days");

        return new WishListItem(
            id: Uuid::fromString($id),
            player: $player,
            puzzle: $puzzle,
            removeOnCollectionAdd: $removeOnCollectionAdd,
            addedAt: $addedAt,
        );
    }
}
