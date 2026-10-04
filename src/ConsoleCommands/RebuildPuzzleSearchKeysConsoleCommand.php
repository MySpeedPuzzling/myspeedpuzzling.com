<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Message\RebuildPuzzleSearchKeys;
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
 * keys), and again whenever SearchText::VERSION changes (docs/features/puzzle-names/README.md, "Search").
 */
#[AsCommand(
    'myspeedpuzzling:rebuild-puzzle-search-keys',
    'Builds the search keys (search_names, search_codes) of every puzzle again. Safe to interrupt and to run again.',
)]
final class RebuildPuzzleSearchKeysConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private GetPuzzlesForSearchKeys $getPuzzlesForSearchKeys,
        readonly private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Puzzles per transaction', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $batchOption */
        $batchOption = $input->getOption('batch');
        $batchSize = max(1, (int) $batchOption);

        $io->writeln(sprintf('Search fold version %d', SearchText::VERSION));

        $progressBar = new ProgressBar($output, $this->getPuzzlesForSearchKeys->count());
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% %memory:6s%');

        $changed = 0;
        $afterId = null;

        while (($puzzleIds = $this->getPuzzlesForSearchKeys->idsAfter($afterId, $batchSize)) !== []) {
            $envelope = $this->messageBus->dispatch(new RebuildPuzzleSearchKeys($puzzleIds));

            /** @var int $changedInBatch */
            $changedInBatch = $envelope->last(HandledStamp::class)?->getResult() ?? 0;
            $changed += $changedInBatch;

            // The doctrine_transaction middleware has committed the batch and the loop holds plain ids only: the
            // identity map stays at one batch, so memory does not grow over the whole catalogue. Not in the handler
            // - the middleware flushes after it returns, a clear there would throw the changes away.
            $this->entityManager->clear();

            $afterId = $puzzleIds[array_key_last($puzzleIds)];
            $progressBar->advance(count($puzzleIds));
        }

        $progressBar->finish();
        $io->newLine(2);

        $withoutNameKey = $this->getPuzzlesForSearchKeys->countWithoutNameKey();

        $io->table(['Keys changed', 'Puzzles without a name key'], [[$changed, $withoutNameKey]]);

        if ($withoutNameKey > 0) {
            $io->error('Some puzzles have no name key - added meanwhile by an older release? Run it again.');

            return self::FAILURE;
        }

        $io->success('Every puzzle has its search keys.');

        return self::SUCCESS;
    }
}
