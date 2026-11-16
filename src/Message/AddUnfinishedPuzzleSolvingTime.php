<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\FirstTryResolution;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use SpeedPuzzling\Web\Value\UploadedImageMetadata;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * An unfinished competition result - the round's time ran out with $piecesPlaced pieces placed
 * (docs/features/competitions-management/unfinished-results-plan.md, step 3). Beside AddPuzzleSolvingTime on purpose:
 * the add form's path stays as it is (Jan, 2026-11-14). The same fields, the same meaning, except:
 *  - no time to solve: $totalTime is the total when they kept going and finished afterwards (optional, over the limit);
 *  - an event is required - a one-time event or edition ($competitionId), a round ($roundId, the API) or a series pick
 *    ($seriesId, whose edition must be found by its revealed round puzzle) - and it must resolve to a round with a time
 *    limit for the puzzle and solo/pair/team (UnfinishedResult);
 *  - no duplicate-time or pace questions: there is no time to compare.
 */
readonly final class AddUnfinishedPuzzleSolvingTime
{
    public function __construct(
        public UuidInterface $timeId,
        public string $userId,
        public string $puzzleId,
        public null|string $competitionId,
        public int $piecesPlaced,
        // "1:48:12" - the total time, when they finished afterwards; null = they stopped when the time ran out
        public null|string $totalTime,
        public null|string $comment,
        public null|UploadedFile $finishedPuzzlesPhoto,
        /** @var array<string> */
        public array $groupPlayers,
        public null|DateTimeImmutable $finishedAt,
        public bool $firstAttempt,
        public bool $unboxed,
        public null|string $roundId = null,
        public null|string $teamName = null,
        public FirstTryResolution $firstTryResolution = FirstTryResolution::None,
        public null|SolvingTimeSource $createdVia = null,
        // A stopwatch past the limit is exactly the "finished afterwards" case: its time is the total
        public null|string $stopwatchId = null,
        public null|string $seriesId = null,
        public null|UploadedImageMetadata $finishedPuzzlesPhotoBrowserMetadata = null,
    ) {
    }
}
