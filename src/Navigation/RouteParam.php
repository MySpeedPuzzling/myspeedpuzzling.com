<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * A route parameter filled per viewer or per request when an entry's link is built.
 */
enum RouteParam: string
{
    case ViewerPlayerId = 'viewer_player_id';
    case ViewerCountryCode = 'viewer_country_code';
    case CurrentYear = 'current_year';
}
