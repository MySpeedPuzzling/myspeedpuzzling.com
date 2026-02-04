<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Voucher;
use SpeedPuzzling\Web\Entity\VoucherClaim;

final class VoucherClaimFixture extends Fixture implements DependentFixtureInterface
{
    public const string CLAIM_FOR_MAX_USES = '018d0009-0000-0000-0000-000000000001';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();
        $playerRegular = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $maxUsesVoucher = $this->getReference(VoucherFixture::VOUCHER_PERCENTAGE_MAX_USES_REACHED, Voucher::class);

        // Create a claim for the max uses voucher (making it fully used since maxUses=1)
        $claim = new VoucherClaim(
            id: Uuid::fromString(self::CLAIM_FOR_MAX_USES),
            voucher: $maxUsesVoucher,
            player: $playerRegular,
            claimedAt: $now->modify('-1 day'),
        );
        $manager->persist($claim);
        $this->addReference(self::CLAIM_FOR_MAX_USES, $claim);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            VoucherFixture::class,
        ];
    }
}
