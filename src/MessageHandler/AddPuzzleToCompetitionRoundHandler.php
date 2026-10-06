<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCompetitionRoundCategory;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Query\GetCompetitionRounds;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\ManufacturerResolver;
use SpeedPuzzling\Web\Services\PuzzleImageNamer;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddPuzzleToCompetitionRoundHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompetitionRoundRepository $competitionRoundRepository,
        private CompetitionRoundPuzzleRepository $competitionRoundPuzzleRepository,
        private PuzzleRepository $puzzleRepository,
        private PlayerRepository $playerRepository,
        private ManufacturerResolver $manufacturerResolver,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private SecretPuzzleHides $secretPuzzleHides,
        private ImageOptimizer $imageOptimizer,
        private PuzzleImageNamer $puzzleImageNamer,
        private GetCompetitionRounds $getCompetitionRounds,
    ) {
    }

    /**
     * @throws PuzzleAlreadyInCompetitionRoundCategory
     */
    public function __invoke(AddPuzzleToCompetitionRound $message): void
    {
        $round = $this->competitionRoundRepository->get($message->roundId);

        $isNewPuzzle = !Uuid::isValid($message->puzzle);

        if ($isNewPuzzle) {
            $puzzle = $this->createNewPuzzle($message);
        } else {
            $puzzle = $this->puzzleRepository->get($message->puzzle);

            $conflictingRound = $this->getCompetitionRounds->roundWithPuzzleInCategory(
                competitionId: $round->competition->id->toString(),
                puzzleIds: [$puzzle->id->toString()],
                category: $round->category,
            );

            if ($conflictingRound !== null) {
                throw new PuzzleAlreadyInCompetitionRoundCategory($conflictingRound);
            }
        }

        // A new puzzle exists nowhere else yet, and a puzzle still secret from another round is not public either:
        // the round keeps it secret on the whole site, not only on its event pages (SecretPuzzleHides)
        $roundPuzzle = new CompetitionRoundPuzzle(
            id: $message->roundPuzzleId,
            round: $round,
            puzzle: $puzzle,
            hideUntilRoundStarts: $message->hideUntilRoundStarts,
            hideMode: $message->hideUntilRoundStarts ? $message->hideMode : null,
            hidesEverywhere: $message->hideUntilRoundStarts
                && ($isNewPuzzle || $puzzle->isImageHiddenAt($this->clock->now())),
        );

        $this->competitionRoundPuzzleRepository->save($roundPuzzle);
        $this->secretPuzzleHides->resync($puzzle);
    }

    private function createNewPuzzle(AddPuzzleToCompetitionRound $message): Puzzle
    {
        $player = $this->playerRepository->getByUserIdCreateIfNotExists($message->userId);
        $now = $this->clock->now();

        $manufacturer = $this->manufacturerResolver->resolve($message->brand, $player, $now);

        $puzzleId = Uuid::uuid7();
        $puzzlePhotoPath = null;

        if ($message->puzzlePhoto !== null) {
            $extension = $message->puzzlePhoto->guessExtension() ?? 'jpg';
            // A secret puzzle's picture must not be found by guessing its file name from the public name and id
            $puzzlePhotoPath = $message->hideUntilRoundStarts
                ? $this->puzzleImageNamer->secretFilename($extension)
                : $this->puzzleImageNamer->generateFilename(
                    $manufacturer->name,
                    $message->puzzle,
                    $message->piecesCount ?? 0,
                    $puzzleId->toString(),
                    $extension,
                );

            $this->imageOptimizer->optimize($message->puzzlePhoto->getPathname());

            $stream = fopen($message->puzzlePhoto->getPathname(), 'rb');
            $this->filesystem->writeStream($puzzlePhotoPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $puzzle = new Puzzle(
            $puzzleId,
            $message->piecesCount ?? 0,
            $message->puzzle,
            approved: false,
            image: $puzzlePhotoPath,
            manufacturer: $manufacturer,
            addedByUser: $player,
            addedAt: $now,
            brandCodes: $message->brandCodes,
            eans: $message->eans,
        );

        $this->entityManager->persist($puzzle);

        return $puzzle;
    }
}
