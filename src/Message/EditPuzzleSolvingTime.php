<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use SpeedPuzzling\Web\FormData\EditPuzzleSolvingTimeFormData;
use SpeedPuzzling\Web\Value\FirstTryResolution;
use SpeedPuzzling\Web\Value\PuzzleAddMode;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class EditPuzzleSolvingTime
{
    public function __construct(
        public string $currentUserId,
        public string $puzzleSolvingTimeId,
        public null|string $competitionId,
        public null|string $time,
        public null|string $comment,
        /** @var array<string> */
        public array $groupPlayers,
        public null|DateTimeImmutable $finishedAt,
        public null|UploadedFile $finishedPuzzlesPhoto,
        public bool $firstAttempt,
        public bool $unboxed,
        // Names the pair/team of $groupPlayers when it has no name yet
        public null|string $teamName = null,
        // Answer to a first try the group already has (docs/features/first-try-integrity.md)
        public FirstTryResolution $firstTryResolution = FirstTryResolution::None,
        // The player was told the same time from the same day is saved already and said it is another solve
        // (docs/features/duplicate-results.md, Layer 2) - recorded as `saved_anyway` with the edit
        public bool $duplicateConfirmed = false,
        // The puzzle the result belongs to - another one than now moves it there (tracker only,
        // docs/features/duplicate-results.md, Layer 4). Null = the puzzle stays
        public null|string $puzzleId = null,
    ) {
    }

    /**
     * @param array<string> $groupPlayers
     */
    public static function fromFormData(
        string $userId,
        string $timeId,
        array $groupPlayers,
        EditPuzzleSolvingTimeFormData $formData,
        null|string $teamName = null,
        FirstTryResolution $firstTryResolution = FirstTryResolution::None,
        bool $duplicateConfirmed = false,
    ): self {
        return new self(
            currentUserId: $userId,
            puzzleSolvingTimeId: $timeId,
            competitionId: $formData->competition,
            time: $formData->mode === PuzzleAddMode::Relax ? null : $formData->getTimeAsString(),
            comment: $formData->comment,
            groupPlayers: $groupPlayers,
            finishedAt: $formData->finishedAt,
            finishedPuzzlesPhoto: $formData->finishedPuzzlesPhoto,
            firstAttempt: $formData->firstAttempt,
            unboxed: $formData->unboxed,
            teamName: $teamName,
            firstTryResolution: $firstTryResolution,
            duplicateConfirmed: $duplicateConfirmed,
            puzzleId: $formData->puzzle,
        );
    }
}
