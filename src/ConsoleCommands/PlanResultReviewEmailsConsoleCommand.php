<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\PlanResultReviewEmails;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'myspeedpuzzling:plan-result-review-emails',
    description: 'Plan the "Your results" e-mails about results saved twice (sent later, paced, by myspeedpuzzling:send-result-review-emails)',
)]
final class PlanResultReviewEmailsConsoleCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $envelope = $this->messageBus->dispatch(new PlanResultReviewEmails());

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        /** @var int $planned */
        $planned = $handledStamp->getResult();

        (new SymfonyStyle($input, $output))->success(sprintf('Planned e-mails: %d', $planned));

        return Command::SUCCESS;
    }
}
