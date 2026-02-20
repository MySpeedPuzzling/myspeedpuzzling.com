<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DigestEmailLog;
use SpeedPuzzling\Web\Entity\Player;

final class DigestEmailLogFixture extends Fixture implements DependentFixtureInterface
{
    public const string LOG_FOR_FAVORITES_PLAYER = '018d0010-0000-0000-0000-000000000001';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        // WITH_FAVORITES was already notified about their unread messages (marketplace conversation)
        // The oldestUnreadMessageAt matches the marketplace message sent_at (-3 days)
        // This means they should NOT be notified again for the same batch
        $playerWithFavorites = $this->getReference(PlayerFixture::PLAYER_WITH_FAVORITES, Player::class);

        $log = new DigestEmailLog(
            id: Uuid::fromString(self::LOG_FOR_FAVORITES_PLAYER),
            player: $playerWithFavorites,
            sentAt: $now->modify('-1 day'),
            oldestUnreadMessageAt: $now->modify('-3 days'),
        );
        $manager->persist($log);

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            ChatMessageFixture::class,
        ];
    }
}
