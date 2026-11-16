<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventResultsImport;

/**
 * What can be wrong with one tab of an organizer's results sheet (docs/features/event-results-import/README.md
 * "Tab outcomes"). A tab-level problem keeps the whole tab out - half a tab never shows half its results; a row-level
 * one skips that row only.
 */
enum EventTabProblemCode: string
{
    case MissingColumn = 'missing_column';
    case MissingEventField = 'missing_event_field';
    case UnreadableDate = 'unreadable_date';
    case UnreadableStartTime = 'unreadable_start_time';
    case UnreadableTimeLimit = 'unreadable_time_limit';
    case UnknownCompetitionType = 'unknown_competition_type';
    case UnreadablePuzzleLink = 'unreadable_puzzle_link';
    case NoTeams = 'no_teams';
    // Unfinished teams (pieces placed) need the round's time limit - their time spent, and what they did not finish
    // within (docs/features/event-results-import/README.md "Unfinished results (D9)")
    case UnfinishedWithoutTimeLimit = 'unfinished_without_time_limit';
    case UnreadablePiecesPlaced = 'unreadable_pieces_placed';
    // Found while applying, not while reading
    case PuzzleNotFound = 'puzzle_not_found';
    case PuzzleChangedAfterImport = 'puzzle_changed_after_import';
    case CompetitionTypeChangedAfterImport = 'competition_type_changed_after_import';
    // Pieces placed must be fewer than the puzzle has - all of them is a finished team with a time
    case PiecesPlacedOutOfRange = 'pieces_placed_out_of_range';

    case RowWithoutTeamName = 'row_without_team_name';
    case TeamNameTooLong = 'team_name_too_long';
    case TeamNamedTwice = 'team_named_twice';
    case UnreadableTeamSize = 'unreadable_team_size';
    case TeamSizeDoesNotFitCategory = 'team_size_does_not_fit_category';
    case UnreadableTime = 'unreadable_time';
    case RowWithoutResult = 'row_without_result';
    case TimeAndPiecesPlaced = 'time_and_pieces_placed';
    // A team somebody claimed: the sheet no longer has it, or gives it another size - a person decides, never the import
    case ClaimedTeamRemovedFromSheet = 'claimed_team_removed_from_sheet';
    case ClaimedTeamChangedSize = 'claimed_team_changed_size';

    public function keepsTheWholeTabOut(): bool
    {
        return match ($this) {
            self::MissingColumn,
            self::MissingEventField,
            self::UnreadableDate,
            self::UnreadableStartTime,
            self::UnreadableTimeLimit,
            self::UnknownCompetitionType,
            self::UnreadablePuzzleLink,
            self::NoTeams,
            self::UnfinishedWithoutTimeLimit,
            self::UnreadablePiecesPlaced,
            self::PiecesPlacedOutOfRange,
            self::PuzzleNotFound,
            self::PuzzleChangedAfterImport,
            self::CompetitionTypeChangedAfterImport => true,
            self::RowWithoutTeamName,
            self::TeamNameTooLong,
            self::TeamNamedTwice,
            self::UnreadableTeamSize,
            self::TeamSizeDoesNotFitCategory,
            self::UnreadableTime,
            self::RowWithoutResult,
            self::TimeAndPiecesPlaced,
            self::ClaimedTeamRemovedFromSheet,
            self::ClaimedTeamChangedSize => false,
        };
    }
}
