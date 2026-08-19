<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Query\GetPlayerStatistics;
use SpeedPuzzling\Web\Results\PlayerStatistics;
use SpeedPuzzling\Web\Security\ApiUser;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MyStatisticsResponse>
 */
final readonly class MyStatisticsResponseProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private GetPlayerStatistics $getPlayerStatistics,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MyStatisticsResponse
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        $playerId = $user->getPlayer()->id->toString();

        $solo = $this->getPlayerStatistics->solo($playerId);
        $duo = $this->getPlayerStatistics->duo($playerId);
        $team = $this->getPlayerStatistics->team($playerId);

        return new MyStatisticsResponse(
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
