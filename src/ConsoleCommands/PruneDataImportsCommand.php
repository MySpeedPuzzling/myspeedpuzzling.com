<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\PruneDataImports;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'myspeedpuzzling:prune-data-imports',
    description: 'Remove spreadsheet uploads never confirmed, import files kept for 30 days and discarded imports',
)]
final class PruneDataImportsCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $envelope = $this->messageBus->dispatch(new PruneDataImports());

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        /** @var array{unconfirmed: int, files: int, discarded: int, finished: int} $removed */
        $removed = $handledStamp->getResult();

        (new SymfonyStyle($input, $output))->success(sprintf(
            'Removed %d unconfirmed uploads, %d files kept long enough and %d discarded imports. Finished %d imports with nothing left to add.',
            $removed['unconfirmed'],
            $removed['files'],
            $removed['discarded'],
            $removed['finished'],
        ));

        return Command::SUCCESS;
    }
}
