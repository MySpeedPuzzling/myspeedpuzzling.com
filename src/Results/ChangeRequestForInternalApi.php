<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PuzzleChangeRequestSource;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;

/**
 * A puzzle change request as GET /internal-api/puzzle-change-requests lists it (GetChangeRequestsForInternalApi).
 * Image paths are storage paths - the controller turns them into fetchable URLs.
 */
readonly final class ChangeRequestForInternalApi
{
    /**
     * @param list<string> $tags
     * @param null|array{outcome: string, decidedAt: DateTimeImmutable, decidedById: null|string, decidedByName: null|string, source: null|string, note: null|string, rejectionReason: null|string, image: null|string} $decision
     */
    public function __construct(
        public string $id,
        public string $puzzleId,
        public string $puzzleName,
        public PuzzleReportStatus $status,
        public PuzzleChangeRequestSource $source,
        public array $tags,
        public null|float $confidence,
        public null|string $modelVersion,
        public null|string $sourceImageFingerprint,
        // The puzzle's picture when the request was filed - an image change never deletes it
        public null|string $originalImage,
        // The proposed picture: the proposal's file while pending, the puzzle's new picture once approved with it,
        // null once rejected (the proposal's file is deleted then) or when it proposes none
        public null|string $proposedImage,
        public null|string $originalArchivePath,
        public DateTimeImmutable $submittedAt,
        public null|array $decision,
    ) {
    }
}
