<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\PuzzlingTeam;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CouldNotGenerateUniqueCode;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Exceptions\SuspiciousPpm;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\MistypedYearNormalizer;
use SpeedPuzzling\Web\Services\PuzzlersGrouping;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Value\SolvingTime;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use SpeedPuzzling\Web\Services\RoundResults\SolvingTimeRoundResolver;

#[AsMessageHandler]
readonly final class EditPuzzleSolvingTimeHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PuzzlersGrouping $puzzlersGrouping,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private CompetitionRepository $competitionRepository,
        private ImageOptimizer $imageOptimizer,
        private MistypedYearNormalizer $mistypedYearNormalizer,
        private LoggerInterface $logger,
        private SolvingTimeRoundResolver $roundResolver,
        private PuzzlingTeamResolver $puzzlingTeamResolver,
    ) {
    }

    /**
     * @throws PuzzleSolvingTimeNotFound
     * @throws CanNotModifyOtherPlayersTime
     * @throws CouldNotGenerateUniqueCode
     * @throws CanNotAssembleEmptyGroup
     */
    public function __invoke(EditPuzzleSolvingTime $message): void
    {
        $solvingTime = $this->puzzleSolvingTimeRepository->get($message->puzzleSolvingTimeId);
        $currentPlayer = $this->playerRepository->getByUserIdCreateIfNotExists($message->currentUserId);

        // Checked against the group as stored, before the edit: a member removing themselves
        // finishes this edit and only then loses access
        if ($solvingTime->canBeModifiedBy($currentPlayer) === false) {
            throw new CanNotModifyOtherPlayersTime();
        }

        // Always assembled around whoever tracked the time, never around the editor - the row stays
        // theirs (first puzzler, not removable) no matter which group member edits it
        $group = $this->puzzlersGrouping->assembleGroup($solvingTime->player, $message->groupPlayers);

        $competition = null;

        if ($message->competitionId !== null) {
            try {
                $competition = $this->competitionRepository->get($message->competitionId);
            } catch (CompetitionNotFound $e) {
                // The form validates the id against the picker's set, so this is only reachable when the
                // competition disappeared between render and submit — saving the time without the link
                // beats failing the edit, but it must not pass silently
                $this->logger->warning('Solving time saved without competition: submitted competition id does not exist', [
                    'timeId' => $message->puzzleSolvingTimeId,
                    'competitionId' => $message->competitionId,
                    'userId' => $message->currentUserId,
                    'exception' => $e,
                ]);
            }
        }

        $finishedAt = $this->mistypedYearNormalizer->normalizeFinishedAt($message->finishedAt);

        $seconds = null;
        if ($message->time !== null) {
            $solvingTimeValue = SolvingTime::fromUserInput($message->time);
            $seconds = $solvingTimeValue->seconds;

            $puzzlersCount = 1;
            if ($group !== null) {
                $puzzlersCount = count($group->puzzlers);
            }

            $ppm = $solvingTimeValue->calculatePpm($solvingTime->puzzle->piecesCount, $puzzlersCount);

            if ($ppm >= 100) {
                throw new SuspiciousPpm($solvingTimeValue, $ppm);
            }
        }

        $finishedPuzzlePhotoPath = $solvingTime->finishedPuzzlePhoto;

        if ($message->finishedPuzzlesPhoto !== null) {
            $extension = $message->finishedPuzzlesPhoto->guessExtension();
            $timestamp = $this->clock->now()->getTimestamp();
            $finishedPuzzlePhotoPath = "players/{$solvingTime->player->id->toString()}/{$message->puzzleSolvingTimeId}-$timestamp.$extension";

            $this->imageOptimizer->optimize($message->finishedPuzzlesPhoto->getPathname());

            // Stream is better because it is memory safe
            $stream = fopen($message->finishedPuzzlesPhoto->getPathname(), 'rb');
            $this->filesystem->writeStream($finishedPuzzlePhotoPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $membersBeforeEdit = $solvingTime->memberPlayerIds();

        $solvingTime->modify(
            $seconds,
            $message->comment,
            $group,
            $finishedAt,
            $finishedPuzzlePhotoPath,
            $message->firstAttempt,
            $message->unboxed,
            competition: $competition,
            puzzlingTeam: $puzzlingTeam = $this->puzzlingTeamResolver->resolve($group),
        );

        // Only when a name was typed: touching the team otherwise would load it for nothing
        if (PuzzlingTeam::cleanName($message->teamName) !== null) {
            $puzzlingTeam?->nameIfUnnamed($currentPlayer, $message->teamName, $this->clock->now());
        }

        // Several people can now change one result, so the others get told who did
        if (count($membersBeforeEdit) > 1 || $solvingTime->team !== null) {
            $solvingTime->recordGroupEdit($currentPlayer, $membersBeforeEdit);
        }

        // After modify(): the round depends on the competition and on solo/duo/team, both final only now
        $solvingTime->changeCompetitionRound($this->roundResolver->resolve($solvingTime));
    }
}
