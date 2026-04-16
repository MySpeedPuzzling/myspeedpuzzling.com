<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzlingTeam;
use SpeedPuzzling\Web\Entity\ResultDuplicatePrevention;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CouldNotGenerateUniqueCode;
use SpeedPuzzling\Web\Exceptions\FirstTryAlreadyTaken;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Exceptions\SuspiciousPpm;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\ResultDuplicatePreventionRepository;
use SpeedPuzzling\Web\Services\FirstTry\FirstTryAssessor;
use SpeedPuzzling\Web\Value\FirstTryEntry;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\MistypedYearNormalizer;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\SolvingTimePredictor;
use SpeedPuzzling\Web\Services\PuzzlersGrouping;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Value\DuplicatePreventionKind;
use SpeedPuzzling\Web\Value\SolvingTime;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use SpeedPuzzling\Web\Services\RoundResults\SolvingTimeRoundResolver;
use Symfony\Component\Messenger\MessageBusInterface;

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
        private SolvingTimePredictor $solvingTimePredictor,
        private FirstTryAssessor $firstTryAssessor,
        private ResultDuplicatePreventionRepository $resultDuplicatePreventionRepository,
        private PuzzleRepository $puzzleRepository,
        private MessageBusInterface $commandBus,
    ) {
    }

    /**
     * @throws PuzzleSolvingTimeNotFound
     * @throws CanNotModifyOtherPlayersTime
     * @throws CouldNotGenerateUniqueCode
     * @throws CanNotAssembleEmptyGroup
     * @throws SuspiciousPpm
     * @throws FirstTryAlreadyTaken
     * @throws PuzzleNotFound
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

        // Another puzzle (docs/features/duplicate-results.md, Layer 4): only whoever tracked the result moves it -
        // for everybody else in the group it is the tracker's result
        $puzzle = $solvingTime->puzzle;

        if ($message->puzzleId !== null && strtolower($message->puzzleId) !== $puzzle->id->toString()) {
            if ($solvingTime->player->id->equals($currentPlayer->id) === false) {
                throw new CanNotModifyOtherPlayersTime();
            }

            $puzzle = $this->puzzleRepository->get($message->puzzleId);
        }

        $puzzleChanges = $puzzle->id->equals($solvingTime->puzzle->id) === false;

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

        // Before anything is written: the form checks the same, this catches races and the API
        $unmarkFirstTryOf = [];

        if ($message->firstAttempt) {
            // On another puzzle the result is new there - nothing about it is an old, tolerated duplicate
            $firstTry = $this->firstTryAssessor->assess(new FirstTryEntry(
                actorPlayerId: $currentPlayer->id->toString(),
                puzzleId: $puzzle->id->toString(),
                memberPlayerIds: FirstTryEntry::memberIdsOf($solvingTime->player->id->toString(), $group),
                solvedAt: $finishedAt ?? $solvingTime->trackedAt,
                editedTimeId: $solvingTime->id->toString(),
                previouslyFirstAttempt: $puzzleChanges ? false : $solvingTime->firstAttempt,
                previousMemberPlayerIds: $puzzleChanges ? null : $solvingTime->memberPlayerIds(),
            ));

            if ($firstTry->blocks($message->firstTryResolution)) {
                throw new FirstTryAlreadyTaken($firstTry);
            }

            $unmarkFirstTryOf = $firstTry->timeIdsToUnmark($message->firstTryResolution);
        }

        $seconds = null;
        if ($message->time !== null) {
            $solvingTimeValue = SolvingTime::fromUserInput($message->time);
            $seconds = $solvingTimeValue->seconds;

            $puzzlersCount = 1;
            if ($group !== null) {
                $puzzlersCount = count($group->puzzlers);
            }

            $ppm = $solvingTimeValue->calculatePpm($puzzle->piecesCount, $puzzlersCount);

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

        // Before modify(): its PuzzleSolvingTimeModified is then about the new puzzle, the old one is told by this
        $solvingTime->moveToPuzzle($puzzle);

        $solvingTime->modify(
            $seconds,
            $message->comment,
            $group,
            $finishedAt,
            $finishedPuzzlePhotoPath,
            $message->firstAttempt,
            $message->unboxed,
            competition: $competition,
            puzzlingTeam: $puzzlingTeam = $this->puzzlingTeamResolver->resolve($group, usedByPlayerId: $currentPlayer->id->toString()),
        );

        // Only when a name was typed: touching the team otherwise would load it for nothing
        if (PuzzlingTeam::cleanName($message->teamName) !== null) {
            $puzzlingTeam?->nameIfUnnamed($currentPlayer, $message->teamName, $this->clock->now());
        }

        // Several people can now change one result, so the others get told who did
        if (count($membersBeforeEdit) > 1 || $solvingTime->team !== null) {
            $solvingTime->recordGroupEdit($currentPlayer, $membersBeforeEdit);
        }

        // The first try moved here
        foreach ($unmarkFirstTryOf as $timeId) {
            $this->puzzleSolvingTimeRepository->get($timeId)->unmarkFirstAttempt($currentPlayer);
        }

        // After modify(): the round depends on the competition and on solo/duo/team, both final only now
        $solvingTime->changeCompetitionRound($this->roundResolver->resolve($solvingTime));

        // modify() forgets the prediction when the date, solo/group or the presence of a time changed, moveToPuzzle()
        // always
        $this->solvingTimePredictor->reconstructIfPending($solvingTime);

        // In the same transaction: the pair is a confirmed real second solve only if the edit is saved
        if ($message->duplicateConfirmed) {
            $this->resultDuplicatePreventionRepository->save(new ResultDuplicatePrevention(
                id: Uuid::uuid7(),
                player: $currentPlayer,
                kind: DuplicatePreventionKind::SavedAnyway,
                timeId: $solvingTime->id,
                puzzleId: $solvingTime->puzzle->id,
                createdAt: $this->clock->now(),
                via: SolvingTimeSource::Form,
            ));
        }

        $this->commandBus->dispatch(new RecalculateBadgesForPlayer($currentPlayer->id->toString()));
    }
}
