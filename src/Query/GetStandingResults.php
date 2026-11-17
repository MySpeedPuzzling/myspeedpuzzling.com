<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\CompetitionStandingOverview;
use SpeedPuzzling\Web\Results\PublicStandingRow;
use SpeedPuzzling\Web\Results\PublicStandingTable;
use SpeedPuzzling\Web\Results\PublishedRoundEntrant;
use SpeedPuzzling\Web\Results\PublishedRoundEntry;
use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Results\RoundResultEntryMember;
use SpeedPuzzling\Web\Results\StandingRoundColumn;
use SpeedPuzzling\Web\Results\StandingRow;
use SpeedPuzzling\Web\Results\StandingTable;
use SpeedPuzzling\Web\Services\CompetitionToolkit\StandingCalculator;
use SpeedPuzzling\Web\Value\CompetitionLineupId;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundEntryPuzzles;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;
use SpeedPuzzling\Web\Value\StandingEntrant;
use SpeedPuzzling\Web\Value\StandingEntryInput;
use SpeedPuzzling\Web\Value\StandingRoundInput;

/**
 * A standing computed for the organiser (competition toolkit A6, A-round-formats.md §3.4 "Organiser view"): over every
 * round of it, published or not - "provisional" until all are published. Entries and their in-round ranks are the
 * organiser tools' own (GetRoundResultEntries: people going to the event, the official ranking), so the preview agrees
 * with the desk. A round counts once anything is recorded in it (a result or did not start) - a round not played yet
 * makes nobody "missing".
 *
 * The public view (A7, publicView()) reads the entries only through OfficialEntrantsView: published rounds only, ranked
 * results only (did not start and no result are "missing" either way, like for the organiser), private players masked,
 * people removed from the event left out. It ranks every entrant with the same StandingCalculator::sum() and drops the
 * rows hidden from the viewer only after that, without renumbering - so with every round published its ranks are the
 * organiser's preview's.
 *
 * Statements: the event's rounds (with their piece totals), then GetRoundResultEntries per round of the standing
 * (organiser) or at most two for all its published rounds (public).
 *
 * @phpstan-type RoundRow array{id: string, name: string, slug: null|string, category: string, minutes_limit: null|int, published: bool, puzzle_format: string, puzzles_to_solve: null|int, puzzles_count: int, pieces_total: null|int, pieces_by_round_puzzle: array<string, int>}
 */
readonly final class GetStandingResults
{
    public function __construct(
        private Connection $database,
        private GetRoundResultEntries $getRoundResultEntries,
        private OfficialEntrantsView $officialEntrantsView,
    ) {
    }

    /**
     * @param bool $organiser false = a referee: no profile codes of private players
     */
    public function organiserView(CompetitionStandingOverview $standing, bool $organiser = true): StandingTable
    {
        $roundsById = $this->rounds($standing->competitionId);
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

            $columns[] = self::column($round, $counted, array_any($entries, static fn (StandingEntryInput $entry): bool => $entry->result->isUnfinished()));
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

    /**
     * The standing as the public page shows it to one viewer (competition toolkit A7, A-round-formats.md §3.4). Whether
     * the standing is published and the event public is the caller's business; this counts only the rounds whose
     * official results are published and have something ranked - the others show "–" and make nobody "missing".
     */
    public function publicView(CompetitionStandingOverview $standing, null|string $viewerPlayerId): PublicStandingTable
    {
        $roundsById = $this->rounds($standing->competitionId);

        /** @var array<string, RoundRow> $included */
        $included = [];
        $skipped = 0;

        foreach ($standing->roundIds as $roundId) {
            $round = $roundsById[$roundId] ?? null;

            if ($round === null || $round['category'] !== $standing->category->value) {
                $skipped++;

                continue;
            }

            $included[$roundId] = $round;
        }

        $published = array_filter($included, static fn (array $round): bool => $round['published']);
        $officialByRound = $published !== []
            ? $this->officialEntrantsView->forRounds(
                array_map(static fn (array $round): RoundCategory => RoundCategory::from($round['category']), $published),
                $viewerPlayerId,
                keepHidden: true,
            )
            : [];

        $columns = [];
        $inputs = [];
        /** @var array<string, PublishedRoundEntry> $latest key => the entry of the latest round it is in */
        $latest = [];
        /** @var array<string, bool> $hidden */
        $hidden = [];

        foreach ($included as $roundId => $round) {
            $roundId = (string) $roundId;
            $official = $officialByRound[$roundId] ?? null;
            $counted = $official !== null && $official->isEmpty() === false;
            $entries = [];

            foreach ($official->entries ?? [] as $entry) {
                $key = self::publicKey($standing->competitionId, $entry);
                $entries[] = new StandingEntryInput(
                    key: $key,
                    result: $entry->result,
                    rank: $entry->rank,
                    piecesCount: self::publicPiecesCount($round, $entry),
                    name: $entry->teamName ?? implode(', ', array_map(static fn (PublishedRoundEntrant $entrant): string => $entrant->playerName, $entry->entrants)),
                    countries: self::countries($entry->entrants),
                    entrants: array_map(static fn (PublishedRoundEntrant $entrant): StandingEntrant => new StandingEntrant(
                        name: $entrant->playerName,
                        country: $entrant->playerCountry?->name,
                        playerId: $entrant->playerId,
                        playerCode: $entrant->playerId !== null ? $entrant->linkedPlayerCode : null,
                    ), $entry->entrants),
                );
                $latest[$key] = $entry;
                $hidden[$key] = ($hidden[$key] ?? false) || $entry->hiddenFromViewer;
            }

            $columns[] = self::column($round, $counted, array_any($entries, static fn (StandingEntryInput $entry): bool => $entry->result->isUnfinished()));
            $inputs[] = new StandingRoundInput($roundId, $round['minutes_limit'], $counted, $entries);
        }

        $rows = StandingCalculator::sum($standing->rules, $inputs);

        // Hidden AFTER ranking - everybody else keeps the standing's places (docs/features/player-blocklist.md)
        $visible = array_values(array_filter($rows, static fn (StandingRow $row): bool => ($hidden[$row->key] ?? false) === false));

        return new PublicStandingTable(
            table: new StandingTable(
                standing: $standing,
                rounds: $columns,
                rows: $visible,
                skippedRounds: $skipped,
                includesUnpublished: false,
            ),
            rows: array_map(static function (StandingRow $row) use ($latest): PublicStandingRow {
                $entry = $latest[$row->key];

                return new PublicStandingRow(
                    row: $row,
                    entrants: $entry->entrants,
                    isTeam: $entry->isTeam,
                    teamName: $entry->teamName,
                    isViewers: $entry->isViewers,
                );
            }, $visible),
        );
    }

    /**
     * @return array<string, RoundRow>
     */
    private function rounds(string $competitionId): array
    {
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
    r.puzzles_to_solve,
    (SELECT COUNT(*) FROM competition_round_puzzle crp WHERE crp.round_id = r.id) AS puzzles_count,
    (SELECT SUM(p.pieces_count) FROM competition_round_puzzle crp INNER JOIN puzzle p ON p.id = crp.puzzle_id WHERE crp.round_id = r.id) AS pieces_total,
    (SELECT json_object_agg(crp.id, p.pieces_count) FROM competition_round_puzzle crp INNER JOIN puzzle p ON p.id = crp.puzzle_id WHERE crp.round_id = r.id) AS pieces_by_round_puzzle
FROM competition_round r
WHERE r.competition_id = :competitionId
SQL, ['competitionId' => $competitionId]) as $row
        ) {
            /** @var array{id: string, name: string, slug: null|string, category: string, minutes_limit: null|int, published: bool, puzzle_format: string, puzzles_to_solve: null|int, puzzles_count: int|string, pieces_total: null|int|string, pieces_by_round_puzzle: null|string} $row */
            $pieces = $row['pieces_by_round_puzzle'] !== null ? json_decode($row['pieces_by_round_puzzle'], true, flags: JSON_THROW_ON_ERROR) : [];
            $roundsById[strtolower($row['id'])] = [
                ...$row,
                'puzzles_count' => (int) $row['puzzles_count'],
                'pieces_total' => $row['pieces_total'] !== null ? (int) $row['pieces_total'] : null,
                'pieces_by_round_puzzle' => is_array($pieces) ? array_map(static fn (mixed $count): int => is_numeric($count) ? (int) $count : 0, $pieces) : [],
            ];
            }

        /** @var array<string, RoundRow> $roundsById */
            return $roundsById;
    }

    /**
     * @param RoundRow $round
     */
    private static function column(array $round, bool $counted, bool $hasUnfinished): StandingRoundColumn
    {
        return new StandingRoundColumn(
            id: strtolower($round['id']),
            name: $round['name'],
            slug: $round['slug'],
            minutesLimit: $round['minutes_limit'],
            published: $round['published'],
            counted: $counted,
            piecesUnknown: $round['pieces_total'] === null,
            hasUnfinished: $hasUnfinished,
        );
    }

    /**
     * The entrant across rounds - the same keys as the organiser's input(): the participant (solo), the exact set of
     * members (pair/team), else the pair/team row itself.
     */
    private static function publicKey(string $competitionId, PublishedRoundEntry $entry): string
    {
        if ($entry->isTeam === false && count($entry->participantIds) === 1) {
            return 'p:' . $entry->participantIds[0];
        }

        return $entry->participantIds !== []
            ? 'l:' . CompetitionLineupId::of($competitionId, $entry->participantIds)
            : 't:' . $entry->ref->id;
    }

    /**
     * What the entry places when it finishes - like GetRoundResultEntries: a `pick` entry with all K chosen its puzzles,
     * else the round's K biggest; every other round all its puzzles.
     *
     * @param RoundRow $round
     */
    private static function publicPiecesCount(array $round, PublishedRoundEntry $entry): null|int
    {
        if ($round['puzzle_format'] !== RoundPuzzleFormat::Pick->value || $round['puzzles_to_solve'] === null) {
            return $round['pieces_total'];
        }

        $pieces = $round['pieces_by_round_puzzle'];

        return $entry->puzzles !== null && count($entry->puzzles->items) === $round['puzzles_to_solve']
            ? $entry->puzzles->piecesCount($pieces)
            : RoundEntryPuzzles::roundPiecesBound($round['puzzles_to_solve'], $pieces);
    }

    /**
     * @param list<PublishedRoundEntrant> $entrants
     * @return list<string>
     */
    private static function countries(array $entrants): array
    {
        $countries = [];

        foreach ($entrants as $entrant) {
            if ($entrant->playerCountry !== null && !in_array($entrant->playerCountry->name, $countries, true)) {
                $countries[] = $entrant->playerCountry->name;
            }
        }

        return $countries;
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
