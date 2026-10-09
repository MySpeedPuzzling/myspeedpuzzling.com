<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\NewEdition;

/**
 * "Add several dates" (docs/features/organizations/README.md, D15): up to MAX real editions of one series in one step,
 * each dated one day. No recurrence is stored - a venue reschedule is an edit of that one edition.
 *
 * Idempotent: the ids come from the page (one per proposed day, kept on a re-render), an id or a day the series has an
 * edition of already is skipped - so a form sent twice creates nothing twice. Two sends at once take turns (the lock).
 * The handler answers how many editions it created.
 */
readonly final class AddEditions implements SerializedByLock
{
    public const int MAX = 24;

    /**
     * @param list<NewEdition> $editions
     */
    public function __construct(
        public string $seriesId,
        public array $editions,
        public null|string $eligibility = null,
        public bool $isDraft = false,
    ) {
    }

    public function lockKey(): string
    {
        return 'series-editions-' . strtolower($this->seriesId);
    }
}
