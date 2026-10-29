<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use DateTimeZone;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Query\GetAllPlayerIdsWithSolveTimes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

#[AsCommand(
    name: 'myspeedpuzzling:recalculate-badges',
    description: 'Recalculate badges for one or all players by dispatching async recalc messages (--sync: in this process).',
)]
final class RecalculateBadgesConsoleCommand extends Command
{
    /**
     * Stagger between dispatched backfill messages - keeps a full run from monopolizing the worker and the database.
     */
    private const int BACKFILL_DELAY_MS = 2000;

    private const int BATCH_SIZE = 100;

    public function __construct(
        readonly private GetAllPlayerIdsWithSolveTimes $getAllPlayerIds,
        readonly private MessageBusInterface $commandBus,
        readonly private ManagerRegistry $doctrine,
        readonly private ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'player',
                null,
                InputOption::VALUE_REQUIRED,
                'Recalculate only for this single player UUID.',
            )
            ->addOption(
                'backfill',
                null,
                InputOption::VALUE_NONE,
                'Stagger dispatches with DelayStamp (full seed runs).',
            )
            ->addOption(
                'sync',
                null,
                InputOption::VALUE_NONE,
                'Recalculate in this process, one player after another (an achievement drop): no queue to wait for, and no consumer of the previous release can take a message.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $specificPlayer = $input->getOption('player');
        $backfill = (bool) $input->getOption('backfill');

        if ($specificPlayer !== null) {
            if (!is_string($specificPlayer)) {
                $io->error('Invalid --player value.');
                return self::INVALID;
            }

            $this->commandBus->dispatch(new RecalculateBadgesForPlayer($specificPlayer));
            $io->success("Dispatched badge recalculation for player {$specificPlayer}.");

            return self::SUCCESS;
        }

        $playerIds = $this->getAllPlayerIds->execute();

        if ($playerIds === []) {
            $io->success('No players with solve times found — nothing to dispatch.');
            return self::SUCCESS;
        }

        if ($input->getOption('sync') === true) {
            return $this->recalculateHere($io, $playerIds);
        }

        foreach ($playerIds as $index => $playerId) {
            $stamps = [];
            if ($backfill) {
                $stamps[] = new DelayStamp($index * self::BACKFILL_DELAY_MS);
            }

            $this->commandBus->dispatch(new RecalculateBadgesForPlayer($playerId), $stamps);
        }

        $count = count($playerIds);
        $mode = $backfill ? 'backfill (staggered)' : 'immediate';
        $io->success("Dispatched {$count} badge recalculation message(s) — {$mode}.");

        return self::SUCCESS;
    }

    /**
     * An achievement drop (docs/features/badges.md "Achievement drops"): every player evaluated by this release's
     * code, then the command that dates the new tiers from history - printed with the moment this run started
     *
     * @param list<string> $playerIds
     */
    private function recalculateHere(SymfonyStyle $io, array $playerIds): int
    {
        $startedAt = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        /** @var list<string> $failed */
        $failed = [];

        $io->progressStart(count($playerIds));

        foreach ($playerIds as $index => $playerId) {
            try {
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

        $io->progressFinish();

        if ($failed !== []) {
            $io->error(sprintf('%d player(s) failed - re-run them with --player.', count($failed)));
            $io->listing(array_slice($failed, 0, 20));
        }

        $io->success(sprintf('Recalculated %d player(s). Date the new tiers from history next:', count($playerIds) - count($failed)));
        $io->writeln(sprintf(
            'bin/console myspeedpuzzling:date-achievements-from-history --earned-since="%s" --dry-run',
            $startedAt->format('Y-m-d H:i:s'),
        ));

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
