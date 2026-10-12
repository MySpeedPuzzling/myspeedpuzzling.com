<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * How the Menu renders an entry's row.
 */
enum RowKind: string
{
    case Link = 'link';
    // One of the places, a row of the docked column only (the bars carry them on phones)
    case Place = 'place';
    // Opens the Help & feedback sheet (README §4.5)
    case HelpSheet = 'help_sheet';
    // The inline language switch: the current page in another locale
    case Language = 'language';
    // Terms · Privacy · Data deletion: the Legal entries sharing one group position render as one row of links
    case Legal = 'legal';
    case LogOut = 'logout';
    case YouCard = 'you_card';
    case GuestCard = 'guest_card';
}
