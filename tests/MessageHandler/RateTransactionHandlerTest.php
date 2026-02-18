<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\DuplicateTransactionRating;
use SpeedPuzzling\Web\Exceptions\TransactionRatingExpired;
use SpeedPuzzling\Web\Exceptions\TransactionRatingNotAllowed;
use SpeedPuzzling\Web\Message\RateTransaction;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\TransactionRatingRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SoldSwappedItemFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class RateTransactionHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private TransactionRatingRepository $transactionRatingRepository;
    private PlayerRepository $playerRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->transactionRatingRepository = $container->get(TransactionRatingRepository::class);
        $this->playerRepository = $container->get(PlayerRepository::class);
    }

    public function testSellerCanRateBuyer(): void
    {
        // SOLD_RECENT: seller=PLAYER_WITH_STRIPE, buyer=PLAYER_REGULAR, -5 days
        $this->messageBus->dispatch(
            new RateTransaction(
                soldSwappedItemId: SoldSwappedItemFixture::SOLD_RECENT,
                reviewerId: PlayerFixture::PLAYER_WITH_STRIPE,
                stars: 4,
                reviewText: 'Good buyer!',
            ),
        );

        $rating = $this->transactionRatingRepository->findByTransactionAndReviewer(
            SoldSwappedItemFixture::SOLD_RECENT,
            PlayerFixture::PLAYER_WITH_STRIPE,
        );

        self::assertNotNull($rating);
        self::assertSame(4, $rating->stars);
        self::assertSame('Good buyer!', $rating->reviewText);
    }

    public function testBuyerCanRateSeller(): void
    {
        // SOLD_RECENT: seller=PLAYER_WITH_STRIPE, buyer=PLAYER_REGULAR, -5 days
        $this->messageBus->dispatch(
            new RateTransaction(
                soldSwappedItemId: SoldSwappedItemFixture::SOLD_RECENT,
                reviewerId: PlayerFixture::PLAYER_REGULAR,
                stars: 5,
            ),
        );

        $rating = $this->transactionRatingRepository->findByTransactionAndReviewer(
            SoldSwappedItemFixture::SOLD_RECENT,
            PlayerFixture::PLAYER_REGULAR,
        );

        self::assertNotNull($rating);
        self::assertSame(5, $rating->stars);
        self::assertNull($rating->reviewText);

        // Check denormalized stats updated on reviewed player (PLAYER_WITH_STRIPE)
        $reviewedPlayer = $this->playerRepository->get(PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertGreaterThan(0, $reviewedPlayer->ratingCount);
    }

    public function testNonParticipantCannotRate(): void
    {
        try {
            $this->messageBus->dispatch(
                new RateTransaction(
                    soldSwappedItemId: SoldSwappedItemFixture::SOLD_RECENT,
                    reviewerId: PlayerFixture::PLAYER_ADMIN,
                    stars: 3,
                ),
            );
            self::fail('Expected TransactionRatingNotAllowed exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(TransactionRatingNotAllowed::class, $previous);
        }
    }

    public function testRatingsOnlyWorkWithRegisteredBuyer(): void
    {
        // SOLD_02 has buyerPlayer=null
        try {
            $this->messageBus->dispatch(
                new RateTransaction(
                    soldSwappedItemId: SoldSwappedItemFixture::SOLD_02,
                    reviewerId: PlayerFixture::PLAYER_PRIVATE,
                    stars: 4,
                ),
            );
            self::fail('Expected TransactionRatingNotAllowed exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(TransactionRatingNotAllowed::class, $previous);
        }
    }

    public function testDuplicateRatingThrowsException(): void
    {
        // SOLD_01 already has a rating from PLAYER_ADMIN via TransactionRatingFixture
        try {
            $this->messageBus->dispatch(
                new RateTransaction(
                    soldSwappedItemId: SoldSwappedItemFixture::SOLD_01,
                    reviewerId: PlayerFixture::PLAYER_ADMIN,
                    stars: 3,
                ),
            );
            self::fail('Expected DuplicateTransactionRating exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(DuplicateTransactionRating::class, $previous);
        }
    }

    public function testExpiredRatingThrowsException(): void
    {
        // SOLD_EXPIRED is -60 days old
        try {
            $this->messageBus->dispatch(
                new RateTransaction(
                    soldSwappedItemId: SoldSwappedItemFixture::SOLD_EXPIRED,
                    reviewerId: PlayerFixture::PLAYER_ADMIN,
                    stars: 4,
                ),
            );
            self::fail('Expected TransactionRatingExpired exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(TransactionRatingExpired::class, $previous);
        }
    }
}
