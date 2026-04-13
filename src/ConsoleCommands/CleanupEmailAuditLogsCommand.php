<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Repository\EmailAuditLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'myspeedpuzzling:cleanup-email-audit-logs',
    description: 'Delete email audit log entries older than specified number of days',
)]
final class CleanupEmailAuditLogsCommand extends Command
{
    public function __construct(
        private readonly EmailAuditLogRepository $emailAuditLogRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('days', InputArgument::OPTIONAL, 'Delete entries older than this many days', '90');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $daysArg */
        $daysArg = $input->getArgument('days');
        $days = (int) $daysArg;

        if ($days < 1) {
            $io->error('Days must be a positive integer.');
            return Command::FAILURE;
        }

        $before = new \DateTimeImmutable("-{$days} days");
        $deleted = $this->emailAuditLogRepository->deleteOlderThan($before);

        $io->success("Deleted {$deleted} email audit log entries older than {$days} days.");

        return Command::SUCCESS;
    }
}
