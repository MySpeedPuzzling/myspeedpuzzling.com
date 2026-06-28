<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter;

#[ApiResource(
    shortName: 'CompetitionList',
    operations: [
        new Get(
            uriTemplate: '/v1/competitions',
            openapi: new OpenApiOperation(
                tags: ['Competitions'],
                parameters: [
                    new Parameter(
                        name: 'status',
                        in: 'query',
                        description: 'Filter competitions by their time period.',
                        required: false,
                        schema: [
                            'type' => 'string',
                            'enum' => ['all', 'live', 'upcoming', 'past'],
                            'default' => 'all',
                        ],
                    ),
                    new Parameter(
                        name: 'online',
                        in: 'query',
                        description: 'Return only online competitions when set to true.',
                        required: false,
                        schema: [
                            'type' => 'boolean',
                            'default' => false,
                        ],
                    ),
                    new Parameter(
                        name: 'country',
                        in: 'query',
                        description: 'Filter by ISO 3166-1 alpha-2 country code (e.g. "cz").',
                        required: false,
                        schema: [
                            'type' => 'string',
                        ],
                    ),
                ],
            ),
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            provider: CompetitionListResponseProvider::class,
        ),
    ],
)]
final class CompetitionListResponse
{
    /** @var array<CompetitionListItemResponse> */
    public array $competitions;

    /**
     * @param array<CompetitionListItemResponse> $competitions
     */
    public function __construct(
        public int $count,
        array $competitions,
    ) {
        $this->competitions = $competitions;
    }
}
