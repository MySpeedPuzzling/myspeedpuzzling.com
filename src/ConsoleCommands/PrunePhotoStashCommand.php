<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\PrunePhotoStash;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'myspeedpuzzling:prune-photo-stash',
    description: 'Delete photos kept for refused add/edit time forms and uploaded participant lists that nobody came back for',
)]
final class PrunePhotoStashCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $envelope = $this->messageBus->dispatch(new PrunePhotoStash());

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        /** @var int $deleted */
        $deleted = $handledStamp->getResult();

        (new SymfonyStyle($input, $output))->success("Deleted {$deleted} kept files.");

        return Command::SUCCESS;
    }
}
