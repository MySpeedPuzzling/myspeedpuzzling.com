<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\RebuildPuzzleSearchKeys;
use SpeedPuzzling\Web\Query\GetPuzzleSearchKeyDrift;
use SpeedPuzzling\Web\Query\GetPuzzlesForSearchKeys;
use SpeedPuzzling\Web\Value\SearchText;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Run once after the release that added the keys (and its old containers are gone - they create puzzles without
 * keys), after a puzzle was inserted by SQL (docs/ravensburger-puzzle-month.md), and whenever SearchText::VERSION
 * changes (docs/features/puzzle-names/README.md, "Search"). Nightly on lily with --alert-on-drift as the safety net:
 * normally it changes nothing - a changed key means some write went around the entity, or a release that changes the
 * keys was not followed by a run (docs/features/puzzle-names/codes-help-and-check-digit.md, "Rollout and safety").
 */
#[AsCommand(
    'myspeedpuzzling:rebuild-puzzle-search-keys',
    'Builds the search keys (search_names, search_codes) of every puzzle again. Safe to interrupt and to run again.',
)]
final class RebuildPuzzleSearchKeysConsoleCommand extends Command
{
    // How many puzzle ids the drift warning names
    private const int ALERT_SAMPLE_SIZE = 20;

    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private GetPuzzlesForSearchKeys $getPuzzlesForSearchKeys,
        readonly private GetPuzzleSearchKeyDrift $getPuzzleSearchKeyDrift,
        readonly private EntityManagerInterface $entityManager,
        readonly private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Puzzles per transaction', '500')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count the keys that would change, write nothing')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'With --dry-run: path of a CSV with every key that would change, stored and new')
            ->addOption('alert-on-drift', null, InputOption::VALUE_NONE, 'Log a warning (Sentry) when any key changed - the nightly safety net');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $batchOption */
        $batchOption = $input->getOption('batch');
        $batchSize = max(1, (int) $batchOption);
        $dryRun = $input->getOption('dry-run') === true;
        /** @var null|string $reportPath */
        $reportPath = $input->getOption('report');

        if ($reportPath !== null && $dryRun === false) {
            $io->error('--report needs --dry-run: it lists what a run would change.');

            return self::INVALID;
        }

        $report = null;

        if ($reportPath !== null) {
            $report = @fopen($reportPath, 'wb');

            if ($report === false) {
                $io->error(sprintf('The report "%s" cannot be written.', $reportPath));

                return self::FAILURE;
            }

            fputcsv($report, ['puzzle_id', 'stored_search_names', 'new_search_names', 'stored_search_codes', 'new_search_codes'], escape: '');
        }

        $io->writeln(sprintf('Search fold version %d%s', SearchText::VERSION, $dryRun ? ' - dry run, nothing is written' : ''));

        $progressBar = new ProgressBar($output, $this->getPuzzlesForSearchKeys->count());
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% %memory:6s%');

        /** @var list<string> $changedIds */
        $changedIds = [];
        $afterId = null;

        while (($puzzleIds = $this->getPuzzlesForSearchKeys->idsAfter($afterId, $batchSize)) !== []) {
            if ($dryRun) {
                foreach ($this->getPuzzleSearchKeyDrift->forIds($puzzleIds) as $drift) {
                    $changedIds[] = $drift->puzzleId;

                    if ($report !== null) {
                        fputcsv($report, [
                            $drift->puzzleId,
                            self::readable($drift->storedNames),
                            self::readable($drift->expectedNames),
                            self::readable($drift->storedCodes),
                            self::readable($drift->expectedCodes),
                        ], escape: '');
                    }
                }
            } else {
                $envelope = $this->messageBus->dispatch(new RebuildPuzzleSearchKeys($puzzleIds));

                /** @var list<string> $changedInBatch */
                $changedInBatch = $envelope->last(HandledStamp::class)?->getResult() ?? [];
                $changedIds = [...$changedIds, ...$changedInBatch];

                // The doctrine_transaction middleware has committed the batch and the loop holds plain ids only: the
                // identity map stays at one batch, so memory does not grow over the whole catalogue. Not in the
                // handler - the middleware flushes after it returns, a clear there would throw the changes away.
                $this->entityManager->clear();
            }

            $afterId = $puzzleIds[array_key_last($puzzleIds)];
            $progressBar->advance(count($puzzleIds));
        }

        $progressBar->finish();
        $io->newLine(2);

        if ($report !== null) {
            fclose($report);
        }

        $withoutNameKey = $this->getPuzzlesForSearchKeys->countWithoutNameKey();

        $io->table(
            [$dryRun ? 'Keys that would change' : 'Keys changed', 'Puzzles without a name key'],
            [[count($changedIds), $withoutNameKey]],
        );

        if ($dryRun) {
            $io->success(sprintf('Dry run: nothing was written.%s', $reportPath !== null ? sprintf(' Report: %s.', $reportPath) : ''));

            return self::SUCCESS;
        }

        if ($changedIds !== [] && $input->getOption('alert-on-drift') === true) {
            $this->logger->warning('Puzzle search keys were out of date and were rebuilt - a write went around the Puzzle entity, or a release that changes the keys was not followed by a rebuild', [
                'changed' => count($changedIds),
                'puzzle_ids' => array_slice($changedIds, 0, self::ALERT_SAMPLE_SIZE),
            ]);
        }

        if ($withoutNameKey > 0) {
            $io->error(sprintf(
                '%d puzzles still have no name key: added while this ran, by the previous release during a deploy or by SQL. Run it again.',
                $withoutNameKey,
            ));

            return self::FAILURE;
        }

        $io->success('Every puzzle has its search keys.');

        return self::SUCCESS;
    }

    /**
     * A key on one CSV line: its lines joined by " | "
     */
    private static function readable(null|string $key): string
    {
        return $key === null ? '' : implode(' | ', explode("\n", trim($key, "\n")));
    }
}
