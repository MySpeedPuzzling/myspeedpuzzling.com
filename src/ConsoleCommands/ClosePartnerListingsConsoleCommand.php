<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\CloseFinishedPartnerListings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Daily on lily (docs/features/partner-finder.md "Part C as built"). Safe to run any time and again.
 */
#[AsCommand(
    name: 'myspeedpuzzling:close-partner-listings',
    description: 'Closes partner finder listings of events that are over, and of players entered in a pair or team of the event',
)]
final class ClosePartnerListingsConsoleCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $envelope = $this->messageBus->dispatch(new CloseFinishedPartnerListings());

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        /** @var array{eventOver: int, entered: int} $closed */
        $closed = $handledStamp->getResult();

        (new SymfonyStyle($input, $output))->success(sprintf(
            'Closed %d listings of events that are over and %d of players entered in a pair or team.',
            $closed['eventOver'],
            $closed['entered'],
        ));

        return Command::SUCCESS;
    }
}
