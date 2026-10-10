<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\RecalculateKarma;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * The backfill and the daily safety net of player.karma (docs/features/karma.md). Runs in this process (the message is
 * routed async for the deciding handlers), so the cron row finishes when the totals are stored.
 */
#[AsCommand(
    name: 'myspeedpuzzling:recalculate-karma',
    description: 'Re-derive player.karma for every player, or for one (--player=UUID).',
)]
final class RecalculateKarmaConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('player', null, InputOption::VALUE_REQUIRED, 'Only this player (UUID).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $player = $input->getOption('player');

        if ($player !== null && is_string($player) === false) {
            $io->error('Invalid --player value.');

            return self::INVALID;
        }

        $startedAt = microtime(true);

        $this->commandBus->dispatch(
            new RecalculateKarma($player !== null ? [$player] : null),
            [new TransportNamesStamp(['sync'])],
        );

        $io->success(sprintf(
            'Karma recalculated for %s in %d ms.',
            $player ?? 'every player',
            (int) round((microtime(true) - $startedAt) * 1000),
        ));

        return self::SUCCESS;
    }
}
