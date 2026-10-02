<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\DetectDuplicateResults;
use SpeedPuzzling\Web\Results\DuplicateDetectionSummary;
use SpeedPuzzling\Web\Value\DuplicateDetectedBy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'myspeedpuzzling:detect-duplicate-results',
    description: 'Store new duplicate result cases (same person, puzzle and time) and close the ones that no longer match',
)]
final class DetectDuplicateResultsConsoleCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('backfill', null, InputOption::VALUE_NONE, 'The first run over all existing results (cases are stored as detected by backfill)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $detectedBy = $input->getOption('backfill') === true ? DuplicateDetectedBy::Backfill : DuplicateDetectedBy::Cron;

        $envelope = $this->messageBus->dispatch(new DetectDuplicateResults($detectedBy));

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        /** @var DuplicateDetectionSummary $summary */
        $summary = $handledStamp->getResult();

        (new SymfonyStyle($input, $output))->success(sprintf(
            'Candidates: %d, new cases: %d, gone: %d',
            $summary->candidates,
            $summary->newCases,
            $summary->goneCases,
        ));

        return Command::SUCCESS;
    }
}
