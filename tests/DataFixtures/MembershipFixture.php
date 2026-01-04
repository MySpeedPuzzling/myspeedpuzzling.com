<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Membership;
use SpeedPuzzling\Web\Entity\Player;

final class MembershipFixture extends Fixture implements DependentFixtureInterface
{
    public const string MEMBERSHIP_ACTIVE = '018d0007-0000-0000-0000-000000000001';
    public const string MEMBERSHIP_ADMIN = '018d0007-0000-0000-0000-000000000002';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $playerWithStripe = $this->getReference(PlayerFixture::PLAYER_WITH_STRIPE, Player::class);
        $playerAdmin = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);

        $activeMembership = $this->createMembership(
            id: self::MEMBERSHIP_ACTIVE,
            player: $playerWithStripe,
            stripeSubscriptionId: 'sub_test_123456789',
            daysFromNow: 30,
        );
        $manager->persist($activeMembership);
        $this->addReference(self::MEMBERSHIP_ACTIVE, $activeMembership);

        $adminMembership = $this->createMembership(
            id: self::MEMBERSHIP_ADMIN,
            player: $playerAdmin,
            stripeSubscriptionId: 'sub_admin_123456789',
            daysFromNow: 30,
        );
        $manager->persist($adminMembership);
        $this->addReference(self::MEMBERSHIP_ADMIN, $adminMembership);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
        ];
    }

    private function createMembership(
        string $id,
        Player $player,
        null|string $stripeSubscriptionId = null,
        null|int $daysFromNow = null,
    ): Membership {
        $now = $this->clock->now();
        $billingPeriodEndsAt = $daysFromNow !== null
            ? $now->modify("+{$daysFromNow} days")
            : null;

        return new Membership(
            id: Uuid::fromString($id),
            player: $player,
            createdAt: $now->modify('-60 days'),
            stripeSubscriptionId: $stripeSubscriptionId,
            billingPeriodEndsAt: $billingPeriodEndsAt,
            endsAt: null,
        );
    }
}
