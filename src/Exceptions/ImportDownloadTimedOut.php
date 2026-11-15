<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * Object storage did not hand over an import's file in time (ImportFileStorage::downloadTo()) - a fault of ours,
 * never the file's: the read fails as "our side" and the player can try again.
 */
final class ImportDownloadTimedOut extends \RuntimeException
{
}
