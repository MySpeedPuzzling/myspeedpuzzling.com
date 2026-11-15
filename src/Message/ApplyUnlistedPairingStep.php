<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * One step of a pairing of an unlisted puzzle, in its own transaction (UnlistedPairer): one unlisted result or one
 * owned / wish mark becomes a real one - or, for an Undo, is taken back -, a step that threw is marked, or the
 * pairing is finished. Not routed - handled at once by ProcessUnlistedPairing.
 *
 * A fresh entity manager for every step: it must read its subject as the database has it now.
 */
readonly final class ApplyUnlistedPairingStep implements RequiresFreshEntityManagerState
{
    public const string RESULT = 'result';
    public const string OWNERSHIP = 'ownership';
    public const string FINISH = 'finish';

    /**
     * @param string $step RESULT / OWNERSHIP (which way is the pairing's own state: running or being undone), FINISH
     * @param bool $failed the step threw: its subject is marked and left alone
     */
    public function __construct(
        public string $pairingId,
        public string $step,
        public null|string $subjectId = null,
        public bool $failed = false,
    ) {
    }
}
