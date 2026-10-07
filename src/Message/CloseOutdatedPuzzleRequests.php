<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The daily safety net of OutdatedPuzzleRequests - closes every pending merge or change request with nothing left
 * to do.
 */
readonly final class CloseOutdatedPuzzleRequests
{
}
