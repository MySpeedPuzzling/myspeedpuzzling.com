<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'myspeedpuzzling:oauth2:create-client',
    description: 'Create a new OAuth2 client',
)]
final class OAuth2CreateClientConsoleCommand extends Command
{
    public function __construct(
        private readonly ClientManagerInterface $clientManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Human-readable name of the client')
            ->addArgument('identifier', InputArgument::REQUIRED, 'Unique identifier for the client')
            ->addOption('redirect-uri', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Redirect URI(s)')
            ->addOption('grant-type', 'g', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Grant type(s): authorization_code, client_credentials, refresh_token')
            ->addOption('scope', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Allowed scope(s)')
            ->addOption('public', null, InputOption::VALUE_NONE, 'Create a public client (no secret, PKCE required)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var non-empty-string $name */
        $name = $input->getArgument('name');

        /** @var non-empty-string $identifier */
        $identifier = $input->getArgument('identifier');

        $isPublic = (bool) $input->getOption('public');
        $secret = $isPublic ? null : bin2hex(random_bytes(32));

        $client = new Client($name, $identifier, $secret);

        /** @var array<non-empty-string> $redirectUris */
        $redirectUris = $input->getOption('redirect-uri');
        if ($redirectUris !== []) {
            $client->setRedirectUris(...array_map(
                static fn (string $uri) => new RedirectUri($uri),
                $redirectUris,
            ));
        }

        /** @var array<non-empty-string> $grantTypes */
        $grantTypes = $input->getOption('grant-type');
        if ($grantTypes !== []) {
            $client->setGrants(...array_map(
                static fn (string $grant) => new Grant($grant),
                $grantTypes,
            ));
        }

        /** @var array<non-empty-string> $scopes */
        $scopes = $input->getOption('scope');
        if ($scopes !== []) {
            $client->setScopes(...array_map(
                static fn (string $scope) => new Scope($scope),
                $scopes,
            ));
        }

        $this->clientManager->save($client);

        $io->success('OAuth2 client created successfully!');
        $io->table(
            ['Property', 'Value'],
            [
                ['Name', $name],
                ['Identifier', $identifier],
                ['Secret', $isPublic ? '(public client - no secret)' : $secret],
                ['Type', $isPublic ? 'Public (PKCE required)' : 'Confidential'],
                ['Redirect URIs', implode(', ', $redirectUris) ?: '(none)'],
                ['Grant Types', implode(', ', $grantTypes) ?: '(none)'],
                ['Scopes', implode(', ', $scopes) ?: '(none)'],
            ],
        );

        if (!$isPublic && $secret !== null) {
            $io->warning('Save the client secret securely - it will not be shown again!');
        }

        return Command::SUCCESS;
    }
}
