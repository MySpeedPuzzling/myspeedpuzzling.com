<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Query\GetCollectionItems;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Results\CollectionItemOverview;

/**
 * @implements ProviderInterface<PlayerCollectionItemsResponse>
 */
final readonly class PlayerCollectionItemsResponseProvider implements ProviderInterface
{
    public function __construct(
        private GetCollectionItems $getCollectionItems,
        private GetPlayerProfile $getPlayerProfile,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerCollectionItemsResponse
    {
        /** @var string $playerId */
        $playerId = $uriVariables['playerId'];

        /** @var string $collectionId */
        $collectionId = $uriVariables['collectionId'];

        $profile = $this->getPlayerProfile->byId($playerId);

        if ($profile->isPrivate) {
            return new PlayerCollectionItemsResponse(
                collection_id: $collectionId,
                count: 0,
                items: [],
            );
        }

        $dbCollectionId = $collectionId === 'default' ? null : $collectionId;

        $items = $this->getCollectionItems->byCollectionAndPlayer($dbCollectionId, $playerId);

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
                ),
                $items,
            ),
        );
    }
}
