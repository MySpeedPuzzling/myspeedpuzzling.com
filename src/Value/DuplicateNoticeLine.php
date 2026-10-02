<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Results\FirstTryPerson;
use SpeedPuzzling\Web\Results\FirstTryTime;

/**
 * One result with the same time as the one being entered, already cut down to what the viewer may be told
 * (docs/features/duplicate-results.md, Layer 2).
 */
readonly final class DuplicateNoticeLine
{
    // The viewer saved it themselves
    public const string OWN = 'own';
    // Somebody else saved it with the viewer in it - a teammate's copy, already on the viewer's profile
    public const string WITH_VIEWER = 'with_viewer';
    // A result of a teammate of the new result, without the viewer
    public const string TEAMMATE = 'teammate';

    /**
     * @param list<string> $with own result only: the other people of it, as the viewer may call them
     */
    public function __construct(
        public string $kind,
        public FirstTryTime $time,
        // Who saved it (WITH_VIEWER) or whose it is (TEAMMATE)
        public null|FirstTryPerson $person = null,
        public array $with = [],
        // Saved today (in the site's time zone) - "today at 14:05" instead of the date
        public bool $savedToday = false,
        // Whether the viewer may open the result: they took part in it, or nobody in it is hidden from them
        public bool $viewable = false,
    ) {
    }
}
