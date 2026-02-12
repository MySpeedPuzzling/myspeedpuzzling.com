<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('myspeedpuzzling:moderation:expire-mutes')]
final class ExpireMutesCommand extends Command
{
    public function __construct(
        readonly private Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $affected = $this->connection->executeStatement("
            UPDATE player
            SET messaging_muted = false, messaging_muted_until = NULL
            WHERE messaging_muted = true AND messaging_muted_until < NOW()
        ");

        $io->success("Expired {$affected} mutes.");

        return self::SUCCESS;
    }
}
