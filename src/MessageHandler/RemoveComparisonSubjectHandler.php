<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CanNotRemoveYourselfFromComparison;
use SpeedPuzzling\Web\Exceptions\ComparisonSubjectNotFound;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RemoveComparisonSubject;
use SpeedPuzzling\Web\Query\GetPlayerMembership;
use SpeedPuzzling\Web\Repository\ComparisonSubjectRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Takes one row out of the owner's own line-up (docs/features/player-comparison.md). Without a membership the Solo
 * line-up is "you + 1 other", so the owner's own row stays.
 */
#[AsMessageHandler]
readonly final class RemoveComparisonSubjectHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private ComparisonSubjectRepository $comparisonSubjectRepository,
        private GetPlayerMembership $getPlayerMembership,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws ComparisonSubjectNotFound
     * @throws CanNotRemoveYourselfFromComparison
     */
    public function __invoke(RemoveComparisonSubject $message): void
    {
        $owner = $this->playerRepository->get($message->playerId);
        $row = $this->comparisonSubjectRepository->get($owner, $message->comparisonSubjectId);

        if ($row->isSelf() && $this->isMember($owner->id->toString()) === false) {
            throw new CanNotRemoveYourselfFromComparison();
        }

        $this->comparisonSubjectRepository->delete($row);
    }

    private function isMember(string $playerId): bool
    {
        try {
            return $this->getPlayerMembership->byId($playerId)->isActive($this->clock->now());
        } catch (MembershipNotFound) {
            return false;
        }
    }
}
