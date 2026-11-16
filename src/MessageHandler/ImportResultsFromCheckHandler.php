<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\ApplyEventResultsTab;
use SpeedPuzzling\Web\Message\ImportResultsFromCheck;
use SpeedPuzzling\Web\Message\RecordResultsImport;
use SpeedPuzzling\Web\Repository\EventResultsSheetRepository;
use SpeedPuzzling\Web\Repository\ResultsImportCheckRepository;
use SpeedPuzzling\Web\Repository\ResultsImportLogRepository;
use SpeedPuzzling\Web\Services\EventResultsImport\Format\ResultsFormats;
use SpeedPuzzling\Web\Value\EventResultsImport\AppliedEventResultsTab;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabStatus;
use SpeedPuzzling\Web\Value\EventResultsImport\ParsedEvent;
use SpeedPuzzling\Web\Value\EventResultsImport\ResultsImportOutcome;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

/**
 * Imports the ticked events of a stored check (docs/features/event-results-import/results-import-page.md "As built:
 * PR 3"): the check's sheet parsed again through the source's format - what the organizer saw is what is published,
 * Google is never read here. Outside a transaction (HandledOutsideTransaction): every event is ApplyEventResultsTab in
 * a transaction of its own, like the sync - one that fails changes nothing of itself and holds back no other. An event
 * applied after the check was read is refused by the apply (a newer check won). Nothing is imported twice for one
 * import id: the log written at the end is the mark, and the source's lock keeps a second submit waiting until then.
 */
#[AsMessageHandler]
readonly final class ImportResultsFromCheckHandler
{
    public function __construct(
        private EventResultsSheetRepository $sourceRepository,
        private ResultsImportCheckRepository $checkRepository,
        private ResultsImportLogRepository $logRepository,
        private ResultsFormats $formats,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $managerRegistry,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ImportResultsFromCheck $message): void
    {
        // A second submit of the same confirmation - done already
        if ($this->logRepository->find($message->importId) !== null) {
            return;
        }

        $source = $this->sourceRepository->get($message->sourceId);
        $check = $this->checkRepository->get($message->checkId);
        $snapshot = $check->sheetSnapshot();

        if ($check->source->id->equals($source->id) === false || $snapshot === null) {
            return;
        }

        $sourceId = $source->id->toString();
        $readAt = $check->checkedAt;

        $eventsByKey = [];

        foreach ($this->formats->get($source->format)->parse($snapshot) as $event) {
            $eventsByKey[$event->key] = $event;
        }

        $logged = [];

        foreach (array_values(array_unique($message->eventKeys)) as $key) {
            $event = $eventsByKey[$key] ?? null;

            if ($event === null) {
                $logged[] = self::entry($key, $key, ResultsImportOutcome::NotInCheck);
                continue;
            }

            if ($event->isImportable() === false) {
                $logged[] = self::entry($event->key, $event->title, ResultsImportOutcome::NotImported);
                continue;
            }

            $logged[] = $this->apply($sourceId, $event, $readAt);
        }

        $this->messageBus->dispatch(new RecordResultsImport(
            importId: $message->importId,
            sourceId: $sourceId,
            checkId: $message->checkId,
            importedByPlayerId: $message->importedByPlayerId,
            importedAt: $this->clock->now(),
            events: $logged,
        ));

        // No approval needed (P2) - counted in Loki, nobody has to look
        $this->logger->info('Results imported from source {source} by {player}: {count} events', [
            'source' => $sourceId,
            'player' => $message->importedByPlayerId,
            'count' => count($logged),
            'events' => $logged,
        ]);
    }

    /**
     * @return array{key: string, title: string, outcome: string, competition_id: null|string, added: int, updated: int, removed: int}
     */
    private function apply(string $sourceId, ParsedEvent $event, DateTimeImmutable $readAt): array
    {
        try {
            $envelope = $this->messageBus->dispatch(new ApplyEventResultsTab($sourceId, $event, $readAt));
        } catch (Throwable $e) {
            // Its own transaction was rolled back - nothing of it changed; the next event starts from a clean state
            $this->logger->warning('Event "{title}" of results source {source} could not be imported from the page', [
                'title' => $event->title,
                'source' => $sourceId,
                'exception' => $e,
            ]);
            $this->freshEntityManager();

            return self::entry($event->key, $event->title, ResultsImportOutcome::Failed);
        }

        $applied = $envelope->last(HandledStamp::class)?->getResult();
        $this->entityManager->clear();

        if (!$applied instanceof AppliedEventResultsTab) {
            return self::entry($event->key, $event->title, ResultsImportOutcome::Failed);
        }

        $outcome = match (true) {
            $applied->stale => ResultsImportOutcome::Stale,
            $applied->unchanged => ResultsImportOutcome::Unchanged,
            $applied->status === EventTabStatus::Imported => ResultsImportOutcome::Imported,
            default => ResultsImportOutcome::NotImported,
        };

        return self::entry($event->key, $event->title, $outcome, $applied->competitionId, $applied->teamsAdded, $applied->teamsUpdated, $applied->teamsRemoved);
    }

    private function freshEntityManager(): void
    {
        if ($this->entityManager->isOpen()) {
            $this->entityManager->clear();

            return;
        }

        $this->managerRegistry->resetManager();
    }

    /**
     * @return array{key: string, title: string, outcome: string, competition_id: null|string, added: int, updated: int, removed: int}
     */
    private static function entry(
        string $key,
        string $title,
        ResultsImportOutcome $outcome,
        null|string $competitionId = null,
        int $added = 0,
        int $updated = 0,
        int $removed = 0,
    ): array {
        return [
            'key' => $key,
            'title' => $title,
            'outcome' => $outcome->value,
            'competition_id' => $competitionId,
            'added' => $added,
            'updated' => $updated,
            'removed' => $removed,
        ];
    }
}
