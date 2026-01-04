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
use SpeedPuzzling\Web\Entity\SoldSwappedItem;
use SpeedPuzzling\Web\Value\ListingType;

final class SoldSwappedItemFixture extends Fixture implements DependentFixtureInterface
{
    public const string SOLD_01 = '018d000d-0000-0000-0000-000000000001';
    public const string SOLD_02 = '018d000d-0000-0000-0000-000000000002';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $playerAdmin = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);
        $playerPrivate = $this->getReference(PlayerFixture::PLAYER_PRIVATE, Player::class);
        $playerRegular = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $puzzle500_05 = $this->getReference(PuzzleFixture::PUZZLE_500_05, Puzzle::class);

        // PLAYER_ADMIN sold PUZZLE_500_05 to PLAYER_REGULAR
        $sold01 = new SoldSwappedItem(
            id: Uuid::fromString(self::SOLD_01),
            seller: $playerAdmin,
            puzzle: $puzzle500_05,
            buyerPlayer: $playerRegular,
            buyerName: null,
            listingType: ListingType::Sell,
            price: 25.00,
            soldAt: $this->clock->now()->modify('-30 days'),
        );
        $manager->persist($sold01);
        $this->addReference(self::SOLD_01, $sold01);

        // PLAYER_PRIVATE sold PUZZLE_500_05 to external buyer
        $sold02 = new SoldSwappedItem(
            id: Uuid::fromString(self::SOLD_02),
            seller: $playerPrivate,
            puzzle: $puzzle500_05,
            buyerPlayer: null,
            buyerName: 'Jane External',
            listingType: ListingType::Swap,
            price: null,
            soldAt: $this->clock->now()->modify('-20 days'),
        );
        $manager->persist($sold02);
        $this->addReference(self::SOLD_02, $sold02);

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
}
