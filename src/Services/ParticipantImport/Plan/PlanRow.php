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

    /** @var array<string, array{before: null|string, after: string}> round name => the division the row gives the entry */
    public array $divisionChanges = [];

    /** The file's row of the team row this person comes from - null = a row per person */
    public null|int $teamRow = null;

    /** The member column of that team row (1 = the first) */
    public null|int $member = null;

    public function __construct(
        public readonly int $rowNumber,
        public readonly string $name,
    ) {
    }
}
