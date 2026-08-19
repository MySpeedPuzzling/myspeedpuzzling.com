<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Query\GetCollectionItems;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Results\CollectionItemOverview;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\Api\ApiTokenOwner;
use SpeedPuzzling\Web\Services\Api\PuzzleResponseFactory;
use SpeedPuzzling\Web\Value\CollectionVisibility;

/**
 * GET /api/v1/players/{playerId}/collections/{collectionId}/items - another
 * player's collection, with the website's visibility rules (CollectionDetailController,
 * SystemCollectionDetailController): a private profile, a private custom collection
 * and a private system collection ("default", the player's puzzle-collection
 * visibility setting) are all invisible to anyone but that player - zeroed, not 403,
 * like every /players/{id} endpoint. The player behind an authorization-code token
 * sees their own collections in full here, exactly as under /me.
 *
 * Per-item objects follow the website's collection page as well: "solves" are the
 * collection owner's (results:read data), "prediction" is always the token owner's
 * own forecast - the page shows the visitor's predicted time next to each item of
 * somebody else's collection, never the owner's (plan §0 N1).
 *
 * @implements ProviderInterface<PlayerCollectionItemsResponse>
 */
final readonly class PlayerCollectionItemsResponseProvider implements ProviderInterface
{
    public function __construct(
        private GetCollectionItems $getCollectionItems,
        private GetPlayerCollections $getPlayerCollections,
        private GetPlayerProfile $getPlayerProfile,
        private ApiTokenOwner $tokenOwner,
        private PuzzleResponseFactory $puzzleResponseFactory,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerCollectionItemsResponse
    {
        /** @var string $playerId */
        $playerId = $uriVariables['playerId'];

        /** @var string $collectionId */
        $collectionId = $uriVariables['collectionId'];

        $profile = $this->getPlayerProfile->byId($playerId);

        $dbCollectionId = $collectionId === 'default' ? null : $collectionId;

        // Garbage ids (e.g. "undefined") must 404, not crash the query with an invalid uuid cast
        if ($dbCollectionId !== null && !Uuid::isValid($dbCollectionId)) {
            throw new CollectionNotFound();
        }

        if ($this->isVisibleToTokenOwner($profile, $dbCollectionId) === false) {
            return new PlayerCollectionItemsResponse(
                collection_id: $collectionId,
                count: 0,
                items: [],
            );
        }

        $items = $this->getCollectionItems->byCollectionAndPlayer($dbCollectionId, $playerId);

        // One batch per list, whatever its size: the collection owner's solves
        // (results:read data, so only for a token that may read results) and the
        // token owner's own predictions
        $insights = $this->puzzleResponseFactory->insightsFor(
            array_map(static fn (CollectionItemOverview $item): string => $item->puzzleId, $items),
            solvesOfPlayerId: $playerId,
            includePrediction: true,
        );

        return new PlayerCollectionItemsResponse(
            collection_id: $collectionId,
            count: count($items),
            items: array_map(
                static fn(CollectionItemOverview $item) => new CollectionItemResponse(
                    collection_item_id: $item->collectionItemId,
                    puzzle_id: $item->puzzleId,
                    puzzle_name: $item->puzzleName,
                    manufacturer_name: $item->manufacturerName,
                    pieces_count: $item->piecesCount,
                    image: $item->image,
                    comment: $item->comment,
                    added_at: $item->addedAt->format('c'),
                    statistics: $insights->statistics($item->puzzleId),
                    difficulty: $insights->difficulty($item->puzzleId),
                    prediction: $insights->prediction($item->puzzleId),
                    solves: $insights->solves($item->puzzleId),
                ),
                $items,
            ),
        );
    }

    /**
     * The website's rule, in order: the owner always sees their own; a private
     * profile hides everything; the system collection follows the player's
     * puzzle-collection visibility; a custom collection its own visibility (an
     * unknown id is indistinguishable from a private one - zeroed as well).
     */
    private function isVisibleToTokenOwner(PlayerProfile $profile, null|string $dbCollectionId): bool
    {
        if ($this->tokenOwner->profile()?->playerId === $profile->playerId) {
            return true;
        }

        if ($profile->isPrivate) {
            return false;
        }

        if ($dbCollectionId === null) {
            return $profile->puzzleCollectionVisibility === CollectionVisibility::Public;
        }

        foreach ($this->getPlayerCollections->byPlayerId($profile->playerId, includePrivate: false) as $collection) {
            if ($collection->collectionId === $dbCollectionId) {
                return true;
            }
        }

        return false;
    }
}
