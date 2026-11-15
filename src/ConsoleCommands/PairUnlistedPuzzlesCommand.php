<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\SuggestUnlistedPuzzlePairings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'myspeedpuzzling:pair-unlisted-puzzles',
    description: 'Look in the catalogue for players\' unlisted puzzles again and suggest certain matches - nothing is paired without the player',
)]
final class PairUnlistedPuzzlesCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'At most so many unlisted puzzles a run', '5000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $input->getOption('limit');
        $envelope = $this->messageBus->dispatch(new SuggestUnlistedPuzzlePairings(is_numeric($limit) ? max(1, (int) $limit) : 5_000));

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        /** @var array{looked: int, suggested: int, playersTold: int} $done */
        $done = $handledStamp->getResult();

        (new SymfonyStyle($input, $output))->success(sprintf(
            'Looked for %d unlisted puzzles, suggested %d, told %d players.',
            $done['looked'],
            $done['suggested'],
            $done['playersTold'],
        ));

        return Command::SUCCESS;
    }
}
