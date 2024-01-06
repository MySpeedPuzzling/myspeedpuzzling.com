<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\CouldNotGenerateUniqueCode;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Services\PuzzlersGrouping;
use SpeedPuzzling\Web\Value\SolvingTime;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditPuzzleSolvingTimeHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PuzzlersGrouping $puzzlersGrouping,
        private Filesystem $filesystem,
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
        $group = $this->puzzlersGrouping->assembleGroup($currentPlayer, $message->groupPlayers);

        if ($currentPlayer->id->equals($solvingTime->player->id) === false) {
            throw new CanNotModifyOtherPlayersTime();
        }

        $finishedAt = $message->finishedAt ?? $solvingTime->finishedAt;

        $seconds = SolvingTime::fromUserInput($message->time)->seconds;
        assert($seconds !== null);

        $finishedPuzzlePhotoPath = $solvingTime->finishedPuzzlePhoto;

        if ($message->finishedPuzzlesPhoto !== null) {
            $extension = $message->finishedPuzzlesPhoto->guessExtension();
            $fileName = $message->puzzleSolvingTimeId;

            // There was some original image - we need to generate unique name because of caching
            if ($finishedPuzzlePhotoPath !== null) {
                $fileName = $message->puzzleSolvingTimeId . '-' . Uuid::uuid4()->toString();
            }

            $finishedPuzzlePhotoPath = "players/{$currentPlayer->id->toString()}/$fileName.$extension";

            // Stream is better because it is memory safe
            $stream = fopen($message->finishedPuzzlesPhoto->getPathname(), 'rb');
            $this->filesystem->writeStream($finishedPuzzlePhotoPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $solvingTime->modify(
            $seconds,
            $message->comment,
            $group,
            $finishedAt,
            $finishedPuzzlePhotoPath,
        );
    }
}
