<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\SnapshotPlayerInsights;
use SpeedPuzzling\Web\Message\StorePlayerInsightsSnapshots;
use SpeedPuzzling\Web\Query\GetInsightsContext;
use SpeedPuzzling\Web\Query\GetInsightsSoloResults;
use SpeedPuzzling\Web\Services\Insights\InsightsBuilder;
use SpeedPuzzling\Web\Services\Insights\InsightsSnapshotCriteria;
use SpeedPuzzling\Web\Services\Insights\InsightsSnapshotFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Builds the report of every player with at least InsightsSnapshotCriteria::MIN_RESULTS solo results in the last 12
 * months (members' view, no filters, no close calls) and stores the snapshot JSON in chunks.
 */
#[AsMessageHandler]
readonly final class SnapshotPlayerInsightsHandler
{
    public const int CHUNK = 200;

    public function __construct(
        private Connection $connection,
        private GetInsightsSoloResults $getInsightsSoloResults,
        private GetInsightsContext $getInsightsContext,
        private InsightsBuilder $builder,
        private InsightsSnapshotFactory $snapshotFactory,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{month: string, players: int, written: int}
     */
    public function __invoke(SnapshotPlayerInsights $message): array
    {
        $now = $this->clock->now();
        $month = $message->month !== null
            ? new DateTimeImmutable($message->month . '-01')
            : new DateTimeImmutable($now->format('Y-m-01'));
        $criteria = InsightsSnapshotCriteria::criteria();

        $chunk = [];
        $players = 0;
        $written = 0;

        foreach ($this->activePlayers($criteria->periodFrom($now) ?? $now) as $playerId) {
            $context = $this->getInsightsContext->forPlayer($playerId);
            $report = $this->builder->build($playerId, $this->getInsightsSoloResults->forPlayer($playerId), [], $context, $criteria, null, $now);

            if ($report->summary->resultCount < InsightsSnapshotCriteria::MIN_RESULTS) {
                continue;
            }

            $chunk[$playerId] = $this->snapshotFactory->fromReport($report, $context);
            $players++;

            if (count($chunk) >= self::CHUNK) {
                $written += $this->store($month, $chunk);
                $chunk = [];
            }
        }

        $written += $this->store($month, $chunk);

        return ['month' => $month->format('Y-m'), 'players' => $players, 'written' => $written];
    }

    /**
     * @param array<string, array<string, mixed>> $chunk
     */
    private function store(DateTimeImmutable $month, array $chunk): int
    {
        if ($chunk === []) {
            return 0;
        }

        $this->messageBus->dispatch(new StorePlayerInsightsSnapshots($month->format('Y-m-d'), $chunk));

        return count($chunk);
    }

    /**
     * @return list<string>
     */
    private function activePlayers(DateTimeImmutable $from): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn("
            SELECT player_id::text
            FROM puzzle_solving_time
            WHERE puzzling_type = 'solo'
                AND suspicious = false
                AND seconds_to_solve > 0
                AND COALESCE(finished_at, tracked_at) >= :from
            GROUP BY player_id
            HAVING COUNT(*) >= :minResults
            ORDER BY 1
        ", ['from' => $from->format('Y-m-d H:i:s'), 'minResults' => InsightsSnapshotCriteria::MIN_RESULTS]);
    }
}
