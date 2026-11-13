<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Services\Sandbox\SandboxAccountExport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints one account of production as JSON for myspeedpuzzling:sandbox:import-account - READ-ONLY, meant to be piped
 * straight into the sandbox's container on the same box, so the password hash never leaves the server and is never
 * shown (docs/features/sandbox/README.md "Copying a real account"):
 *
 *   docker compose exec -T web php bin/console myspeedpuzzling:sandbox:export-account <email> \
 *     | (cd /srv/msp-sandbox && docker compose exec -T web php bin/console myspeedpuzzling:sandbox:import-account)
 *
 * Never run it into a terminal or a file.
 */
#[AsCommand(
    name: 'myspeedpuzzling:sandbox:export-account',
    description: 'Print one account (sign-in + basic profile) as JSON for the sandbox - read-only; pipe it, never show it.',
)]
final class SandboxExportAccountConsoleCommand extends Command
{
    public function __construct(
        readonly private SandboxAccountExport $accountExport,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'The account\'s e-mail');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getArgument('email');
        assert(is_string($email));

        $account = $this->accountExport->byEmail($email);
        $output->writeln(json_encode($account, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $errorOutput->writeln(sprintf('Exported %s (%s).', $account['email'], $account['passwordHash'] !== null ? 'with a password' : 'no password - signs in by e-mailed link'));

        return self::SUCCESS;
    }
}
