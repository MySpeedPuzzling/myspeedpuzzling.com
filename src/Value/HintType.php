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
    // "How achievements work" on top of the achievements reference - collapses to one line, can be opened again
    case AchievementsExplainer = 'achievements_explainer';

    // Not banners: "the player opened this from the Getting started guide", stored the same
    // way so the guide can tick steps that leave no other trace (docs/features/getting-started-guide.md)
    case GuideStatisticsSeen = 'guide_statistics_seen';
    case GuideLeaderboardSeen = 'guide_leaderboard_seen';

    // The navigation redesign's 30-day pointers (docs/features/navigation, INB-05/LAUNCH-03): their dismissals ride on
    // the viewer's profile row (GetPlayerProfile::byUserId(), every type starting with NAVIGATION_PREFIX), read through
    // NavigationPointers - never through IsHintDismissed
    // "Your favorites' new times are now on Home › Favorites" on the Inbox's For you tab (INB-05)
    case NavigationInboxPointer = 'navigation_inbox_pointer';
    // "We rearranged the navigation" - the Hub banner and the line at the top of the Menu: one dismissal hides both
    // (LAUNCH-03, decision 52)
    case NavigationLaunchPointer = 'navigation_launch_pointer';

    public const string NAVIGATION_PREFIX = 'navigation_';

    public function isNavigationPointer(): bool
    {
        return str_starts_with($this->value, self::NAVIGATION_PREFIX);
    }
}
