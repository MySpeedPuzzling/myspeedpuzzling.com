<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\MatchImportPuzzlesFuzzy;
use SpeedPuzzling\Web\Query\GetImportPairing;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportPuzzleMatcher;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * One chunk of the similar-name search under the import's lock (ImportPuzzleMatcher::searchSimilarNames()), then
 * itself again while puzzles wait. When none waits the pairing has come to rest: the counters are written and the
 * import says so (`paired_at`). A chain of an older run finds another run number and stops.
 */
#[AsMessageHandler]
readonly final class MatchImportPuzzlesFuzzyHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private ImportPuzzleMatcher $matcher,
        private GetImportPairing $getImportPairing,
        private MessageBusInterface $messageBus,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(MatchImportPuzzlesFuzzy $message): void
    {
        if (Uuid::isValid($message->importId) === false) {
            return;
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->find($message->importId);

        if ($import === null || $import->isPairingRun($message->pairingRun) === false) {
            return;
        }

        $now = $this->clock->now();

        if ($this->matcher->searchSimilarNames($import, $now) >= ImportPuzzleMatcher::SEARCH_CHUNK) {
            $import->workedOn($now);
            $this->messageBus->dispatch(new MatchImportPuzzlesFuzzy($message->importId, $message->pairingRun));

            return;
        }

        $import->paired($this->getImportPairing->counters($message->importId), $now);
    }
}
