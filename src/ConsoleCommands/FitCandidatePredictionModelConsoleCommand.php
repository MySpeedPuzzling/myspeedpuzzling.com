<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\FitCandidatePredictionModel;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PeakMemory;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    'myspeedpuzzling:fit-candidate-prediction-model',
    'Fits the candidate prediction model (never shown) on the last 12 months, with its brand priors, every puzzle\'s evidence, every player\'s taste states and adjusted paces. Daily, after myspeedpuzzling:fit-prediction-model.',
)]
final class FitCandidatePredictionModelConsoleCommand extends Command
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

        /** @var array{candidate: CandidateModelParameters, priors_written: int, evidence_written: int, taste_states_written: int, pace_offsets_written: int} $result */
        $result = $this->messageBus->dispatch(new FitCandidatePredictionModel())->last(HandledStamp::class)?->getResult();

        $io->table(['Setting', 'Value'], [
            ['brand priors', $result['priors_written']],
            ['evidence rows written', $result['evidence_written']],
            ['taste states written', $result['taste_states_written']],
            ['adjusted pace offsets written', $result['pace_offsets_written']],
            ...array_map(static fn (string $key, int|float|string $value): array => [$key, $value], array_keys($result['candidate']->summary), $result['candidate']->summary),
            ['peak memory PHP (MB)', round(memory_get_peak_usage(true) / 1048576)],
            ['peak memory resident (MB)', PeakMemory::residentMegabytes() ?? '-'],
        ]);
        $io->success(sprintf('Candidate prediction model fitted in %.1f s', microtime(true) - $startedAt));

        return self::SUCCESS;
    }
}
