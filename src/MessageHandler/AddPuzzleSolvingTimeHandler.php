<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\PuzzlingTeam;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CouldNotGenerateUniqueCode;
use SpeedPuzzling\Web\Exceptions\SuspiciousPpm;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\MistypedYearNormalizer;
use SpeedPuzzling\Web\Services\PuzzlersGrouping;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Value\SolvingTime;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use SpeedPuzzling\Web\Services\RoundResults\SolvingTimeRoundResolver;

#[AsMessageHandler]
readonly final class AddPuzzleSolvingTimeHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private Filesystem $filesystem,
        private PuzzlersGrouping $puzzlersGrouping,
        private ClockInterface $clock,
        private CompetitionRepository $competitionRepository,
        private CompetitionRoundRepository $competitionRoundRepository,
        private ImageOptimizer $imageOptimizer,
        private MistypedYearNormalizer $mistypedYearNormalizer,
        private LoggerInterface $logger,
        private SolvingTimeRoundResolver $roundResolver,
        private PuzzlingTeamResolver $puzzlingTeamResolver,
    ) {
    }

    /**
     * @throws CouldNotGenerateUniqueCode
     * @throws CanNotAssembleEmptyGroup
     * @throws SuspiciousPpm
     */
    public function __invoke(AddPuzzleSolvingTime $message): void
    {
        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        $player = $this->playerRepository->getByUserIdCreateIfNotExists($message->userId);
        $group = $this->puzzlersGrouping->assembleGroup($player, $message->groupPlayers);
        $solvingTimeId = $message->timeId;
        $finishedPuzzlePhotoPath = null;
        $trackedAt = $this->clock->now();
        $finishedAt = $this->mistypedYearNormalizer->normalizeFinishedAt($message->finishedAt);
        $solvingTime = SolvingTime::fromUserInput($message->time);
        $puzzlersCount = 1;
        $competitionRound = null;
        $competition = null;

        if ($message->roundId !== null) {
            // The round's competition is derived; both are written together so they cannot disagree.
            $competitionRound = $this->competitionRoundRepository->get($message->roundId);
            $competition = $competitionRound->competition;
        } elseif ($message->competitionId !== null) {
            try {
                $competition = $this->competitionRepository->get($message->competitionId);
            } catch (CompetitionNotFound $e) {
                // The form validates the id against the picker's set, so this is only reachable when the
                // competition disappeared between render and submit — saving the time without the link
                // beats losing the upload, but it must not pass silently
                $this->logger->warning('Solving time saved without competition: submitted competition id does not exist', [
                    'timeId' => $solvingTimeId->toString(),
                    'puzzleId' => $message->puzzleId,
                    'competitionId' => $message->competitionId,
                    'userId' => $message->userId,
                    'exception' => $e,
                ]);
            }
        }

        if ($group !== null) {
            $puzzlersCount = count($group->puzzlers);
        }

        $ppm = $solvingTime->calculatePpm($puzzle->piecesCount, $puzzlersCount);

        if ($ppm >= 100) {
            throw new SuspiciousPpm($solvingTime, $ppm);
        }

        if ($message->finishedPuzzlesPhoto !== null) {
            $extension = $message->finishedPuzzlesPhoto->guessExtension();
            $timestamp = $this->clock->now()->getTimestamp();
            $finishedPuzzlePhotoPath = "players/$player->id/$solvingTimeId-$timestamp.$extension";

            $this->imageOptimizer->optimize($message->finishedPuzzlesPhoto->getPathname());

            // Stream is better because it is memory safe
            $stream = fopen($message->finishedPuzzlesPhoto->getPathname(), 'rb');
            $this->filesystem->writeStream($finishedPuzzlePhotoPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $solvingTime = new PuzzleSolvingTime(
            $solvingTimeId,
            $solvingTime->seconds,
            $player,
            $puzzle,
            $trackedAt,
            false,
            $group,
            $finishedAt,
            $message->comment,
            $finishedPuzzlePhotoPath,
            $message->firstAttempt,
            $message->unboxed,
            competitionRound: $competitionRound,
            competition: $competition,
            // Resolved last, once nothing above can refuse the time any more - the team is created outside
            // the unit of work
            puzzlingTeam: $puzzlingTeam = $this->puzzlingTeamResolver->resolve($group),
        );

        // Only when a name was typed: touching the team otherwise would load it for nothing
        if (PuzzlingTeam::cleanName($message->teamName) !== null) {
            $puzzlingTeam?->nameIfUnnamed($player, $message->teamName, $trackedAt);
        }

        $solvingTime->changeCompetitionRound($this->roundResolver->resolve($solvingTime));

        $this->entityManager->persist($solvingTime);
    }
}
