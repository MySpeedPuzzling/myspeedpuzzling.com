<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\SendPlannedResultReviewEmails;
use SpeedPuzzling\Web\Results\ResultReviewSendingSummary;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'myspeedpuzzling:send-result-review-emails',
    description: 'Queue planned "Your results" e-mails, at most result_review_emails_per_run per run (result_review_email_spacing_seconds apart) and result_review_emails_per_day a day',
)]
final class SendResultReviewEmailsConsoleCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $envelope = $this->messageBus->dispatch(new SendPlannedResultReviewEmails());

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        /** @var ResultReviewSendingSummary $summary */
        $summary = $handledStamp->getResult();

        (new SymfonyStyle($input, $output))->success(sprintf(
            'Sent: %d, skipped: %d, still planned: %d (sent today before this run: %d)',
            $summary->sent,
            $summary->skipped,
            $summary->stillPlanned,
            $summary->sentTodayBefore,
        ));

        return Command::SUCCESS;
    }
}
