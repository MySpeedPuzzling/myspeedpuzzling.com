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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filePath = __DIR__ . '/../../wjpc_individuals.csv';
        /** @var array<array{name: string, country: string, group: null|string, rank: null|int}> $data */
        $data = [];

        if (($handle = fopen($filePath, 'r')) !== false) {
            while (($row = fgetcsv($handle)) !== false) {
                [$name, $location, $country, $group, $rank] = $row;

                if ($rank === '') {
                    $rank = null;
                } else {
                    $rank = (int) str_replace(['#', 'º'], '', (string) $rank);
                }

                if ($group === '') {
                    $group = null;
                }

                $data[] = [
                    'name' => (string) $name,
                    'country' => (string) $country,
                    'group' => $group,
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
