<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DataImport;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\BuildImportPuzzles;
use SpeedPuzzling\Web\Message\ConfirmImportUploaded;
use SpeedPuzzling\Web\Message\MatchImportPuzzlesFuzzy;
use SpeedPuzzling\Web\Message\ParseImportSheet;
use SpeedPuzzling\Web\Message\ProposeImportMappings;
use SpeedPuzzling\Web\Message\ReadImportFile;
use SpeedPuzzling\Web\Message\StartImport;
use SpeedPuzzling\Web\MessageHandler\ParseImportSheetHandler;
use SpeedPuzzling\Web\MessageHandler\ProposeImportMappingsHandler;
use SpeedPuzzling\Web\MessageHandler\ReadImportFileHandler;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Tests\Services\SpreadsheetReader\RawXlsx;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * An import read from a made-up workbook **by the real handlers** - the file stored, read, its columns recognised,
 * its rows parsed - for the screens whose messages read a row's own cells again (AnswerImportRows,
 * ConfirmImportMapping): a row seeded as "parsed" has no cells to read. Every puzzle and every row is made up.
 */
trait ImportedWorkbookScenario
{
    /**
     * An import of the player with one sheet: the columns Puzzle, Pieces, Date, Time and the rows given (from row 2
     * on).
     *
     * @param list<array{0: string, 1: int, 2: string, 3: int, 4?: string}> $rows name, pieces, day, seconds - and,
     *        with $peopleHeader, who it was solved with
     * @param string $title the sheet's title
     * @param null|string $peopleHeader the header of a fifth column naming the people - null: no such column
     */
    private function importedWorkbook(Player $player, array $rows, string $title = 'Results', null|string $peopleHeader = null): string
    {
        return $this->importedSheets($player, [$title => $rows], $peopleHeader);
    }

    /**
     * An import of the player with several sheets of the same columns, in the order given.
     *
     * @param array<string, list<array{0: string, 1: int, 2: string, 3: int, 4?: string}>> $sheets title => rows
     */
    private function importedSheets(Player $player, array $sheets, null|string $peopleHeader = null): string
    {
        $text = RawXlsx::inline(...);
        $workbook = (new RawXlsx())->styles(['[h]:mm:ss', 'yyyy-mm-dd']);

        foreach ($sheets as $title => $rows) {
            $cells = [1 => ['A' => $text('Puzzle'), 'B' => $text('Pieces'), 'C' => $text('Date'), 'D' => $text('Time')]];

            if ($peopleHeader !== null) {
                $cells[1]['E'] = $text($peopleHeader);
            }

            foreach ($rows as $index => $row) {
                [$name, $pieces, $day, $seconds] = $row;
                $cells[$index + 2] = [
                    'A' => $text($name),
                    'B' => RawXlsx::n((string) $pieces),
                    'C' => RawXlsx::n((string) (intdiv((int) (new DateTimeImmutable($day . ' UTC'))->format('U'), 86400) + 25569), 2),
                    'D' => RawXlsx::n((string) ($seconds / 86400), 1),
                ];

                if ($peopleHeader !== null && isset($row[4])) {
                    $cells[$index + 2]['E'] = $text($row[4]);
                }
            }

            $workbook->sheet((string) $title, RawXlsx::worksheet($cells));
        }

        $path = tempnam(sys_get_temp_dir(), 'msp-import-test');
        assert($path !== false);
        $workbook->save($path);
        $content = (string) file_get_contents($path);
        unlink($path);

        $bus = self::getContainer()->get(MessageBusInterface::class);
        $importId = Uuid::uuid7()->toString();
        $playerId = $player->id->toString();
        $bus->dispatch(new StartImport($importId, $playerId, 'Times.xlsx', strlen($content)));
        self::getContainer()->get(Filesystem::class)->write(ImportFileStorage::objectKey($playerId, $importId, DataImportFileKind::Xlsx), $content);
        $bus->dispatch(new ConfirmImportUploaded($importId, $playerId));
        $this->workImportsQueue();

        return $importId;
    }

    /**
     * The `imports` worker for reading, proposing and parsing - until nothing of them waits (the pairing's own
     * messages are let go: pair() runs them).
     */
    private function workImportsQueue(): void
    {
        $queue = self::getContainer()->get('messenger.transport.imports');
        self::assertInstanceOf(InMemoryTransport::class, $queue);

        for ($round = 0; $round < 20; $round++) {
            $messages = array_values(array_filter(
                array_map(static fn ($envelope): object => $envelope->getMessage(), $queue->getSent()),
                static fn (object $message): bool => $message instanceof BuildImportPuzzles === false && $message instanceof MatchImportPuzzlesFuzzy === false,
            ));

            if ($messages === []) {
                break;
            }

            $queue->reset();

            foreach ($messages as $message) {
                $handler = self::getContainer()->get(match (true) {
                    $message instanceof ReadImportFile => ReadImportFileHandler::class,
                    $message instanceof ProposeImportMappings => ProposeImportMappingsHandler::class,
                    $message instanceof ParseImportSheet => ParseImportSheetHandler::class,
                    default => self::fail('Nothing handles ' . $message::class),
                });
                self::assertIsCallable($handler);
                $entityManager = self::getContainer()->get(EntityManagerInterface::class);
                $entityManager->clear();
                $handler($message);
                $entityManager->clear();
            }
        }

        $queue->reset();
    }
}
