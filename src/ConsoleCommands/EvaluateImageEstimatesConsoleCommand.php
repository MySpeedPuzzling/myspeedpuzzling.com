<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Entity\ImageEstimateEvaluation;
use SpeedPuzzling\Web\Message\EvaluateImageEstimates;
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
            $output->writeln(json_encode([
                'id' => $evaluation->id->toString(),
                'evaluatedAt' => $evaluation->evaluatedAt->format(DATE_ATOM),
                'modelVersion' => $evaluation->modelVersion,
                'newerThan' => $evaluation->newerThan->format(DATE_ATOM),
                'measuredMinIndices' => $evaluation->measuredMinIndices,
                'overall' => $evaluation->overall,
                'byK' => $evaluation->byK,
                'groups' => $evaluation->groups,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION));

            return self::SUCCESS;
        }

        $io->title(sprintf('Image estimates - puzzles added since %s, k = 0..4', $evaluation->newerThan->format('Y-m-d')));

        $generations = $evaluation->overall['estimateModels'] ?? [];

        if (is_array($generations) && $generations !== []) {
            $io->text('Estimate generations (image rows): ' . implode(', ', array_map(
                static fn (int|string $model, mixed $rows): string => sprintf('%s: %s', $model, is_int($rows) ? $rows : '?'),
                array_keys($generations),
                $generations,
            )));
        }

        $rows = [];

        foreach ([['all', 'all', $evaluation->overall], ...array_map(static fn (array $group): array => [$group['sizeGroup'], $group['imageKind'], $group], $evaluation->groups)] as [$size, $kind, $metrics]) {
            /** @var array<string, mixed> $metrics */
            $failures = $size === 'all' ? null : ImageEstimateGate::failures($metrics);
            $rows[] = [
                $size,
                $kind,
                self::show($metrics['rows'] ?? null),
                self::show($metrics['measuredPuzzles'] ?? null),
                self::show($metrics['spearman'] ?? null),
                self::show($metrics['tierExact'] ?? null) . ' / ' . self::show($metrics['tierWithinOne'] ?? null) . ' (avg ' . self::show($metrics['alwaysAverage'] ?? null) . ')',
                self::show($metrics['maeImage'] ?? null) . ' / ' . self::show($metrics['maeBlend'] ?? null) . ' / ' . self::show($metrics['maeStatistical'] ?? null),
                self::show($metrics['window50Hit'] ?? null) . ' / ' . self::show($metrics['window50Width'] ?? null),
                self::show($metrics['window80Hit'] ?? null) . ' / ' . self::show($metrics['window80Width'] ?? null),
                $failures === null ? '' : ($failures === [] ? 'PASS' : 'fail: ' . implode('; ', $failures)),
            ];
        }

        $io->table(['size', 'kind', 'rows', 'measured', 'Spearman', 'tier exact / ±1', 'MAE image / blend / stat', '50 % hit / width', '80 % hit / width', 'gate'], $rows);

        $kRows = array_map(static fn (array $bucket): array => [
            self::show($bucket['k'] ?? null),
            self::show($bucket['rows'] ?? null),
            self::show($bucket['maeImage'] ?? null) . ' / ' . self::show($bucket['maeBlend'] ?? null) . ' / ' . self::show($bucket['maeStatistical'] ?? null),
            self::show($bucket['window50Hit'] ?? null),
            self::show($bucket['blendWindow50Hit'] ?? null),
        ], $evaluation->byK);
        $io->table(['k', 'rows', 'MAE image / blend / stat', 'image 50 % hit', 'blend 50 % hit'], $kRows);

        $io->success(sprintf('Gate record %s stored.', $evaluation->id->toString()));

        return self::SUCCESS;
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            is_float($value) => sprintf('%.3f', $value),
            is_int($value), is_string($value) => (string) $value,
            default => '-',
        };
    }
}
