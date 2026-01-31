<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'myspeedpuzzling:oauth2:list-clients',
    description: 'List all OAuth2 clients',
)]
final class OAuth2ListClientsConsoleCommand extends Command
{
    public function __construct(
        private readonly ClientManagerInterface $clientManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $clients = $this->clientManager->list(null);

        if ($clients === []) {
            $io->info('No OAuth2 clients found.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($clients as $client) {
            $rows[] = [
                $client->getIdentifier(),
                $client->getName(),
                $client->isConfidential() ? 'Confidential' : 'Public',
                $client->isActive() ? 'Yes' : 'No',
                implode(', ', array_map(
                    static fn (Grant $grant) => (string) $grant,
                    $client->getGrants(),
                )) ?: '(none)',
                implode(', ', array_map(
                    static fn (Scope $scope) => (string) $scope,
                    $client->getScopes(),
                )) ?: '(none)',
            ];
        }

        $io->table(
            ['Identifier', 'Name', 'Type', 'Active', 'Grants', 'Scopes'],
            $rows,
        );

        return Command::SUCCESS;
    }
}
