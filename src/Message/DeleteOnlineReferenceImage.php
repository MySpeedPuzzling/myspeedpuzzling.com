<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Deletes an online round's replaced or removed reference image (OnlineReferenceImages).
 *
 * Dispatched with DispatchAfterCurrentBusStamp and routed async: it only goes out once the change has committed, so a
 * rolled-back change never leaves a round pointing at a deleted file. The handler deletes nothing a round still holds.
 */
readonly final class DeleteOnlineReferenceImage
{
    public function __construct(
        public string $objectKey,
    ) {
    }
}
