<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\CompetitionStandingOverview;
use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Results\RoundResultEntryMember;
use SpeedPuzzling\Web\Results\StandingRoundColumn;
use SpeedPuzzling\Web\Results\StandingTable;
use SpeedPuzzling\Web\Services\CompetitionToolkit\StandingCalculator;
use SpeedPuzzling\Web\Value\CompetitionLineupId;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;
use SpeedPuzzling\Web\Value\StandingEntrant;
use SpeedPuzzling\Web\Value\StandingEntryInput;
use SpeedPuzzling\Web\Value\StandingRoundInput;

/**
 * A standing computed for the organiser (competition toolkit A6, A-round-formats.md §3.4 "Organiser view"): over every
 * round of it, published or not - "provisional" until all are published. Entries and their in-round ranks are the
 * organiser tools' own (GetRoundResultEntries: people going to the event, the official ranking), so the preview agrees
 * with the desk. A round counts once anything is recorded in it (a result or did not start) - a round not played yet
 * makes nobody "missing". The public view (published rounds only, OfficialEntrantsView's masking) is A7's.
 *
 * Statements: the event's rounds (with their piece totals), then GetRoundResultEntries per round of the standing.
 */
readonly final class GetStandingResults
{
    public function __construct(
        private Connection $database,
        private GetRoundResultEntries $getRoundResultEntries,
    ) {
    }

    /**
     * @param bool $organiser false = a referee: no profile codes of private players
     */
    public function organiserView(CompetitionStandingOverview $standing, bool $organiser = true): StandingTable
    {
        /** @var array<string, array{id: string, name: string, slug: null|string, category: string, minutes_limit: null|int, published: bool, puzzle_format: string, puzzles_count: int, pieces_total: null|int}> $roundsById */
        $roundsById = [];

        foreach (
            $this->database->fetchAllAssociative(<<<SQL
SELECT
    r.id,
    r.name,
    r.slug,
    r.category,
    r.minutes_limit,
    r.results_published_at IS NOT NULL AS published,
    r.puzzle_format,
    (SELECT COUNT(*) FROM competition_round_puzzle crp WHERE crp.round_id = r.id) AS puzzles_count,
    (SELECT SUM(p.pieces_count) FROM competition_round_puzzle crp INNER JOIN puzzle p ON p.id = crp.puzzle_id WHERE crp.round_id = r.id) AS pieces_total
FROM competition_round r
WHERE r.competition_id = :competitionId
SQL, ['competitionId' => $standing->competitionId]) as $row
        ) {
            /** @var array{id: string, name: string, slug: null|string, category: string, minutes_limit: null|int, published: bool, puzzle_format: string, puzzles_count: int|string, pieces_total: null|int|string} $row */
            $roundsById[strtolower($row['id'])] = [
                ...$row,
                'puzzles_count' => (int) $row['puzzles_count'],
                'pieces_total' => $row['pieces_total'] !== null ? (int) $row['pieces_total'] : null,
            ];
            }

            $columns = [];
            $inputs = [];
            $skipped = 0;

            foreach ($standing->roundIds as $roundId) {
                $round = $roundsById[$roundId] ?? null;

                if ($round === null || $round['category'] !== $standing->category->value) {
                    $skipped++;

                    continue;
                }

                $isPick = $round['puzzle_format'] === RoundPuzzleFormat::Pick->value;
                $entries = array_map(
                    fn (RoundResultEntry $entry): StandingEntryInput => $this->input($standing->competitionId, $entry, $isPick ? $entry->piecesCount : $round['pieces_total'], $organiser),
                    $this->getRoundResultEntries->forRound($roundId),
                );
                $counted = StandingCalculator::anyRecorded($entries);

                $columns[] = new StandingRoundColumn(
                    id: $roundId,
                    name: $round['name'],
                    slug: $round['slug'],
                    minutesLimit: $round['minutes_limit'],
                    published: $round['published'],
                    counted: $counted,
                    piecesUnknown: $round['pieces_total'] === null,
                );
                $inputs[] = new StandingRoundInput($roundId, $round['minutes_limit'], $counted, $entries);
            }

            return new StandingTable(
                standing: $standing,
                rounds: $columns,
                rows: StandingCalculator::sum($standing->rules, $inputs),
                skippedRounds: $skipped,
                includesUnpublished: true,
            );
    }

    private function input(string $competitionId, RoundResultEntry $entry, null|int $piecesCount, bool $organiser): StandingEntryInput
    {
        if ($entry->participantId !== null) {
            return new StandingEntryInput(
                key: 'p:' . strtolower($entry->participantId),
                result: $entry->result,
                rank: $entry->rank,
                piecesCount: $piecesCount,
                name: $entry->displayName(),
                countries: $entry->countries,
                entrants: [new StandingEntrant(
                    name: $entry->name ?? $entry->displayName(),
                    country: $entry->country,
                    playerId: $entry->playerId,
                    playerCode: $organiser || $entry->playerIsPrivate === false ? $entry->playerCode : null,
                )],
            );
        }

        $participantIds = $entry->participantIds();

        return new StandingEntryInput(
            // The exact set of people - a substitute makes another line-up; a pair/team without people is its own
            key: $participantIds !== [] ? 'l:' . CompetitionLineupId::of($competitionId, $participantIds) : 't:' . $entry->ref->id,
            result: $entry->result,
            rank: $entry->rank,
            piecesCount: $piecesCount,
            name: $entry->displayName(),
            countries: $entry->countries,
            entrants: array_map(static fn (RoundResultEntryMember $member): StandingEntrant => new StandingEntrant(
                name: $member->name,
                country: $member->country,
                playerId: $member->playerId,
                playerCode: $organiser || $member->playerIsPrivate === false ? $member->playerCode : null,
            ), $entry->members),
        );
    }
}
