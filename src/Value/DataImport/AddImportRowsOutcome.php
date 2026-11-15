<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * What an "Add" of an import answered (AddImportRowsHandler) - or an Undo (UndoImportRowsHandler): started with so
 * many rows, the same request as before, or why nothing was started. Never an exception: every one of these is
 * something the screen says.
 */
readonly final class AddImportRowsOutcome
{
    public const string STARTED = 'started';
    /** The same request id as an earlier "Add" - that batch answers, nothing new was queued */
    public const string SAME_REQUEST = 'same_request';
    /** Adding is paused by an admin (the safety switch) */
    public const string PAUSED = 'paused';
    /** The import is not at a stage rows can be added from (still being read, discarded, its content wiped) */
    public const string NOT_ADDABLE = 'not_addable';
    /** An "Add" of the player is still running - one at a time */
    public const string ALREADY_RUNNING = 'already_running';
    public const string RATE_LIMITED = 'rate_limited';
    /** Nothing of the selection can be added (or taken back) now - `skipped` says why */
    public const string NOTHING = 'nothing';

    /**
     * @param array<string, int> $skipped rows of the selection that were not taken, by reason: `unlisted_not_yet`,
     *        `needs_exact_day`, `over_daily_limit`
     */
    public function __construct(
        public string $status,
        /** The batch - or the undo - that was started or that answers the same request */
        public null|string $id = null,
        public int $rows = 0,
        public array $skipped = [],
    ) {
    }

    public function isStarted(): bool
    {
        return $this->status === self::STARTED || $this->status === self::SAME_REQUEST;
    }
}
