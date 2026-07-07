<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum PageSectionType: string
{
    case RichText = 'rich_text';
    case Faq = 'faq';
    case Gallery = 'gallery';
    case Venue = 'venue';
    case Sponsors = 'sponsors';
    case Links = 'links';
    case Contact = 'contact';
}
