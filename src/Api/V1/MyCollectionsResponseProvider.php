<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Query\GetCollectionItems;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
use SpeedPuzzling\Web\Security\ApiUser;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MyCollectionsResponse>
 */
final readonly class MyCollectionsResponseProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private GetPlayerCollections $getPlayerCollections,
        private GetCollectionItems $getCollectionItems,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MyCollectionsResponse
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        $playerId = $user->getPlayer()->id->toString();

        $systemItemCount = $this->getCollectionItems->countByCollectionAndPlayer(null, $playerId);

        $collections = $this->getPlayerCollections->byPlayerId($playerId, includePrivate: true);

        $responses = [];

        if ($systemItemCount > 0 || $collections === []) {
            $responses[] = new CollectionResponse(
                collection_id: 'default',
                name: 'Default Collection',
                description: null,
                visibility: 'private',
            );
        }

        foreach ($collections as $collection) {
            $responses[] = new CollectionResponse(
                collection_id: $collection->collectionId ?? 'default',
                name: $collection->name,
                description: $collection->description,
                visibility: $collection->visibility->value,
            );
        }

        return new MyCollectionsResponse(
            player_id: $playerId,
            count: count($responses),
            collections: $responses,
        );
    }
}
