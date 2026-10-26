<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\PruneReadNotifications;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'myspeedpuzzling:prune-read-notifications',
    description: 'Delete read "a puzzler you follow added a time" notifications older than the given number of days',
)]
final class PruneReadNotificationsCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('days', InputArgument::OPTIONAL, 'Delete read notifications older than this many days', '90');
        $this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows per transaction', '50000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $daysArg */
        $daysArg = $input->getArgument('days');
        $days = (int) $daysArg;
        /** @var string $batchSizeOption */
        $batchSizeOption = $input->getOption('batch-size');
        $batchSize = (int) $batchSizeOption;

        if ($days < 30 || $batchSize < 1) {
            $io->error('Days must be at least 30 and the batch size a positive integer.');

            return Command::FAILURE;
        }

        // One message per batch: each runs in its own transaction
        $total = 0;
        do {
            $envelope = $this->messageBus->dispatch(new PruneReadNotifications($days, $batchSize));

            /** @var HandledStamp $handledStamp */
            $handledStamp = $envelope->last(HandledStamp::class);
            /** @var int $deleted */
            $deleted = $handledStamp->getResult();
            $total += $deleted;
        } while ($deleted === $batchSize);

        $io->success("Deleted {$total} read notifications older than {$days} days.");

        return Command::SUCCESS;
    }
}
