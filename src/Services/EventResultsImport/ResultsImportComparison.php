<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventResultsImport;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Entity\ResultsImportCheck;
use SpeedPuzzling\Web\Exceptions\InvalidPiecesPlaced;
use SpeedPuzzling\Web\Exceptions\UnfinishedResultNeedsTimeLimit;
use SpeedPuzzling\Web\Exceptions\UnfinishedTotalTimeWithinLimit;
use SpeedPuzzling\Web\Query\GetResultsImportPuzzles;
use SpeedPuzzling\Web\Query\GetResultsImportStoredTabs;
use SpeedPuzzling\Web\Results\ResultsImport\EventFieldChange;
use SpeedPuzzling\Web\Results\ResultsImport\PreviewResultRow;
use SpeedPuzzling\Web\Results\ResultsImport\ResultsImportEvent;
use SpeedPuzzling\Web\Results\ResultsImport\ResultsImportNote;
use SpeedPuzzling\Web\Results\ResultsImport\ResultsImportOverview;
use SpeedPuzzling\Web\Results\ResultsImport\ResultsImportPuzzle;
use SpeedPuzzling\Web\Results\ResultsImport\StoredResultsTab;
use SpeedPuzzling\Web\Results\ResultsImport\TeamChange;
use SpeedPuzzling\Web\Services\EventResultsImport\Format\ResultsFormats;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabProblemCode;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabStatus;
use SpeedPuzzling\Web\Value\EventResultsImport\ParsedEvent;
use SpeedPuzzling\Web\Value\EventResultsImport\ResultsImportStatus;
use SpeedPuzzling\Web\Value\RoundResultStatus;
use SpeedPuzzling\Web\Value\UnfinishedResult;

/**
 * A check of a results source compared with what is stored (docs/features/event-results-import/results-import-page.md
 * "Three layers", 3.): the check's sheet parsed through the source's format - exactly as an import from it would parse
 * it - and every event's status decided in the order ApplyEventResultsTabHandler decides (ResultsImportComparisonTest
 * pins the two together): nothing changed since it was imported, its event deleted, a problem of the sheet, no puzzle,
 * pieces placed the puzzle does not have, a round or puzzle changed on MySpeedPuzzling since. Three statements for any
 * number of events, nothing written.
 */
readonly final class ResultsImportComparison
{
    public function __construct(
        private ResultsFormats $formats,
        private GetResultsImportStoredTabs $getStoredTabs,
        private GetResultsImportPuzzles $getPuzzles,
    ) {
    }

    public function compare(EventResultsSheet $source, ResultsImportCheck $check): ResultsImportOverview
    {
        $checkedBy = $check->checkedBy !== null ? ($check->checkedBy->name ?? '#' . $check->checkedBy->code) : null;
        $snapshot = $check->sheetSnapshot();

        if ($snapshot === null) {
            return new ResultsImportOverview($check->id->toString(), $check->checkedAt, $checkedBy, $check->readError ?? '', [], []);
        }

        $events = $this->formats->get($source->format)->parse($snapshot);
        $stored = $this->getStoredTabs->ofSource($source->id->toString());
        $puzzles = $this->getPuzzles->byLinkedIds(array_values(array_filter(array_map(
            static fn (ParsedEvent $event): null|string => $event->puzzleId,
            $events,
        ))));

        return new ResultsImportOverview(
            checkId: $check->id->toString(),
            checkedAt: $check->checkedAt,
            checkedByName: $checkedBy,
            readError: null,
            events: array_map(
                fn (ParsedEvent $event): ResultsImportEvent => $this->event(
                    $source,
                    $event,
                    $stored[$event->key] ?? null,
                    $event->puzzleId !== null ? ($puzzles[strtolower($event->puzzleId)] ?? null) : null,
                ),
                $events,
            ),
            unreadableTabs: $snapshot->unreadableTabs,
        );
    }

    /**
     * The event's teams ranked as the round page ranks the organizer's results (GetRoundResults::order()): finished
     * within the limit by time (equal times share a place, gaps to the fastest and the one above), then the unfinished
     * by pieces placed, then times over the limit - each group in the sheet's order on a tie. A claimed team gone from
     * the sheet stays on the round page (P4), so it is ranked here too, marked kept.
     *
     * @param list<TeamChange> $teamChanges the event's differences - its KEPT_CLAIMED teams are ranked with the sheet's
     * @return list<PreviewResultRow>
     */
    public static function rankedRows(ParsedEvent $event, array $teamChanges = []): array
    {
        $limitSeconds = $event->timeLimitMinutes === null ? PHP_INT_MAX : $event->timeLimitMinutes * 60;
        $statusOrder = [RoundResultStatus::Finished->value => 0, RoundResultStatus::Unfinished->value => 1, RoundResultStatus::OverLimit->value => 2];

        /** @var list<array{name: string, size: int, seconds: null|int, pieces: null|int, line: int, kept: bool}> $teams */
        $teams = [];

        // Imported before every row of the sheet - first on a tie, as on the round page (earliest first)
        foreach ($teamChanges as $change) {
            if ($change->kind === TeamChange::KEPT_CLAIMED && ($change->secondsBefore !== null || $change->piecesBefore !== null)) {
                $teams[] = ['name' => $change->teamName, 'size' => $change->sizeBefore ?? 0, 'seconds' => $change->secondsBefore, 'pieces' => $change->piecesBefore, 'line' => 0, 'kept' => true];
            }
        }

        foreach ($event->rows as $row) {
            $teams[] = ['name' => $row->teamName, 'size' => $row->teamSize, 'seconds' => $row->seconds, 'pieces' => $row->piecesPlaced, 'line' => $row->line, 'kept' => false];
        }

        $candidates = array_map(static fn (array $team): array => [
            'team' => $team,
            'status' => match (true) {
                $team['pieces'] !== null => RoundResultStatus::Unfinished,
                $team['seconds'] !== null && $team['seconds'] <= $limitSeconds => RoundResultStatus::Finished,
                default => RoundResultStatus::OverLimit,
            },
        ], $teams);

        usort($candidates, static function (array $a, array $b) use ($statusOrder): int {
            $byStatus = $statusOrder[$a['status']->value] <=> $statusOrder[$b['status']->value];

            if ($byStatus !== 0) {
                return $byStatus;
            }

            if ($a['status'] === RoundResultStatus::Unfinished) {
                return ($b['team']['pieces'] ?? 0) <=> ($a['team']['pieces'] ?? 0);
            }

            return ($a['team']['seconds'] ?? 0) <=> ($b['team']['seconds'] ?? 0);
        });

        $rows = [];
        $rank = 0;
        $ranked = 0;
        $previousSeconds = null;
        $leaderSeconds = null;

        foreach ($candidates as ['team' => $team, 'status' => $status]) {
            $resultRank = null;
            $gapToLeader = null;
            $gapToFaster = null;

            if ($status === RoundResultStatus::Finished) {
                $ranked++;
                $seconds = (int) $team['seconds'];

                if ($leaderSeconds !== null && $previousSeconds !== null) {
                    $gapToFaster = $seconds - $previousSeconds;
                    $gapToLeader = $ranked > 2 ? $seconds - $leaderSeconds : null;
                }

                if ($seconds !== $previousSeconds) {
                    $rank = $ranked;
                }

                $previousSeconds = $seconds;
                $leaderSeconds ??= $seconds;
                $resultRank = $rank;
            }

            $rows[] = new PreviewResultRow(
                teamName: $team['name'],
                teamSize: $team['size'],
                status: $status,
                seconds: $team['seconds'],
                piecesPlaced: $team['pieces'],
                rank: $resultRank,
                gapToLeader: $gapToLeader,
                gapToFaster: $gapToFaster,
                line: $team['line'],
                keptClaimed: $team['kept'],
            );
        }

        return $rows;
    }

    private function event(EventResultsSheet $source, ParsedEvent $event, null|StoredResultsTab $stored, null|ResultsImportPuzzle $puzzle): ResultsImportEvent
    {
        $blockers = [];
        $warnings = [];

        foreach ($event->problems as $problem) {
            if ($problem->code->keepsTheWholeTabOut()) {
                $blockers[] = ResultsImportNote::fromProblem($problem);
            } else {
                // A row the import skips - the rest of the event is imported
                $warnings[] = ResultsImportNote::fromProblem($problem);
            }
        }

        $status = $this->status($event, $stored, $puzzle, $blockers);

        if ($puzzle !== null && $puzzle->approved === false) {
            $warnings[] = new ResultsImportNote(ResultsImportNote::PUZZLE_NOT_APPROVED, $puzzle->name);
        }

        array_push($warnings, ...self::timeWarnings($event));

        $teamChanges = [];
        $eventChanges = [];

        // What is stored of an imported event and what the sheet says now - also of an unchanged one: a claimed team
        // that left the sheet is still kept
        if ($stored !== null && $stored->isImported() && $status !== ResultsImportStatus::EventDeleted) {
            $teamChanges = self::teamChanges($event, $stored);

            if ($status !== ResultsImportStatus::Unchanged) {
                $eventChanges = self::eventChanges($source, $event, $stored);
            } else {
                // Unchanged: the import does nothing - only what it keeps because it is claimed stays to be said
                $teamChanges = array_values(array_filter($teamChanges, static fn (TeamChange $change): bool => $change->kind === TeamChange::KEPT_CLAIMED || $change->sizeKept()));
            }
        }

        return new ResultsImportEvent($event, $status, $stored, $puzzle, $blockers, $warnings, $eventChanges, $teamChanges);
    }

    /**
     * ApplyEventResultsTabHandler's order of decisions - change both together.
     *
     * @param list<ResultsImportNote> $blockers
     */
    private function status(ParsedEvent $event, null|StoredResultsTab $stored, null|ResultsImportPuzzle $puzzle, array &$blockers): ResultsImportStatus
    {
        if ($stored !== null && $stored->status === EventTabStatus::Imported && $stored->isImported() && $stored->contentHash === $event->contentHash) {
            return ResultsImportStatus::Unchanged;
        }

        if ($stored?->isDetached === true) {
            return ResultsImportStatus::EventDeleted;
        }

        if ($event->hasTabProblem()) {
            return ResultsImportStatus::NeedsFixing;
        }

        if ($event->puzzleId === null) {
            return ResultsImportStatus::WaitingForPuzzle;
        }

        if ($puzzle === null) {
            $blockers[] = new ResultsImportNote(EventTabProblemCode::PuzzleNotFound->value, $event->puzzleId);

            return ResultsImportStatus::WaitingForPuzzle;
        }

        $fits = true;

        foreach ($event->rows as $row) {
            if ($row->piecesPlaced === null) {
                continue;
            }

            try {
                UnfinishedResult::fromInput($row->piecesPlaced, null, $puzzle->piecesCount, $event->timeLimitMinutes);
            } catch (InvalidPiecesPlaced) {
                $blockers[] = new ResultsImportNote(EventTabProblemCode::PiecesPlacedOutOfRange->value, sprintf('%s: %d / %d', $row->teamName, $row->piecesPlaced, $puzzle->piecesCount), $row->line);
                $fits = false;
            } catch (UnfinishedResultNeedsTimeLimit | UnfinishedTotalTimeWithinLimit) {
                $blockers[] = new ResultsImportNote(EventTabProblemCode::UnfinishedWithoutTimeLimit->value, $row->teamName, $row->line);
                $fits = false;
            }
        }

        if ($fits === false) {
            return ResultsImportStatus::NeedsFixing;
        }

        if ($stored === null || $stored->isImported() === false) {
            return ResultsImportStatus::New;
        }

        if ($stored->roundId === null || $stored->roundCategory !== $event->category) {
            $blockers[] = new ResultsImportNote(EventTabProblemCode::CompetitionTypeChangedAfterImport->value, $event->category?->value);

            return ResultsImportStatus::NeedsFixing;
        }

        if ($stored->roundPuzzleIds !== [$puzzle->id]) {
            $blockers[] = new ResultsImportNote(EventTabProblemCode::PuzzleChangedAfterImport->value, $puzzle->name);

            return ResultsImportStatus::NeedsFixing;
        }

        return ResultsImportStatus::Changed;
    }

    /**
     * Soft warnings about the teams' times: rows out of time order (perhaps a typo), a time over the limit without
     * pieces placed (ranked last, after the unfinished teams).
     *
     * @return list<ResultsImportNote>
     */
    private static function timeWarnings(ParsedEvent $event): array
    {
        $warnings = [];
        $previous = null;

        foreach ($event->rows as $row) {
            if ($row->seconds === null) {
                continue;
            }

            if ($previous !== null && $row->seconds < $previous) {
                $warnings[] = new ResultsImportNote(ResultsImportNote::ROWS_OUT_OF_TIME_ORDER, $row->teamName, $row->line);

                break;
            }

            $previous = $row->seconds;
        }

        if ($event->timeLimitMinutes !== null) {
            foreach ($event->rows as $row) {
                if ($row->seconds !== null && $row->seconds > $event->timeLimitMinutes * 60) {
                    $warnings[] = new ResultsImportNote(ResultsImportNote::OVER_TIME_LIMIT, $row->teamName, $row->line);
                }
            }
        }

        return $warnings;
    }

    /**
     * What ApplyEventResultsTabHandler::upsertResults() does to every team: adds the new ones, follows the sheet for the
     * others (a claimed team keeps its people), removes the ones gone from the sheet - unless somebody claimed them (P4).
     *
     * @return list<TeamChange>
     */
    private static function teamChanges(ParsedEvent $event, StoredResultsTab $stored): array
    {
        $existing = $stored->results;
        $changes = [];

        foreach ($event->rows as $row) {
            $before = $existing[$row->teamKey] ?? null;
            unset($existing[$row->teamKey]);

            if ($before === null) {
                $changes[] = new TeamChange(
                    kind: TeamChange::ADDED,
                    teamName: $row->teamName,
                    claimed: false,
                    secondsAfter: $row->seconds,
                    piecesAfter: $row->piecesPlaced,
                    sizeAfter: $row->teamSize,
                );

                continue;
            }

            $change = new TeamChange(
                kind: TeamChange::CHANGED,
                teamName: $row->teamName,
                claimed: $before->claimed,
                secondsBefore: $before->seconds,
                piecesBefore: $before->piecesPlaced,
                sizeBefore: $before->teamSize,
                secondsAfter: $row->seconds,
                piecesAfter: $row->piecesPlaced,
                sizeAfter: $row->teamSize,
                nameBefore: $before->teamName !== null && $before->teamName !== $row->teamName && $before->claimed === false ? $before->teamName : null,
            );

            if ($change->resultChanged() || $change->sizeChanged() || $change->nameBefore !== null) {
                $changes[] = $change;
            }
        }

        foreach ($existing as $gone) {
            $changes[] = new TeamChange(
                kind: $gone->claimed ? TeamChange::KEPT_CLAIMED : TeamChange::REMOVED,
                teamName: $gone->teamName ?? $gone->teamKey,
                claimed: $gone->claimed,
                secondsBefore: $gone->seconds,
                piecesBefore: $gone->piecesPlaced,
                sizeBefore: $gone->teamSize,
            );
        }

        return $changes;
    }

    /**
     * The event's own fields the import would change (ApplyEventResultsTabHandler::updateEvent()).
     *
     * @return list<EventFieldChange>
     */
    private static function eventChanges(EventResultsSheet $source, ParsedEvent $event, StoredResultsTab $stored): array
    {
        $changes = [];

        if ($event->eventName !== null && $stored->competitionName !== $event->eventName) {
            $changes[] = new EventFieldChange(EventFieldChange::NAME, $stored->competitionName, $event->eventName);
        }

        if ($event->location !== null && $event->cityAndState !== null) {
            $location = $event->location . ', ' . $event->cityAndState;

            if ($stored->location !== $location) {
                $changes[] = new EventFieldChange(EventFieldChange::LOCATION, $stored->location, $location);
            }
        }

        if ($event->localStartsAt !== null) {
            $day = new DateTimeImmutable($event->localStartsAt->format('Y-m-d'));

            if ($stored->dateFrom?->format('Y-m-d') !== $day->format('Y-m-d')) {
                $changes[] = new EventFieldChange(EventFieldChange::DATE, $stored->dateFrom, $day);
            }

            $storedStart = null;

            if ($stored->roundStartsAt !== null && $stored->startTimeKnown) {
                $storedStart = $stored->roundStartsAt->setTimezone(new DateTimeZone($stored->roundTimezone ?? $source->timezone))->format('H:i');
            }

            $start = $event->startTimeKnown ? $event->localStartsAt->format('H:i') : null;

            if ($storedStart !== $start) {
                $changes[] = new EventFieldChange(EventFieldChange::START_TIME, $storedStart, $start);
            }
        }

        if ($stored->minutesLimit !== $event->timeLimitMinutes) {
            $changes[] = new EventFieldChange(EventFieldChange::TIME_LIMIT, $stored->minutesLimit, $event->timeLimitMinutes);
        }

        return $changes;
    }
}
