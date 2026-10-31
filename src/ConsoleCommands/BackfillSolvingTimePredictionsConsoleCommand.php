<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Message\BackfillSolvingTimePredictions;
use SpeedPuzzling\Web\Query\GetPlayersWithPendingPredictions;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelParametersProvider;
use SpeedPuzzling\Web\Value\ShownPredictionModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
    'Reconstructs the prediction of every solo time that has none yet (shown, and side by side for the current model - the image / blend rows of first tries once their puzzle has an out-of-fold estimate, the candidate model\'s rows once it is fitted), one player at a time. Safe to interrupt and to run again; daily it heals whatever the live recording missed.',
)]
final class BackfillSolvingTimePredictionsConsoleCommand extends Command
{
    private const int MAX_CONSECUTIVE_FAILURES = 10;
    private const int COLLECT_GARBAGE_EVERY = 50;

    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private GetPlayersWithPendingPredictions $getPlayersWithPendingPredictions,
        readonly private EntityManagerInterface $entityManager,
        readonly private PredictionModelParametersProvider $parametersProvider,
        #[Autowire(param: 'predictionCandidateRows')]
        readonly private bool $candidateRows = false,
        // PREDICTION_SHOWN_MODEL: v7 = the candidate makes the shown predictions (its rows are always listed then)
        #[Autowire(param: 'predictionShownModel')]
        readonly private string $shownModel = 'v3',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('player', null, InputOption::VALUE_REQUIRED, 'Only this player (uuid)');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many players');
        $this->addOption('recompute-reconstructed', null, InputOption::VALUE_NONE, 'Also replace every reconstructed prediction (shown and side by side) with the current fit - live ones stay; once the model is fitted');
        $this->addOption('candidate', null, InputOption::VALUE_NONE, 'Also the candidate model\'s (v4, never shown) rows while PREDICTION_CANDIDATE_ROWS is off - the first manual fill before the flag goes on');
        $this->addOption('recompute-image', null, InputOption::VALUE_NONE, 'Also replace every reconstructed image / blend row older than the out-of-fold estimate its puzzle has now or than the current fit (after a new derive or fit, before myspeedpuzzling:evaluate-image-estimates); once the model is fitted');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var null|string $playerOption */
        $playerOption = $input->getOption('player');
        /** @var null|string $limitOption */
        $limitOption = $input->getOption('limit');

        $fittedAt = $this->parametersProvider->fittedAt();
        // The candidate model's rows (v4, never shown) once it is fitted and switched on (PREDICTION_CANDIDATE_ROWS) or
        // asked for (--candidate, the first manual backfill)
        $candidateRequested = (bool) $input->getOption('candidate');
        $candidateShown = ShownPredictionModel::fromSetting($this->shownModel)->isCandidate();
        $candidateFittedAt = $fittedAt !== null && ($this->candidateRows || $candidateRequested || $candidateShown) ? $this->parametersProvider->candidateFittedAt() : null;
        // The model of the shown predictions (the statistical one stands in before the candidate's first fit)
        $shown = ShownPredictionModel::effective($candidateShown && $candidateFittedAt !== null);
        $recompute = (bool) $input->getOption('recompute-reconstructed');
        $recomputeImage = (bool) $input->getOption('recompute-image');

        if ($fittedAt === null) {
            // Not a failure: the cron runs this before the first fit too - pending times still get their shown prediction
            $io->note('The prediction model is not fitted yet (myspeedpuzzling:fit-prediction-model): only pending times, no side-by-side rows' . ($recompute || $recomputeImage ? ', nothing recomputed.' : '.'));
            $recompute = false;
            $recomputeImage = false;
        }

        $playerIds = $playerOption !== null
            ? [$playerOption]
            : $this->getPlayersWithPendingPredictions->all(
                $recompute ? $fittedAt : null,
                withSideRows: $fittedAt !== null,
                recomputeImage: $recomputeImage,
                withCandidateRows: $candidateFittedAt !== null,
                recomputeCandidateBefore: $recompute ? $candidateFittedAt : null,
                recomputeShownBefore: $recompute ? ($shown->isCandidate() ? $candidateFittedAt : $fittedAt) : null,
                shownModelVersion: $shown->modelVersion(),
            );

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
                $envelope = $this->messageBus->dispatch(new BackfillSolvingTimePredictions($playerId, recomputeReconstructed: $recompute, recomputeImage: $recomputeImage, candidateRows: $candidateRequested));

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
