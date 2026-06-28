<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;

#[ApiResource(
    shortName: 'CompetitionDetail',
    operations: [
        new Get(
            uriTemplate: '/v1/competitions/{id}',
            openapi: new OpenApiOperation(tags: ['Competitions']),
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            provider: CompetitionDetailResponseProvider::class,
        ),
    ],
)]
final class CompetitionDetailResponse
{
    /** @var array<CompetitionRoundResponse> */
    public array $rounds;

    /**
     * @param array<CompetitionRoundResponse> $rounds
     */
    public function __construct(
        public string $id,
        public string $name,
        public null|string $shortcut,
        public null|string $slug,
        public null|string $logo,
        public null|string $description,
        public null|string $location,
        public null|string $country_code,
        public bool $is_online,
        public null|string $date_from,
        public null|string $date_to,
        public null|string $link,
        public null|string $registration_link,
        public null|string $results_link,
        array $rounds,
    ) {
        $this->rounds = $rounds;
    }
}
