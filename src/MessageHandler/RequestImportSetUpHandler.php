<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ParseImportSheet;
use SpeedPuzzling\Web\Message\ProposeImportMappings;
use SpeedPuzzling\Web\Message\RequestImportSetUp;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportSheetRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImportLimits;
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
        private LoggerInterface $logger,
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

        $now = $this->clock->now();
        $deadBefore = $now->modify('-' . DataImportLimits::PARSE_CLAIM_SECONDS . ' seconds');

        foreach ($this->dataImportSheetRepository->ofImport($import) as $sheet) {
            if ($sheet->setUpFailure !== null) {
                continue;
            }

            if ($sheet->mappingVersion === 0) {
                // Asked for a few times, then given up: a proposal that fails every time is not queued for ever
                if ($sheet->proposalRequestedAgain()) {
                    $propose = true;
                } else {
                    $this->logger->error('The mapping of a sheet of an import was asked for several times and never arrived - given up', [
                        'import_id' => $message->importId,
                        'sheet_id' => $sheet->id->toString(),
                    ]);
                }

                continue;
            }

            // A parse somebody is at (its claim is fresh) is left alone; the job itself counts its starts
            $claimed = $sheet->parsingVersion === $sheet->mappingVersion && $sheet->parsingStartedAt !== null && $sheet->parsingStartedAt > $deadBefore;

            if ($sheet->waitsForItsParse() && $claimed === false) {
                $this->messageBus->dispatch(new ParseImportSheet($message->importId, $sheet->id->toString(), $sheet->mappingVersion));
                $queued = true;
            }
        }

        if ($propose) {
            $this->messageBus->dispatch(new ProposeImportMappings($message->importId));
        }

        $import->workedOn($now);

        return $propose || $queued;
    }
}
