<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use SpeedPuzzling\Web\Message\PrunePlayerInsightsSnapshots;
use SpeedPuzzling\Web\Services\Insights\PlayerInsightsSnapshotWriter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class PrunePlayerInsightsSnapshotsHandler
{
    public function __construct(
        private PlayerInsightsSnapshotWriter $writer,
    ) {
    }

    public function __invoke(PrunePlayerInsightsSnapshots $message): int
    {
        return $this->writer->deleteOthers(new DateTimeImmutable($message->month), $message->keptPlayerIds);
    }
}
