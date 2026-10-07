<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Removes the stored pictures a section no longer shows (a deleted section, a removed gallery photo or sponsor logo).
 *
 * Dispatched with DispatchAfterCurrentBusStamp and routed async: it only goes out once the change has committed, so a
 * rolled-back edit never leaves a section pointing at a deleted file. The handler deletes nothing still referenced.
 */
readonly final class DeletePageSectionImages
{
    /**
     * @param list<string> $paths
     */
    public function __construct(
        public array $paths,
    ) {
    }
}
