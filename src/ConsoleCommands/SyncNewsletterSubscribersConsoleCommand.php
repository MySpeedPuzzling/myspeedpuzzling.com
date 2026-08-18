<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\SyncNewsletterSubscribers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'myspeedpuzzling:sync-newsletter-subscribers',
    description: 'Reconcile newsletter subscribers between MySpeedPuzzling (source of truth) and Listmonk',
)]
final class SyncNewsletterSubscribersConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->commandBus->dispatch(new SyncNewsletterSubscribers());

        $io->success('Newsletter subscribers synced with Listmonk.');

        return self::SUCCESS;
    }
}
