<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\FirstTryResolution;
use SpeedPuzzling\Web\Value\UploadedImageMetadata;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Saves a result as unfinished (docs/features/competitions-management/unfinished-results-plan.md, step 3): an unfinished
 * result edited (pieces, total, puzzle, event, people...), or a finished / relax result that turns out unfinished. Beside
 * EditPuzzleSolvingTime on purpose, the same fields and meaning; the way back (unfinished -> finished / relax) is
 * EditPuzzleSolvingTime with $endsUnfinishedResult. The event is required here as in AddUnfinishedPuzzleSolvingTime:
 * $competitionId (one-time event or edition) or $seriesId (a series pick) - null for both is refused.
 */
readonly final class EditUnfinishedPuzzleSolvingTime
{
    public function __construct(
        public string $currentUserId,
        public string $puzzleSolvingTimeId,
        public null|string $competitionId,
        public int $piecesPlaced,
        // The total time, when they finished afterwards; null = they stopped when the time ran out
        public null|string $totalTime,
        public null|string $comment,
        /** @var array<string> */
        public array $groupPlayers,
        public null|DateTimeImmutable $finishedAt,
        public null|UploadedFile $finishedPuzzlesPhoto,
        public bool $firstAttempt,
        public bool $unboxed,
        public null|string $teamName = null,
        public FirstTryResolution $firstTryResolution = FirstTryResolution::None,
        // Another puzzle moves the result there (tracker only); null = the puzzle stays
        public null|string $puzzleId = null,
        public null|string $seriesId = null,
        public null|UploadedImageMetadata $finishedPuzzlesPhotoBrowserMetadata = null,
    ) {
    }
}
