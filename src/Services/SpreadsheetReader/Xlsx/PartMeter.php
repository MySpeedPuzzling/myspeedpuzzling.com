<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx;

/**
 * Counts the bytes one part really unpacks to, as they are read: against what the archive says the part holds (libzip
 * hands out whatever the compressed stream holds, whatever size its header declares) and against the workbook's
 * budget.
 */
final class PartMeter
{
    public const string LONGER_THAN_DECLARED = 'longer';
    public const string OVER_BUDGET = 'budget';

    public int $read = 0;
    /** @var null|self::* */
    public null|string $failure = null;

    public function __construct(
        private readonly int $declared,
        private readonly ReadBudget $budget,
    ) {
    }

    /**
     * @return bool false: reading stops here
     */
    public function add(int $bytes): bool
    {
        $this->read += $bytes;
        $this->budget->read += $bytes;
        $this->budget->remaining -= $bytes;

        if ($this->read > $this->declared) {
            $this->failure = self::LONGER_THAN_DECLARED;

            return false;
        }

        if ($this->budget->remaining < 0) {
            $this->failure = self::OVER_BUDGET;

            return false;
        }

        return true;
    }
}
