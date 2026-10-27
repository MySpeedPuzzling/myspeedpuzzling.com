<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\RefreshCandidatePredictionEvidence;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PeakMemory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    'myspeedpuzzling:refresh-candidate-prediction-evidence',
    'Refreshes every puzzle\'s candidate prediction evidence (v4, never shown) with the stored candidate fit - only changed rows. Every 15 minutes, after myspeedpuzzling:recalculate-puzzle-intelligence; nothing before the first candidate fit.',
)]
final class RefreshCandidatePredictionEvidenceConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $startedAt = microtime(true);

        /** @var array{evidence_written: int, fitted: bool} $result */
        $result = $this->messageBus->dispatch(new RefreshCandidatePredictionEvidence())->last(HandledStamp::class)?->getResult();

        if ($result['fitted'] === false) {
            // Not a failure: the cron may run before the first candidate fit
            $io->note('The candidate prediction model is not fitted yet (myspeedpuzzling:fit-candidate-prediction-model) - nothing to refresh.');

            return self::SUCCESS;
        }

        $io->writeln(sprintf(
            'Candidate evidence: %d rows written in %.1f s, peak memory %d MB PHP / %s MB resident',
            $result['evidence_written'],
            microtime(true) - $startedAt,
            round(memory_get_peak_usage(true) / 1048576),
            PeakMemory::residentMegabytes() ?? '-',
        ));

        return self::SUCCESS;
    }
}
