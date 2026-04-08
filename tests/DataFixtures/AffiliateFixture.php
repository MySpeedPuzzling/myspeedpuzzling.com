<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\AffiliatePayout;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Referral;
use SpeedPuzzling\Web\Value\PayoutStatus;
use SpeedPuzzling\Web\Value\ReferralSource;

final class AffiliateFixture extends Fixture implements DependentFixtureInterface
{
    public const string REFERRAL_ID = '019f0000-0000-0000-0000-000000000010';
    public const string PAYOUT_PENDING_ID = '019f0000-0000-0000-0000-000000000020';
    public const string PAYOUT_PAID_ID = '019f0000-0000-0000-0000-000000000021';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        // PLAYER_REGULAR joins referral program (active)
        $activePlayer = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $activePlayer->joinReferralProgram($now->modify('-30 days'));

        // PLAYER_WITH_STRIPE joins but is suspended
        $suspendedPlayer = $this->getReference(PlayerFixture::PLAYER_WITH_STRIPE, Player::class);
        $suspendedPlayer->joinReferralProgram($now->modify('-60 days'));
        $suspendedPlayer->suspendFromReferralProgram();

        // Referral: PLAYER_PRIVATE is a supporter of PLAYER_REGULAR
        $subscriber = $this->getReference(PlayerFixture::PLAYER_PRIVATE, Player::class);
        $referral = new Referral(
            id: Uuid::fromString(self::REFERRAL_ID),
            subscriber: $subscriber,
            affiliatePlayer: $activePlayer,
            source: ReferralSource::Link,
            createdAt: $now->modify('-15 days'),
        );
        $manager->persist($referral);

        // Pending payout
        $pendingPayout = new AffiliatePayout(
            id: Uuid::fromString(self::PAYOUT_PENDING_ID),
            affiliatePlayer: $activePlayer,
            referral: $referral,
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
            affiliatePlayer: $activePlayer,
            referral: $referral,
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
