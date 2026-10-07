<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport\Plan;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * One row of the file while an import is planned.
 */
final class PlanRow
{
    /** null = skipped (no name, ambiguous name) */
    public null|string $personKey = null;

    /** @var list<TranslatableMessage> */
    public array $messages = [];

    /** @var list<string> pair/team rounds of the row, shown with the person's team after the import */
    public array $teamRoundIds = [];

    public function __construct(
        public readonly int $rowNumber,
        public readonly string $name,
    ) {
    }
}
