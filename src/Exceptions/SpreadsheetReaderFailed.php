<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * SpreadsheetReader could not do its work for a reason that is the server's, not the file's: its temporary folder can
 * not be created or written (a full disk), a stream filter can not be set up. Never shown to a player as "your file
 * is damaged" - it is ours to see and fix.
 */
final class SpreadsheetReaderFailed extends \RuntimeException
{
}
