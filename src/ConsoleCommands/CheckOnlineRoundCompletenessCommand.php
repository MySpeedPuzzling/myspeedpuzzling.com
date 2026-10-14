<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Message\CheckOnlineRoundFinishPhotos;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineCvSettings;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStores;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * (Re)checks the finish photos of an online round for completeness (docs/features/online-competitions/README.md §5.8,
 * Phase 3) - after the worker was down, or with `--force` after a new worker version (its verdicts add rows next to
 * the older ones). Sends the photos to the async queue (CheckOnlineRoundFinishPhotos → CheckOnlineFinishPhoto each);
 * the messenger consumer runs the checks.
 */
#[AsCommand(
    name: 'myspeedpuzzling:online-cv:check-round',
    description: 'Send the finish photos of an online round to the completeness check (those without a verdict, or every one with --force)',
)]
final class CheckOnlineRoundCompletenessCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly OnlineCvSettings $onlineCvSettings,
        private readonly OnlineMediaStores $onlineMediaStores,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('roundId', InputArgument::REQUIRED, 'The competition round (its id)');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Check every finish photo again, also those with a verdict');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->onlineCvSettings->isOn() === false) {
            $io->error('The completeness check is off - ONLINE_CV_URL is empty.');

            return Command::FAILURE;
        }

        /** @var string $roundId */
        $roundId = $input->getArgument('roundId');
        $force = $input->getOption('force') === true;

        try {
            $sent = $this->messageBus->dispatch(new CheckOnlineRoundFinishPhotos($roundId, $force))->last(HandledStamp::class)?->getResult();
        } catch (CompetitionRoundNotFound) {
            $io->error(sprintf('No competition round "%s".', $roundId));

            return Command::FAILURE;
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof CompetitionRoundNotFound) {
                $io->error(sprintf('No competition round "%s".', $roundId));

                return Command::FAILURE;
            }

            throw $e;
        }

        assert(is_int($sent));

        $io->success(sprintf('%d finish photo(s) sent to the completeness check%s.', $sent, $force ? ' (forced)' : ''));
        $io->note(sprintf(
            'The worker (%s) must allow these hosts (ONLINE_CV_ALLOWED_HOSTS): %s',
            $this->onlineCvSettings->url(),
            implode(',', $this->onlineMediaStores->serverHosts()),
        ));

        return Command::SUCCESS;
    }
}
