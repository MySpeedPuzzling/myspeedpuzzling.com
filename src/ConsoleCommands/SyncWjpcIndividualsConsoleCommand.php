<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\SyncWjpcIndividualParticipants;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand('myspeedpuzzling:wjpc:sync-individuals')]
final class SyncWjpcIndividualsConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filePath = __DIR__ . '/../../wjpc_individuals.csv';
        /** @var array<array{name: string, country: string, rank: null|int}> $data */
        $data = [];

        if (($handle = fopen($filePath, 'r')) !== false) {
            while (($row = fgetcsv($handle)) !== false) {
                $rank = $row[2] ?? '';

                if ($rank === '') {
                    $rank = null;
                } else {
                    $rank = (int) str_replace(['#', 'º'], '', $rank);
                }

                $data[] = [
                    'name' => $row[0] ?? '',
                    'country' => $row[1] ?? '',
                    'rank' => $rank,
                ];
            }
            fclose($handle);
        } else {
            throw new \Exception("Unable to open the file.");
        }

        $this->messageBus->dispatch(
            new SyncWjpcIndividualParticipants($data),
        );

        return self::SUCCESS;
    }
}
