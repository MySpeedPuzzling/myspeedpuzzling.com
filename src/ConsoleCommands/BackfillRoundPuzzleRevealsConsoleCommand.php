<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\BackfillRoundPuzzleReveals;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * One-off after the reveal model (docs/features/competitions-management/README.md "Hide Until Round Starts"): marks
 * the round puzzles whose puzzle the round created, and sets the puzzle's site-wide hide dates to the reveal moment.
 * Lists what it does; changes nothing without --write.
 */
#[AsCommand('myspeedpuzzling:backfill-round-puzzle-reveals')]
final class BackfillRoundPuzzleRevealsConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('write', null, InputOption::VALUE_NONE, 'Apply the changes (default: dry run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $write = $input->getOption('write') === true;
        $envelope = $this->messageBus->dispatch(new BackfillRoundPuzzleReveals(dryRun: !$write));

        /** @var null|list<string> $changes */
        $changes = $envelope->last(HandledStamp::class)?->getResult();
        $changes ??= [];

        $io = new SymfonyStyle($input, $output);
        $io->listing($changes === [] ? ['nothing to change'] : $changes);
        $io->success(sprintf('%d round puzzles %s.', count($changes), $write ? 'changed' : 'would change (dry run, add --write)'));

        return self::SUCCESS;
    }
}
