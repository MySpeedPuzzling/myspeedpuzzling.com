<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Repository\DataImportPersonRepository;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;
use SpeedPuzzling\Web\Value\DataImportLimits;

/**
 * The two limits that protect a registered player from somebody else's import
 * (docs/features/data-import/people.md "Limits") - asked for a row right before its result is written, under the
 * importing player's lock, so what is counted is what holds:
 *
 * - one "Add" puts results on at most DataImportLimits::MAX_PARTNERS_PER_BATCH registered partners;
 * - one partner gets at most DataImportLimits::MAX_RESULTS_PER_PARTNER_PER_DAY results a day (UTC) from one
 *   importing player - the rest of that partner's rows wait with the reason and are ready tomorrow.
 *
 * A row without a registered partner costs nothing.
 */
readonly final class ImportPartnerLimits
{
    public function __construct(
        private DataImportPersonRepository $personRepository,
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

        $today = $now->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);

        foreach ($this->personRepository->partnerResultsSince($importerId, $registeredPlayerIds, $today) as $results) {
            if ($results >= DataImportLimits::MAX_RESULTS_PER_PARTNER_PER_DAY) {
                return ImportRowRefusal::PartnerDailyLimit->value;
            }
        }

        return null;
    }
}
