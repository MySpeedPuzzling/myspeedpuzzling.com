<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\UpdateMembershipSubscription;
use SpeedPuzzling\Web\Query\GetRunningStripeSubscriptionIds;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Reads every running Stripe subscription again through the webhook's own handler - once after the release
 * that started storing the plan (docs/features/stripe.md "Switching plans"), so members see it before their
 * next renewal. Never announces a renewal (no payment is confirmed here), so it sends no e-mail.
 */
#[AsCommand(
    name: 'myspeedpuzzling:stripe:refresh-subscriptions',
    description: 'Re-read every running Stripe subscription into its membership, like a webhook would (manual)',
)]
final class RefreshStripeSubscriptionsConsoleCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetRunningStripeSubscriptionIds $getRunningStripeSubscriptionIds,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count the subscriptions');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ids = $this->getRunningStripeSubscriptionIds->all($this->clock->now());

        if ((bool) $input->getOption('dry-run')) {
            $io->note(sprintf('%d running subscriptions would be read again.', count($ids)));

            return Command::SUCCESS;
        }

        $failed = 0;
        $io->progressStart(count($ids));

        foreach ($ids as $id) {
            try {
                $this->messageBus->dispatch(new UpdateMembershipSubscription($id));
            } catch (HandlerFailedException $e) {
                $failed++;
                $io->warning(sprintf('%s: %s', $id, $e->getMessage()));
            }

            $io->progressAdvance();
            // Well below Stripe's rate limit - every subscription is 2-3 calls
            usleep(200_000);
        }

        $io->progressFinish();
        $io->success(sprintf('Read %d subscriptions again, %d failed.', count($ids) - $failed, $failed));

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
