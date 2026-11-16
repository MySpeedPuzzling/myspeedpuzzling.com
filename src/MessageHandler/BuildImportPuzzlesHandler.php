<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\BuildImportPuzzles;
use SpeedPuzzling\Web\Message\MatchImportPuzzlesFuzzy;
use SpeedPuzzling\Web\Query\GetImportPairing;
use SpeedPuzzling\Web\Repository\DataImportPuzzleRepository;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportPeopleBuilder;
use SpeedPuzzling\Web\Services\DataImport\ImportPuzzleMatcher;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Under the import's lock, in one transaction (docs/features/data-import/pairing.md "The flow"): the import's
 * distinct puzzles from its rows as they are parsed now, every row linked to its puzzle, then the exact pairing
 * (ImportPuzzleMatcher) - a fixed number of statements whatever the import's size. The similar-name search is asked
 * for next; the run's number makes an older search stop. The import's people are built in the same step
 * (ImportPeopleBuilder).
 *
 * Only an import that is being set up and still has its rows' content: later stages never build again.
 */
#[AsMessageHandler]
readonly final class BuildImportPuzzlesHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportPuzzleRepository $dataImportPuzzleRepository,
        private ImportPuzzleMatcher $matcher,
        private ImportPeopleBuilder $peopleBuilder,
        private GetImportPairing $getImportPairing,
        private MessageBusInterface $messageBus,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(BuildImportPuzzles $message): void
    {
        if (Uuid::isValid($message->importId) === false) {
            return;
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->find($message->importId);

        if ($import === null || $import->isBeingSetUp() === false || $import->cellsWipedAt !== null) {
            return;
        }

        $now = $this->clock->now();
        $run = $import->pairingStarted($now);

        $puzzles = [];

        foreach ($this->dataImportPuzzleRepository->distinctPuzzlesOfRows($import) as $said) {
            // A key comes from a name: a row without one has no key
            if ($said['name'] !== null) {
                $puzzles[] = ['id' => Uuid::uuid7()->toString(), ...$said, 'name' => $said['name']];
            }
        }

        // A puzzle left undecided in no pile (a remembered answer taken back before the handler handed it back to
        // the rules) is nobody's decision: this run looks at it
        $this->dataImportPuzzleRepository->releaseUndecidedNobodyLookedAt($import, $now);
        $this->dataImportPuzzleRepository->store($import, $puzzles, $now);
        $this->dataImportPuzzleRepository->deleteWithoutRows($import);
        $this->dataImportPuzzleRepository->linkRows($import);

        $this->matcher->matchExact($import, $now);

        // The people the rows name, with the puzzles they name (docs/features/data-import/people.md "Building")
        $this->peopleBuilder->build($import, $now);

        $import->pairingCounted($this->getImportPairing->counters($message->importId));

        $this->messageBus->dispatch(new MatchImportPuzzlesFuzzy($message->importId, $run));
    }
}
