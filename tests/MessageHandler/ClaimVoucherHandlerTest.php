<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\VoucherAlreadyUsed;
use SpeedPuzzling\Web\Exceptions\VoucherExpired;
use SpeedPuzzling\Web\Exceptions\VoucherNotFound;
use SpeedPuzzling\Web\Message\ClaimVoucher;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Repository\VoucherRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\VoucherFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class ClaimVoucherHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private VoucherRepository $voucherRepository;
    private MembershipRepository $membershipRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->voucherRepository = $container->get(VoucherRepository::class);
        $this->membershipRepository = $container->get(MembershipRepository::class);
    }

    public function testClaimingVoucherCreatesNewMembership(): void
    {
        // Use a player without existing membership
        $playerId = PlayerFixture::PLAYER_WITH_FAVORITES;
        $voucherCode = VoucherFixture::VOUCHER_AVAILABLE_CODE;

        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: $playerId,
                voucherCode: $voucherCode,
            ),
        );

        // Verify voucher is marked as used
        $voucher = $this->voucherRepository->getByCode($voucherCode);
        self::assertTrue($voucher->isUsed());
        self::assertNotNull($voucher->usedAt);
        self::assertNotNull($voucher->usedBy);
        self::assertSame($playerId, $voucher->usedBy->id->toString());

        // Verify membership was created
        $membership = $this->membershipRepository->getByPlayerId($playerId);
        self::assertNotNull($membership->endsAt);
    }

    public function testClaimingVoucherWithInvalidCodeThrowsException(): void
    {
        try {
            $this->messageBus->dispatch(
                new ClaimVoucher(
                    playerId: PlayerFixture::PLAYER_REGULAR,
                    voucherCode: 'INVALIDCODE12345',
                ),
            );
            self::fail('Expected VoucherNotFound exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(VoucherNotFound::class, $previous);
        }
    }

    public function testClaimingAlreadyUsedVoucherThrowsException(): void
    {
        try {
            $this->messageBus->dispatch(
                new ClaimVoucher(
                    playerId: PlayerFixture::PLAYER_REGULAR,
                    voucherCode: VoucherFixture::VOUCHER_USED_CODE,
                ),
            );
            self::fail('Expected VoucherAlreadyUsed exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(VoucherAlreadyUsed::class, $previous);
        }
    }

    public function testClaimingExpiredVoucherThrowsException(): void
    {
        try {
            $this->messageBus->dispatch(
                new ClaimVoucher(
                    playerId: PlayerFixture::PLAYER_REGULAR,
                    voucherCode: VoucherFixture::VOUCHER_EXPIRED_CODE,
                ),
            );
            self::fail('Expected VoucherExpired exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(VoucherExpired::class, $previous);
        }
    }

    public function testVoucherCodeIsCaseInsensitive(): void
    {
        $playerId = PlayerFixture::PLAYER_PRIVATE;
        $voucherCode = strtolower(VoucherFixture::VOUCHER_AVAILABLE_CODE);

        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: $playerId,
                voucherCode: $voucherCode,
            ),
        );

        // Verify voucher is marked as used
        $voucher = $this->voucherRepository->getByCode(VoucherFixture::VOUCHER_AVAILABLE_CODE);
        self::assertTrue($voucher->isUsed());
    }
}
