<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Removes uploaded display pictures (competition toolkit D7) no design shows any more: pictures dropped from a saved
 * design, the pictures of a deleted display, of a deleted competition's or series' displays.
 *
 * Dispatched with DispatchAfterCurrentBusStamp and routed async: it only goes out once the change has committed, so a
 * rolled-back save never leaves a display pointing at a deleted file. The handler deletes nothing still referenced (a
 * duplicated display shares its source's files) and nothing outside `competition-displays/`.
 */
readonly final class DeleteCompetitionDisplayImages
{
    /**
     * @param list<string> $paths
     */
    public function __construct(
        public array $paths,
    ) {
    }
}
