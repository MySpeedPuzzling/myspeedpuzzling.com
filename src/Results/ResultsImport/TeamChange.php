<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\ResultsImport;

/**
 * One team's difference between a check and what is stored - what importing the event does to it
 * (docs/features/event-results-import/results-import-page.md P4: a team gone from the sheet is removed when nobody
 * claimed it, kept when somebody did).
 */
readonly final class TeamChange
{
    public const string ADDED = 'added';
    public const string REMOVED = 'removed';
    // No longer in the sheet - kept, somebody claimed it
    public const string KEPT_CLAIMED = 'kept_claimed';
    public const string CHANGED = 'changed';

    /**
     * @param self::ADDED|self::REMOVED|self::KEPT_CLAIMED|self::CHANGED $kind
     */
    public function __construct(
        public string $kind,
        public string $teamName,
        public bool $claimed,
        public null|int $secondsBefore = null,
        public null|int $piecesBefore = null,
        public null|int $sizeBefore = null,
        public null|int $secondsAfter = null,
        public null|int $piecesAfter = null,
        public null|int $sizeAfter = null,
        // Renamed the same team ("puzzle pals" -> "Puzzle Pals"): the name it had
        public null|string $nameBefore = null,
    ) {
    }

    public function resultChanged(): bool
    {
        return $this->secondsBefore !== $this->secondsAfter || $this->piecesBefore !== $this->piecesAfter;
    }

    public function sizeChanged(): bool
    {
        return $this->sizeBefore !== $this->sizeAfter;
    }

    /**
     * A claimed team keeps its people - the sheet's new size is not applied (ClaimedTeamChangedSize)
     */
    public function sizeKept(): bool
    {
        return $this->claimed && $this->sizeChanged();
    }
}
