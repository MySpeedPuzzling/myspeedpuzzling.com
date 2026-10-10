<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Xp;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use LogicException;

/**
 * When the full XP formula starts: solves logged (`tracked_at`) before this moment earn the history formula (base,
 * difficulty, unboxed), solves logged from it on also the incentives (speed bonus, weekly boost, daily warm-up,
 * settlements). The moment XP reached production, stored once by the migration that created `xp_settings`
 * (docs/features/xp-levels/README.md "Formula cutoff").
 *
 * The value never changes after the deploy, so it is read once per worker process and kept - no ResetInterface on
 * purpose: nothing about it is specific to a request or a person.
 */
final class XpFormulaCutoff
{
    private null|DateTimeImmutable $fullFormulaFrom = null;

    public function __construct(
        readonly private Connection $database,
    ) {
    }

    public function fullFormulaFrom(): DateTimeImmutable
    {
        if ($this->fullFormulaFrom !== null) {
            return $this->fullFormulaFrom;
        }

        $value = $this->database->fetchOne('SELECT full_formula_from FROM xp_settings WHERE id = 1');

        if (!is_string($value)) {
            throw new LogicException('xp_settings has no row - the migration that creates the table inserts it.');
        }

        return $this->fullFormulaFrom = new DateTimeImmutable($value);
    }

    /**
     * Was the solve logged before the full formula started?
     */
    public function isHistory(DateTimeImmutable $trackedAt): bool
    {
        return $trackedAt < $this->fullFormulaFrom();
    }
}
