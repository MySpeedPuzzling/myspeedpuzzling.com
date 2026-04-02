<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;

#[ApiResource(
    shortName: 'PlayerCollectionItems',
    operations: [
        new Get(
            uriTemplate: '/v1/players/{playerId}/collections/{collectionId}/items',
            openapi: new OpenApiOperation(tags: ['Players']),
            security: "is_granted('ROLE_OAUTH2_COLLECTIONS:READ')",
            provider: PlayerCollectionItemsResponseProvider::class,
        ),
    ],
)]
final class PlayerCollectionItemsResponse
{
    /** @var array<CollectionItemResponse> */
    public array $items;

    /**
     * @param array<CollectionItemResponse> $items
     */
    public function __construct(
        public string $collection_id,
        public int $count,
        array $items,
    ) {
        $this->items = $items;
    }
}
