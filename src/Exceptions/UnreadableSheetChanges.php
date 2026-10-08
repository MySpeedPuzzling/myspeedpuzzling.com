<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * A participants sheet change set (SheetChangesParser) the server cannot answer group by group - not a list, too
 * many groups or changes, an unknown op, a missing field, an id that is no UUID, no change set id, a group id used
 * twice. `reason` is the key of the organiser's text, `participants_sheet_server.invalid.<reason>` - the exception's own
 * message is for developers only and never shown.
 */
final class UnreadableSheetChanges extends \InvalidArgumentException
{
    public const string NOT_A_LIST = 'not_a_list';
    public const string TOO_MANY_GROUPS = 'too_many_groups';
    public const string TOO_MANY_CHANGES = 'too_many_changes';
    public const string UNKNOWN_OP = 'unknown_op';
    public const string MISSING_FIELD = 'missing_field';
    public const string INVALID_ID = 'invalid_id';
    public const string CHANGESET_ID_MISSING = 'changeset_id_missing';
    public const string DUPLICATE_GROUP_ID = 'duplicate_group_id';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
