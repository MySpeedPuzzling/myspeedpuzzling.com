<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeZone;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportBatch;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Message\AddImportRows;
use SpeedPuzzling\Web\Message\ProcessImportBatch;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\DataImportSettingsRepository;
use SpeedPuzzling\Web\Value\DataImport\AddImportRowsOutcome;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * "Add" (docs/features/data-import/adding.md "The flow"). Serialized per player (AddImportRows::lockKey()), in one
 * transaction; every check comes before the one statement that queues the rows:
 *
 * 1. the import is the player's (anybody else's is "not found");
 * 2. the same request id as before answers that batch - nothing is queued twice;
 * 3. the safety switch: while adding is paused nothing is queued;
 * 4. the import can add (it is set up, not discarded, its rows' content not wiped);
 * 5. one "Add" of a player at a time - an "Add" that still has queued rows answers "already running";
 * 6. the rate limit (`data_import_add`) and the daily guard of DataImportLimits::MAX_RESULTS_ADDED_PER_DAY results;
 * 7. the ready rows of the selection are queued for the batch (DataImportRowRepository::queueReadyRows()) and the
 *    batch goes to the `imports` worker. Nothing is added here.
 */
#[AsMessageHandler]
readonly final class AddImportRowsHandler
{
    public const string SKIPPED_OVER_DAILY_LIMIT = 'over_daily_limit';

    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportRowRepository $rowRepository,
        private DataImportBatchRepository $batchRepository,
        private DataImportSettingsRepository $settingsRepository,
        private RateLimiterFactoryInterface $dataImportAddLimiter,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DataImportNotFound
     */
    public function __invoke(AddImportRows $message): AddImportRowsOutcome
    {
        if (Uuid::isValid($message->requestId) === false) {
            throw new DataImportNotFound();
        }

        $import = $this->dataImportRepository->getOwnedBy($message->importId, $message->playerId);
        $playerId = $import->player->id->toString();
        $sameRequest = $this->batchRepository->findByRequestId($message->requestId);

        if ($sameRequest !== null) {
            // Somebody else's request id is nobody's business
            if ($sameRequest->import->id->equals($import->id) === false) {
                throw new DataImportNotFound();
            }

            return new AddImportRowsOutcome(AddImportRowsOutcome::SAME_REQUEST, $sameRequest->id->toString(), $sameRequest->rowsRequested, $sameRequest->skipped);
        }

        if ($this->settingsRepository->isAddingPaused()) {
            return new AddImportRowsOutcome(AddImportRowsOutcome::PAUSED);
        }

        if (in_array($import->status, [DataImportStatus::SettingUp, DataImportStatus::Reviewing], true) === false || $import->cellsWipedAt !== null) {
            return new AddImportRowsOutcome(AddImportRowsOutcome::NOT_ADDABLE);
        }

        if ($this->rowRepository->hasQueuedRowsOfPlayer($playerId)) {
            return new AddImportRowsOutcome(AddImportRowsOutcome::ALREADY_RUNNING);
        }

        if ($this->dataImportAddLimiter->create($playerId)->consume()->isAccepted() === false) {
            return new AddImportRowsOutcome(AddImportRowsOutcome::RATE_LIMITED);
        }

        $now = $this->clock->now();
        $startOfDay = $now->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
        $resultsLeft = DataImportLimits::MAX_RESULTS_ADDED_PER_DAY - $this->rowRepository->countResultsAddedOrQueuedSince($playerId, $startOfDay);

        $batchId = Uuid::uuid7();
        $counts = $this->rowRepository->queueReadyRows(
            $import,
            $batchId->toString(),
            self::ids($message->rowIds),
            self::ids($message->importPuzzleIds),
            $resultsLeft,
        );

        $skipped = array_filter([
            ImportRowRefusal::UnlistedNotYet->value => $counts['unlisted'],
            ImportRowRefusal::NeedsExactDay->value => $counts['needsExactDay'],
            self::SKIPPED_OVER_DAILY_LIMIT => $counts['overDailyLimit'],
        ], static fn (int $count): bool => $count > 0);

        if ($counts['queued'] === 0) {
            return new AddImportRowsOutcome(AddImportRowsOutcome::NOTHING, skipped: $skipped);
        }

        $this->batchRepository->save(new DataImportBatch($batchId, $import, Uuid::fromString($message->requestId), $counts['queued'], $skipped, $now));
        $import->workedOn($now);

        // On the `imports` queue; with the Doctrine transport the job is written in this very transaction
        $this->messageBus->dispatch(new ProcessImportBatch($batchId->toString()));

        return new AddImportRowsOutcome(AddImportRowsOutcome::STARTED, $batchId->toString(), $counts['queued'], $skipped);
    }

    /**
     * @param null|list<string> $ids
     *
     * @return null|list<string>
     */
    private static function ids(null|array $ids): null|array
    {
        return $ids === null ? null : array_values(array_unique(array_map(strtolower(...), array_filter($ids, Uuid::isValid(...)))));
    }
}
