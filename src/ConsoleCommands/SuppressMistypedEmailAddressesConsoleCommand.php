<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\SuppressMistypedEmailAddresses;
use SpeedPuzzling\Web\Query\GetMistypedEmailAddresses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * docs/features/mass-mailing/README.md "Mistyped domains" - run --dry-run first
 */
#[AsCommand('myspeedpuzzling:suppress-mistyped-email-addresses', 'Suppress stored addresses with a mistyped mail domain (gmail.con, hotmatil.com, …)')]
final class SuppressMistypedEmailAddressesConsoleCommand extends Command
{
    public function __construct(
        readonly private GetMistypedEmailAddresses $getMistypedEmailAddresses,
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list the addresses');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $found = $this->getMistypedEmailAddresses->notSuppressed();

        $io->table(['Address', 'Probably meant'], array_map(
            static fn (string $email, null|string $suggestion): array => [$email, $suggestion ?? '(SES: invalid domain)'],
            array_keys($found),
            array_values($found),
        ));

        if ($input->getOption('dry-run') === true) {
            $io->note(sprintf('%d addresses would be suppressed (dry run).', count($found)));

            return self::SUCCESS;
        }

        $envelope = $this->messageBus->dispatch(new SuppressMistypedEmailAddresses());

        /** @var null|list<string> $suppressed */
        $suppressed = $envelope->last(HandledStamp::class)?->getResult();

        $io->success(sprintf('%d addresses suppressed.', count($suppressed ?? [])));

        return self::SUCCESS;
    }
}
