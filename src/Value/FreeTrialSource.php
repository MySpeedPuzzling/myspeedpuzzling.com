<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where a free trial was started from - stored on the membership so the funnel can tell which
 * surface earns its place (docs/features/free-trial/README.md, "Measurement").
 */
enum FreeTrialSource: string
{
    case MembershipPage = 'membership_page';
    case MembersModal = 'members_modal';
    case OfferModal = 'offer_modal';
    /** A lock of the Insights page (docs/features/insights/page.md) */
    case Insights = 'insights';
}
