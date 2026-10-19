<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\StorePlayerInsightsSnapshots;
use SpeedPuzzling\Web\Services\Insights\PlayerInsightsSnapshotWriter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class StorePlayerInsightsSnapshotsHandler
{
    public function __construct(
        private PlayerInsightsSnapshotWriter $writer,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(StorePlayerInsightsSnapshots $message): int
    {
        return $this->writer->write(new DateTimeImmutable($message->month), $message->snapshots, $this->clock->now());
    }
}
