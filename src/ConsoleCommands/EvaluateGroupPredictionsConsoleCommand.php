<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use DateTimeImmutable;
use SpeedPuzzling\Web\Message\EvaluateGroupPredictions;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PeakMemory;
use SpeedPuzzling\Web\Value\GroupModelParameters;
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
 * The pair/team model on a time split (docs/features/puzzle-intelligence/README.md "Pair and team predictions"): fitted
 * on the results solved before --fit-before, scored on the ones after it. Read only.
 */
#[AsCommand(
    'myspeedpuzzling:evaluate-group-predictions',
    'Fits the pair/team prediction model on the results before a date and measures it on the ones after it (read only).',
)]
final class EvaluateGroupPredictionsConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('fit-before', null, InputOption::VALUE_REQUIRED, 'Fit on the results solved before this date (YYYY-MM-DD)');
        $this->addOption('until', null, InputOption::VALUE_REQUIRED, 'Score only the results solved before this date');
        $this->addOption('off', null, InputOption::VALUE_REQUIRED, 'Parts to switch off, comma separated: ' . implode(', ', GroupModelParameters::PARTS));
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the numbers as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fitBefore = self::date($input->getOption('fit-before'), 'fit-before');

        if ($fitBefore === null) {
            throw new InvalidOptionException('--fit-before=YYYY-MM-DD is required');
        }

        $offOption = $input->getOption('off');
        $off = is_string($offOption) && $offOption !== '' ? array_map('trim', explode(',', $offOption)) : [];
        $unknown = array_diff($off, GroupModelParameters::PARTS);

        if ($unknown !== []) {
            throw new InvalidOptionException(sprintf('Unknown part(s) %s - one of %s', implode(', ', $unknown), implode(', ', GroupModelParameters::PARTS)));
        }

        /** @var array{parameters: GroupModelParameters, groups: array<string, array<string, null|int|float>>, reliability: array<string, array<string, null|int|float>>, seconds: array{load: float, fit_and_score: float}} $result */
        $result = $this->messageBus->dispatch(new EvaluateGroupPredictions($fitBefore, self::date($input->getOption('until'), 'until'), $off))->last(HandledStamp::class)?->getResult();
        $memory = ['resident_peak_mb' => PeakMemory::residentMegabytes(), 'php_peak_mb' => round(memory_get_peak_usage(true) / 1048576)];

        if ($input->getOption('json') === true) {
            $output->writeln(json_encode(['parameters' => $result['parameters']->toArray(), 'groups' => $result['groups'], 'reliability' => $result['reliability'], 'memory' => $memory, 'seconds' => $result['seconds']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

            return self::SUCCESS;
        }

        $io->title(sprintf('Pair/team model fitted before %s%s', $fitBefore->format('Y-m-d'), $off !== [] ? ' without ' . implode(', ', $off) : ''));
        $io->table(['group', 'n', 'model', 'bias', 'rule alone', '50 % / 80 % hits', 'width 50 %', 'reference n', 'reference vs model', 'reference hits'], array_map(
            static fn (string $group, array $numbers): array => [
                $group,
                $numbers['rows'],
                $numbers['error'],
                $numbers['bias'],
                $numbers['ruleError'],
                sprintf('%s / %s', self::percent($numbers['window50Hit']), self::percent($numbers['window80Hit'])),
                self::percent($numbers['window50Width']),
                $numbers['referenceRows'],
                $numbers['referenceRows'] > 0 ? sprintf('%s vs %s', $numbers['referenceError'], $numbers['errorOnReferenceRows']) : '-',
                $numbers['referenceRows'] > 0 ? sprintf('%s / %s', self::percent($numbers['referenceWindow50Hit']), self::percent($numbers['referenceWindow80Hit'])) : '-',
            ],
            array_keys($result['groups']),
            $result['groups'],
        ));
        $io->table(['synergy reliability (teams with 8+ results)', 'teams', 'split-half', 'Spearman-Brown', 'SD of means'], array_map(
            static fn (string $subset, array $numbers): array => [$subset, $numbers['teams'], $numbers['splitHalf'] ?? '-', $numbers['spearmanBrown'] ?? '-', $numbers['sdOfTeamMeans'] ?? '-'],
            array_keys($result['reliability']),
            $result['reliability'],
        ));
        $io->table(['fit', 'value'], array_map(static fn (string $key, int|float|string $value): array => [$key, $value], array_keys($result['parameters']->summary), $result['parameters']->summary));
        $io->writeln(sprintf('Peak memory: %s MB resident, %d MB PHP; load %.1f s, fit and score %.1f s', $memory['resident_peak_mb'] ?? '-', $memory['php_peak_mb'], $result['seconds']['load'], $result['seconds']['fit_and_score']));

        return self::SUCCESS;
    }

    private static function date(mixed $value, string $name): null|DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false) {
            throw new InvalidOptionException(sprintf('--%s must be YYYY-MM-DD', $name));
        }

        return $date;
    }

    private static function percent(null|int|float $value): string
    {
        return $value !== null ? sprintf('%.1f %%', $value * 100) : '-';
    }
}
