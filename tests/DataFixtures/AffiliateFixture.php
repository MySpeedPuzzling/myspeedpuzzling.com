<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Affiliate;
use SpeedPuzzling\Web\Entity\AffiliatePayout;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Tribute;
use SpeedPuzzling\Web\Value\AffiliateStatus;
use SpeedPuzzling\Web\Value\PayoutStatus;
use SpeedPuzzling\Web\Value\TributeSource;

final class AffiliateFixture extends Fixture implements DependentFixtureInterface
{
    public const string AFFILIATE_ACTIVE_ID = '019f0000-0000-0000-0000-000000000001';
    public const string AFFILIATE_ACTIVE_CODE = 'ACTV001';
    public const string AFFILIATE_PENDING_ID = '019f0000-0000-0000-0000-000000000002';
    public const string AFFILIATE_PENDING_CODE = 'PEND002';
    public const string AFFILIATE_SUSPENDED_ID = '019f0000-0000-0000-0000-000000000003';
    public const string AFFILIATE_SUSPENDED_CODE = 'SUSP003';
    public const string TRIBUTE_ID = '019f0000-0000-0000-0000-000000000010';
    public const string PAYOUT_PENDING_ID = '019f0000-0000-0000-0000-000000000020';
    public const string PAYOUT_PAID_ID = '019f0000-0000-0000-0000-000000000021';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        // Active affiliate (PLAYER_REGULAR)
        $activePlayer = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $activeAffiliate = new Affiliate(
            id: Uuid::fromString(self::AFFILIATE_ACTIVE_ID),
            player: $activePlayer,
            code: self::AFFILIATE_ACTIVE_CODE,
            createdAt: $now->modify('-30 days'),
            status: AffiliateStatus::Active,
        );
        $manager->persist($activeAffiliate);
        $this->addReference(self::AFFILIATE_ACTIVE_ID, $activeAffiliate);

        // Pending affiliate (PLAYER_WITH_FAVORITES)
        $pendingPlayer = $this->getReference(PlayerFixture::PLAYER_WITH_FAVORITES, Player::class);
        $pendingAffiliate = new Affiliate(
            id: Uuid::fromString(self::AFFILIATE_PENDING_ID),
            player: $pendingPlayer,
            code: self::AFFILIATE_PENDING_CODE,
            createdAt: $now->modify('-2 days'),
            status: AffiliateStatus::Pending,
        );
        $manager->persist($pendingAffiliate);

        // Suspended affiliate (PLAYER_WITH_STRIPE)
        $suspendedPlayer = $this->getReference(PlayerFixture::PLAYER_WITH_STRIPE, Player::class);
        $suspendedAffiliate = new Affiliate(
            id: Uuid::fromString(self::AFFILIATE_SUSPENDED_ID),
            player: $suspendedPlayer,
            code: self::AFFILIATE_SUSPENDED_CODE,
            createdAt: $now->modify('-60 days'),
            status: AffiliateStatus::Suspended,
        );
        $manager->persist($suspendedAffiliate);

        // Tribute: PLAYER_PRIVATE is a supporter of active affiliate
        $subscriber = $this->getReference(PlayerFixture::PLAYER_PRIVATE, Player::class);
        $tribute = new Tribute(
            id: Uuid::fromString(self::TRIBUTE_ID),
            subscriber: $subscriber,
            affiliate: $activeAffiliate,
            source: TributeSource::Link,
            createdAt: $now->modify('-15 days'),
        );
        $manager->persist($tribute);

        // Pending payout
        $pendingPayout = new AffiliatePayout(
            id: Uuid::fromString(self::PAYOUT_PENDING_ID),
            affiliate: $activeAffiliate,
            tribute: $tribute,
            stripeInvoiceId: 'in_test_pending_001',
            paymentAmountCents: 600,
            payoutAmountCents: 60,
            currency: 'EUR',
            createdAt: $now->modify('-10 days'),
        );
        $manager->persist($pendingPayout);

        // Paid payout
        $paidPayout = new AffiliatePayout(
            id: Uuid::fromString(self::PAYOUT_PAID_ID),
            affiliate: $activeAffiliate,
            tribute: $tribute,
            stripeInvoiceId: 'in_test_paid_001',
            paymentAmountCents: 600,
            payoutAmountCents: 60,
            currency: 'EUR',
            createdAt: $now->modify('-40 days'),
            status: PayoutStatus::Paid,
        );
        $paidPayout->markAsPaid($now->modify('-35 days'));
        $manager->persist($paidPayout);

        $manager->flush();
    }

    /**
     * @return list<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
        ];
    }
}
