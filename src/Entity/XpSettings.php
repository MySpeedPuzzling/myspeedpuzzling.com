<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;

/**
 * The XP system's one row of settings.
 *
 * `full_formula_from` = the moment XP reached production: the migration that created the table stored NOW() into it,
 * so the deploy itself set the cutoff and nobody edits a constant (docs/features/xp-levels/README.md "Formula
 * cutoff"). Solves logged (`tracked_at`) before it earn the history formula, solves logged from it on the full one.
 * Read through XpFormulaCutoff, never written by the application.
 *
 * `launch_ready_at` = the launch switch (docs/features/feature_flags.md): set once by MarkXpLaunchReady after the
 * launch backfill was verified; until then XP / achievements are shown to admins only (XpLaunchReadiness). Rollback
 * = set it back to NULL by hand and clear the `xp_launch_readiness_cache` pool.
 */
#[Entity]
class XpSettings
{
    public const int ID = 1;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: Types::SMALLINT)]
        public int $id,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $fullFormulaFrom,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $launchReadyAt = null,
    ) {
    }

    /**
     * Once, for good: a second verified run keeps the first moment.
     */
    public function markLaunchReady(DateTimeImmutable $at): void
    {
        $this->launchReadyAt ??= $at;
    }
}
