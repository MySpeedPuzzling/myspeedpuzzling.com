<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\FitPredictionModel;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    'myspeedpuzzling:fit-prediction-model',
    'Fits the prediction model (priors, tau, thin sizes, window calibration) on the last 12 months and rewrites the drift with it. Daily.',
)]
final class FitPredictionModelConsoleCommand extends Command
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

        /** @var array{prior_groups: int, prior_puzzles: int, priors_seconds: float, drift_rows_written: int, parameters: PredictionModelParameters, candidate: CandidateModelParameters, candidate_evidence_written: int} $result */
        $result = $this->messageBus->dispatch(new FitPredictionModel())->last(HandledStamp::class)?->getResult();

        $io->table(['Setting', 'Value'], [
            ['tau', $result['parameters']->tau],
            ['thin sizes', json_encode($result['parameters']->thinSizes, JSON_THROW_ON_ERROR)],
            ['prior groups', $result['prior_groups']],
            ['puzzles with a share or a prior', $result['prior_puzzles']],
            ['priors written in (s)', $result['priors_seconds']],
            ['drift rows written', $result['drift_rows_written']],
            ...array_map(static fn (string $key, int|float|string $value): array => [$key, $value], array_keys($result['parameters']->summary), $result['parameters']->summary),
            // The candidate model (v4, never shown)
            ['candidate evidence rows written', $result['candidate_evidence_written']],
            ...array_map(static fn (string $key, int|float|string $value): array => ['candidate ' . $key, $value], array_keys($result['candidate']->summary), $result['candidate']->summary),
            ['peak memory (MB)', round(memory_get_peak_usage(true) / 1048576)],
        ]);
        $io->success(sprintf('Prediction model fitted in %.1f s', microtime(true) - $startedAt));

        return self::SUCCESS;
    }
}
