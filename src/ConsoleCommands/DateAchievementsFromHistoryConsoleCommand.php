<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Doctrine\Persistence\ManagerRegistry;
use SpeedPuzzling\Web\Message\DateAchievementsFromHistory;
use SpeedPuzzling\Web\Query\GetPlayersWithLaunchStampedAchievements;
use SpeedPuzzling\Web\Results\AchievementDatingOutcome;
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
 * One-off after the XP launch (docs/features/badges.md "Real dates"): every tier the launch backfill stamped with its
 * own run time gets the moment the player's records first met it, so "Held at launch" gives way to a real date and the
 * medal numbers follow the real order. In this process, one player after the other. Safe to re-run: a dated tier is
 * out of the backfill's window and never touched again; --dry-run counts and changes nothing.
 */
#[AsCommand(
    name: 'myspeedpuzzling:date-achievements-from-history',
    description: 'Give the achievement tiers stamped by the XP launch backfill the date their requirement was first met.',
)]
final class DateAchievementsFromHistoryConsoleCommand extends Command
{
    /** Players per batch between two clears of the entity manager (memory stays flat over thousands of players) */
    private const int BATCH_SIZE = 100;

    public function __construct(
        readonly private GetPlayersWithLaunchStampedAchievements $getPlayers,
        readonly private MessageBusInterface $commandBus,
        readonly private ManagerRegistry $doctrine,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be dated, change nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run') === true;
        $started = microtime(true);
        $playerIds = $this->getPlayers->playerIds();
        $stampedBefore = $this->getPlayers->stampedTiers();

        $io->section(sprintf('%s %d stamped tiers of %d players', $dryRun ? 'Dry run over' : 'Dating', $stampedBefore, count($playerIds)));
        $io->progressStart(count($playerIds));

        $dated = 0;
        $undated = 0;
        /** @var list<string> $notMatched */
        $notMatched = [];
        /** @var list<string> $failed */
        $failed = [];

        foreach ($playerIds as $index => $playerId) {
            try {
                $envelope = $this->commandBus->dispatch(new DateAchievementsFromHistory($playerId, $dryRun), [new TransportNamesStamp(['sync'])]);
                $outcome = $envelope->last(HandledStamp::class)?->getResult();
                assert($outcome instanceof AchievementDatingOutcome);

                $dated += $outcome->dated;
                $undated += $outcome->undated;

                if ($outcome->replayMatched === false) {
                    $notMatched[] = $playerId;
                }
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

        $io->progressFinish();

        $io->table(['', 'Tiers'], [
            [$dryRun ? 'Would be dated' : 'Dated', $dated],
            ['Left "held at launch"', $undated],
            ['Players whose history did not match their records', count($notMatched)],
            ['Players failed', count($failed)],
        ]);

        if ($notMatched !== []) {
            $io->writeln('History did not match (first 20): ' . implode(', ', array_slice($notMatched, 0, 20)));
        }

        if ($failed !== []) {
            $io->error(sprintf('%d player(s) failed - re-run after fixing (dated tiers are never touched again).', count($failed)));
            $io->listing(array_slice($failed, 0, 20));

            return self::FAILURE;
        }

        $io->success(sprintf('%s in %.1f s.', $dryRun ? 'Dry run done - nothing changed' : sprintf('%d tiers dated', $dated), microtime(true) - $started));

        return self::SUCCESS;
    }
}
