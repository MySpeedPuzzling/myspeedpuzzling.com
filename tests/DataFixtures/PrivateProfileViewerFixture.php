<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PrivateProfileViewer;

/**
 * PLAYER_PRIVATE lets PLAYER_WITH_FAVORITES see her - and nobody else.
 */
final class PrivateProfileViewerFixture extends Fixture implements DependentFixtureInterface
{
    public const string PRIVATE_ALLOWS_WITH_FAVORITES = '018d0011-0000-0000-0000-000000000001';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $manager->persist(new PrivateProfileViewer(
            id: Uuid::fromString(self::PRIVATE_ALLOWS_WITH_FAVORITES),
            owner: $this->getReference(PlayerFixture::PLAYER_PRIVATE, Player::class),
            viewer: $this->getReference(PlayerFixture::PLAYER_WITH_FAVORITES, Player::class),
            addedAt: $this->clock->now()->modify('-3 days'),
        ));

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
        ];
    }
}
