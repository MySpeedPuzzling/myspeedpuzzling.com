<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\SnapshotActivityDailySummary;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'myspeedpuzzling:snapshot-activity-summary',
    description: 'Aggregate one UTC day of player activity into activity_daily_summary (default: yesterday)',
)]
final class SnapshotActivityDailySummaryCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('day', InputArgument::OPTIONAL, 'UTC day to snapshot (Y-m-d); useful for backfills');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var null|string $day */
        $day = $input->getArgument('day');

        if ($day !== null && \DateTimeImmutable::createFromFormat('Y-m-d', $day) === false) {
            $io->error('Day must be in Y-m-d format.');

            return Command::FAILURE;
        }

        $envelope = $this->messageBus->dispatch(new SnapshotActivityDailySummary($day));

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        /** @var int $rows */
        $rows = $handledStamp->getResult();

        $io->success(sprintf('Wrote %d summary rows for %s.', $rows, $day ?? 'yesterday (UTC)'));

        return Command::SUCCESS;
    }
}
