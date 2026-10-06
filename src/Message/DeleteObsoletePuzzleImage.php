<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Deletes a puzzle's old picture object after it was copied to a random name (a puzzle that became secret on the whole
 * site keeps no guessable file name - SecretPuzzleHides).
 *
 * Dispatched with DispatchAfterCurrentBusStamp and routed async: it only goes out once the rename has committed, so a
 * rolled-back change never leaves a puzzle pointing at a deleted file. The handler deletes nothing still referenced.
 */
readonly final class DeleteObsoletePuzzleImage
{
    public function __construct(
        public string $puzzleId,
        public string $path,
    ) {
    }
}
