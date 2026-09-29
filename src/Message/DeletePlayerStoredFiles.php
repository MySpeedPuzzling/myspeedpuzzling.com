<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Removes a deleted player's files from object storage: the listed paths
 * (avatar, photos of the solving times removed with the account - captured by
 * DeletePlayerHandler while the rows still existed) plus every object left
 * under `players/<id>/` that no row references any more (result share images,
 * photos replaced by an earlier edit).
 *
 * Dispatched with DispatchAfterCurrentBusStamp and routed async: it only goes
 * out once the deletion has committed, so a rolled-back deletion never loses a
 * file. The handler refuses to touch anything while the player row still exists.
 */
readonly final class DeletePlayerStoredFiles
{
    /**
     * @param list<string> $paths
     */
    public function __construct(
        public string $playerId,
        public array $paths,
    ) {
    }
}
