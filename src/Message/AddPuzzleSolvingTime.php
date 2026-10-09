<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\FirstTryResolution;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class AddPuzzleSolvingTime
{
    public function __construct(
        public UuidInterface $timeId,
        public string $userId,
        public string $puzzleId,
        public null|string $competitionId,
        public string $time,
        public null|string $comment,
        public null|UploadedFile $finishedPuzzlesPhoto,
        /** @var array<string> */
        public array $groupPlayers,
        public null|DateTimeImmutable $finishedAt,
        public bool $firstAttempt,
        public bool $unboxed,
        public null|string $roundId = null,
        // Names the pair/team of $groupPlayers when it has no name yet
        public null|string $teamName = null,
        // Answer to a first try the group already has (docs/features/first-try-integrity.md)
        public FirstTryResolution $firstTryResolution = FirstTryResolution::None,
        public null|SolvingTimeSource $createdVia = null,
        // Saving the stopwatch's result finishes it, in the same transaction
        public null|string $stopwatchId = null,
        // The player was told the same time from the same day is saved already and said it is another solve
        // (docs/features/duplicate-results.md, Layer 2) - recorded as `saved_anyway` with the result
        public bool $duplicateConfirmed = false,
        // The form asked about the time (far off the player's own times) and the player answered "Yes, it's right"
        // (docs/features/suspicious-time-review.md, "Catch it while typing"): the time the form compared it with -
        // stored with the result as a SuspiciousTimeConfirmation. Null = not asked
        public null|int $paceConfirmedExpectedSeconds = null,
        // A series pick (docs/features/events-page/high-frequency-series.md): MySpeedPuzzling finds the edition. Only
        // when neither roundId nor competitionId is given - those are explicit links and win
        public null|string $seriesId = null,
    ) {
    }
}
