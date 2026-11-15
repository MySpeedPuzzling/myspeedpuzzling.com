<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\ApplyImportRow;
use SpeedPuzzling\Web\Services\DataImport\ImportRowAdder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One row, one transaction (docs/features/data-import/adding.md "A row, step by step") - all of it is
 * ImportRowAdder's. What it returns travels back to ProcessImportBatchHandler.
 */
#[AsMessageHandler]
readonly final class ApplyImportRowHandler
{
    public function __construct(
        private ImportRowAdder $adder,
    ) {
    }

    /**
     * @return array{status: string, code: null|string, customValuesOf: list<string>}
     */
    public function __invoke(ApplyImportRow $message): array
    {
        if ($message->settleOnly) {
            $settled = $this->adder->settle($message->rowId, $message->batchId, $message->playerId);

            return ['status' => $settled ? 'already_yours' : 'queued', 'code' => null, 'customValuesOf' => []];
        }

        return $this->adder->add($message->rowId, $message->batchId, $message->playerId);
    }
}
