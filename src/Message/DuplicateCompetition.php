<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;

/**
 * "Duplicate" of a one-time event or an edition (docs/features/competitions-management/README.md "Duplicating an
 * event"): a new draft with the organiser's details, settings, rounds and page content - never anything people did
 * (participants, entries, teams, results, referees, round puzzles, tables, online timing). An edition's copy is a new
 * edition of the same series.
 *
 * The overrides (internal API only - the web copies everything and the organiser edits the draft): null = the source's
 * value. A new `dateFrom` moves everything dated by the same number of days - `dateTo` (unless sent), every round's
 * start (wall clock in the round's zone) and the registration window.
 */
readonly final class DuplicateCompetition
{
    public function __construct(
        public UuidInterface $newCompetitionId,
        public string $sourceCompetitionId,
        public string $actingPlayerId,
        public null|string $name = null,
        public null|DateTimeImmutable $dateFrom = null,
        public null|DateTimeImmutable $dateTo = null,
        public null|string $location = null,
    ) {
    }
}
