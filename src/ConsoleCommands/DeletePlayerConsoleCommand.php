<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\DeletePlayer;
use SpeedPuzzling\Web\Services\ResolvePlayerByIdentifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Permanently deletes a player + their user account (GDPR / support requests).
 * Same handler as the self-service "delete my account" flow - see
 * docs/features/account-deletion.md.
 */
#[AsCommand(
    name: 'myspeedpuzzling:player:delete',
    description: 'Permanently delete a player and their user account (by UUID, player code or e-mail)',
)]
final class DeletePlayerConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private ResolvePlayerByIdentifier $resolvePlayerByIdentifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('player', InputArgument::REQUIRED, 'Player UUID, player code (with or without #) or e-mail address')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the confirmation prompt (required when running non-interactively)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $identifier */
        $identifier = $input->getArgument('player');

        try {
            $player = $this->resolvePlayerByIdentifier->resolve($identifier);
        } catch (PlayerNotFound) {
            $io->error(sprintf('No player found for "%s".', $identifier));

            return self::FAILURE;
        }

        $io->definitionList(
            ['Player ID' => $player->id->toString()],
            ['Code' => '#' . $player->code],
            ['Name' => $player->name ?? '—'],
            ['E-mail' => $player->email ?? '—'],
            ['User ID' => $player->userId ?? '—'],
            ['Registered' => $player->registeredAt->format('Y-m-d H:i')],
        );

        $io->warning('This permanently deletes the player, their user account and all personal data. There is no undo.');

        if ($input->getOption('force') !== true) {
            if ($input->isInteractive() === false) {
                $io->error('Refusing to delete without confirmation - pass --force when running non-interactively.');

                return self::FAILURE;
            }

            if ($io->confirm('Delete this player permanently?', false) === false) {
                $io->note('Nothing deleted.');

                return self::SUCCESS;
            }
        }

        $this->messageBus->dispatch(new DeletePlayer($player->id->toString()));

        $io->success(sprintf('Player %s (#%s) deleted.', $player->id->toString(), $player->code));

        return self::SUCCESS;
    }
}
