<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\DetectDuplicatePuzzleSignals;
use SpeedPuzzling\Web\Services\DuplicateResults\DailyDuplicateDetection;
use SpeedPuzzling\Web\Results\DuplicatePuzzleSignalDetectionSummary;
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
    description: 'Store new duplicate result cases (same person, puzzle and time), close the ones that no longer match, remove the certain copies (Tier A), then the duplicate puzzle signals for admins',
)]
final class DetectDuplicateResultsConsoleCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly DailyDuplicateDetection $dailyDuplicateDetection,
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

        // The detection, then every certain copy removed in a message of its own (a failing one is logged and skipped)
        $summary = $this->dailyDuplicateDetection->run($detectedBy);

        (new SymfonyStyle($input, $output))->success(sprintf(
            'Candidates: %d, new cases: %d, gone: %d, removed automatically: %d, removals failed: %d',
            $summary->candidates,
            $summary->newCases,
            $summary->goneCases,
            $summary->autoRemoved,
            $summary->autoRemovalsFailed,
        ));

        // After the removals: the same time on two different puzzles is no duplicate result but a merge hint (Layer 4)
        $envelope = $this->messageBus->dispatch(new DetectDuplicatePuzzleSignals());

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        /** @var DuplicatePuzzleSignalDetectionSummary $signals */
        $signals = $handledStamp->getResult();

        (new SymfonyStyle($input, $output))->success(sprintf(
            'Duplicate puzzle signals: %d pairs, new: %d, removed: %d',
            $signals->pairs,
            $signals->newSignals,
            $signals->removedSignals,
        ));

        return Command::SUCCESS;
    }
}
