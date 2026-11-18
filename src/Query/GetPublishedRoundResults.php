<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Results\PublishedChallengeRow;
use SpeedPuzzling\Web\Results\PublishedEntryPuzzle;
use SpeedPuzzling\Web\Results\PublishedRoundEntrant;
use SpeedPuzzling\Web\Results\PublishedRoundEntry;
use SpeedPuzzling\Web\Results\PublishedRoundResults;
use SpeedPuzzling\Web\Results\RoundDivision;
use SpeedPuzzling\Web\Results\RoundPuzzleSlot;
use SpeedPuzzling\Web\Services\ChallengeRanking;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\ParticipantNameKey;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\OfficialEntryProfileState;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use Doctrine\DBAL\Connection;

/**
 * A round's published official results for the public round page (docs/features/competitions-management/official-results.md):
 *
 * - The entries come from OfficialEntrantsView - the one place for what the public may see of official entries
 *   (published only, ranked over every ranked entry, then the rows hidden from the viewer dropped without renumbering,
 *   private players masked, people removed from the event left out). The competitor pages and standings share it.
 * - A `pick` round (competition toolkit A5): every row shows the puzzles its entry chose (PublishedEntryPuzzle - names
 *   by the public reveal rule, a hidden one by its label only) and offers "Add to my profile" per puzzle
 *   (withPuzzleProfileStates()).
 * - "Add to my profile" (OfficialEntryProfileState) for the signed-in viewer: their own entry - or, when the organiser
 *   linked them to no entry of the round at all (did not start and no result yet included), an unlinked person whose
 *   name is the viewer's (ParticipantNameKey, the country must not differ), a pair/team nobody is linked to with one
 *   member of the viewer's name (the same rule), and a pair/team with no member names at all (the organiser typed the
 *   team's name only - the Minnesota case) - with a finished result, in a round with exactly one puzzle
 *   that is revealed; offered until the viewer has a time in the round, then "On your profile" (derived, nothing
 *   stored). Every other unlinked row is somebody else's as far as anybody knows: the viewer linked to no entry gets one
 *   line instead, "Is your name here? Connect it" (the event's join flow), when the round has an unlinked row.
 *
 * - A challenge view (real events W4, `?challenge=` - a shown puzzle of a pick round of K ≥ 2): the entries by that
 *   puzzle's own time (ChallengeRanking over every entry, the rows hidden from the viewer dropped after, never
 *   renumbered), the unranked after. It offers no "Add to my profile" (the round's view does) - no statement more.
 *
 * - A round that uses divisions (shared-puzzles-and-divisions.md D5): every row carries its division and division rank;
 *   `?division=` (id, short label or name) shows one division's entries only, and "N ranked" counts that division (A12).
 *   An unknown value - or any on a round without divisions - shows every entry, exactly as without it. A challenge view
 *   (`?challenge=`) ignores `?division=`: the two are exclusive, each one's links drop the other.
 *
 * One statement for the entries (OfficialEntrantsView; it also tells whether the viewer is linked to any entry of the
 * round, and their name), one more for the viewer's own times in the round - only when a row could offer "Add to my
 * profile" (with a viewer's time no round holds yet on the round's puzzle: "This is my time - link it" instead). A pick round's puzzles ride on the rounds statement the page runs anyway (GetEditionRounds).
 */
readonly final class GetPublishedRoundResults
{
    public function __construct(
        private Connection $database,
        private OfficialEntrantsView $officialEntrantsView,
    ) {
    }

    /**
     * SQL condition: the round behind `$roundAlias` (a competition_round alias) shows official results publicly -
     * published, with at least one ranked result. The one rule of the event pages, the result counts and the sitemap.
     */
    public static function sqlShowsOfficialResults(string $roundAlias): string
    {
        return "({$roundAlias}.results_published_at IS NOT NULL AND (" . self::sqlRankedEntriesCount($roundAlias) . ') > 0)';
    }

    /**
     * SQL expression: how many ranked official results the round behind `$roundAlias` has (published or not) - entries
     * of a solo round, pairs/teams of a pair/team round, the same rows forRound() ranks.
     */
    public static function sqlRankedEntriesCount(string $roundAlias): string
    {
        return <<<SQL
CASE WHEN {$roundAlias}.category = 'solo' THEN (
        SELECT COUNT(*)
        FROM competition_participant_round official_entry
        INNER JOIN competition_participant official_participant ON official_participant.id = official_entry.participant_id AND official_participant.deleted_at IS NULL
        WHERE official_entry.round_id = {$roundAlias}.id
            AND (official_entry.result_seconds IS NOT NULL OR official_entry.result_pieces_placed IS NOT NULL)
    ) ELSE (
        SELECT COUNT(*)
        FROM competition_team official_team
        WHERE official_team.round_id = {$roundAlias}.id
            AND (official_team.result_seconds IS NOT NULL OR official_team.result_pieces_placed IS NOT NULL)
    ) END
SQL;
    }

    /**
     * Null when the round's results are not published or nothing is ranked - the page then stays exactly as without
     * official results.
     *
     * @param null|string $division `?division=` of the page - a division's id, short label or name
     */
    public function forRound(EditionRoundDetail $round, null|string $viewerPlayerId, null|string $challengeParameter = null, null|string $division = null): null|PublishedRoundResults
    {
        if ($round->resultsPublished === false) {
            return null;
        }

        $viewerPlayerId = $viewerPlayerId !== null ? strtolower($viewerPlayerId) : null;
        $challenge = $round->challengeSlot($challengeParameter);
        // A challenge is ranked over every entry - the rows hidden from the viewer come along, marked, and go after it
        $view = $challenge !== null
            ? $this->officialEntrantsView->forRounds([$round->id => $round->category], $viewerPlayerId, keepHidden: true)[$round->id]
            : $this->officialEntrantsView->forRound($round->id, $round->category, $viewerPlayerId);

        if ($view->isEmpty()) {
            return null;
        }

        $viewer = [
            'has_entry' => $view->viewerHasEntry,
            'name' => $view->viewerName,
            'country' => $view->viewerCountry,
        ];
        $entries = $view->entries;
        $challengeRows = [];
        $linkableTimeIds = [];
        // One division (D5) - only on the round's own ranking: a challenge view (W4) ignores `?division=`
        $chosenDivision = $challenge === null ? self::chosenDivision($view->divisions, $division) : null;
        $rankedCount = $view->rankedCount;

        if ($chosenDivision !== null) {
            $entries = array_values(array_filter(
                $entries,
                static fn (PublishedRoundEntry $entry): bool => $entry->division?->id === $chosenDivision->id,
            ));
            $rankedCount = $view->rankedCountByDivision[$chosenDivision->id] ?? 0;
        }

        if ($challenge !== null) {
            $entries = array_map(
                static fn (PublishedRoundEntry $entry): PublishedRoundEntry => $entry->withPuzzleChips(PublishedEntryPuzzle::listOf($entry->puzzles, $round->puzzleSlots)),
                $entries,
            );
            $challengeRows = self::challengeRows($entries, $challenge);
            $entries = array_values(array_filter($entries, static fn (PublishedRoundEntry $entry): bool => $entry->hiddenFromViewer === false));
            $onlyPuzzle = null;
            $profilePuzzleId = null;
        } elseif ($round->isPick()) {
            // A pick round (competition toolkit A5): each entry's chosen puzzles by the public reveal rule, and
            // "Add to my profile" per puzzle - the round has no one puzzle a time is logged on
            $entries = array_map(
                static fn (PublishedRoundEntry $entry): PublishedRoundEntry => $entry->withPuzzleChips(PublishedEntryPuzzle::listOf($entry->puzzles, $round->puzzleSlots)),
                $entries,
            );
            $onlyPuzzle = null;
            $profilePuzzleId = null;

            if ($viewerPlayerId !== null) {
                $entries = $this->withPuzzleProfileStates($entries, $round, $viewerPlayerId, $viewer);
            }
        } else {
            // One puzzle in the round, and the viewer sees it - its piece count, and the puzzle a time is logged on
            $onlyPuzzle = $view->roundPuzzlesCount === 1 && count($round->puzzles) === 1 ? array_values($round->puzzles)[0] : null;
            $profilePuzzleId = $onlyPuzzle !== null && $onlyPuzzle->imageHidden === false ? $onlyPuzzle->puzzleId : null;

            if ($viewerPlayerId !== null && $profilePuzzleId !== null) {
                [$entries, $linkableTimeIds] = $this->withProfileStates($entries, $round->id, $viewerPlayerId, $viewer, $round->minutesLimit !== null);
            }
        }

        return new PublishedRoundResults(
            entries: $entries,
            piecesCount: $onlyPuzzle?->piecesCount,
            profilePuzzleId: $profilePuzzleId,
            rankedCount: $rankedCount,
            // Somebody signed in, linked to nothing here, on a round with names nobody is linked to: maybe one is theirs
            offersConnecting: $viewerPlayerId !== null
                && $viewer['has_entry'] === false
                && array_any($entries, static fn (PublishedRoundEntry $entry): bool => array_any(
                    $entry->entrants,
                    static fn (PublishedRoundEntrant $entrant): bool => $entrant->linkedPlayerId === null,
                )),
            challenge: $challenge,
            challengeRows: $challengeRows,
            linkableTimeIds: $linkableTimeIds,
            divisions: $view->divisions,
            division: $chosenDivision,
        );
    }

    /**
     * One challenge's ranking (real events W4): every entry that did that puzzle - or has no per-puzzle record to tell -
     * placed by the puzzle's own time over all of them, THEN the rows hidden from the viewer dropped without
     * renumbering. Ranked first, the unranked after, each in the round's order.
     *
     * @param list<PublishedRoundEntry> $entries with their chips, the hidden ones included
     * @return list<PublishedChallengeRow>
     */
    private static function challengeRows(array $entries, RoundPuzzleSlot $challenge): array
    {
        $roundPuzzleId = strtolower($challenge->roundPuzzleId);
        $candidates = [];

        foreach ($entries as $entry) {
            $chip = null;

            foreach ($entry->puzzleChips as $candidate) {
                if (strtolower($candidate->roundPuzzleId) === $roundPuzzleId) {
                    $chip = $candidate;
                    break;
                }
            }

            // A record without this puzzle: the entry did another one (pick K of N)
            if ($chip === null && $entry->puzzles !== null) {
                continue;
            }

            $candidates[] = ['entry' => $entry, 'chip' => $chip];
        }

        $ranks = ChallengeRanking::rank(array_map(
            static fn (array $candidate): null|int => $candidate['chip'] !== null && $candidate['chip']->done ? $candidate['chip']->duration : null,
            $candidates,
        ));

        $rows = [];

        foreach ($candidates as $index => $candidate) {
            if ($candidate['entry']->hiddenFromViewer) {
                continue;
            }

            $rows[] = new PublishedChallengeRow($candidate['entry'], $ranks[$index], $candidate['chip']);
        }

        usort($rows, static fn (PublishedChallengeRow $a, PublishedChallengeRow $b): int => ($a->rank === null) <=> ($b->rank === null)
            ?: ($a->rank ?? 0) <=> ($b->rank ?? 0)
            ?: $a->entry->rank <=> $b->entry->rank);

        return $rows;
    }

    /**
     * The round's division `?division=` names - by id, short label or name (case-insensitive); null for none, an unknown
     * one, or a round without divisions.
     *
     * @param list<RoundDivision> $divisions
     */
    private static function chosenDivision(array $divisions, null|string $value): null|RoundDivision
    {
        $value = mb_strtolower(trim((string) $value));

        if ($value === '') {
            return null;
        }

        foreach ($divisions as $division) {
            if ($division->id === $value || ($division->shortName !== null && mb_strtolower($division->shortName) === $value)) {
                return $division;
            }
        }

        foreach ($divisions as $division) {
            if (mb_strtolower($division->name) === $value) {
                return $division;
            }
        }

        return null;
    }

    /**
     * The round an official entry (a person of a solo round, a pair/team) belongs to, when it is a round of the given
     * competition - with the competition's name.
     *
     * @return null|array{round_id: string, competition_name: string}
     */
    public function roundOfEntry(string $competitionId, RoundEntryRef $ref): null|array
    {
        $table = $ref->isTeam() ? 'competition_team' : 'competition_participant_round';

        /** @var false|array{round_id: string, competition_name: string} $row */
        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT cr.id AS round_id, c.name AS competition_name
FROM {$table} entry
INNER JOIN competition_round cr ON cr.id = entry.round_id
INNER JOIN competition c ON c.id = cr.competition_id
WHERE entry.id = :entryId
    AND c.id = :competitionId
SQL,
            ['entryId' => $ref->id, 'competitionId' => $competitionId],
        );

        return $row === false ? null : $row;
    }

    /**
     * A shared puzzle (docs/features/competitions-management/shared-puzzles-and-divisions.md §2.4 row 15): the viewer has
     * no time in this round, but one on its puzzle in this event and category that no round holds yet (the rule could
     * not tell which round it belongs to) - the rows that would offer "Add to my profile" offer "This is my time - link
     * it to <round>" instead (OfficialEntryProfileState::LinkToRound): a second "Add to my profile" would save the
     * same solve twice - only on a row whose result the time has (its seconds, or the pieces placed of an unfinished
     * one); any other row keeps "Add to my profile" (never link another round's solve).
     *
     * @param list<PublishedRoundEntry> $entries
     * @param array{has_entry: bool, name: null|string, country: null|string} $viewer
     * @return array{list<PublishedRoundEntry>, array<string, string>} the entries, and per row (entry ref) the viewer's
     *     time its LinkToRound links
     */
    private function withProfileStates(array $entries, string $roundId, string $viewerPlayerId, array $viewer, bool $roundHasTimeLimit): array
    {
        // The organiser linked the viewer to an entry of the round (ranked or not): that one is theirs. Otherwise an
        // entry nobody is linked to with a person of the viewer's name may be - and a pair/team the organiser typed by
        // its name only (no member names to tell whose it is) - never everybody's unlinked row for every visitor.
        // A finished entry, or one that did not finish within the round's time limit - a round with a limit only, the
        // only one an unfinished result can be recorded against (docs/features/competitions-management/
        // unfinished-results-plan.md "Refined 2026-11-16" rule 8)
        $candidates = array_filter(
            $entries,
            static fn (PublishedRoundEntry $entry): bool => ($entry->result->isFinished() || ($roundHasTimeLimit && $entry->result->isUnfinished()))
                && self::mayBeViewers($entry, $viewer),
        );

        if ($candidates === []) {
            return [$entries, []];
        }

        $times = $this->viewerTimesInRound($roundId, $viewerPlayerId);
        $linkedTimes = array_values(array_filter($times, static fn (array $time): bool => $time['linked']));

        if ($linkedTimes === [] && $times !== []) {
            // Only a time whose result is this row's (its seconds, or the pieces placed of an unfinished one) - never
            // another round's solve; any other row keeps "Add to my profile"
            $linkable = [];

            $entries = array_map(static function (PublishedRoundEntry $entry) use ($candidates, $times, &$linkable): PublishedRoundEntry {
                if (in_array($entry, $candidates, true) === false) {
                    return $entry;
                }

                foreach ($times as $time) {
                    if (
                        ($time['seconds'] !== null && $entry->result->seconds === $time['seconds'])
                        || ($time['seconds'] === null && $time['pieces_placed'] !== null && $entry->result->seconds === null && $entry->result->piecesPlaced === $time['pieces_placed'])
                    ) {
                        $linkable[$entry->ref->toString()] = $time['time_id'];

                        return $entry->withProfileState(OfficialEntryProfileState::LinkToRound);
                    }
                }

                return $entry->withProfileState(OfficialEntryProfileState::Offer);
            }, $entries);

            return [$entries, $linkable];
        }

        $viewerTimes = array_column($linkedTimes, 'seconds');

        return [array_map(static function (PublishedRoundEntry $entry) use ($candidates, $viewerTimes): PublishedRoundEntry {
            if (in_array($entry, $candidates, true) === false) {
                return $entry;
            }

            if ($viewerTimes === []) {
                return $entry->withProfileState(OfficialEntryProfileState::Offer);
            }

            // The viewer's own entry is the one on their profile; of the entries nobody is linked to, the one whose
            // time the viewer logged - the rest offer nothing any more
            if ($entry->isViewers || in_array($entry->result->seconds, $viewerTimes, true)) {
                return $entry->withProfileState(OfficialEntryProfileState::OnProfile);
            }

            return $entry;
        }, $entries), []];
    }

    /**
     * "Add to my profile" per chosen puzzle of a `pick` round (A-round-formats.md §2.7) for the viewer's entry - or an
     * unlinked one that may be theirs (the same rule as a one-puzzle round). A puzzle offers its own time
     * (PublishedRoundEntry::puzzleProfileTime()); it says "On your profile" once the viewer has a time on that puzzle in
     * the round - on an unlinked entry only the time that entry's puzzle took.
     *
     * @param list<PublishedRoundEntry> $entries
     * @param array{has_entry: bool, name: null|string, country: null|string} $viewer
     * @return list<PublishedRoundEntry>
     */
    private function withPuzzleProfileStates(array $entries, EditionRoundDetail $round, string $viewerPlayerId, array $viewer): array
    {
        $toSolve = $round->puzzlesToSolve ?? 0;
        $roundHasTimeLimit = $round->minutesLimit !== null;

        $offers = static fn (PublishedRoundEntry $entry): bool => self::mayBeViewers($entry, $viewer) && array_any(
            $entry->puzzleChips,
            static fn (PublishedEntryPuzzle $chip): bool => $entry->puzzleProfileTime($chip, $toSolve, $roundHasTimeLimit) !== null,
        );

        if (array_any($entries, $offers) === false) {
            return $entries;
        }

        // Times in the round only - a pick round offers no link of an unlinked time (one puzzle at a time is added)
        $viewerTimes = array_values(array_filter(
            $this->viewerTimesInRound($round->id, $viewerPlayerId),
            static fn (array $time): bool => $time['linked'],
        ));

        return array_map(static function (PublishedRoundEntry $entry) use ($offers, $viewerTimes, $toSolve, $roundHasTimeLimit): PublishedRoundEntry {
            if ($offers($entry) === false) {
                return $entry;
            }

            return $entry->withPuzzleChips(array_map(static function (PublishedEntryPuzzle $chip) use ($entry, $viewerTimes, $toSolve, $roundHasTimeLimit): PublishedEntryPuzzle {
                $time = $entry->puzzleProfileTime($chip, $toSolve, $roundHasTimeLimit);

                if ($time === null) {
                    return $chip;
                }

                $onPuzzle = array_column(array_filter(
                    $viewerTimes,
                    static fn (array $viewerTime): bool => $viewerTime['puzzle_id'] === $chip->profilePuzzleId(),
                ), 'seconds');

                if ($onPuzzle === []) {
                    return $chip->withProfileState(OfficialEntryProfileState::Offer);
                }

                if ($entry->isViewers || in_array($time['seconds'], $onPuzzle, true)) {
                    return $chip->withProfileState(OfficialEntryProfileState::OnProfile);
                }

                return $chip;
            }, $entry->puzzleChips));
        }, $entries);
    }

    /**
     * The organiser linked the viewer to an entry of the round (ranked or not): that one is theirs. Otherwise an entry
     * nobody is linked to with a person of the viewer's name may be - and a pair/team the organiser typed by its name
     * only (no member names to tell whose it is) - never everybody's unlinked row for every visitor.
     *
     * @param array{has_entry: bool, name: null|string, country: null|string} $viewer
     */
    private static function mayBeViewers(PublishedRoundEntry $entry, array $viewer): bool
    {
        return $entry->isViewers || (
            $viewer['has_entry'] === false
            && $entry->hasLinkedPlayer() === false
            && (($entry->isTeam && $entry->entrants === []) || self::hasViewersName($entry, $viewer))
        );
    }

    /**
     * Every time the viewer has in the round - their own or one they took part in - with its puzzle, and (`linked`
     * false) every one of theirs on a puzzle of the round in its event and category that no round holds (a puzzle
     * several rounds share whose round the rule could not tell - shared-puzzles-and-divisions.md §2.4 row 15), latest
     * first. A time without seconds (unfinished) counts as one too, so seconds may be null.
     *
     * @return list<array{time_id: string, puzzle_id: string, seconds: null|int, pieces_placed: null|int, linked: bool}>
     */
    private function viewerTimesInRound(string $roundId, string $viewerPlayerId): array
    {
        /** @var list<array{id: string, puzzle_id: string, seconds_to_solve: null|int|string, pieces_placed: null|int|string, linked: bool}> $rows */
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT pst.id, pst.puzzle_id, pst.seconds_to_solve, pst.pieces_placed, pst.competition_round_id IS NOT NULL AS linked
FROM puzzle_solving_time pst
WHERE (
        pst.competition_round_id = :roundId
        OR (
            pst.competition_round_id IS NULL
            AND pst.competition_id = (SELECT viewer_round.competition_id FROM competition_round viewer_round WHERE viewer_round.id = :roundId)
            AND pst.puzzling_type = (SELECT viewer_round.category FROM competition_round viewer_round WHERE viewer_round.id = :roundId)
            AND pst.puzzle_id IN (SELECT viewer_round_puzzle.puzzle_id FROM competition_round_puzzle viewer_round_puzzle WHERE viewer_round_puzzle.round_id = :roundId)
        )
    )
    AND (
        pst.player_id = :viewerId
        OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:viewerId AS UUID)))
    )
ORDER BY pst.tracked_at DESC, pst.id DESC
SQL,
            ['roundId' => $roundId, 'viewerId' => $viewerPlayerId],
        );

        return array_map(static fn (array $row): array => [
            'time_id' => strtolower($row['id']),
            'puzzle_id' => strtolower($row['puzzle_id']),
            'seconds' => $row['seconds_to_solve'] !== null ? (int) $row['seconds_to_solve'] : null,
            'pieces_placed' => $row['pieces_placed'] !== null ? (int) $row['pieces_placed'] : null,
            'linked' => $row['linked'],
        ], $rows);
    }

    /**
     * A person of an unlinked row - the one of a solo row, any member of a pair/team - has the viewer's name, spelled
     * the way the participant import compares names (ParticipantNameKey), and no other country.
     *
     * @param array{has_entry: bool, name: null|string, country: null|string} $viewer
     */
    private static function hasViewersName(PublishedRoundEntry $entry, array $viewer): bool
    {
        if ($viewer['name'] === null || trim($viewer['name']) === '') {
            return false;
        }

        $viewerKey = ParticipantNameKey::of($viewer['name']);
        $viewerCountry = CountryCode::fromCode($viewer['country']);

        return array_any(
            $entry->entrants,
            static fn (PublishedRoundEntrant $entrant): bool => ParticipantNameKey::of($entrant->playerName) === $viewerKey
                && ($entrant->playerCountry === null || $viewerCountry === null || $entrant->playerCountry === $viewerCountry),
        );
    }
}
