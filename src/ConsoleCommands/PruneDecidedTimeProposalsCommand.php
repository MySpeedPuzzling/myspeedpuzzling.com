<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\PruneDecidedTimeProposals;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'myspeedpuzzling:prune-decided-time-proposals',
    description: 'Delete competitors\' time proposals decided more than the given number of days ago (pending ones are kept)',
)]
final class PruneDecidedTimeProposalsCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('days', InputArgument::OPTIONAL, 'Delete proposals decided more than this many days ago', '90');
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

        $envelope = $this->messageBus->dispatch(new PruneDecidedTimeProposals($days));

        $deleted = $envelope->last(HandledStamp::class)?->getResult();
        assert(is_int($deleted));

        $io->success(sprintf('Deleted %d time proposals decided more than %d days ago.', $deleted, $days));

        return Command::SUCCESS;
    }
}
