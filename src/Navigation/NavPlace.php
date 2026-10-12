<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * The five places of the bottom bar (D1) plus "none" for pages that belong to no place (Inbox, help, legal, auth,
 * endpoints). The value is the place segment of data-nav and data-nav-place.
 */
enum NavPlace: string
{
    case Home = 'home';
    case Puzzles = 'puzzles';
    case Add = 'add';
    case Community = 'community';
    case Profile = 'profile';
    case None = 'none';

    /**
     * The sections a place's chips switch between (COM-02, PUZ-01). Community pages always sit in one of the three,
     * except others' player pages, team pages and the landing (decision 75); Puzzles records its section on the four
     * start pages and "library" on the player's own list pages.
     *
     * @return list<string>
     */
    public function sections(): array
    {
        return match ($this) {
            self::Puzzles => ['library', 'all', 'marketplace', 'next'],
            self::Community => ['leaderboards', 'puzzlers', 'events'],
            default => [],
        };
    }
}
