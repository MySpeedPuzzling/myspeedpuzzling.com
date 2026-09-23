<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;

/**
 * One user_account row per fixture player, carrying the same address as the player's
 * mirror column: `user_account.email` is the single source of truth for where a player is
 * reached, so a fixture player without one would have no e-mail at all. Mirrors what the
 * test login helpers used to create on demand (an Auth0 import: legacy flag + verified).
 */
final class UserAccountFixture extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $playerIds = [
            PlayerFixture::PLAYER_REGULAR,
            PlayerFixture::PLAYER_PRIVATE,
            PlayerFixture::PLAYER_ADMIN,
            PlayerFixture::PLAYER_WITH_FAVORITES,
            PlayerFixture::PLAYER_WITH_STRIPE,
        ];

        foreach ($playerIds as $playerId) {
            $player = $this->getReference($playerId, Player::class);
            assert($player->userId !== null && $player->email !== null);

            $userAccount = new UserAccount(
                Uuid::uuid7(),
                $player->userId,
                $player->email,
                $this->clock->now(),
            );

            // auth0|... fixture players stand for accounts imported from Auth0
            $userAccount->applyAuth0Import($player->email, null, true, $this->clock->now());

            $manager->persist($userAccount);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [PlayerFixture::class];
    }
}
