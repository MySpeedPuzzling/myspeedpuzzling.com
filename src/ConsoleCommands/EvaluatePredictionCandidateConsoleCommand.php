<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Entity\ImageEstimateEvaluation;
use SpeedPuzzling\Web\Message\EvaluatePredictionCandidate;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation\GateRecordPrinter;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation\PredictionCandidateEvaluator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation\PredictionGroupAccumulator;
use SpeedPuzzling\Web\Value\PredictorKind;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Gate v2 for a candidate time model stored side by side (docs/features/insights/image-estimate-gates.md "Candidate
 * time models"): measures it against a reference and stores its gate record, then prints every size group with the
 * verdict. E.g. `--candidate=statistical:4 --reference=statistical:3`.
 */
#[AsCommand(
    'myspeedpuzzling:evaluate-prediction-candidate',
    'Measures a candidate prediction (predictor:model version) stored side by side against a reference per size group - out-of-period honesty and skill per k bucket - and stores its gate record.',
)]
final class EvaluatePredictionCandidateConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('candidate', null, InputOption::VALUE_REQUIRED, 'The candidate: <predictor>:<model version>, e.g. statistical:4');
        $this->addOption('reference', null, InputOption::VALUE_REQUIRED, 'The reference: <predictor>:<model version>, e.g. statistical:3');
        $this->addOption('solved-since-months', null, InputOption::VALUE_REQUIRED, 'Solves of the last N months', '12');
        $this->addOption('newer-than-months', null, InputOption::VALUE_REQUIRED, 'Only puzzles added in the last N months (default: every puzzle)');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the stored record as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        [$candidate, $candidateVersion] = self::predictor($input->getOption('candidate'), 'candidate');
        [$reference, $referenceVersion] = self::predictor($input->getOption('reference'), 'reference');
        $solvedSince = $input->getOption('solved-since-months');
        $newerThan = $input->getOption('newer-than-months');

        $evaluation = $this->messageBus->dispatch(new EvaluatePredictionCandidate(
            $candidate,
            $candidateVersion,
            $reference,
            $referenceVersion,
            max(1, is_string($solvedSince) ? (int) $solvedSince : 12),
            is_string($newerThan) ? max(1, (int) $newerThan) : null,
        ))->last(HandledStamp::class)?->getResult();
        assert($evaluation instanceof ImageEstimateEvaluation);

        if ($input->getOption('json') === true) {
            $output->writeln(GateRecordPrinter::json($evaluation));

            return self::SUCCESS;
        }

        $io->title(sprintf('%s - gate v%d, out-of-period split %s', $evaluation->subject, $evaluation->gateVersion, $evaluation->splitAt?->format('Y-m-d') ?? '-'));
        $rows = [];

        foreach ([['all', $evaluation->overall], ...array_map(static fn (array $group): array => [$group['sizeGroup'], $group], $evaluation->groups)] as [$size, $metrics]) {
            /** @var array<string, mixed> $metrics */
            $failures = $size === 'all' ? null : PredictionCandidateEvaluator::failures($metrics);
            $buckets = [];

            foreach (PredictionGroupAccumulator::K_BUCKETS as $bucket) {
                $numbers = GateRecordPrinter::bucketSkill($metrics, $bucket);

                if ($numbers !== '-') {
                    $buckets[] = $bucket . ': ' . $numbers;
                }
            }

            $rows[] = [
                $size,
                GateRecordPrinter::show($metrics['rows'] ?? null),
                GateRecordPrinter::honesty($metrics),
                implode("\n", $buckets),
                GateRecordPrinter::slice($metrics, 'live') . "\n" . GateRecordPrinter::slice($metrics, 'reconstructed'),
                $failures === null ? '' : ($failures === [] ? 'PASS' : 'fail: ' . implode('; ', $failures)),
            ];
        }

        $io->table(['size', 'rows', 'honesty 50 % / 80 % (Wilson)', 'candidate − reference error per k', 'live / reconstructed', 'gate'], $rows);
        $io->success(sprintf('Gate record %s stored.', $evaluation->id->toString()));

        return self::SUCCESS;
    }

    /**
     * @return array{0: PredictorKind, 1: int}
     */
    private static function predictor(mixed $option, string $name): array
    {
        $parts = is_string($option) ? explode(':', $option) : [];
        $predictor = PredictorKind::tryFrom($parts[0] ?? '');

        if (count($parts) !== 2 || $predictor === null || !ctype_digit($parts[1])) {
            throw new InvalidOptionException(sprintf('--%s must be <predictor>:<model version> (predictor one of %s)', $name, implode(', ', array_column(PredictorKind::cases(), 'value'))));
        }

        return [$predictor, (int) $parts[1]];
    }
}
