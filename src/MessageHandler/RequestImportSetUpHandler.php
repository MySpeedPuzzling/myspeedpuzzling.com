<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ParseImportSheet;
use SpeedPuzzling\Web\Message\ProposeImportMappings;
use SpeedPuzzling\Web\Message\RequestImportSetUp;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportSheetRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Under the import's lock, in one transaction with the messages it queues: a sheet without a mapping gets the
 * proposal queued (once for the import), a mapped sheet that is not parsed for its current version and has no
 * question gets its parsing queued. Both jobs find nothing to do when the first message was only slow.
 */
#[AsMessageHandler]
readonly final class RequestImportSetUpHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportSheetRepository $dataImportSheetRepository,
        private MessageBusInterface $messageBus,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RequestImportSetUp $message): bool
    {
        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->find($message->importId);

        if ($import === null || $import->isBeingSetUp() === false) {
            return false;
        }

        $propose = false;
        $queued = false;

        foreach ($this->dataImportSheetRepository->ofImport($import) as $sheet) {
            if ($sheet->mappingVersion === 0) {
                $propose = true;
            } elseif ($sheet->isParsed() === false && ImportMapping::fromArray($sheet->mapping ?? [])->needsAnAnswer() === false) {
                $this->messageBus->dispatch(new ParseImportSheet($message->importId, $sheet->id->toString(), $sheet->mappingVersion));
                $queued = true;
            }
        }

        if ($propose) {
            $this->messageBus->dispatch(new ProposeImportMappings($message->importId));
        }

        $import->workedOn($this->clock->now());

        return $propose || $queued;
    }
}
