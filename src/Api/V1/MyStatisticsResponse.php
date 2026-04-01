<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;

#[ApiResource(
    shortName: 'MyStatistics',
    operations: [
        new Get(
            uriTemplate: '/v1/me/statistics',
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_STATISTICS:READ')",
            provider: MyStatisticsResponseProvider::class,
        ),
    ],
)]
final class MyStatisticsResponse
{
    public function __construct(
        public string $player_id,
        public StatisticsGroupResponse $solo,
        public StatisticsGroupResponse $duo,
        public StatisticsGroupResponse $team,
    ) {
    }
}
