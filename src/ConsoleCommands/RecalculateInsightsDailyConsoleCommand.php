<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\RecalculateInsightsDaily;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'myspeedpuzzling:recalculate-insights-daily',
    description: 'Daily Insights batch: refit the pair/team coordination constants and rebuild the puzzler profile cards (percentiles)',
)]
final class RecalculateInsightsDailyConsoleCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $started = microtime(true);

        $envelope = $this->messageBus->dispatch(new RecalculateInsightsDaily());

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        /** @var array{coordination_written: int, profiles: int, profiles_written: int, profiles_deleted: int, generation: int} $result */
        $result = $handledStamp->getResult();

        $io->success(sprintf(
            'Insights daily batch done in %.2f s: %d coordination constants changed; %d profile cards (%d written, %d deleted); generation %d.',
            microtime(true) - $started,
            $result['coordination_written'],
            $result['profiles'],
            $result['profiles_written'],
            $result['profiles_deleted'],
            $result['generation'],
        ));

        return Command::SUCCESS;
    }
}
