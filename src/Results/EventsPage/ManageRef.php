<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

/**
 * What the ⋯ menu of a row manages - whether it shows is decided by the voters in the template.
 */
readonly final class ManageRef
{
    public const string KIND_COMPETITION = 'competition';
    public const string KIND_SERIES = 'series';

    /**
     * @param 'competition'|'series' $kind
     */
    public function __construct(
        public string $kind,
        public string $id,
        public string $name,
    ) {
    }
}
