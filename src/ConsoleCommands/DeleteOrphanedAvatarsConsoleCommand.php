<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\DeleteOrphanedAvatar;
use SpeedPuzzling\Web\Services\Storage\OrphanedAvatarFinder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * One-off cleanup for avatars left behind by deleted players (before
 * DeletePlayerStoredFiles existed) and by avatar replacements. Dry run by
 * default. See docs/features/account-deletion.md.
 */
#[AsCommand(
    name: 'myspeedpuzzling:storage:delete-orphaned-avatars',
    description: 'List avatar objects no player references (dry run); --delete removes them',
)]
final class DeleteOrphanedAvatarsConsoleCommand extends Command
{
    public function __construct(
        readonly private OrphanedAvatarFinder $orphanedAvatarFinder,
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('delete', null, InputOption::VALUE_NONE, 'Actually delete the orphaned avatars (default is a dry run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $delete = (bool) $input->getOption('delete');

        $orphans = $this->orphanedAvatarFinder->find();

        foreach ($orphans as $path) {
            $io->writeln($path);
        }

        $io->newLine();
        $io->writeln(sprintf(
            '%d orphaned avatar(s) under %s (objects younger than %d hours are skipped).',
            count($orphans),
            OrphanedAvatarFinder::PREFIX,
            OrphanedAvatarFinder::GRACE_PERIOD_HOURS,
        ));

        if ($delete === false) {
            $io->note('Dry run - nothing deleted. Re-run with --delete to remove them.');

            return Command::SUCCESS;
        }

        $failed = 0;

        foreach ($orphans as $path) {
            try {
                $this->messageBus->dispatch(new DeleteOrphanedAvatar($path));
            } catch (\Throwable $e) {
                $failed++;
                $io->error(sprintf('%s: %s', $path, $e->getMessage()));
            }
        }

        if ($failed > 0) {
            $io->warning(sprintf('Done, %d of %d could not be deleted - run it again.', $failed, count($orphans)));

            return Command::FAILURE;
        }

        $io->success(sprintf('Deleted %d orphaned avatar(s).', count($orphans)));

        return Command::SUCCESS;
    }
}
