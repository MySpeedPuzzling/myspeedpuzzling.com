<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\BackfillPhotoStashEntries;
use SpeedPuzzling\Web\Value\PhotoStashBackfillResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Release 1 of 2 (docs/TODO.md "PhotoStash descriptions in Postgres") - run once after the deploy, when no container of
 * the previous release serves any more; then release 2 may drop the old format.
 */
#[AsCommand(
    name: 'myspeedpuzzling:backfill-photo-stash-entries',
    description: 'Copy the old .json PhotoStash descriptions still valid into photo_stash_entry (dry run unless --write)',
)]
final class BackfillPhotoStashEntriesConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('write', null, InputOption::VALUE_NONE, 'Record the rows (default: only count)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $write = $input->getOption('write') === true;
        $envelope = $this->messageBus->dispatch(new BackfillPhotoStashEntries($write));

        /** @var PhotoStashBackfillResult $result */
        $result = $envelope->last(HandledStamp::class)?->getResult();

        $io = new SymfonyStyle($input, $output);
        $io->definitionList(
            ['Old descriptions' => $result->descriptions],
            ['Copies of rows (ignored)' => $result->mirrors],
            ['Expired' => $result->expired],
            ['Broken' => $result->broken],
            ['Already recorded' => $result->alreadyRecorded],
            [($write ? 'Recorded' : 'Would be recorded') => $write ? $result->recorded : $result->toRecord],
            ['Failed (player gone, or logged)' => $result->failed],
        );

        if ($write === false) {
            $io->note('Dry run - nothing written. Run again with --write.');
        }

        return self::SUCCESS;
    }
}
