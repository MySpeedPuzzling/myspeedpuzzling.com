<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a scanned competition tag points at (docs/features/competitions-management/toolkit/R-review-and-plan.md §1.3):
 * its kind and id as the path carries them - a participant / line-up / entry id (lower-cased) or a table number.
 */
final readonly class CompetitionTag
{
    private function __construct(
        public CompetitionTagKind $kind,
        public string $id,
    ) {
    }

    public static function participant(string $participantId): self
    {
        return new self(CompetitionTagKind::Participant, strtolower($participantId));
    }

    public static function lineup(string $lineupId): self
    {
        return new self(CompetitionTagKind::Lineup, strtolower($lineupId));
    }

    public static function table(int $tableNumber): self
    {
        return new self(CompetitionTagKind::Table, (string) $tableNumber);
    }

    public static function entry(string $entryId): self
    {
        return new self(CompetitionTagKind::Entry, strtolower($entryId));
    }

    public function tableNumber(): null|int
    {
        return $this->kind === CompetitionTagKind::Table ? (int) $this->id : null;
    }
}
