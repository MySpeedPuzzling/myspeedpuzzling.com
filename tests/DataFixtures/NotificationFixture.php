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
    // Favorites' times (SubscribedPlayerAddedTime) from before docs/features/navigation INB-03: nothing creates them any
    // more - a solve tells no follower, NotifyWhenPuzzleSolved is gone - and the rows that exist are neither listed nor
    // counted until the D4 cleanup deletes them. Unread, as most of them are on production
    public const string NOTIFICATION_FIRST_ATTEMPT = '018d0009-0000-0000-0000-000000000001';
    public const string NOTIFICATION_UNBOXED = '018d0009-0000-0000-0000-000000000002';
    public const string NOTIFICATION_REGULAR = '018d0009-0000-0000-0000-000000000003';
    // The Inbox badge's For you part (docs/features/navigation INB-02): favorites' times no longer count there
    public const string NOTIFICATION_GROUP_TIME_EDITED = '018d0009-0000-0000-0000-000000000004';

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

        // Unread "edited your shared result" on TIME_41 (a pair time PLAYER_REGULAR tracked with PLAYER_PRIVATE) by the
        // tracker: PLAYER_WITH_FAVORITES was in the group before the edit took them out, as
        // NotifyWhenGroupSolvingTimeEdited tells a removed member. The player's For you row (docs/features/navigation
        // INB-02). NotifyWhenGroupSolvingTimeEditedTest works on TIME_12, so this never stops one of its notifications;
        // the editor is named on purpose (docs/features/group-time-editing.md), so it is not the private PLAYER_PRIVATE
        $manager->persist(new Notification(
            id: Uuid::fromString(self::NOTIFICATION_GROUP_TIME_EDITED),
            player: $playerWithFavorites,
            type: NotificationType::GroupSolvingTimeEdited,
            notifiedAt: $this->clock->now()->modify('-3 days'),
            targetSolvingTime: $this->getReference(PuzzleSolvingTimeFixture::TIME_41, PuzzleSolvingTime::class),
            actorPlayer: $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class),
        ));

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
