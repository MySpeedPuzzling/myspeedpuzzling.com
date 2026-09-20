<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\BackfillPuzzlingTeams;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand('myspeedpuzzling:backfill-puzzling-teams', 'Gives every group time without a pair/team its team. Safe to interrupt and to run again.')]
final class BackfillPuzzlingTeamsConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Times per transaction', '1000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $batchOption */
        $batchOption = $input->getOption('batch');
        $batchSize = max(1, (int) $batchOption);
        $batches = 0;
        $afterTimeId = null;

        do {
            $envelope = $this->messageBus->dispatch(new BackfillPuzzlingTeams($batchSize, $afterTimeId));

            /** @var null|string $afterTimeId */
            $afterTimeId = $envelope->last(HandledStamp::class)?->getResult();

            if ($afterTimeId !== null) {
                $io->writeln(sprintf('Batch %d done…', ++$batches));
            }
        } while ($afterTimeId !== null);

        $io->success(sprintf('Done in %d batches of up to %d group times.', $batches, $batchSize));

        return self::SUCCESS;
    }
}
