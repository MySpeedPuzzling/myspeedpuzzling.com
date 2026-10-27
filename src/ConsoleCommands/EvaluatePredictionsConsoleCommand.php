<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PredictionEvaluator;
use SpeedPuzzling\Web\Value\CandidateFeatures;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read only: measures the prediction model on a time split (docs/features/puzzle-intelligence/README.md, "Step 8",
 * evaluation). Heavy (every first attempt in memory, ~1-2 GB on the production copy) - run it on a copy, not on the
 * web container in production hours.
 */
#[AsCommand(
    'myspeedpuzzling:evaluate-predictions',
    'Fits the prediction model on what was solved before a date and measures it on everything after (accuracy, window calibration).',
)]
final class EvaluatePredictionsConsoleCommand extends Command
{
    public function __construct(
        readonly private PredictionEvaluator $evaluator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('fit-before', null, InputOption::VALUE_REQUIRED, 'Fit on solves before this date (Y-m-d), evaluate on the solves from it on');
        $this->addOption('until', null, InputOption::VALUE_REQUIRED, 'Evaluate on solves before this date only (Y-m-d)');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the whole result as JSON (with the fitted parameters)');
        $this->addOption('candidate', null, InputOption::VALUE_NONE, 'Also fit the candidate model (v4) and compare it with the shown model and its blend on the same first tries and repeats');
        $this->addOption('candidate-off', null, InputOption::VALUE_REQUIRED, 'Comma-separated candidate parts to switch off (' . implode(', ', array_keys(CandidateFeatures::NAMES)) . ')');
        $this->addOption('ablation', null, InputOption::VALUE_OPTIONAL, 'Refit the candidate with every part (the not kept ones too) and without each of them (or only the listed ones, comma-separated) and compare', false);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fitBefore = $input->getOption('fit-before');
        $until = $input->getOption('until');

        if (!is_string($fitBefore) || DateTimeImmutable::createFromFormat('!Y-m-d', $fitBefore) === false) {
            $io->error('--fit-before=Y-m-d is required');

            return self::INVALID;
        }

        $untilDate = is_string($until) ? DateTimeImmutable::createFromFormat('!Y-m-d', $until) : null;
        $untilDate = $untilDate !== false ? $untilDate : null;
        $ablation = $input->getOption('ablation');

        if ($ablation !== false) {
            $only = is_string($ablation) && $ablation !== '' ? array_map('trim', explode(',', $ablation)) : [];
            $result = $this->evaluator->ablation(new DateTimeImmutable($fitBefore), $untilDate, $only);

            return $this->printRows($io, $output, $input, $result, $result['rows'], 'Candidate ablation (fitted before ' . $fitBefore . ')');
        }

        if ($input->getOption('candidate') === true) {
            $off = $input->getOption('candidate-off');
            // The kept parts (CandidateFeatures::NOT_KEPT stay off), less the listed ones
            $features = is_string($off) && $off !== '' ? CandidateFeatures::without([...CandidateFeatures::NOT_KEPT, ...array_map('trim', explode(',', $off))]) : CandidateFeatures::kept();
            $result = $this->evaluator->candidate(new DateTimeImmutable($fitBefore), $untilDate, $features);

            if ($input->getOption('json') === true) {
                $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            $this->printRows($io, $output, $input, $result, $result['first_try'], 'First tries: shown (statistical), blend, candidate');
            $this->printRows($io, $output, $input, $result, $result['repeat'], 'Repeats: shown, candidate');
            $this->printRows($io, $output, $input, $result, $result['unboxed'], 'Unboxed first tries: shown, candidate');
            $output->writeln(json_encode($result['candidate_parameters']['summary'] ?? [], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $result = $this->evaluator->evaluate(new DateTimeImmutable($fitBefore), $untilDate);

        if ($input->getOption('json') === true) {
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach (['first_try' => 'First tries', 'repeat' => 'Repeats'] as $key => $title) {
            $io->section($title);
            $rows = $result[$key];
            $headers = ['group', 'n', 'coverage', 'mae', 'median_bias', 'hit50', 'width50', 'hit80', 'width80'];
            $io->table($headers, array_map(
                static fn (array $row): array => array_map(static fn (string $column): string => (string) ($row[$column] ?? ''), $headers),
                $rows,
            ));
        }

        $io->section('Fitted on the solves before ' . $fitBefore);
        $output->writeln(json_encode($result['parameters']['summary'] ?? [], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $output->writeln(sprintf('tau %s · thin sizes %s', json_encode($result['parameters']['tau'] ?? null), json_encode($result['parameters']['thin_sizes'] ?? [])));
        $io->success(sprintf('Done in %.1f s', $result['seconds']));

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $result
     * @param list<array<string, int|float|string>> $rows
     */
    private function printRows(SymfonyStyle $io, OutputInterface $output, InputInterface $input, array $result, array $rows, string $title): int
    {
        if ($input->getOption('json') === true && $input->getOption('candidate') !== true) {
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $headers = [];

        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                $headers[$column] = true;
            }
        }

        $headers = array_keys($headers);
        $io->section($title);
        $io->table($headers, array_map(
            static fn (array $row): array => array_map(static fn (string $column): string => (string) ($row[$column] ?? ''), $headers),
            $rows,
        ));

        return self::SUCCESS;
    }
}
