<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

/**
 * One item of a puzzle collection: the flat puzzle fields plus the per-puzzle
 * insight objects. The four trailing objects are always present in the JSON
 * and null means exactly "not available to this token" (see
 * docs/features/api/README.md, Members-Exclusive Data): statistics is public
 * and always an object; difficulty needs the token owner to be a member;
 * prediction is self-only - present on /me/collections/{id}/items for a
 * member who has not opted out and whose token may read results, always null
 * on /players/{id}/collections/{cid}/items; solves are the collection owner's
 * own history (the token owner on /me, the listed player on /players/{id})
 * when the token may read results (PAT or results:read).
 */
final class CollectionItemResponse
{
    public function __construct(
        public string $collectionItemId,
        public string $puzzleId,
        public string $puzzleName,
        public null|string $manufacturerName,
        public int $piecesCount,
        public null|string $image,
        public null|string $comment,
        public string $addedAt,
        public PuzzleStatisticsResponse $statistics,
        public null|PuzzleDifficultyResponse $difficulty = null,
        public null|TimePredictionResponse $prediction = null,
        public null|PlayerSolvesResponse $solves = null,
    ) {
    }
}
