<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\PruneOnlineMedia;
use SpeedPuzzling\Web\MessageHandler\PruneOnlineMediaHandler;
use SpeedPuzzling\Web\Results\PrunedOnlineMedia;
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
 * PruneOnlineMedia batch after another until nothing is left (or MAX_BATCHES, the next run goes on).
 */
#[AsCommand(
    name: 'myspeedpuzzling:prune-online-media',
    description: 'Delete online competition evidence past its retention: periodic photos after the given number of days, everything else after 365 days',
)]
final class PruneOnlineMediaCommand extends Command
{
    public const int MAX_BATCHES = 1_000;

    public function __construct(
        private readonly MessageBusInterface $messageBus,
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
        $batches = 0;

        do {
            $envelope = $this->messageBus->dispatch(new PruneOnlineMedia($days));
            $pruned = $envelope->last(HandledStamp::class)?->getResult();
            assert($pruned instanceof PrunedOnlineMedia);

            $periodic += $pruned->periodic;
            $evidence += $pruned->evidence;
            $batches++;
        } while ($pruned->hasMore && $batches < self::MAX_BATCHES);

        $io->success(sprintf(
            'Deleted %d periodic photos older than %d days and %d other evidence objects older than %d days.',
            $periodic,
            $days,
            $evidence,
            max(PruneOnlineMediaHandler::EVIDENCE_RETENTION_DAYS, $days),
        ));

        return Command::SUCCESS;
    }
}
