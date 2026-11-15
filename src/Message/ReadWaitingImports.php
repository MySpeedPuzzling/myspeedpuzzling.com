<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * The reading's safety net (`myspeedpuzzling:read-waiting-imports`, docs/features/data-import/reading.md): imports
 * that rest in `uploaded` and nobody started to read are queued again; imports "being read" without progress for a
 * quarter of an hour - their worker died - are failed, so the player can try again.
 *
 * Outside a transaction: each import is written by a message of its own, and its page is told after that commit.
 */
readonly final class ReadWaitingImports implements HandledOutsideTransaction
{
}
