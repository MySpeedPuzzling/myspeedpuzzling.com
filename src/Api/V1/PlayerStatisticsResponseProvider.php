<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Query\GetPlayerStatistics;
use SpeedPuzzling\Web\Results\PlayerStatistics;

/**
 * @implements ProviderInterface<PlayerStatisticsResponse>
 */
final readonly class PlayerStatisticsResponseProvider implements ProviderInterface
{
    public function __construct(
        private GetPlayerStatistics $getPlayerStatistics,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerStatisticsResponse
    {
        /** @var string $playerId */
        $playerId = $uriVariables['playerId'];

        $solo = $this->getPlayerStatistics->solo($playerId);
        $duo = $this->getPlayerStatistics->duo($playerId);
        $team = $this->getPlayerStatistics->team($playerId);

        return new PlayerStatisticsResponse(
            player_id: $playerId,
            solo: $this->mapStatistics($solo),
            duo: $this->mapStatistics($duo),
            team: $this->mapStatistics($team),
        );
    }

    private function mapStatistics(PlayerStatistics $stats): StatisticsGroupResponse
    {
        return new StatisticsGroupResponse(
            total_seconds: $stats->totalSeconds,
            total_pieces: $stats->totalPieces,
            solved_puzzles_count: $stats->solvedPuzzlesCount,
        );
    }
}
