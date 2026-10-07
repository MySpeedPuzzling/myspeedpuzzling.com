<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\BackfillRoundTimezones;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * One-off after rounds kept their zone: saves the zone every older round has been read in (BackfillRoundTimezonesHandler)
 * and lists the rounds whose start looks moved by the old bug, to check by hand. Changes nothing without --write.
 */
#[AsCommand('myspeedpuzzling:backfill-round-timezones')]
final class BackfillRoundTimezonesConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('write', null, InputOption::VALUE_NONE, 'Save the zones (default: dry run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $write = $input->getOption('write') === true;
        $envelope = $this->messageBus->dispatch(new BackfillRoundTimezones(dryRun: !$write));

        /** @var null|array{changes: list<string>, suspects: list<string>} $result */
        $result = $envelope->last(HandledStamp::class)?->getResult();
        $changes = $result['changes'] ?? [];
        $suspects = $result['suspects'] ?? [];

        $io = new SymfonyStyle($input, $output);
        $io->section('Rounds without a saved zone - the zone they are read in (local start)');
        $io->listing($changes === [] ? ['nothing to change'] : $changes);
        $io->section('Starts that look moved by the old untouched-save bug - check the organiser\'s schedule');
        $io->listing($suspects === [] ? ['none'] : $suspects);
        $io->success(sprintf('%d rounds %s.', count($changes), $write ? 'got their zone saved' : 'would get their zone saved (dry run, add --write)'));

        return self::SUCCESS;
    }
}
