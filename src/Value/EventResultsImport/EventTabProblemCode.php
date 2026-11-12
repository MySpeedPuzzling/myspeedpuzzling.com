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

    case RowWithoutTeamName = 'row_without_team_name';
    case TeamNameTooLong = 'team_name_too_long';
    case TeamNamedTwice = 'team_named_twice';
    case UnreadableTeamSize = 'unreadable_team_size';
    case TeamSizeDoesNotFitCategory = 'team_size_does_not_fit_category';
    case UnreadableTime = 'unreadable_time';
    case RowWithoutResult = 'row_without_result';
    case TimeAndPiecesPlaced = 'time_and_pieces_placed';
    case UnfinishedNotSupportedYet = 'unfinished_not_supported_yet';

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
            self::NoTeams => true,
            self::RowWithoutTeamName,
            self::TeamNameTooLong,
            self::TeamNamedTwice,
            self::UnreadableTeamSize,
            self::TeamSizeDoesNotFitCategory,
            self::UnreadableTime,
            self::RowWithoutResult,
            self::TimeAndPiecesPlaced,
            self::UnfinishedNotSupportedYet => false,
        };
    }
}
