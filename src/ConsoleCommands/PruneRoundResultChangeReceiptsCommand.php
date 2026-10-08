<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\PruneRoundResultChangeReceipts;
use SpeedPuzzling\Web\Results\PrunedChangeReceipts;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'myspeedpuzzling:prune-round-result-change-receipts',
    description: 'Delete the receipts of official results changes and of participants sheet change sets older than the given number of days',
)]
final class PruneRoundResultChangeReceiptsCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('days', InputArgument::OPTIONAL, 'Delete receipts older than this many days', '90');
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

        $envelope = $this->messageBus->dispatch(new PruneRoundResultChangeReceipts($days));

        $pruned = $envelope->last(HandledStamp::class)?->getResult();
        assert($pruned instanceof PrunedChangeReceipts);

        $io->success(sprintf(
            'Deleted %d receipts of official results changes and %d receipts of participants sheet change sets older than %d days.',
            $pruned->roundResultReceipts,
            $pruned->participantSheetReceipts,
            $days,
        ));

        return Command::SUCCESS;
    }
}
