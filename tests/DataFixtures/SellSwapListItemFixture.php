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
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;
use SpeedPuzzling\Web\Value\SellSwapListSettings;

final class SellSwapListItemFixture extends Fixture implements DependentFixtureInterface
{
    public const string SELLSWAP_01 = '018d000b-0000-0000-0000-000000000001';
    public const string SELLSWAP_02 = '018d000b-0000-0000-0000-000000000002';
    public const string SELLSWAP_03 = '018d000b-0000-0000-0000-000000000003';
    public const string SELLSWAP_04 = '018d000b-0000-0000-0000-000000000004';
    public const string SELLSWAP_05 = '018d000b-0000-0000-0000-000000000005';
    public const string SELLSWAP_06 = '018d000b-0000-0000-0000-000000000006';
    public const string SELLSWAP_07 = '018d000b-0000-0000-0000-000000000007';
    public const string SELLSWAP_08 = '018d000b-0000-0000-0000-000000000008';
    public const string SELLSWAP_09 = '018d000b-0000-0000-0000-000000000009';
    public const string SELLSWAP_10 = '018d000b-0000-0000-0000-000000000010';
    public const string SELLSWAP_11 = '018d000b-0000-0000-0000-000000000011';
    public const string SELLSWAP_12 = '018d000b-0000-0000-0000-000000000012';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // Players with membership who can use sell/swap feature
        $player3 = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);
        $player5 = $this->getReference(PlayerFixture::PLAYER_WITH_STRIPE, Player::class);

        // Set up shipping countries for sellers
        // player5 (gb) ships to gb, cz, de
        $player5->changeSellSwapListSettings(new SellSwapListSettings(
            shippingCountries: ['gb', 'cz', 'de'],
        ));

        // player3 (cz) ships to cz, sk only
        $player3->changeSellSwapListSettings(new SellSwapListSettings(
            shippingCountries: ['cz', 'sk'],
        ));

        // Puzzles from player5's collection
        $puzzle500_01 = $this->getReference(PuzzleFixture::PUZZLE_500_01, Puzzle::class);
        $puzzle500_04 = $this->getReference(PuzzleFixture::PUZZLE_500_04, Puzzle::class);
        $puzzle500_05 = $this->getReference(PuzzleFixture::PUZZLE_500_05, Puzzle::class);
        $puzzle500_02 = $this->getReference(PuzzleFixture::PUZZLE_500_02, Puzzle::class);
        $puzzle500_03 = $this->getReference(PuzzleFixture::PUZZLE_500_03, Puzzle::class);
        $puzzle1000_01 = $this->getReference(PuzzleFixture::PUZZLE_1000_01, Puzzle::class);
        $puzzle1000_02 = $this->getReference(PuzzleFixture::PUZZLE_1000_02, Puzzle::class);
        $puzzle1000_03 = $this->getReference(PuzzleFixture::PUZZLE_1000_03, Puzzle::class);
        $puzzle1500_01 = $this->getReference(PuzzleFixture::PUZZLE_1500_01, Puzzle::class);

        // Sell only, like new condition, with comment
        $item01 = $this->createSellSwapListItem(
            id: self::SELLSWAP_01,
            player: $player5,
            puzzle: $puzzle500_01,
            listingType: ListingType::Sell,
            price: 25.00,
            condition: PuzzleCondition::LikeNew,
            comment: 'Perfect condition, only solved once',
            daysAgo: 20,
        );
        $manager->persist($item01);
        $this->addReference(self::SELLSWAP_01, $item01);

        // Swap only, normal condition, no comment
        $item02 = $this->createSellSwapListItem(
            id: self::SELLSWAP_02,
            player: $player5,
            puzzle: $puzzle500_02,
            listingType: ListingType::Swap,
            price: null,
            condition: PuzzleCondition::Normal,
            comment: null,
            daysAgo: 18,
        );
        $manager->persist($item02);
        $this->addReference(self::SELLSWAP_02, $item02);

        // Both sell and swap, normal condition, with comment, RESERVED
        $item03 = $this->createSellSwapListItem(
            id: self::SELLSWAP_03,
            player: $player5,
            puzzle: $puzzle1000_01,
            listingType: ListingType::Both,
            price: 45.00,
            condition: PuzzleCondition::Normal,
            comment: 'Open to offers',
            daysAgo: 15,
        );
        $item03->markAsReserved();
        $manager->persist($item03);
        $this->addReference(self::SELLSWAP_03, $item03);

        // Sell only, not so good condition, with comment, RESERVED for a specific player
        $item04 = $this->createSellSwapListItem(
            id: self::SELLSWAP_04,
            player: $player5,
            puzzle: $puzzle500_03,
            listingType: ListingType::Sell,
            price: 15.00,
            condition: PuzzleCondition::NotSoGood,
            comment: 'Some wear on box',
            daysAgo: 10,
        );
        $item04->markAsReserved(Uuid::fromString(PlayerFixture::PLAYER_ADMIN));
        $manager->persist($item04);
        $this->addReference(self::SELLSWAP_04, $item04);

        // Swap only, like new condition, no comment, RESERVED (all offers on this puzzle are reserved)
        $item05 = $this->createSellSwapListItem(
            id: self::SELLSWAP_05,
            player: $player5,
            puzzle: $puzzle1000_02,
            listingType: ListingType::Swap,
            price: null,
            condition: PuzzleCondition::LikeNew,
            comment: null,
            daysAgo: 5,
        );
        $item05->markAsReserved();
        $manager->persist($item05);
        $this->addReference(self::SELLSWAP_05, $item05);

        // Both sell and swap, missing pieces condition, with comment
        $item06 = $this->createSellSwapListItem(
            id: self::SELLSWAP_06,
            player: $player5,
            puzzle: $puzzle1500_01,
            listingType: ListingType::Both,
            price: 60.00,
            condition: PuzzleCondition::MissingPieces,
            comment: '2 pieces missing, otherwise good',
            daysAgo: 2,
        );
        $manager->persist($item06);
        $this->addReference(self::SELLSWAP_06, $item06);

        // Sell only, for testing mark as sold on unsolved page (puzzle_1000_03 is unsolved)
        $item07 = $this->createSellSwapListItem(
            id: self::SELLSWAP_07,
            player: $player5,
            puzzle: $puzzle1000_03,
            listingType: ListingType::Sell,
            price: 35.00,
            condition: PuzzleCondition::Normal,
            comment: null,
            daysAgo: 1,
        );
        $manager->persist($item07);
        $this->addReference(self::SELLSWAP_07, $item07);

        // SELLSWAP_08: PLAYER_ADMIN selling PUZZLE_500_05 (for merge testing)
        // This item should be migrated to survivor puzzle during merge
        $item08 = $this->createSellSwapListItem(
            id: self::SELLSWAP_08,
            player: $player3,
            puzzle: $puzzle500_05,
            listingType: ListingType::Sell,
            price: 20.00,
            condition: PuzzleCondition::Normal,
            comment: null,
            daysAgo: 9,
        );
        $manager->persist($item08);
        $this->addReference(self::SELLSWAP_08, $item08);

        // SELLSWAP_09: PLAYER_ADMIN selling PUZZLE_500_04 (for merge deduplication testing)
        // Creates deduplication scenario with SELLSWAP_08 (same player has both puzzles on sell/swap)
        // When merging PUZZLE_500_05 into PUZZLE_500_04, SELLSWAP_08 should be REMOVED (not migrated)
        $item09 = $this->createSellSwapListItem(
            id: self::SELLSWAP_09,
            player: $player3,
            puzzle: $puzzle500_04,
            listingType: ListingType::Swap,
            price: null,
            condition: PuzzleCondition::LikeNew,
            comment: null,
            daysAgo: 7,
        );
        $manager->persist($item09);
        $this->addReference(self::SELLSWAP_09, $item09);

        // SELLSWAP_10: PLAYER_ADMIN also listing PUZZLE_500_01 (multiple offers on same puzzle)
        $item10 = $this->createSellSwapListItem(
            id: self::SELLSWAP_10,
            player: $player3,
            puzzle: $puzzle500_01,
            listingType: ListingType::Both,
            price: 22.00,
            condition: PuzzleCondition::Normal,
            comment: 'Good condition, complete',
            daysAgo: 6,
        );
        $manager->persist($item10);
        $this->addReference(self::SELLSWAP_10, $item10);

        // SELLSWAP_11: PLAYER_ADMIN also listing PUZZLE_1000_01 (multiple offers, mixed reservation)
        // SELLSWAP_03 on the same puzzle is reserved, this one is NOT reserved
        $item11 = $this->createSellSwapListItem(
            id: self::SELLSWAP_11,
            player: $player3,
            puzzle: $puzzle1000_01,
            listingType: ListingType::Sell,
            price: 40.00,
            condition: PuzzleCondition::LikeNew,
            comment: null,
            daysAgo: 4,
        );
        $manager->persist($item11);
        $this->addReference(self::SELLSWAP_11, $item11);

        // SELLSWAP_12: PLAYER_ADMIN also listing PUZZLE_1000_02, RESERVED
        // Combined with SELLSWAP_05 (also reserved), this puzzle has ONLY reserved offers
        $item12 = $this->createSellSwapListItem(
            id: self::SELLSWAP_12,
            player: $player3,
            puzzle: $puzzle1000_02,
            listingType: ListingType::Sell,
            price: 30.00,
            condition: PuzzleCondition::Normal,
            comment: null,
            daysAgo: 3,
        );
        $item12->markAsReserved();
        $manager->persist($item12);
        $this->addReference(self::SELLSWAP_12, $item12);

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
            CollectionItemFixture::class,
            MembershipFixture::class,
        ];
    }

    private function createSellSwapListItem(
        string $id,
        Player $player,
        Puzzle $puzzle,
        ListingType $listingType,
        null|float $price,
        PuzzleCondition $condition,
        null|string $comment,
        int $daysAgo,
    ): SellSwapListItem {
        $addedAt = $this->clock->now()->modify("-{$daysAgo} days");

        return new SellSwapListItem(
            id: Uuid::fromString($id),
            player: $player,
            puzzle: $puzzle,
            listingType: $listingType,
            price: $price,
            condition: $condition,
            comment: $comment,
            addedAt: $addedAt,
        );
    }
}
