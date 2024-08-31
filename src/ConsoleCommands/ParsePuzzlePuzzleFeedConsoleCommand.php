<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\UpdatePuzzlePuzzleUrl;
use SpeedPuzzling\Web\Results\PuzzleForRemoteSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand('myspeedpuzzling:feed:puzzle-puzzle')]
final class ParsePuzzlePuzzleFeedConsoleCommand extends Command
{
    public function __construct(
        readonly private Connection $database,
        readonly private string $puzzlePuzzleUsername,
        readonly private string $puzzlePuzzlePassword,
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $feedUrl = sprintf(
            'https://www.puzzle-puzzle.cz/xml/myspeedpuzzling.xml?u=%s&p=%s',
            $this->puzzlePuzzleUsername,
            $this->puzzlePuzzlePassword,
        );

        $fileContent = file_get_contents($feedUrl);

        if (!is_string($fileContent)) {
            throw new \Exception('Could not load feed.');
        }

        $xml = new \SimpleXMLElement($fileContent);

        $ourPuzzle = $this->getExistingPuzzleDatabase();

        // mapping: EAN => URL
        /** @var array<string, string> $puzzlePuzzleUrls */
        $puzzlePuzzleUrls = [];

        foreach ($xml->SHOPITEM as $shopItem) {
            if (isset($shopItem->EAN)) {
                $eanFromFeed = ltrim((string) $shopItem->EAN, '0');

                if (isset($ourPuzzle[$eanFromFeed])) {
                    $puzzlePuzzleUrls[$eanFromFeed] = (string) $shopItem->URL;
                }
            }
        }

        // Puzzle that are in our database, has URL but are no longer available on puzzle-puzzle
        // Remove remote url for those
        foreach ($ourPuzzle as $puzzle) {
            $ean = ltrim($puzzle->ean, '0');

            if (!isset($puzzlePuzzleUrls[$ean]) && $puzzle->remoteUrl !== null) {
                $output->writeln('Setting remote_url to null for puzzle EAN: ' . $ean);

                $this->messageBus->dispatch(
                    new UpdatePuzzlePuzzleUrl($puzzle->puzzleId, null),
                );
            }
        }

        // Remote puzzle that are in our database, compare URLs, if not matching update it
        foreach ($puzzlePuzzleUrls as $ean => $puzzlePuzzleUrl) {
            $puzzle = $ourPuzzle[$ean] ?? null; // It should never be null but just to make sure

            if ($puzzle !== null && $puzzle->remoteUrl !== $puzzlePuzzleUrl) {
                $output->writeln('Updating remote_url for puzzle EAN: ' . $ean);

                $this->messageBus->dispatch(
                    new UpdatePuzzlePuzzleUrl($puzzle->puzzleId, $puzzlePuzzleUrl),
                );
            }
        }


        return self::SUCCESS;
    }

    // Mapping EAN -> UUID
    /** @return array<string, PuzzleForRemoteSync> */
    private function getExistingPuzzleDatabase(): array
    {
        $query = <<<SQL
SELECT ean, id AS puzzle_id, remote_puzzle_puzzle_url
FROM puzzle
WHERE ean IS NOT NULL
SQL;

        $data = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        /** @return array<string, PuzzleForRemoteSync> */
        $results = [];

        foreach ($data as $row) {
            /** @var array{ean: string, puzzle_id: string, remote_puzzle_puzzle_url: null|string} $row */

            $results[$row['ean']] = PuzzleForRemoteSync::fromDatabaseRow($row);
        }

        return $results;
    }
}
