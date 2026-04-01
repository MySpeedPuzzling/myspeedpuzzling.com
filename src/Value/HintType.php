<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum HintType: string
{
    case MarketplaceDisclaimer = 'marketplace_disclaimer';
    case MarketplaceSettingsChecklist = 'marketplace_settings_checklist';
    case FeatureRequestsIntro = 'feature_requests_intro';
}
