<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\RecordEventResultsSheetFetch;
use SpeedPuzzling\Web\Repository\EventResultsSheetRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RecordEventResultsSheetFetchHandler
{
    public function __construct(
        private EventResultsSheetRepository $sheetRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordEventResultsSheetFetch $message): void
    {
        $sheet = $this->sheetRepository->get($message->sheetId);

        if ($message->error === null) {
            $sheet->fetched($this->clock->now());
        } else {
            $sheet->fetchFailed($this->clock->now(), $message->error);
        }
    }
}
