<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
use SpeedPuzzling\Web\Query\GetPlayerProfile;

/**
 * @implements ProviderInterface<PlayerCollectionsResponse>
 */
final readonly class PlayerCollectionsResponseProvider implements ProviderInterface
{
    public function __construct(
        private GetPlayerCollections $getPlayerCollections,
        private GetPlayerProfile $getPlayerProfile,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerCollectionsResponse
    {
        /** @var string $playerId */
        $playerId = $uriVariables['playerId'];

        $profile = $this->getPlayerProfile->byId($playerId);

        if ($profile->isPrivate) {
            return new PlayerCollectionsResponse(
                player_id: $playerId,
                count: 0,
                collections: [],
            );
        }

        $collections = $this->getPlayerCollections->byPlayerId($playerId, includePrivate: false);

        $responses = array_map(
            static fn($collection) => new CollectionResponse(
                collection_id: $collection->collectionId ?? 'default',
                name: $collection->name,
                description: $collection->description,
                visibility: $collection->visibility->value,
            ),
            $collections,
        );

        return new PlayerCollectionsResponse(
            player_id: $playerId,
            count: count($responses),
            collections: $responses,
        );
    }
}
