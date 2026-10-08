<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

readonly final class YourEvent
{
    public const string MARK_GOING = 'going';
    public const string MARK_FOLLOWING = 'following';

    /**
     * @param 'going'|'following' $mark
     */
    public function __construct(
        public AgendaRow $row,
        public string $mark,
    ) {
    }
}
