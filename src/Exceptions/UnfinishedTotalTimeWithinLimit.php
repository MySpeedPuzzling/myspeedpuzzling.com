<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * The total time of an unfinished result (they kept going and finished afterwards) must be over the round's limit - a
 * total within it means they finished in time: that is a finished result with that time.
 */
final class UnfinishedTotalTimeWithinLimit extends \Exception
{
    public function __construct(
        readonly public int $totalSeconds,
        readonly public int $minutesLimit,
    ) {
        parent::__construct(sprintf('A total of %d s is within the %d min limit.', $totalSeconds, $minutesLimit));
    }
}
