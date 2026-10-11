<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Doctrine\Persistence\ManagerRegistry;
use SpeedPuzzling\Web\Exceptions\XpLaunchNotVerified;
use SpeedPuzzling\Web\Message\MarkXpLaunchReady;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\RecalculateXpForPlayer;
use SpeedPuzzling\Web\Query\GetAllPlayerIdsWithSolveTimes;
use SpeedPuzzling\Web\Query\GetXpLaunchVerification;
use SpeedPuzzling\Web\Results\XpLaunchVerification;
use SpeedPuzzling\Web\Services\Xp\XpLaunchReadiness;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * The XP launch, in one run (docs/features/xp-levels/launch-runbook.md): rebuild every player's XP ledger from their
 * whole solve history and evaluate their achievements - in this process, one player after the other, so the end of
 * the command is the end of the work - then verify the result and flip the launch switch (MarkXpLaunchReady): from
 * that moment XP, levels and achievements are shown to everybody and the launch modal starts.
 *
 * Safe to re-run: the XP rebuild is deterministic and the achievement evaluation only fills gaps. A player that fails
 * is reported and the switch stays off; --verify-only checks again (and flips the switch when the check passes)
 * without recalculating anything.
 */
#[AsCommand(
    name: 'myspeedpuzzling:xp-backfill',
    description: 'Rebuild XP ledgers + achievements for all players with solves, verify, then switch the XP launch on.',
)]
final class XpBackfillConsoleCommand extends Command
{
    /** Players per batch between two clears of the entity manager (memory stays flat over thousands of players) */
    private const int BATCH_SIZE = 100;

    public function __construct(
        readonly private GetAllPlayerIdsWithSolveTimes $getAllPlayerIds,
        readonly private MessageBusInterface $commandBus,
        readonly private ManagerRegistry $doctrine,
        readonly private GetXpLaunchVerification $getXpLaunchVerification,
        readonly private XpLaunchReadiness $xpLaunchReadiness,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('verify-only', null, InputOption::VALUE_NONE, 'Recalculate nothing - verify and switch the launch on when the check passes.')
            ->addOption('no-launch', null, InputOption::VALUE_NONE, 'Recalculate and verify, but leave the launch switch as it is.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $started = microtime(true);

        if ($input->getOption('verify-only') !== true) {
            $failed = $this->recalculateEverybody($io);

            if ($failed !== []) {
                $io->error(sprintf('%d player(s) failed - the launch switch stays off. Fix and re-run.', count($failed)));
                $io->listing(array_slice($failed, 0, 50));

                return self::FAILURE;
            }
        }

        $io->section('Verification');

        if ($input->getOption('no-launch') === true) {
            $verification = $this->getXpLaunchVerification->check();

            if ($verification->passed() === false) {
                $io->error('Verification failed.');
                $io->listing($verification->problems());

                return self::FAILURE;
            }

            $io->success(sprintf('Verified %d players with solves - the launch switch was left as it is (--no-launch).', $verification->playersWithSolves));

            return self::SUCCESS;
        }

        try {
            $envelope = $this->commandBus->dispatch(new MarkXpLaunchReady());
        } catch (HandlerFailedException $exception) {
            $previous = $exception->getPrevious();

            if ($previous instanceof XpLaunchNotVerified) {
                $io->error('Verification failed - the launch switch stays off.');
                $io->listing($previous->verification->problems());

                return self::FAILURE;
            }

            throw $exception;
        }

        // Committed - every worker and container reads the switch again on its next request
        $this->xpLaunchReadiness->forget();

        $verification = $envelope->last(HandledStamp::class)?->getResult();
        assert($verification instanceof XpLaunchVerification);

        $io->success(sprintf(
            'Verified %d players with solves in %.1f s - XP, levels and achievements are now live for everybody.',
            $verification->playersWithSolves,
            microtime(true) - $started,
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<string> ids of the players whose recalculation failed
     */
    private function recalculateEverybody(SymfonyStyle $io): array
    {
        $playerIds = $this->getAllPlayerIds->execute();
        $failed = [];

        $io->section(sprintf('Recalculating XP and achievements of %d players', count($playerIds)));
        $io->progressStart(count($playerIds));

        foreach ($playerIds as $index => $playerId) {
            try {
                // In this process (not the async queue): when the loop ends, the work is done
                $this->commandBus->dispatch(new RecalculateXpForPlayer($playerId), [new TransportNamesStamp(['sync'])]);
                $this->commandBus->dispatch(new RecalculateBadgesForPlayer($playerId), [new TransportNamesStamp(['sync'])]);
            } catch (HandlerFailedException $exception) {
                $failed[] = sprintf('%s: %s', $playerId, ($exception->getPrevious() ?? $exception)->getMessage());
                // A failed flush closes the entity manager - the next player needs a fresh one
                $this->doctrine->resetManager();
            }

            if (($index + 1) % self::BATCH_SIZE === 0) {
                $this->doctrine->getManager()->clear();
            }

            $io->progressAdvance();
        }

        $this->doctrine->getManager()->clear();
        $io->progressFinish();

        return $failed;
    }
}
