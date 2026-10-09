<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Query\GetApiSeriesList;
use SpeedPuzzling\Web\Results\ApiSeriesListItem;

/**
 * GET /api/v1/series - the series a client can link a solving time to (`series_id`), docs/features/events-page/
 * high-frequency-series.md "API v1" (P16). Events are public: any authenticated token, no membership gate.
 *
 * @implements ProviderInterface<SeriesListResponse>
 */
final readonly class SeriesListResponseProvider implements ProviderInterface
{
    public function __construct(
        private GetApiSeriesList $getApiSeriesList,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SeriesListResponse
    {
        $series = array_map(
            static fn (ApiSeriesListItem $item): SeriesListItemResponse => new SeriesListItemResponse(
                id: $item->id,
                name: $item->name,
                shortcut: $item->shortcut,
                slug: $item->slug,
                logo: $item->logo,
                isOnline: $item->isOnline,
                location: $item->location,
                countryCode: $item->countryCode?->name,
                link: $item->link,
                organizationName: $item->organizationName,
                editionsCount: $item->editionsCount,
                nextDate: $item->nextDate,
                lastDate: $item->lastDate,
            ),
            $this->getApiSeriesList->all(),
        );

        return new SeriesListResponse(
            count: count($series),
            series: $series,
        );
    }
}
