<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum NewsletterSignupTicketCheck
{
    case Valid;
    // Sent sooner than a person could have read the step
    case TooFast;
    case Expired;
    // Missing, forged or tampered with
    case Invalid;
}
