<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Repository\DataImportDailyCountRepository;
use SpeedPuzzling\Web\Repository\DataImportPersonRepository;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;
use SpeedPuzzling\Web\Value\DataImportLimits;

/**
 * The limits that protect a registered player from other people's imports
 * (docs/features/data-import/people.md "Limits") - asked for a row right before its result is written, under the
 * importing player's lock:
 *
 * - one "Add" puts results on at most DataImportLimits::MAX_PARTNERS_PER_BATCH registered partners;
 * - one partner gets at most DataImportLimits::MAX_RESULTS_PER_PARTNER_PER_DAY results a day (UTC) from one
 *   importing player, and at most DataImportLimits::MAX_RESULTS_PER_PARTNER_PER_DAY_FROM_EVERYBODY from everybody
 *   together - the rest of that partner's rows wait with the reason and are ready tomorrow.
 *
 * **The daily numbers count what was made, not what is still there** (`data_import_daily_count`): an Undo gives no
 * room back, so adding, undoing and adding again can not churn a partner's profile.
 *
 * A row without a registered partner costs nothing.
 */
readonly final class ImportPartnerLimits
{
    public function __construct(
        private DataImportPersonRepository $personRepository,
        private DataImportDailyCountRepository $dailyCounts,
    ) {
    }

    /**
     * @param list<string> $registeredPlayerIds the row's registered partners
     */
    public function refusal(Player $importer, string $batchId, array $registeredPlayerIds, DateTimeImmutable $now): null|string
    {
        if ($registeredPlayerIds === []) {
            return null;
        }

        $importerId = $importer->id->toString();
        $inBatch = array_keys($this->personRepository->partnerResultsOfBatch($batchId, $importerId));

        if (count(array_unique([...$inBatch, ...array_map(strtolower(...), $registeredPlayerIds)])) > DataImportLimits::MAX_PARTNERS_PER_BATCH) {
            return ImportRowRefusal::TooManyPartners->value;
        }

        foreach ($this->dailyCounts->onPartners($importerId, $registeredPlayerIds, $now) as $made) {
            if (
                $made['fromImporter'] >= DataImportLimits::MAX_RESULTS_PER_PARTNER_PER_DAY
                || $made['fromEverybody'] >= DataImportLimits::MAX_RESULTS_PER_PARTNER_PER_DAY_FROM_EVERYBODY
            ) {
                return ImportRowRefusal::PartnerDailyLimit->value;
            }
        }

        return null;
    }
}
