<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerStatistics;
use SpeedPuzzling\Web\Results\PlayerStatistics;

/**
 * @implements ProviderInterface<PlayerStatisticsResponse>
 */
final readonly class PlayerStatisticsResponseProvider implements ProviderInterface
{
    public function __construct(
        private GetPlayerStatistics $getPlayerStatistics,
        private GetPlayerProfile $getPlayerProfile,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerStatisticsResponse
    {
        /** @var string $playerId */
        $playerId = $uriVariables['playerId'];

        $profile = $this->getPlayerProfile->byId($playerId);

        $emptyStats = new StatisticsGroupResponse(
            totalSeconds: 0,
            totalPieces: 0,
            solvedPuzzlesCount: 0,
        );

        if ($profile->isPrivate) {
            return new PlayerStatisticsResponse(
                playerId: $playerId,
                solo: $emptyStats,
                duo: $emptyStats,
                team: $emptyStats,
            );
        }

        $solo = $this->getPlayerStatistics->solo($playerId);
        $duo = $this->getPlayerStatistics->duo($playerId);
        $team = $this->getPlayerStatistics->team($playerId);

        return new PlayerStatisticsResponse(
            playerId: $playerId,
            solo: $this->mapStatistics($solo),
            duo: $this->mapStatistics($duo),
            team: $this->mapStatistics($team),
        );
    }

    private function mapStatistics(PlayerStatistics $stats): StatisticsGroupResponse
    {
        return new StatisticsGroupResponse(
            totalSeconds: $stats->totalSeconds,
            totalPieces: $stats->totalPieces,
            solvedPuzzlesCount: $stats->solvedPuzzlesCount,
        );
    }
}
