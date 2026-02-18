<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class TransactionRatingView
{
    public function __construct(
        public string $ratingId,
        public string $reviewerName,
        public string $reviewerCode,
        public null|string $reviewerAvatar,
        public null|string $reviewerCountry,
        public string $reviewerRole,
        public int $stars,
        public null|string $reviewText,
        public DateTimeImmutable $ratedAt,
        public string $puzzleName,
        public null|int $puzzlePiecesCount,
        public string $transactionType,
        public null|string $puzzleImage,
        public null|string $puzzleId,
    ) {
    }
}
