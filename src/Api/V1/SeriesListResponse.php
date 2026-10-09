<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;

#[ApiResource(
    shortName: 'SeriesList',
    operations: [
        new Get(
            uriTemplate: '/v1/series',
            openapi: new OpenApiOperation(
                tags: ['Competitions'],
                summary: 'List the publicly visible competition series',
                description: 'Every publicly visible series of competitions (one-time events are in GET /api/v1/competitions), by name. '
                    . 'Send its id as series_id when adding or editing a solving time: MySpeedPuzzling finds the edition the time belongs to. '
                    . 'editions_count counts its publicly visible editions; next_date / last_date (days, YYYY-MM-DD) are the first day of '
                    . 'the soonest edition starting after today and of the latest edition that is over - an edition held today is in neither. '
                    . 'organization_name is set only for a publicly visible organization.',
            ),
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            provider: SeriesListResponseProvider::class,
        ),
    ],
)]
final class SeriesListResponse
{
    /** @var array<SeriesListItemResponse> */
    public array $series;

    /**
     * @param array<SeriesListItemResponse> $series
     */
    public function __construct(
        public int $count,
        array $series,
    ) {
        $this->series = $series;
    }
}
