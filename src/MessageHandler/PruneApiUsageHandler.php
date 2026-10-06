<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\PruneApiUsage;
use SpeedPuzzling\Web\Repository\ApiUsageRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * API usage rows are kept 24 months (docs/features/api/usage-statistics.md) - a
 * bulk DELETE by day, the documented exception to loading entities one by one.
 */
#[AsMessageHandler]
readonly final class PruneApiUsageHandler
{
    public function __construct(
        private ApiUsageRepository $apiUsageRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PruneApiUsage $message): int
    {
        $before = $this->clock->now()->modify("-{$message->retentionMonths} months");

        return $this->apiUsageRepository->deleteOlderThan($before);
    }
}
