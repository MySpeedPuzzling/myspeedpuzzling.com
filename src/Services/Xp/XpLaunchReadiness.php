<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Xp;

use DateTimeImmutable;

/**
 * The XP launch switch (docs/features/feature_flags.md): XP, levels and achievements are shown to everybody once the
 * launch backfill was verified (`xp_settings.launch_ready_at`, set by MarkXpLaunchReady) - before that, to admins
 * only, so nobody meets "Level 1, 1 XP, no achievements" while the history is still being calculated.
 */
interface XpLaunchReadiness
{
    public function isReady(): bool;

    /**
     * When the switch went on (null while off) - things earned before it, like the achievements the launch backfill
     * stamped, are history, not news (the activity digest leaves them out).
     */
    public function readyAt(): null|DateTimeImmutable;

    /**
     * Where the launch backfill's window starts (null while off): the deploy, `xp_settings.full_formula_from` - the
     * backfill ran after it and switched the launch on at its end, so a tier earned between the two still carries the
     * backfill's own stamp ("held at launch", HeldAtLaunch).
     */
    public function stampedFrom(): null|DateTimeImmutable;

    /**
     * After the marker changed: every worker reads it again on its next request.
     */
    public function forget(): void;
}
