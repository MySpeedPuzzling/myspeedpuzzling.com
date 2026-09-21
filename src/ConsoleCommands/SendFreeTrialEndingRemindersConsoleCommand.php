<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\SendFreeTrialEndingReminder;
use SpeedPuzzling\Web\Query\GetFreeTrialsEndingSoon;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'myspeedpuzzling:send-free-trial-ending-reminders',
    description: 'E-mail players whose free trial of membership ends within a few days (once per trial)',
)]
final class SendFreeTrialEndingRemindersConsoleCommand extends Command
{
    public function __construct(
        readonly private GetFreeTrialsEndingSoon $getFreeTrialsEndingSoon,
        readonly private MessageBusInterface $messageBus,
        readonly private ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $membershipIds = $this->getFreeTrialsEndingSoon->membershipIdsToRemind($this->clock->now());

        foreach ($membershipIds as $membershipId) {
            $this->messageBus->dispatch(new SendFreeTrialEndingReminder($membershipId));
        }

        $io->success(sprintf('Free trial ending reminders: %d', count($membershipIds)));

        return self::SUCCESS;
    }
}
