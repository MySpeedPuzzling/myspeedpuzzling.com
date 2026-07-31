<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\PrunePlayerActivity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'myspeedpuzzling:prune-player-activity',
    description: 'Delete raw player activity rows older than specified number of months',
)]
final class PrunePlayerActivityCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('months', InputArgument::OPTIONAL, 'Delete entries older than this many months', '24');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $monthsArg */
        $monthsArg = $input->getArgument('months');
        $months = (int) $monthsArg;

        if ($months < 1) {
            $io->error('Months must be a positive integer.');

            return Command::FAILURE;
        }

        $envelope = $this->messageBus->dispatch(new PrunePlayerActivity($months));

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        /** @var int $deleted */
        $deleted = $handledStamp->getResult();

        $io->success("Deleted {$deleted} player activity rows older than {$months} months.");

        return Command::SUCCESS;
    }
}
