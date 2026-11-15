<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\ReadWaitingImports;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'myspeedpuzzling:read-waiting-imports',
    description: 'Queue uploaded spreadsheet imports nobody started to read, and fail the ones whose reading stalled',
)]
final class ReadWaitingImportsCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $envelope = $this->messageBus->dispatch(new ReadWaitingImports());

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        /** @var array{queued: int, failed: int} $done */
        $done = $handledStamp->getResult();

        (new SymfonyStyle($input, $output))->success(sprintf(
            'Queued %d imports for reading, failed %d whose reading or fetching stalled.',
            $done['queued'],
            $done['failed'],
        ));

        return Command::SUCCESS;
    }
}
