<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\SettleXpBonuses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'myspeedpuzzling:settle-xp-bonuses',
    description: 'Settle pending XP difficulty/speed bonuses for solves whose puzzle got rated or reached a reliable median. Cron: every 15 minutes, AFTER myspeedpuzzling:recalculate-puzzle-intelligence.',
)]
final class SettleXpBonusesConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->commandBus->dispatch(new SettleXpBonuses());

        (new SymfonyStyle($input, $output))->success('Dispatched XP bonus settlement.');

        return self::SUCCESS;
    }
}
