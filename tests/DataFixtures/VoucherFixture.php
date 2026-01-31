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

final class VoucherFixture extends Fixture implements DependentFixtureInterface
{
    public const string VOUCHER_AVAILABLE = '018d0008-0000-0000-0000-000000000001';
    public const string VOUCHER_AVAILABLE_CODE = 'TESTCODE12345678';

    public const string VOUCHER_USED = '018d0008-0000-0000-0000-000000000002';
    public const string VOUCHER_USED_CODE = 'USEDCODE12345678';

    public const string VOUCHER_EXPIRED = '018d0008-0000-0000-0000-000000000003';
    public const string VOUCHER_EXPIRED_CODE = 'EXPIREDCODE12345';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();
        $playerRegular = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);

        // Available voucher
        $availableVoucher = new Voucher(
            id: Uuid::fromString(self::VOUCHER_AVAILABLE),
            code: self::VOUCHER_AVAILABLE_CODE,
            monthsValue: 1,
            validUntil: $now->modify('+30 days'),
            createdAt: $now->modify('-5 days'),
            internalNote: 'Test available voucher',
        );
        $manager->persist($availableVoucher);
        $this->addReference(self::VOUCHER_AVAILABLE, $availableVoucher);

        // Used voucher
        $usedVoucher = new Voucher(
            id: Uuid::fromString(self::VOUCHER_USED),
            code: self::VOUCHER_USED_CODE,
            monthsValue: 3,
            validUntil: $now->modify('+60 days'),
            createdAt: $now->modify('-10 days'),
            internalNote: 'Test used voucher',
        );
        $usedVoucher->markAsUsed($playerRegular, $now->modify('-2 days'));
        $manager->persist($usedVoucher);
        $this->addReference(self::VOUCHER_USED, $usedVoucher);

        // Expired voucher
        $expiredVoucher = new Voucher(
            id: Uuid::fromString(self::VOUCHER_EXPIRED),
            code: self::VOUCHER_EXPIRED_CODE,
            monthsValue: 1,
            validUntil: $now->modify('-5 days'),
            createdAt: $now->modify('-35 days'),
            internalNote: 'Test expired voucher',
        );
        $manager->persist($expiredVoucher);
        $this->addReference(self::VOUCHER_EXPIRED, $expiredVoucher);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
        ];
    }
}
