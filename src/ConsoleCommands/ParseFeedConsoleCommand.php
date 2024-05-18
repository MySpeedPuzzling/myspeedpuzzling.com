<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('myspeedpuzzling:feed:parse')]
final class ParseFeedConsoleCommand extends Command
{
    private const string FEED_URL_ARGUMENT = 'feedUrl';

    public function __construct(
        readonly private Connection $database,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();

        $this->addArgument(self::FEED_URL_ARGUMENT, InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $feedUrl */
        $feedUrl = $input->getArgument(self::FEED_URL_ARGUMENT);

        $fileContent = file_get_contents($feedUrl);

        if (!is_string($fileContent)) {
            throw new \Exception('Could not load feed.');
        }

        $xml = new \SimpleXMLElement($fileContent);

        /** @var array<string, string> $ourPuzzle */
        $ourPuzzle = $this->getExistingPuzzleDatabase();

        foreach ($xml->SHOPITEM as $shopItem) {
            if (isset($shopItem->EAN)) {
                $eanFromFeed = (string) $shopItem->EAN;

                if (isset($ourPuzzle[$eanFromFeed])) {
                    $idFromFeed = (string) $shopItem->ITEM_ID;
                    $urlFromFeed = (string) $shopItem->URL;

                    $output->writeln('Syncing product EAN: ' . $eanFromFeed);
                }
            }
        }

        return self::SUCCESS;
    }

    // Mapping EAN -> UUID
    /** @return array<string, string> */
    private function getExistingPuzzleDatabase(): array
    {
        $query = <<<SQL
SELECT ean, id
FROM puzzle
WHERE ean IS NOT NULL
SQL;

        $data = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        /** @return array<string, string> */
        $results = [];

        foreach ($data as $row) {
            /** @var array{ean: string, id: string} $row */

            $results[$row['ean']] = $row['id'];
        }

        return $results;
    }
}
