<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\RecalculateXpForPlayer;
use SpeedPuzzling\Web\Query\GetAllPlayerIdsWithSolveTimes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Launch-day backfill orchestration: rebuild every player's XP ledger from their full
 * solve history, then re-evaluate achievements in backfill mode (achievement XP lands
 * with in_weekly_delta = false and congratulation emails stay suppressed — doubly so
 * while the xp-system flag is still active).
 *
 * Safe to re-run: the XP recompute is deterministic and achievement evaluation only
 * fills gaps.
 */
#[AsCommand(
    name: 'myspeedpuzzling:xp-backfill',
    description: 'Backfill XP ledgers + achievements for all players with solves (staggered, idempotent).',
)]
final class XpBackfillConsoleCommand extends Command
{
    /**
     * XP recompute is CPU/DB work with no emails — a short stagger just keeps the
     * worker from monopolizing the database.
     */
    private const int XP_DELAY_MS = 150;

    /**
     * Badge evaluation is staggered like the existing badges backfill; achievement
     * recalc runs AFTER the XP messages so achievement XP lands on rebuilt ledgers.
     */
    private const int BADGES_DELAY_MS = 300;

    public function __construct(
        readonly private GetAllPlayerIdsWithSolveTimes $getAllPlayerIds,
        readonly private MessageBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $playerIds = $this->getAllPlayerIds->execute();

        if ($playerIds === []) {
            $io->success('No players with solve times found — nothing to backfill.');

            return self::SUCCESS;
        }

        foreach ($playerIds as $index => $playerId) {
            $this->commandBus->dispatch(
                new RecalculateXpForPlayer($playerId),
                [new DelayStamp($index * self::XP_DELAY_MS)],
            );
        }

        // Achievements start after the last XP recompute is released, so every badge
        // evaluation sees a complete ledger and Achievement XP entries anchor cleanly.
        $badgesOffset = count($playerIds) * self::XP_DELAY_MS;

        foreach ($playerIds as $index => $playerId) {
            $this->commandBus->dispatch(
                new RecalculateBadgesForPlayer($playerId, isBackfill: true),
                [new DelayStamp($badgesOffset + $index * self::BADGES_DELAY_MS)],
            );
        }

        $count = count($playerIds);
        $io->success("Dispatched {$count} XP recompute + {$count} achievement backfill message(s).");
        $io->note('Re-run myspeedpuzzling:xp-distribution once the queue drains to verify the calibration invariants.');

        return self::SUCCESS;
    }
}
