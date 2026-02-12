<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\TransactionRating;
use SpeedPuzzling\Web\Exceptions\DuplicateTransactionRating;
use SpeedPuzzling\Web\Exceptions\TransactionRatingExpired;
use SpeedPuzzling\Web\Exceptions\TransactionRatingNotAllowed;
use SpeedPuzzling\Web\Message\RateTransaction;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SoldSwappedItemRepository;
use SpeedPuzzling\Web\Repository\TransactionRatingRepository;
use SpeedPuzzling\Web\Value\TransactionRole;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RateTransactionHandler
{
    public function __construct(
        private SoldSwappedItemRepository $soldSwappedItemRepository,
        private PlayerRepository $playerRepository,
        private TransactionRatingRepository $transactionRatingRepository,
        private EntityManagerInterface $entityManager,
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RateTransaction $message): void
    {
        $soldSwappedItem = $this->soldSwappedItemRepository->get($message->soldSwappedItemId);
        $reviewer = $this->playerRepository->get($message->reviewerId);

        // Determine reviewer role
        $sellerId = $soldSwappedItem->seller->id->toString();
        $buyerPlayer = $soldSwappedItem->buyerPlayer;

        if ($buyerPlayer === null) {
            throw new TransactionRatingNotAllowed('Ratings are only available for transactions with registered buyers.');
        }

        $buyerId = $buyerPlayer->id->toString();

        if ($message->reviewerId === $sellerId) {
            $reviewerRole = TransactionRole::Seller;
            $reviewedPlayer = $buyerPlayer;
        } elseif ($message->reviewerId === $buyerId) {
            $reviewerRole = TransactionRole::Buyer;
            $reviewedPlayer = $soldSwappedItem->seller;
        } else {
            throw new TransactionRatingNotAllowed('Only transaction participants can rate.');
        }

        // Check for duplicate rating
        $existingRating = $this->transactionRatingRepository->findByTransactionAndReviewer(
            $message->soldSwappedItemId,
            $message->reviewerId,
        );

        if ($existingRating !== null) {
            throw new DuplicateTransactionRating('You have already rated this transaction.');
        }

        // Check 30-day window
        $daysSinceSold = $soldSwappedItem->soldAt->diff($this->clock->now())->days;

        if ($daysSinceSold > 30) {
            throw new TransactionRatingExpired('Rating window has expired (30 days).');
        }

        // Create the rating
        $rating = new TransactionRating(
            id: Uuid::uuid7(),
            soldSwappedItem: $soldSwappedItem,
            reviewer: $reviewer,
            reviewedPlayer: $reviewedPlayer,
            stars: $message->stars,
            reviewText: $message->reviewText,
            ratedAt: $this->clock->now(),
            reviewerRole: $reviewerRole,
        );

        $this->transactionRatingRepository->save($rating);
        $this->entityManager->flush();

        // Recalculate denormalized rating stats on the reviewed player
        /** @var array{rating_count: int|string, average_rating: null|string}|false $stats */
        $stats = $this->database->executeQuery(
            'SELECT COUNT(*) AS rating_count, ROUND(AVG(stars)::numeric, 2) AS average_rating FROM transaction_rating WHERE reviewed_player_id = :playerId',
            ['playerId' => $reviewedPlayer->id->toString()],
        )->fetchAssociative();

        assert(is_array($stats));

        $ratingCount = (int) $stats['rating_count'];
        $averageRating = is_string($stats['average_rating']) ? $stats['average_rating'] : null;

        $reviewedPlayer->updateRatingStats($ratingCount, $averageRating);
    }
}
