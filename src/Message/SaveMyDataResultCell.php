<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;
use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * One cell of a result edited in place in "My data" (docs/features/my-data.md "Editing in place"): ONE field, with
 * what the cell showed when its editor opened (`from`). Answers a MyDataCellSave.
 *
 * **The compare is part of the write.** A result is saved as a whole row (`EditPuzzleSolvingTime` takes every field),
 * so two cells of one result saved at the same moment would each send the other's field as they read it - and the
 * later one would put the earlier one's change back. Serialized per result: under the lock the handler reads the
 * result again, compares `from`, and only then sends the edit - handled at once, in a transaction of its own, committed
 * before the lock is released (that is why this message itself runs outside a transaction: the lock must outlive the
 * edit's commit, and a refused edit must not take a transaction around it down with it).
 *
 * `EditPuzzleSolvingTime` has no lock of its own, so a save of the full edit form at that very moment is not held
 * back by this - it writes the whole form, as it always did.
 */
readonly final class SaveMyDataResultCell implements SerializedByLock, HandledOutsideTransaction
{
    public function __construct(
        // The signed-in player (dispatched from their own request only - never routed, never queued)
        public PlayerProfile $viewer,
        public string $solvingTimeId,
        // MyDataResultEdit::TIME / DATE / FIRST_TRY / COMMENT
        public string $field,
        public string $from,
        public string $value,
    ) {
    }

    public function lockKey(): string
    {
        return 'my-data-result-' . strtolower($this->solvingTimeId);
    }
}
