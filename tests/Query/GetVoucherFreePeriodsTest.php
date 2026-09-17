<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\ClaimVoucher;
use SpeedPuzzling\Web\Query\GetVoucherFreePeriods;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\VoucherFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class GetVoucherFreePeriodsTest extends KernelTestCase
{
    private GetVoucherFreePeriods $getVoucherFreePeriods;
    private MessageBusInterface $messageBus;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->getVoucherFreePeriods = $container->get(GetVoucherFreePeriods::class);
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->clock = $container->get(ClockInterface::class);
    }

    public function testReturnsFreePeriodOfClaimedVoucherUntilItEnds(): void
    {
        $playerId = PlayerFixture::PLAYER_WITH_FAVORITES;
        $now = $this->clock->now();

        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: $playerId,
                voucherCode: VoucherFixture::VOUCHER_AVAILABLE_CODE,
            ),
        );

        $periods = $this->getVoucherFreePeriods->notEndedForPlayer($playerId, $now);

        self::assertCount(1, $periods);
        self::assertSame(VoucherFixture::VOUCHER_AVAILABLE_CODE, $periods[0]->voucherCode);
        self::assertSame(1, $periods[0]->months);
        self::assertTrue($periods[0]->hasStarted($now));

        // Once the free months are over, there is nothing left to explain
        self::assertSame([], $this->getVoucherFreePeriods->notEndedForPlayer($playerId, $now->modify('+2 months')));
    }

    public function testVouchersUsedBeforeFreePeriodsWereRecordedAreSkipped(): void
    {
        // VOUCHER_USED was redeemed by PLAYER_REGULAR without a recorded free period
        self::assertSame([], $this->getVoucherFreePeriods->notEndedForPlayer(PlayerFixture::PLAYER_REGULAR, $this->clock->now()));
    }
}
