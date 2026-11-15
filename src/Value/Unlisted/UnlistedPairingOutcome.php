<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Unlisted;

/**
 * What "It is this catalogue puzzle" - or its Undo - answered (PairUnlistedPuzzleHandler, UndoUnlistedPairingHandler).
 * Never an exception: every one of these is something the page says.
 */
readonly final class UnlistedPairingOutcome
{
    public const string STARTED = 'started';
    /** The same request id as before - that pairing answers, nothing new was started */
    public const string SAME_REQUEST = 'same_request';
    /** Adding is paused by an admin (the import's safety switch) - pairing makes results public, so it waits too */
    public const string PAUSED = 'paused';
    /** The catalogue puzzle may not be chosen (hidden, secret, waiting for approval, gone) */
    public const string NOT_AVAILABLE = 'not_available';
    /** Results of this unlisted puzzle already became results of another catalogue puzzle - take that back first */
    public const string OTHER_PUZZLE = 'other_puzzle';
    /** A pairing or an Undo of this unlisted puzzle is still at work */
    public const string ALREADY_RUNNING = 'already_running';
    public const string RATE_LIMITED = 'rate_limited';
    /** Nothing unlisted is left on the puzzle / nothing can be taken back any more */
    public const string NOTHING = 'nothing';

    public function __construct(
        public string $status,
        public null|string $pairingId = null,
    ) {
    }

    public function isStarted(): bool
    {
        return $this->status === self::STARTED || $this->status === self::SAME_REQUEST;
    }
}
