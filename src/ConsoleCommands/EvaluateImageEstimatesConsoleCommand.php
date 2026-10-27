<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Entity\ImageEstimateEvaluation;
use SpeedPuzzling\Web\Message\EvaluateImageEstimates;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation\GateRecordPrinter;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\ImageEstimates\ImageEstimateGate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Measures the picture-based estimates on the stored side-by-side predictions and stores the gate record
 * (docs/features/insights/image-estimate-gates.md) - then prints every size group × image kind with the gate's verdict.
 */
#[AsCommand(
    'myspeedpuzzling:evaluate-image-estimates',
    'Measures the image / blend predictions against the statistical ones on newer puzzles (k = 0..4) per size group and image kind, and stores the gate record ImageEstimateGate reads.',
)]
final class EvaluateImageEstimatesConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('newer-than-months', null, InputOption::VALUE_REQUIRED, 'Newer puzzles = added in the last N months', '12');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the stored record as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $months = max(1, (int) (is_string($input->getOption('newer-than-months')) ? $input->getOption('newer-than-months') : 12));

        $evaluation = $this->messageBus->dispatch(new EvaluateImageEstimates($months))->last(HandledStamp::class)?->getResult();
        assert($evaluation instanceof ImageEstimateEvaluation);

        if ($input->getOption('json') === true) {
            $output->writeln(GateRecordPrinter::json($evaluation));

            return self::SUCCESS;
        }

        $io->title(sprintf(
            'Image estimates - puzzles added since %s, k = 0..4, gate v%d, out-of-period split %s',
            $evaluation->newerThan?->format('Y-m-d') ?? '-',
            $evaluation->gateVersion,
            $evaluation->splitAt?->format('Y-m-d') ?? '-',
        ));

        $generations = $evaluation->overall['estimateModels'] ?? [];

        if (is_array($generations) && $generations !== []) {
            $io->text('Estimate generations (image rows): ' . implode(', ', array_map(
                static fn (int|string $model, mixed $rows): string => sprintf('%s: %s', $model, is_int($rows) ? $rows : '?'),
                array_keys($generations),
                $generations,
            )));
        }

        $gate = [];
        $report = [];

        foreach ([['all', 'all', $evaluation->overall], ...array_map(static fn (array $group): array => [$group['sizeGroup'], $group['imageKind'], $group], $evaluation->groups)] as [$size, $kind, $metrics]) {
            /** @var array<string, mixed> $metrics */
            $failures = $size === 'all' ? null : ImageEstimateGate::failures($metrics);
            $gate[] = [
                $size,
                $kind,
                GateRecordPrinter::show($metrics['rows'] ?? null),
                GateRecordPrinter::show($metrics['measuredPuzzles'] ?? null),
                GateRecordPrinter::show($metrics['spearman'] ?? null),
                GateRecordPrinter::honesty($metrics),
                GateRecordPrinter::k0Skill($metrics),
                GateRecordPrinter::bucketSkill($metrics, '1-2') . ' / ' . GateRecordPrinter::bucketSkill($metrics, '3-4'),
                $failures === null ? '' : ($failures === [] ? 'PASS' : 'fail: ' . implode('; ', $failures)),
            ];
            $report[] = [
                $size,
                $kind,
                GateRecordPrinter::show($metrics['tierExact'] ?? null) . ' ± ' . GateRecordPrinter::show($metrics['tierExactSe'] ?? null) . ' (ceiling ' . GateRecordPrinter::show($metrics['tierExactCeiling'] ?? null) . ', avg ' . GateRecordPrinter::show($metrics['alwaysAverage'] ?? null) . ')',
                GateRecordPrinter::show($metrics['tierRangeCoverage'] ?? null) . ' (' . GateRecordPrinter::show($metrics['tierRangeMeanTiers'] ?? null) . ' tiers)',
                GateRecordPrinter::show($metrics['rpss'] ?? null),
                GateRecordPrinter::slice($metrics, 'k0'),
                GateRecordPrinter::slice($metrics, 'k0Measured'),
                GateRecordPrinter::slice($metrics, 'live'),
                GateRecordPrinter::slice($metrics, 'reconstructed'),
            ];
        }

        $io->section('Gate v2');
        $io->table(['size', 'kind', 'rows', 'measured', 'Spearman', 'honesty 50 % / 80 % (Wilson)', 'k = 0 error ratio, 80 % width', 'k = 1-2 / 3-4 blend − stat', 'gate'], $gate);

        $io->section('Reported, never gating (picture-only rows: error image / stat, 50 % / 80 % hit)');
        $io->table(['size', 'kind', 'tier exact ± SE', 'tier range', 'RPSS', 'k = 0 all', 'k = 0 measured', 'live', 'reconstructed'], $report);

        $io->success(sprintf('Gate record %s stored.', $evaluation->id->toString()));

        return self::SUCCESS;
    }
}
