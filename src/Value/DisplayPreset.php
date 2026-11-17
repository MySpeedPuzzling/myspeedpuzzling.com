<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The design a new display starts from (D-displays-overlays.md §6) - the designs themselves are in
 * Services\CompetitionToolkit\DisplayPresets. Screen presets are for hall screens, OBS presets for stream overlays,
 * Blank for either.
 */
enum DisplayPreset: string
{
    case Blank = 'blank';
    case ClassicTimer = 'classic_timer';
    case TimerWithSponsorBar = 'timer_sponsor_bar';
    case TimerWithLiveBoard = 'timer_live_board';
    case ObsLowerThird = 'obs_lower_third';
    case ObsCornerTimer = 'obs_corner_timer';
    case ObsLeaderboardSidebar = 'obs_leaderboard_sidebar';

    public function fits(DisplayKind $kind): bool
    {
        return match ($this) {
            self::Blank => true,
            self::ClassicTimer, self::TimerWithSponsorBar, self::TimerWithLiveBoard => $kind === DisplayKind::Screen,
            self::ObsLowerThird, self::ObsCornerTimer, self::ObsLeaderboardSidebar => $kind === DisplayKind::Overlay,
        };
    }

    /**
     * Starts with results shown before they are published (`source: live`) - the whole point of these presets, and
     * the editor badges every such element (D §5.1).
     */
    public function showsLiveResults(): bool
    {
        return $this === self::TimerWithLiveBoard || $this === self::ObsLeaderboardSidebar;
    }
}
