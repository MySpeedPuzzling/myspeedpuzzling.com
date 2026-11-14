<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Events\GroupSolvingTimeEdited;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\SolvingTimeCustomValuesRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An edit of a pair's/team's result took a registered member off it: their own values of that result go with them
 * (docs/features/custom-fields.md). Nobody else could ever read the row - GetCustomValues reads the viewer's own, and
 * only on a result the viewer is on - so this is tidiness, not privacy. The event is handled asynchronously, after
 * the edit has committed: nothing here runs in the edit's request, and nothing here can fail it.
 */
#[AsMessageHandler]
readonly final class RemoveCustomValuesOfFormerMembers
{
    public function __construct(
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private SolvingTimeCustomValuesRepository $solvingTimeCustomValuesRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(GroupSolvingTimeEdited $event): void
    {
        try {
            $solvingTime = $this->puzzleSolvingTimeRepository->findById($event->puzzleSolvingTimeId);

            if ($solvingTime === null) {
                return;
            }

            $membersNow = $solvingTime->memberPlayerIds();

            if (array_diff($event->memberPlayerIdsBeforeEdit, $membersNow) === []) {
                return;
            }

            foreach ($this->solvingTimeCustomValuesRepository->ofSolvingTime($solvingTime) as $row) {
                if (in_array($row->player->id->toString(), $membersNow, true) === false) {
                    $this->solvingTimeCustomValuesRepository->delete($row);
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Custom values of a member taken off a result could not be removed', [
                'solvingTimeId' => $event->puzzleSolvingTimeId->toString(),
                'exception' => $exception,
            ]);
        }
    }
}
