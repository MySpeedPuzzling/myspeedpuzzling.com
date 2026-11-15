<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * One turn of a group answer (DataImportGroupAnswer) - on the `imports` queue, or at once for a small group: the
 * next chunk of its rows takes the answer, each step a message with a transaction of its own; then the job sends
 * itself again, or ends the answer - with an "Add" of exactly the rows that took "add it anyway". Driven by the
 * answer's cursor: delivered twice or started again it repeats nothing.
 */
readonly final class ProcessImportGroupAnswer implements HandledOutsideTransaction
{
    public function __construct(
        public string $answerId,
    ) {
    }
}
