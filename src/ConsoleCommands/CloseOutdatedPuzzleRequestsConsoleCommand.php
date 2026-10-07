<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\CloseOutdatedPuzzleRequests;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Daily on lily (docs/features/puzzle-approvals.md, "Outdated requests"). Safe to run any time and again.
 */
#[AsCommand(
    name: 'myspeedpuzzling:close-outdated-puzzle-requests',
    description: 'Closes pending merge and change requests the catalogue already took care of (merged, deleted or already applied)',
)]
final class CloseOutdatedPuzzleRequestsConsoleCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $envelope = $this->messageBus->dispatch(new CloseOutdatedPuzzleRequests());

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        /** @var array{mergeRequests: int, changeRequests: int} $closed */
        $closed = $handledStamp->getResult();

        (new SymfonyStyle($input, $output))->success(sprintf(
            'Closed %d merge and %d change requests with nothing left to do.',
            $closed['mergeRequests'],
            $closed['changeRequests'],
        ));

        return Command::SUCCESS;
    }
}
