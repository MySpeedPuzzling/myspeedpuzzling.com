<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\BackfillPaceHistory;
use SpeedPuzzling\Web\Results\PaceHistorySyncResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    'myspeedpuzzling:backfill-pace-history',
    'Brings the players\' pace history in line with their solves (every player, or one). Safe to run again: current pairs are left alone.',
)]
final class BackfillPaceHistoryConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('player', null, InputOption::VALUE_REQUIRED, 'Only this player (uuid)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var null|string $player */
        $player = $input->getOption('player');

        $startedAt = microtime(true);
        $envelope = $this->messageBus->dispatch(new BackfillPaceHistory($player));
        $result = $envelope->last(HandledStamp::class)?->getResult();
        assert($result instanceof PaceHistorySyncResult);

        $io->table(
            ['Pairs (player × pieces)', 'Pairs changed', 'Rows deleted', 'Rows inserted', 'Seconds'],
            [[$result->pairs, $result->changedPairs, $result->deleted, $result->inserted, round(microtime(true) - $startedAt, 1)]],
        );

        return self::SUCCESS;
    }
}
