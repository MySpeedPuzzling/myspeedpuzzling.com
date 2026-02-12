<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\SoldSwappedItem;
use SpeedPuzzling\Web\Entity\TransactionRating;
use SpeedPuzzling\Web\Value\TransactionRole;

final class TransactionRatingFixture extends Fixture implements DependentFixtureInterface
{
    public const string RATING_01 = '018d000e-0000-0000-0000-000000000001';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $soldItem = $this->getReference(SoldSwappedItemFixture::SOLD_01, SoldSwappedItem::class);
        $playerAdmin = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);
        $playerRegular = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);

        // PLAYER_ADMIN (seller) rated PLAYER_REGULAR (buyer) for SOLD_01
        $rating01 = new TransactionRating(
            id: Uuid::fromString(self::RATING_01),
            soldSwappedItem: $soldItem,
            reviewer: $playerAdmin,
            reviewedPlayer: $playerRegular,
            stars: 5,
            reviewText: 'Great buyer, fast payment!',
            ratedAt: $this->clock->now()->modify('-29 days'),
            reviewerRole: TransactionRole::Seller,
        );
        $manager->persist($rating01);

        // Update denormalized stats on reviewed player
        $playerRegular->updateRatingStats(1, '5.00');

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            SoldSwappedItemFixture::class,
        ];
    }
}
