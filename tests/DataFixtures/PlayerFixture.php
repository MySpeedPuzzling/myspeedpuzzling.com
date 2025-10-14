<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Value\CollectionVisibility;

final class PlayerFixture extends Fixture
{
    public const string PLAYER_REGULAR = '018d0000-0000-0000-0000-000000000001';
    public const string PLAYER_PRIVATE = '018d0000-0000-0000-0000-000000000002';
    public const string PLAYER_ADMIN = '018d0000-0000-0000-0000-000000000003';
    public const string PLAYER_WITH_FAVORITES = '018d0000-0000-0000-0000-000000000004';
    public const string PLAYER_WITH_STRIPE = '018d0000-0000-0000-0000-000000000005';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $regularPlayer = $this->createPlayer(
            id: self::PLAYER_REGULAR,
            code: 'player1',
            userId: 'auth0|regular001',
            email: 'player1@speedpuzzling.cz',
            name: 'John Doe',
            country: 'cz',
            city: 'Prague',
        );
        $manager->persist($regularPlayer);
        $this->addReference(self::PLAYER_REGULAR, $regularPlayer);

        $privatePlayer = $this->createPlayer(
            id: self::PLAYER_PRIVATE,
            code: 'player2',
            userId: 'auth0|private002',
            email: 'player2@speedpuzzling.cz',
            name: 'Jane Smith',
            country: 'us',
            city: 'New York',
            isPrivate: true,
        );
        $manager->persist($privatePlayer);
        $this->addReference(self::PLAYER_PRIVATE, $privatePlayer);

        $adminPlayer = $this->createPlayer(
            id: self::PLAYER_ADMIN,
            code: 'admin',
            userId: 'auth0|admin003',
            email: 'admin@speedpuzzling.cz',
            name: 'Admin User',
            country: 'cz',
            city: 'Brno',
            isAdmin: true,
        );
        $manager->persist($adminPlayer);
        $this->addReference(self::PLAYER_ADMIN, $adminPlayer);

        $playerWithFavorites = $this->createPlayer(
            id: self::PLAYER_WITH_FAVORITES,
            code: 'player3',
            userId: 'auth0|fav004',
            email: 'player3@speedpuzzling.cz',
            name: 'Michael Johnson',
            country: 'de',
            city: 'Berlin',
        );
        $playerWithFavorites->addFavoritePlayer($regularPlayer);
        $playerWithFavorites->addFavoritePlayer($adminPlayer);
        $manager->persist($playerWithFavorites);
        $this->addReference(self::PLAYER_WITH_FAVORITES, $playerWithFavorites);

        $playerWithStripe = $this->createPlayer(
            id: self::PLAYER_WITH_STRIPE,
            code: 'player4',
            userId: 'auth0|stripe005',
            email: 'player4@speedpuzzling.cz',
            name: 'Sarah Williams',
            country: 'gb',
            city: 'London',
            stripeCustomerId: 'cus_test_123456789',
            puzzleCollectionVisibility: CollectionVisibility::Public,
        );
        $manager->persist($playerWithStripe);
        $this->addReference(self::PLAYER_WITH_STRIPE, $playerWithStripe);

        $manager->flush();
    }

    private function createPlayer(
        string $id,
        string $code,
        string $userId,
        string $email,
        string $name,
        null|string $country = null,
        null|string $city = null,
        bool $isPrivate = false,
        bool $isAdmin = false,
        null|string $stripeCustomerId = null,
        CollectionVisibility $puzzleCollectionVisibility = CollectionVisibility::Private,
    ): Player {
        $player = new Player(
            id: Uuid::fromString($id),
            code: $code,
            userId: $userId,
            email: $email,
            name: $name,
            registeredAt: $this->clock->now(),
        );

        $player->changeProfile(
            name: $name,
            email: $email,
            city: $city,
            country: $country,
            avatar: null,
            bio: null,
            facebook: null,
            instagram: null,
        );

        if ($isPrivate) {
            $player->changeProfileVisibility(isPrivate: true);
        }

        if ($isAdmin) {
            $player->isAdmin = true;
        }

        if ($stripeCustomerId !== null) {
            $player->updateStripeCustomerId($stripeCustomerId);
        }

        $player->changePuzzleCollectionVisibility($puzzleCollectionVisibility);

        return $player;
    }
}
