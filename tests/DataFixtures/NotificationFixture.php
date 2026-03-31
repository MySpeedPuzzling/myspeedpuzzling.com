<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Value\NotificationType;

final class NotificationFixture extends Fixture implements DependentFixtureInterface
{
    public const string NOTIFICATION_FIRST_ATTEMPT = '018d0009-0000-0000-0000-000000000001';
    public const string NOTIFICATION_UNBOXED = '018d0009-0000-0000-0000-000000000002';
    public const string NOTIFICATION_REGULAR = '018d0009-0000-0000-0000-000000000003';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $playerWithFavorites = $this->getReference(PlayerFixture::PLAYER_WITH_FAVORITES, Player::class);

        // Notification for a first attempt solving time (TIME_01 is firstAttempt=true, unboxed=false)
        $notification1 = new Notification(
            id: Uuid::fromString(self::NOTIFICATION_FIRST_ATTEMPT),
            player: $playerWithFavorites,
            type: NotificationType::SubscribedPlayerAddedTime,
            notifiedAt: $this->clock->now()->modify('-10 days'),
            targetSolvingTime: $this->getReference(PuzzleSolvingTimeFixture::TIME_01, PuzzleSolvingTime::class),
        );
        $manager->persist($notification1);

        // Notification for an unboxed + first attempt solving time (TIME_45_UNBOXED)
        $notification2 = new Notification(
            id: Uuid::fromString(self::NOTIFICATION_UNBOXED),
            player: $playerWithFavorites,
            type: NotificationType::SubscribedPlayerAddedTime,
            notifiedAt: $this->clock->now()->modify('-2 days'),
            targetSolvingTime: $this->getReference(PuzzleSolvingTimeFixture::TIME_45_UNBOXED, PuzzleSolvingTime::class),
        );
        $manager->persist($notification2);

        // Notification for a non-first-attempt solving time (TIME_07 is firstAttempt=false)
        $notification3 = new Notification(
            id: Uuid::fromString(self::NOTIFICATION_REGULAR),
            player: $playerWithFavorites,
            type: NotificationType::SubscribedPlayerAddedTime,
            notifiedAt: $this->clock->now()->modify('-15 days'),
            targetSolvingTime: $this->getReference(PuzzleSolvingTimeFixture::TIME_07, PuzzleSolvingTime::class),
        );
        $manager->persist($notification3);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            PuzzleSolvingTimeFixture::class,
        ];
    }
}
