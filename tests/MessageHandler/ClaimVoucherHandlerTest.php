<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerAlreadyClaimedVoucher;
use SpeedPuzzling\Web\Exceptions\VoucherAlreadyUsed;
use SpeedPuzzling\Web\Exceptions\VoucherExpired;
use SpeedPuzzling\Web\Exceptions\VoucherNotFound;
use SpeedPuzzling\Web\Exceptions\VoucherUsageLimitReached;
use SpeedPuzzling\Web\Message\ClaimVoucher;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\VoucherClaimRepository;
use SpeedPuzzling\Web\Repository\VoucherRepository;
use SpeedPuzzling\Web\Results\ClaimVoucherResult;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\VoucherFixture;
use SpeedPuzzling\Web\Value\VoucherType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class ClaimVoucherHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private VoucherRepository $voucherRepository;
    private MembershipRepository $membershipRepository;
    private PlayerRepository $playerRepository;
    private VoucherClaimRepository $voucherClaimRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->voucherRepository = $container->get(VoucherRepository::class);
        $this->membershipRepository = $container->get(MembershipRepository::class);
        $this->playerRepository = $container->get(PlayerRepository::class);
        $this->voucherClaimRepository = $container->get(VoucherClaimRepository::class);
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

    public function testClaimingPercentageVoucherCreatesClaimAndStoresOnPlayer(): void
    {
        // Use PLAYER_WITH_FAVORITES - has no membership, so won't call Stripe
        $playerId = PlayerFixture::PLAYER_WITH_FAVORITES;
        $voucherCode = VoucherFixture::VOUCHER_PERCENTAGE_AVAILABLE_CODE;

        $envelope = $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: $playerId,
                voucherCode: $voucherCode,
            ),
        );

        /** @var HandledStamp|null $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        self::assertNotNull($handledStamp);

        /** @var ClaimVoucherResult $result */
        $result = $handledStamp->getResult();
        self::assertTrue($result->success);
        self::assertSame(VoucherType::PercentageDiscount, $result->voucherType);
        self::assertTrue($result->redirectToMembership);
        self::assertSame(20, $result->percentageDiscount);

        // Verify voucher claim was created
        $voucher = $this->voucherRepository->getByCode($voucherCode);
        self::assertTrue($this->voucherClaimRepository->hasPlayerClaimedVoucher($playerId, $voucher->id->toString()));

        // Verify voucher is stored on player for future checkout
        $player = $this->playerRepository->get($playerId);
        self::assertNotNull($player->claimedDiscountVoucher);
        self::assertSame($voucher->id->toString(), $player->claimedDiscountVoucher->id->toString());
    }

    public function testClaimingPercentageVoucherResultHasCorrectType(): void
    {
        // Use PLAYER_PRIVATE - has no membership, so won't call Stripe
        $playerId = PlayerFixture::PLAYER_PRIVATE;
        $voucherCode = VoucherFixture::VOUCHER_PERCENTAGE_AVAILABLE_CODE;

        $envelope = $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: $playerId,
                voucherCode: $voucherCode,
            ),
        );

        /** @var HandledStamp|null $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        self::assertNotNull($handledStamp);

        /** @var ClaimVoucherResult $result */
        $result = $handledStamp->getResult();
        self::assertSame(VoucherType::PercentageDiscount, $result->voucherType);
        self::assertSame(20, $result->percentageDiscount);
    }

    public function testClaimingExpiredPercentageVoucherThrowsException(): void
    {
        // Use PLAYER_WITH_FAVORITES - has no membership
        try {
            $this->messageBus->dispatch(
                new ClaimVoucher(
                    playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
                    voucherCode: VoucherFixture::VOUCHER_PERCENTAGE_EXPIRED_CODE,
                ),
            );
            self::fail('Expected VoucherExpired exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(VoucherExpired::class, $previous);
        }
    }

    public function testClaimingPercentageVoucherWhenMaxUsesReachedThrowsException(): void
    {
        // Use PLAYER_PRIVATE - has no membership (PLAYER_REGULAR already claimed this voucher in fixture)
        try {
            $this->messageBus->dispatch(
                new ClaimVoucher(
                    playerId: PlayerFixture::PLAYER_PRIVATE,
                    voucherCode: VoucherFixture::VOUCHER_PERCENTAGE_MAX_USES_REACHED_CODE,
                ),
            );
            self::fail('Expected VoucherUsageLimitReached exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(VoucherUsageLimitReached::class, $previous);
        }
    }

    public function testClaimingSamePercentageVoucherTwiceThrowsException(): void
    {
        // Use PLAYER_WITH_FAVORITES - has no membership
        $playerId = PlayerFixture::PLAYER_WITH_FAVORITES;
        $voucherCode = VoucherFixture::VOUCHER_PERCENTAGE_AVAILABLE_CODE;

        // First claim should succeed
        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: $playerId,
                voucherCode: $voucherCode,
            ),
        );

        // Second claim by same player should fail
        try {
            $this->messageBus->dispatch(
                new ClaimVoucher(
                    playerId: $playerId,
                    voucherCode: $voucherCode,
                ),
            );
            self::fail('Expected PlayerAlreadyClaimedVoucher exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(PlayerAlreadyClaimedVoucher::class, $previous);
        }
    }

    public function testMultiplePlayersCanClaimSamePercentageVoucher(): void
    {
        $voucherCode = VoucherFixture::VOUCHER_PERCENTAGE_AVAILABLE_CODE;

        // First player claims (PLAYER_WITH_FAVORITES - no membership)
        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
                voucherCode: $voucherCode,
            ),
        );

        // Second player claims (PLAYER_PRIVATE - no membership)
        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: PlayerFixture::PLAYER_PRIVATE,
                voucherCode: $voucherCode,
            ),
        );

        // Verify both claims exist
        $voucher = $this->voucherRepository->getByCode($voucherCode);
        self::assertTrue($this->voucherClaimRepository->hasPlayerClaimedVoucher(
            PlayerFixture::PLAYER_WITH_FAVORITES,
            $voucher->id->toString(),
        ));
        self::assertTrue($this->voucherClaimRepository->hasPlayerClaimedVoucher(
            PlayerFixture::PLAYER_PRIVATE,
            $voucher->id->toString(),
        ));

        // Verify usage count
        self::assertSame(2, $this->voucherClaimRepository->countClaimsForVoucher($voucher->id->toString()));
    }

    public function testPercentageVoucherCodeIsCaseInsensitive(): void
    {
        // Use PLAYER_WITH_FAVORITES - has no membership
        $playerId = PlayerFixture::PLAYER_WITH_FAVORITES;
        $voucherCode = strtolower(VoucherFixture::VOUCHER_PERCENTAGE_AVAILABLE_CODE);

        $envelope = $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: $playerId,
                voucherCode: $voucherCode,
            ),
        );

        /** @var HandledStamp|null $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        self::assertNotNull($handledStamp);

        /** @var ClaimVoucherResult $result */
        $result = $handledStamp->getResult();
        self::assertTrue($result->success);
        self::assertSame(VoucherType::PercentageDiscount, $result->voucherType);
    }
}
