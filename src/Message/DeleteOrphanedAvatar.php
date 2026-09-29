<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * One-off cleanup (myspeedpuzzling:storage:delete-orphaned-avatars --delete):
 * deletes one avatar object no player references. The handler re-checks that
 * right before deleting.
 */
readonly final class DeleteOrphanedAvatar
{
    public function __construct(
        public string $path,
    ) {
    }
}
