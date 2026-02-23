<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use League\Flysystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'myspeedpuzzling:imgproxy:cleanup-old-thumbnails',
    description: 'Delete old Liip Imagine thumbnails from S3 (thumbnails/ prefix)',
)]
final class CleanupOldThumbnailsConsoleCommand extends Command
{
    public function __construct(
        private readonly Filesystem $filesystem,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview what would be deleted without deleting');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');

        $io->info('Scanning thumbnails/ prefix in S3...');

        $files = [];
        foreach ($this->filesystem->listContents('thumbnails', true) as $item) {
            if ($item->isFile()) {
                $files[] = $item->path();
            }
        }

        $count = count($files);
        $io->info(sprintf('Found %d files under thumbnails/ prefix', $count));

        if ($count === 0) {
            $io->success('No thumbnails to clean up.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->note(sprintf('Dry run: would delete %d files', $count));
            return Command::SUCCESS;
        }

        if (!$force && !$io->confirm(sprintf('Delete %d files from S3?', $count), false)) {
            $io->warning('Aborted.');
            return Command::SUCCESS;
        }

        $progressBar = $io->createProgressBar($count);
        $progressBar->start();

        $deleted = 0;
        foreach ($files as $path) {
            $this->filesystem->delete($path);
            $deleted++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);
        $io->success(sprintf('Deleted %d files from thumbnails/ prefix', $deleted));

        return Command::SUCCESS;
    }
}
