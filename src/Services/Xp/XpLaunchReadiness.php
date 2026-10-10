<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Xp;

/**
 * The XP launch switch (docs/features/feature_flags.md): XP, levels and achievements are shown to everybody once the
 * launch backfill was verified (`xp_settings.launch_ready_at`, set by MarkXpLaunchReady) - before that, to admins
 * only, so nobody meets "Level 1, 1 XP, no achievements" while the history is still being calculated.
 */
interface XpLaunchReadiness
{
    public function isReady(): bool;

    /**
     * After the marker changed: every worker reads it again on its next request.
     */
    public function forget(): void;
}
