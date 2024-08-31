<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\ImportCompetitionData;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand('myspeedpuzzling:competitions:import')]
final class ImportCompetitionsDataConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filePath = __DIR__ . '/../../competitions_data.csv';

        if (($handle = fopen($filePath, 'r')) !== false) {
            $rowNumber = 0;

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                // Header, skipping...
                if ($rowNumber === 1) {
                    continue;
                }

                // Replace empty strings with null for all data
                foreach ($row as $key => $val) {
                    if ($val === '') {
                        $row[$key] = null;
                    }
                }

                [
                    $competitionName,
                    $competitionDateFrom,
                    $competitionDateTo,
                    $competitionLocation,
                    $roundStart,
                    $roundTimeLimit,
                    $puzzlePieces,
                    $puzzleBrand,
                    $roundName,
                    $puzzleName,
                    $playerName,
                    $playerLocation,
                    $resultTime,
                    $resultMissingPieces,
                    $resultQualified,
                ] = $row;

                $this->messageBus->dispatch(
                    new ImportCompetitionData(
                        $competitionName,
                        $competitionDateFrom,
                        $competitionDateTo,
                        $competitionLocation,
                        $roundStart,
                        $roundTimeLimit,
                        $puzzlePieces,
                        $puzzleBrand,
                        $roundName,
                        $puzzleName,
                        $playerName,
                        $playerLocation,
                        $resultTime,
                        $resultMissingPieces,
                        $resultQualified,
                    ),
                );
            }
            fclose($handle);
        } else {
            throw new \Exception("Unable to open the file.");
        }

        return self::SUCCESS;
    }
}
