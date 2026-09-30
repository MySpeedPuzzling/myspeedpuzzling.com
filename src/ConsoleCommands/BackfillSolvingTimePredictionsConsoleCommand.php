<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Message\BackfillSolvingTimePredictions;
use SpeedPuzzling\Web\Query\GetPlayersWithPendingPredictions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

#[AsCommand(
    'myspeedpuzzling:backfill-solving-time-predictions',
    'Reconstructs the prediction of every solo time that has none yet, one player at a time. Safe to interrupt and to run again; daily it heals whatever the live recording missed.',
)]
final class BackfillSolvingTimePredictionsConsoleCommand extends Command
{
    private const int MAX_CONSECUTIVE_FAILURES = 10;
    private const int COLLECT_GARBAGE_EVERY = 50;

    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private GetPlayersWithPendingPredictions $getPlayersWithPendingPredictions,
        readonly private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('player', null, InputOption::VALUE_REQUIRED, 'Only this player (uuid)');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many players');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var null|string $playerOption */
        $playerOption = $input->getOption('player');
        /** @var null|string $limitOption */
        $limitOption = $input->getOption('limit');

        $playerIds = $playerOption !== null ? [$playerOption] : $this->getPlayersWithPendingPredictions->all();

        if ($limitOption !== null) {
            $playerIds = array_slice($playerIds, 0, max(1, (int) $limitOption));
        }

        if ($playerIds === []) {
            $io->success('Nothing to do - every solo time with seconds has its prediction.');

            return self::SUCCESS;
        }

        $progressBar = new ProgressBar($output, count($playerIds));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');

        $players = 0;
        $times = 0;
        $failed = 0;
        $consecutiveFailures = 0;
        $aborted = false;

        foreach ($playerIds as $index => $playerId) {
            try {
                $envelope = $this->messageBus->dispatch(new BackfillSolvingTimePredictions($playerId));

                /** @var int $recorded */
                $recorded = $envelope->last(HandledStamp::class)?->getResult() ?? 0;
                $times += $recorded;
                $players++;
                $consecutiveFailures = 0;
            } catch (Throwable $e) {
                $failed++;
                $consecutiveFailures++;

                $cause = $e instanceof HandlerFailedException ? ($e->getPrevious() ?? $e) : $e;
                $io->writeln('');
                $io->warning(sprintf('Player %s: %s', $playerId, $cause->getMessage()));

                if ($consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                    $aborted = true;

                    break;
                }
            } finally {
                $progressBar->advance();

                // Safe here: the doctrine_transaction middleware has committed (or rolled back) this
                // player, and the loop holds plain ids only. Keeps the identity map at one player's
                // times, so neither memory nor flush time grow over thousands of players
                $this->entityManager->clear();

                if (($index + 1) % self::COLLECT_GARBAGE_EVERY === 0) {
                    gc_collect_cycles();
                }
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        $io->table(['Players done', 'Times evaluated', 'Players failed'], [[$players, $times, $failed]]);

        if ($aborted) {
            $io->error(sprintf('Aborted after %d consecutive failures - run it again once fixed, it continues where it stopped.', self::MAX_CONSECUTIVE_FAILURES));

            return self::FAILURE;
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
