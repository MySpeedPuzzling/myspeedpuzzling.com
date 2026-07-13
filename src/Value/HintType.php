<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum HintType: string
{
    case MarketplaceDisclaimer = 'marketplace_disclaimer';
    case MarketplaceSettingsChecklist = 'marketplace_settings_checklist';
    case FeatureRequestsIntro = 'feature_requests_intro';
    case GettingStartedChecklist = 'getting_started_checklist';
    // "Add your country" on the Players page and the player's own profile (docs/features/players-page/README.md)
    case PlayersCountryNudge = 'players_country_nudge';
    case MarketplaceAtEvents = 'marketplace_at_events';
    // One-time XP launch reveal page (docs/features/xp-levels/README.md) - not a banner either, dismissed when seen
    case XpLaunchReveal = 'xp_launch_reveal';

    // Not banners: "the player opened this from the Getting started guide", stored the same
    // way so the guide can tick steps that leave no other trace (docs/features/getting-started-guide.md)
    case GuideStatisticsSeen = 'guide_statistics_seen';
    case GuideLeaderboardSeen = 'guide_leaderboard_seen';
}
