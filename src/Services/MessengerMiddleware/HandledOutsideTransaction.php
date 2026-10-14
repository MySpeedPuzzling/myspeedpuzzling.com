<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\MessengerMiddleware;

/**
 * Messages whose handler waits for a slow external service (an HTTP call of seconds, up to a minute) and writes
 * nothing itself: TransactionMiddleware opens no transaction around them. A transaction held open across such a call
 * sits idle in Postgres for that long, holding the locks of every table the handler read - a deploy's ALTER TABLE
 * waits behind it, and every query behind that.
 *
 * The handler reads, calls, and dispatches a message of its own for the write - not routed, so it is handled at once,
 * in a transaction of its own (CheckOnlineFinishPhoto → RecordOnlineMediaCheck). It changes nothing on an entity it
 * loaded: no transaction of its own would flush it.
 */
interface HandledOutsideTransaction
{
}
