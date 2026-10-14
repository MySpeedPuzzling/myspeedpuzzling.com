<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\MarkAbandonedOnlineMedia;
use SpeedPuzzling\Web\Message\PruneOnlineMedia;
use SpeedPuzzling\Web\Message\SweepOnlineMediaOrphans;
use SpeedPuzzling\Web\MessageHandler\PruneOnlineMediaHandler;
use SpeedPuzzling\Web\Results\MarkedAbandonedOnlineMedia;
use SpeedPuzzling\Web\Results\PrunedOnlineMedia;
use SpeedPuzzling\Web\Results\SweptOnlineMediaOrphans;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStores;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Daily (docs/features/online-competitions/README.md §8): online competition evidence past its retention - one
 * PruneOnlineMedia page after another, each starting where the previous one ended (MAX_PRUNE_PAGES at most; a store
 * that keeps failing ends the run) - then the uploads that never arrived are marked abandoned (MarkAbandonedOnlineMedia,
 * MAX_ABANDONED_PAGES at most - safe to run any time, e.g. after an event's round so its review says "never arrived"),
 * then the orphan sweep of every configured store, at most MAX_SWEEP_PAGES pages each (the sweep goes on where it
 * stopped the next day).
 */
#[AsCommand(
    name: 'myspeedpuzzling:prune-online-media',
    description: 'Delete online competition evidence past its retention (periodic photos after the given number of days, everything else after 365 days), mark uploads that never arrived and delete objects whose evidence row is gone',
)]
final class PruneOnlineMediaCommand extends Command
{
    public const int MAX_PRUNE_PAGES = 2_000;
    public const int MAX_SWEEP_PAGES = 20;
    public const int MAX_ABANDONED_PAGES = 200;

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly OnlineMediaStores $onlineMediaStores,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('days', InputArgument::OPTIONAL, 'Delete periodic photos older than this many days', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $daysArgument */
        $daysArgument = $input->getArgument('days');
        $days = (int) $daysArgument;

        if ($days < 1) {
            $io->error('Days must be a positive integer.');

            return Command::FAILURE;
        }

        $periodic = 0;
        $evidence = 0;
        $failed = 0;
        $skipped = 0;
        $afterCreatedAt = null;
        $afterId = null;

        for ($page = 0; $page < self::MAX_PRUNE_PAGES; $page++) {
            $pruned = $this->messageBus->dispatch(new PruneOnlineMedia($days, $afterCreatedAt, $afterId))->last(HandledStamp::class)?->getResult();
            assert($pruned instanceof PrunedOnlineMedia);

            $periodic += $pruned->periodic;
            $evidence += $pruned->evidence;
            $failed += $pruned->failed;
            $skipped += $pruned->skipped;

            if ($pruned->storeFailing) {
                $io->warning('The store kept failing - pruning stopped, the next run goes on.');

                break;
            }

            if ($pruned->hasMore() === false) {
                break;
            }

            $afterCreatedAt = $pruned->nextAfterCreatedAt;
            $afterId = $pruned->nextAfterId;
        }

        $io->success(sprintf(
            'Deleted %d periodic photos older than %d days and %d other evidence objects older than %d days (%d failed, %d skipped).',
            $periodic,
            $days,
            $evidence,
            max(PruneOnlineMediaHandler::EVIDENCE_RETENTION_DAYS, $days),
            $failed,
            $skipped,
        ));

        $this->markAbandoned($io);

        $stores = $this->onlineMediaStores->hasSecondary() ? [OnlineMediaStore::Primary, OnlineMediaStore::Secondary] : [OnlineMediaStore::Primary];

        foreach ($stores as $store) {
            $listed = 0;
            $deleted = 0;
            $notDeleted = 0;

            for ($page = 0; $page < self::MAX_SWEEP_PAGES; $page++) {
                $swept = $this->messageBus->dispatch(new SweepOnlineMediaOrphans($store))->last(HandledStamp::class)?->getResult();
                assert($swept instanceof SweptOnlineMediaOrphans);

                $listed += $swept->listed;
                $deleted += $swept->deleted;
                $notDeleted += $swept->failed;

                if ($swept->hasMore === false) {
                    break;
                }
            }

            $io->success(sprintf('Orphan sweep of the %s store: %d objects listed, %d orphans deleted, %d left for later.', $store->value, $listed, $deleted, $notDeleted));
        }

        return Command::SUCCESS;
    }

    private function markAbandoned(SymfonyStyle $io): void
    {
        $abandoned = 0;
        $inStore = 0;
        $failed = 0;
        $skipped = 0;
        $afterCreatedAt = null;
        $afterId = null;

        for ($page = 0; $page < self::MAX_ABANDONED_PAGES; $page++) {
            $marked = $this->messageBus->dispatch(new MarkAbandonedOnlineMedia($afterCreatedAt, $afterId))->last(HandledStamp::class)?->getResult();
            assert($marked instanceof MarkedAbandonedOnlineMedia);

            $abandoned += $marked->abandoned;
            $inStore += $marked->inStore;
            $failed += $marked->failed;
            $skipped += $marked->skipped;

            if ($marked->storeFailing) {
                $io->warning('The store kept failing - marking uploads that never arrived stopped, the next run goes on.');

                break;
            }

            if ($marked->hasMore() === false) {
                break;
            }

            $afterCreatedAt = $marked->nextAfterCreatedAt;
            $afterId = $marked->nextAfterId;
        }

        $io->success(sprintf(
            'Marked %d uploads that never arrived as abandoned (%d objects there without a report, %d not asked - failed, %d skipped).',
            $abandoned,
            $inStore,
            $failed,
            $skipped,
        ));
    }
}
