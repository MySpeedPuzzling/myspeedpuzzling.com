<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\DuplicateCaseStatus;
use SpeedPuzzling\Web\Value\DuplicateKind;
use SpeedPuzzling\Web\Value\DuplicateTier;

/**
 * One stored duplicate case as the admin list shows it - mostly its snapshot, the results may be gone.
 */
readonly final class DuplicateCaseListItem
{
    /**
     * @param list<string> $differences
     * @param array{id: string, name: null|string, code: string} $trackerA
     * @param array{id: string, name: null|string, code: string} $trackerB
     * @param list<array{id: string, name: null|string, code: string}> $others
     */
    public function __construct(
        public string $caseId,
        public string $playerId,
        public null|string $playerName,
        public string $playerCode,
        public string $puzzleId,
        public string $puzzleName,
        public int $seconds,
        public string $dayA,
        public string $dayB,
        public DateTimeImmutable $trackedAtA,
        public DateTimeImmutable $trackedAtB,
        public int $gapSeconds,
        public array $differences,
        public array $trackerA,
        public array $trackerB,
        public array $others,
        public DuplicateTier $tier,
        public DuplicateKind $kind,
        public DuplicateCaseStatus $status,
        public DateTimeImmutable $detectedAt,
        public null|DateTimeImmutable $resolvedAt,
    ) {
    }
}
