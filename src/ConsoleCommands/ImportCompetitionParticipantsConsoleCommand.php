<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\ImportCompetitionParticipants;
use SpeedPuzzling\Web\Results\ParticipantImportResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Same Excel import as the organizer's "Import participants" upload, for files prepared outside the UI.
 */
#[AsCommand('myspeedpuzzling:import-competition-participants')]
final class ImportCompetitionParticipantsConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('competitionId', InputArgument::REQUIRED);
        $this->addArgument('file', InputArgument::REQUIRED, 'Path to the .xlsx file (columns: name, country, external_id, msp_player_id, status, round_name, team_name)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $competitionId */
        $competitionId = $input->getArgument('competitionId');
        /** @var string $file */
        $file = $input->getArgument('file');

        if (is_file($file) === false) {
            $io->error(sprintf('File "%s" not found.', $file));

            return self::FAILURE;
        }

        $envelope = $this->messageBus->dispatch(new ImportCompetitionParticipants($competitionId, $file));

        $result = $envelope->last(HandledStamp::class)?->getResult();
        assert($result instanceof ParticipantImportResult);

        foreach ($result->warnings as $warning) {
            $io->warning($warning);
        }

        foreach ($result->errors as $error) {
            $io->error($error);
        }

        $io->success(sprintf(
            'Import complete: %d added, %d updated, %d soft-deleted.',
            $result->added,
            $result->updated,
            $result->softDeleted,
        ));

        return self::SUCCESS;
    }
}
