<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\UpdateFeatureRequestStatus;
use SpeedPuzzling\Web\Value\FeatureRequestStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand('myspeedpuzzling:feature-request:declined')]
final class MarkFeatureRequestDeclinedConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('featureRequestId', InputArgument::REQUIRED, 'Feature request UUID');
        $this->addOption('github', null, InputOption::VALUE_REQUIRED, 'GitHub URL (issue or PR)');
        $this->addOption('comment', null, InputOption::VALUE_REQUIRED, 'Admin comment');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $featureRequestId */
        $featureRequestId = $input->getArgument('featureRequestId');

        /** @var string|null $githubUrl */
        $githubUrl = $input->getOption('github');

        /** @var string|null $adminComment */
        $adminComment = $input->getOption('comment');

        $this->messageBus->dispatch(
            new UpdateFeatureRequestStatus(
                featureRequestId: $featureRequestId,
                status: FeatureRequestStatus::Declined,
                githubUrl: $githubUrl,
                adminComment: $adminComment,
            ),
        );

        $io->success(sprintf('Feature request %s marked as declined.', $featureRequestId));

        return self::SUCCESS;
    }
}
