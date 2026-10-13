<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The official result the review suggests for a participant (docs/features/online-competitions/README.md §5.9 "the
 * suggested time rule", OnlineReviewAssessment) - before a person confirmed it:
 * - finished: the last Finished tap that counts (not undone, inside the round) - floor((moment − start) / 1000) s;
 * - unfinished: no such tap, but the participant reported pieces at time-up - pieces placed, from the report in the
 *   round's measure (pieces left convert through the round puzzle's piece count; null when it is not known);
 * - none: neither.
 */
readonly final class OnlineReviewSuggestion implements \JsonSerializable
{
    public const string FINISHED = 'finished';
    public const string UNFINISHED = 'unfinished';
    public const string NONE = 'none';

    /**
     * @param self::FINISHED|self::UNFINISHED|self::NONE $kind
     */
    private function __construct(
        public string $kind,
        public null|int $momentMs = null,
        public null|int $seconds = null,
        public null|string $tapEventId = null,
        public null|int $piecesPlaced = null,
        public null|string $reportedMeasure = null,
        public null|int $reportedPieces = null,
    ) {
    }

    public static function finished(OnlineReviewTap $tap, int $seconds): self
    {
        return new self(self::FINISHED, momentMs: $tap->momentMs, seconds: $seconds, tapEventId: $tap->eventId);
    }

    public static function unfinished(null|int $piecesPlaced, null|string $reportedMeasure, int $reportedPieces): self
    {
        return new self(self::UNFINISHED, piecesPlaced: $piecesPlaced, reportedMeasure: $reportedMeasure, reportedPieces: $reportedPieces);
    }

    public static function none(): self
    {
        return new self(self::NONE);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'kind' => $this->kind,
            'momentMs' => $this->momentMs,
            'seconds' => $this->seconds,
            'tapEventId' => $this->tapEventId,
            'piecesPlaced' => $this->piecesPlaced,
            'reported' => $this->reportedPieces === null ? null : [
                'measure' => $this->reportedMeasure,
                'pieces' => $this->reportedPieces,
            ],
        ];
    }
}
