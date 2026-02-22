<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;

#[ApiResource(
    shortName: 'PlayerStatistics',
    operations: [
        new Get(
            uriTemplate: '/v1/players/{playerId}/statistics',
            security: "is_granted('ROLE_OAUTH2_STATISTICS:READ')",
            provider: PlayerStatisticsResponseProvider::class,
        ),
    ],
)]
final class PlayerStatisticsResponse
{
    public function __construct(
        public string $player_id,
        public StatisticsGroupResponse $solo,
        public StatisticsGroupResponse $duo,
        public StatisticsGroupResponse $team,
    ) {
    }
}
