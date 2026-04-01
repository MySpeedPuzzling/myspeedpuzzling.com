<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;

#[ApiResource(
    shortName: 'PlayerCollections',
    operations: [
        new Get(
            uriTemplate: '/v1/players/{playerId}/collections',
            security: "is_granted('ROLE_OAUTH2_COLLECTIONS:READ')",
            provider: PlayerCollectionsResponseProvider::class,
        ),
    ],
)]
final class PlayerCollectionsResponse
{
    /** @var array<CollectionResponse> */
    public array $collections;

    /**
     * @param array<CollectionResponse> $collections
     */
    public function __construct(
        public string $player_id,
        public int $count,
        array $collections,
    ) {
        $this->collections = $collections;
    }
}
