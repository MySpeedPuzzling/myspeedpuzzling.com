<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\ConversationRatingInfo;
use SpeedPuzzling\Web\Results\PendingTransactionRating;
use SpeedPuzzling\Web\Results\PlayerRatingSummary;
use SpeedPuzzling\Web\Results\TransactionRatingView;

readonly final class GetTransactionRatings
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<TransactionRatingView>
     */
    public function forPlayer(string $playerId, int $limit = 20, int $offset = 0): array
    {
        $query = <<<SQL
SELECT
    tr.id AS rating_id,
    COALESCE(reviewer.name, reviewer.code) AS reviewer_name,
    reviewer.code AS reviewer_code,
    reviewer.avatar AS reviewer_avatar,
    reviewer.country AS reviewer_country,
    tr.reviewer_role,
    tr.stars,
    tr.review_text,
    tr.rated_at,
    p.name AS puzzle_name,
    p.pieces_count AS puzzle_pieces_count,
    ssi.listing_type AS transaction_type,
    p.image AS puzzle_image,
    p.id AS puzzle_id
FROM transaction_rating tr
JOIN player reviewer ON tr.reviewer_id = reviewer.id
JOIN sold_swapped_item ssi ON tr.sold_swapped_item_id = ssi.id
JOIN puzzle p ON ssi.puzzle_id = p.id
WHERE tr.reviewed_player_id = :playerId
ORDER BY tr.rated_at DESC
LIMIT :limit
OFFSET :offset
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'limit' => $limit,
                'offset' => $offset,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): TransactionRatingView {
            /** @var array{
             *     rating_id: string,
             *     reviewer_name: string,
             *     reviewer_code: string,
             *     reviewer_avatar: null|string,
             *     reviewer_country: null|string,
             *     reviewer_role: string,
             *     stars: int|string,
             *     review_text: null|string,
             *     rated_at: string,
             *     puzzle_name: string,
             *     puzzle_pieces_count: null|int|string,
             *     transaction_type: string,
             *     puzzle_image: null|string,
             *     puzzle_id: string,
             * } $row
             */

            return new TransactionRatingView(
                ratingId: $row['rating_id'],
                reviewerName: $row['reviewer_name'],
                reviewerCode: $row['reviewer_code'],
                reviewerAvatar: $row['reviewer_avatar'],
                reviewerCountry: $row['reviewer_country'],
                reviewerRole: $row['reviewer_role'],
                stars: (int) $row['stars'],
                reviewText: $row['review_text'],
                ratedAt: new DateTimeImmutable($row['rated_at']),
                puzzleName: $row['puzzle_name'],
                puzzlePiecesCount: $row['puzzle_pieces_count'] !== null ? (int) $row['puzzle_pieces_count'] : null,
                transactionType: $row['transaction_type'],
                puzzleImage: $row['puzzle_image'],
                puzzleId: $row['puzzle_id'],
            );
        }, $data);
    }

    public function averageForPlayer(string $playerId): null|PlayerRatingSummary
    {
        $query = <<<SQL
SELECT
    COUNT(*) AS rating_count,
    ROUND(AVG(stars)::numeric, 2) AS average_rating
FROM transaction_rating
WHERE reviewed_player_id = :playerId
SQL;

        /** @var false|array{rating_count: int|string, average_rating: null|string} $row */
        $row = $this->database
            ->executeQuery($query, ['playerId' => $playerId])
            ->fetchAssociative();

        if ($row === false || (int) $row['rating_count'] === 0) {
            return null;
        }

        return new PlayerRatingSummary(
            averageRating: (float) $row['average_rating'],
            ratingCount: (int) $row['rating_count'],
        );
    }

    public function canRate(string $soldSwappedItemId, string $playerId): bool
    {
        $query = <<<SQL
SELECT ssi.id
FROM sold_swapped_item ssi
WHERE ssi.id = :soldSwappedItemId
    AND ssi.buyer_player_id IS NOT NULL
    AND (ssi.seller_id = :playerId OR ssi.buyer_player_id = :playerId)
    AND ssi.sold_at > :cutoffDate
    AND NOT EXISTS (
        SELECT 1 FROM transaction_rating tr
        WHERE tr.sold_swapped_item_id = ssi.id
            AND tr.reviewer_id = :playerId
    )
SQL;

        $result = $this->database
            ->executeQuery($query, [
                'soldSwappedItemId' => $soldSwappedItemId,
                'playerId' => $playerId,
                'cutoffDate' => $this->clock->now()->modify('-30 days')->format('Y-m-d H:i:s'),
            ])
            ->fetchOne();

        return $result !== false;
    }

    /**
     * @return array<PendingTransactionRating>
     */
    public function pendingRatings(string $playerId): array
    {
        $query = <<<SQL
SELECT
    ssi.id AS sold_swapped_item_id,
    p.name AS puzzle_name,
    p.image AS puzzle_image,
    p.pieces_count,
    CASE
        WHEN ssi.seller_id = :playerId THEN COALESCE(buyer.name, buyer.code)
        ELSE COALESCE(seller.name, seller.code)
    END AS other_player_name,
    CASE
        WHEN ssi.seller_id = :playerId THEN buyer.code
        ELSE seller.code
    END AS other_player_code,
    CASE
        WHEN ssi.seller_id = :playerId THEN buyer.avatar
        ELSE seller.avatar
    END AS other_player_avatar,
    ssi.listing_type AS transaction_type,
    ssi.sold_at
FROM sold_swapped_item ssi
JOIN puzzle p ON ssi.puzzle_id = p.id
JOIN player seller ON ssi.seller_id = seller.id
JOIN player buyer ON ssi.buyer_player_id = buyer.id
WHERE ssi.buyer_player_id IS NOT NULL
    AND (ssi.seller_id = :playerId OR ssi.buyer_player_id = :playerId)
    AND ssi.sold_at > :cutoffDate
    AND NOT EXISTS (
        SELECT 1 FROM transaction_rating tr
        WHERE tr.sold_swapped_item_id = ssi.id
            AND tr.reviewer_id = :playerId
    )
ORDER BY ssi.sold_at DESC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'cutoffDate' => $this->clock->now()->modify('-30 days')->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): PendingTransactionRating {
            /** @var array{
             *     sold_swapped_item_id: string,
             *     puzzle_name: string,
             *     puzzle_image: null|string,
             *     pieces_count: null|int|string,
             *     other_player_name: string,
             *     other_player_code: string,
             *     other_player_avatar: null|string,
             *     transaction_type: string,
             *     sold_at: string,
             * } $row
             */

            return new PendingTransactionRating(
                soldSwappedItemId: $row['sold_swapped_item_id'],
                puzzleName: $row['puzzle_name'],
                puzzleImage: $row['puzzle_image'],
                piecesCount: $row['pieces_count'] !== null ? (int) $row['pieces_count'] : null,
                otherPlayerName: $row['other_player_name'],
                otherPlayerCode: $row['other_player_code'],
                otherPlayerAvatar: $row['other_player_avatar'],
                transactionType: $row['transaction_type'],
                soldAt: new DateTimeImmutable($row['sold_at']),
            );
        }, $data);
    }

    public function forConversation(string $puzzleId, string $playerAId, string $playerBId, string $viewerId): null|ConversationRatingInfo
    {
        $query = <<<SQL
SELECT
    ssi.id AS sold_swapped_item_id,
    ssi.sold_at,
    tr.stars AS my_rating_stars
FROM sold_swapped_item ssi
LEFT JOIN transaction_rating tr ON tr.sold_swapped_item_id = ssi.id AND tr.reviewer_id = :viewerId
WHERE ssi.puzzle_id = :puzzleId
    AND ssi.buyer_player_id IS NOT NULL
    AND (
        (ssi.seller_id = :playerAId AND ssi.buyer_player_id = :playerBId)
        OR (ssi.seller_id = :playerBId AND ssi.buyer_player_id = :playerAId)
    )
ORDER BY ssi.sold_at DESC
LIMIT 1
SQL;

        /** @var false|array{sold_swapped_item_id: string, sold_at: string, my_rating_stars: null|int|string} $row */
        $row = $this->database
            ->executeQuery($query, [
                'puzzleId' => $puzzleId,
                'playerAId' => $playerAId,
                'playerBId' => $playerBId,
                'viewerId' => $viewerId,
            ])
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $soldAt = new DateTimeImmutable($row['sold_at']);
        $myRatingStars = $row['my_rating_stars'] !== null ? (int) $row['my_rating_stars'] : null;
        $canRate = $myRatingStars === null && $soldAt > $this->clock->now()->modify('-30 days');

        return new ConversationRatingInfo(
            soldSwappedItemId: $row['sold_swapped_item_id'],
            canRate: $canRate,
            myRatingStars: $myRatingStars,
        );
    }

    /**
     * @return array<string, ConversationRatingInfo>
     */
    public function forConversationList(string $playerId): array
    {
        $query = <<<SQL
SELECT DISTINCT ON (c.id)
    c.id AS conversation_id,
    ssi.id AS sold_swapped_item_id,
    ssi.sold_at,
    tr.stars AS my_rating_stars
FROM conversation c
JOIN sold_swapped_item ssi ON ssi.puzzle_id = c.puzzle_id
    AND ssi.buyer_player_id IS NOT NULL
    AND (
        (ssi.seller_id = c.initiator_id AND ssi.buyer_player_id = c.recipient_id)
        OR (ssi.seller_id = c.recipient_id AND ssi.buyer_player_id = c.initiator_id)
    )
LEFT JOIN transaction_rating tr ON tr.sold_swapped_item_id = ssi.id AND tr.reviewer_id = :playerId
WHERE c.sell_swap_list_item_id IS NULL
    AND c.puzzle_id IS NOT NULL
    AND (c.initiator_id = :playerId OR c.recipient_id = :playerId)
ORDER BY c.id, ssi.sold_at DESC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        $result = [];

        foreach ($data as $row) {
            /** @var array{conversation_id: string, sold_swapped_item_id: string, sold_at: string, my_rating_stars: null|int|string} $row */
            $soldAt = new DateTimeImmutable($row['sold_at']);
            $myRatingStars = $row['my_rating_stars'] !== null ? (int) $row['my_rating_stars'] : null;
            $canRate = $myRatingStars === null && $soldAt > $this->clock->now()->modify('-30 days');

            $result[$row['conversation_id']] = new ConversationRatingInfo(
                soldSwappedItemId: $row['sold_swapped_item_id'],
                canRate: $canRate,
                myRatingStars: $myRatingStars,
            );
        }

        return $result;
    }
}
