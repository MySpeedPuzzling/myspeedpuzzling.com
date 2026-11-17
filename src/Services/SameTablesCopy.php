<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Results\SameTablesCopyTarget;

/**
 * "Use these tables in later rounds of this category" (competition toolkit W3, seating.md "Same tables in every
 * round"): the rounds of the same kind listed after the source round that use table numbers, each with the tables
 * "Same tables as round X" would give it (SeatingProposer::sameTables()). Read by the confirm and, under the event's
 * lock, by CopyRoundTableNumbersHandler - one plan for both.
 */
readonly final class SameTablesCopy
{
    public function __construct(
        private GetRoundResultsOverview $getRoundResultsOverview,
        private GetRoundResultEntries $getRoundResultEntries,
    ) {
    }

    /**
     * @return array{sourceSeated: int, targets: list<SameTablesCopyTarget>} sourceSeated = the source round's entries
     *     with a table (0 = nothing to copy)
     * @throws CompetitionRoundNotFound
     */
    public function plan(string $competitionId, string $sourceRoundId): array
    {
        $rounds = $this->getRoundResultsOverview->forCompetition($competitionId);
        $source = null;
        $later = [];

        foreach ($rounds as $round) {
            if ($round->roundId === strtolower($sourceRoundId)) {
                $source = $round;

                continue;
            }

            if ($source !== null && $round->category === $source->category && $round->tableNumbersOff === false) {
                $later[] = $round;
            }
        }

        if ($source === null) {
            throw new CompetitionRoundNotFound();
        }

        $sourceEntries = $this->getRoundResultEntries->forRound($source->roundId);
        $sourceSeated = count(array_filter($sourceEntries, static fn (RoundResultEntry $entry): bool => $entry->tableNumber !== null));
        $compareNames = SeatingProposer::nameComparator('en');
        $targets = [];

        foreach ($later as $round) {
            $entries = $this->getRoundResultEntries->forRound($round->roundId);
            $plan = SeatingProposer::sameTables($entries, $sourceEntries, 1, $compareNames);
            $changes = 0;

            foreach ($entries as $entry) {
                if ($entry->tableNumber !== ($plan['tables'][$entry->ref->toString()] ?? null)) {
                    $changes++;
                }
            }

            $targets[] = new SameTablesCopyTarget(
                roundId: $round->roundId,
                name: $round->name,
                entries: count($entries),
                matched: count($plan['matched']),
                changes: $changes,
                hash: self::hash($entries),
                tables: $plan['tables'],
            );
        }

        return ['sourceSeated' => $sourceSeated, 'targets' => $targets];
    }

    /**
     * What the confirm showed of a target: its entrants and how many of them have a table - an entrant added or
     * removed, or tables given or taken meanwhile, make the copy refuse instead of numbering a round nobody looked at.
     *
     * @param list<RoundResultEntry> $entries
     */
    public static function hash(array $entries): string
    {
        $refs = array_map(static fn (RoundResultEntry $entry): string => $entry->ref->toString(), $entries);
        sort($refs);
        $withTable = count(array_filter($entries, static fn (RoundResultEntry $entry): bool => $entry->tableNumber !== null));

        return substr(hash('sha256', implode(',', $refs) . '|' . $withTable), 0, 16);
    }
}
