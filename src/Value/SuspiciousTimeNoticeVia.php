<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum SuspiciousTimeNoticeVia: string
{
    // The notice run: the banner, the review page and the next "Your results" e-mail
    case Run = 'run';
    // E-mailed by hand - before the automatic system existed, or a mark through the internal API with toldByHand -
    // never shown or sent again
    case ManualEmail = 'manual_email';
}
