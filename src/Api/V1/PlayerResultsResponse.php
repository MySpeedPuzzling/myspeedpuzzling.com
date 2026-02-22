<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter;

#[ApiResource(
    shortName: 'PlayerResults',
    operations: [
        new Get(
            uriTemplate: '/v1/players/{playerId}/results',
            openapi: new OpenApiOperation(
                parameters: [
                    new Parameter(
                        name: 'type',
                        in: 'query',
                        description: 'Type of results to return (solo, duo, or team)',
                        required: false,
                        schema: [
                            'type' => 'string',
                            'enum' => ['solo', 'duo', 'team'],
                            'default' => 'solo',
                        ],
                    ),
                ],
            ),
            security: "is_granted('ROLE_OAUTH2_RESULTS:READ')",
            provider: PlayerResultsResponseProvider::class,
        ),
    ],
)]
final class PlayerResultsResponse
{
    /** @var array<PlayerResultResponse> */
    public array $results;

    /**
     * @param array<PlayerResultResponse> $results
     */
    public function __construct(
        public string $player_id,
        public string $type,
        public int $count,
        array $results,
    ) {
        $this->results = $results;
    }
}
