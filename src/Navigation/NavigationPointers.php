<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Value\HintType;

/**
 * The navigation redesign's 30-day pointers (docs/features/navigation README §8): the Inbox note "Your favorites' new
 * times are now on Home › Favorites" (INB-05) and the Hub banner and the Menu line "We rearranged the navigation"
 * (LAUNCH-03, one pointer: one dismissal hides both). One rule for all of them, no query: the launch date and the
 * clock come from NavigationLaunch (tests replace that service with a MockClock and a date), the dismissals and
 * favorites from the viewer's profile row.
 *
 * A pointer shows iff the launch date is set, now is inside [date, date + 30 days), the account was created before the
 * date, the viewer has not dismissed it and the pointer's own audience holds (INB-05: the viewer follows somebody).
 * Never for guests - guest HTML stays shared.
 */
final readonly class NavigationPointers
{
    public function __construct(
        private NavigationLaunch $launch,
    ) {
    }

    /**
     * The pointer behind a Twig name - navigation_pointer('inbox').
     */
    public static function byName(string $name): HintType
    {
        return match ($name) {
            'inbox' => HintType::NavigationInboxPointer,
            // The Hub banner and the Menu line (LAUNCH-03): one pointer, one dismissal
            'launch' => HintType::NavigationLaunchPointer,
            default => throw new \InvalidArgumentException(sprintf('"%s" is not a navigation pointer', $name)),
        };
    }

    public function isActive(null|PlayerProfile $viewer, HintType $pointer): bool
    {
        if ($pointer->isNavigationPointer() === false) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a navigation pointer', $pointer->value));
        }

        if ($viewer === null) {
            return false;
        }

        // The date is set, now is inside the 30 days, and the account knew the old navigation
        if ($this->launch->pointersActiveFor($viewer) === false) {
            return false;
        }

        if (in_array($pointer, $viewer->dismissedNavigationHints, true)) {
            return false;
        }

        return match ($pointer) {
            // Only somebody who follows players had favorites' times in the bell (INB-03 moved them to the Hub)
            HintType::NavigationInboxPointer => $viewer->favoritePlayers !== [],
            default => true,
        };
    }
}
