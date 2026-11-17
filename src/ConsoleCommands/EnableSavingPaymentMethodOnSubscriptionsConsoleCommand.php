<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\EnableSavingPaymentMethodOnAllSubscriptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'myspeedpuzzling:stripe:enable-saving-payment-methods',
    description: 'One-time backfill: Stripe saves the card that pays a subscription invoice as the subscription default (docs/features/stripe.md "Cards")',
)]
final class EnableSavingPaymentMethodOnSubscriptionsConsoleCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count the subscriptions that need it, change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $envelope = $this->messageBus->dispatch(new EnableSavingPaymentMethodOnAllSubscriptions($dryRun));

        /** @var list<string> $needing */
        $needing = $envelope->last(HandledStamp::class)?->getResult() ?? [];

        if ($dryRun) {
            $io->note(sprintf('Dry run - %d subscriptions would be updated.', count($needing)));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Queued %d subscriptions - the async consumer updates them one by one.', count($needing)));

        return Command::SUCCESS;
    }
}
