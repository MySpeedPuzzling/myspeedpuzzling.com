<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Entity\DataImportSheet;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Message\BuildImportPuzzles;
use SpeedPuzzling\Web\Message\MatchImportPuzzlesFuzzy;
use SpeedPuzzling\Web\MessageHandler\BuildImportPuzzlesHandler;
use SpeedPuzzling\Web\MessageHandler\MatchImportPuzzlesFuzzyHandler;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleKey;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleNames;

/**
 * Imports with parsed rows and catalogue puzzles for the pairing's tests - invented puzzles only, each test's own
 * (their names carry a marker no fixture puzzle has).
 */
trait ImportPairingScenario
{
    private static int $pairingRowNumber = 1;

    private function pairingDatabase(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function pairingEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function seedPairingPlayer(string $name): Player
    {
        $player = self::getContainer()->get(PlayerRepository::class)->getByUserIdCreateIfNotExists('pairing|' . $name . '-' . Uuid::uuid7()->toString());
        $this->pairingEntityManager()->flush();

        return $player;
    }

    private function seedBrand(string $name, bool $approved = true): Manufacturer
    {
        $brand = new Manufacturer(Uuid::uuid7(), $name, $approved, null, TestClock::now());
        $this->pairingEntityManager()->persist($brand);
        $this->pairingEntityManager()->flush();

        return $brand;
    }

    /**
     * @param list<string> $eans
     * @param list<string> $brandCodes
     */
    private function seedCataloguePuzzle(
        string $name,
        int $pieces,
        null|Manufacturer $brand = null,
        array $eans = [],
        array $brandCodes = [],
        bool $approved = true,
        null|Player $addedBy = null,
        null|DateTimeImmutable $hideUntil = null,
        null|DateTimeImmutable $hideImageUntil = null,
        PuzzleNames $otherNames = new PuzzleNames(),
    ): string {
        $puzzle = new Puzzle(
            Uuid::uuid7(),
            $pieces,
            $name,
            $approved,
            manufacturer: $brand,
            alternativeNames: $otherNames,
            addedByUser: $addedBy,
            addedAt: TestClock::now(),
            brandCodes: BrandCodeList::fromInputs($brandCodes),
            eans: EanList::fromInputs($eans),
            isAvailable: true,
            hideImageUntil: $hideImageUntil,
            hideUntil: $hideUntil,
        );
        $this->pairingEntityManager()->persist($puzzle);
        $this->pairingEntityManager()->flush();

        return $puzzle->id->toString();
    }

    /**
     * An import that was read and is being set up, with one sheet.
     *
     * @return array{DataImport, DataImportSheet}
     */
    private function seedImport(Player $player): array
    {
        $now = TestClock::now();
        $import = DataImport::uploadOfFile(Uuid::uuid7(), $player, 'results.xlsx', DataImportFileKind::Xlsx, 1000, 'imports/' . Uuid::uuid7()->toString(), $now);
        $import->uploadConfirmed($now);
        $import->readingStarted($now);
        $import->readingFinished($now);
        $sheet = new DataImportSheet(Uuid::uuid7(), $import, 0, 'Results');
        $this->pairingEntityManager()->persist($import);
        $this->pairingEntityManager()->persist($sheet);
        $this->pairingEntityManager()->flush();

        return [$import, $sheet];
    }

    /**
     * A parsed row as ImportRowParser leaves it: the puzzle's name, pieces and brand as written, its key, anything
     * else of `parsed` in `$more` (barcodes, brandCodes, puzzleId, date, datePrecision, seconds, type).
     *
     * @param array<string, mixed> $more
     */
    private function seedRow(
        DataImport $import,
        DataImportSheet $sheet,
        string $name,
        null|int $pieces = null,
        null|string $brand = null,
        array $more = [],
        DataImportRowKind $kind = DataImportRowKind::Result,
        null|string $sourceKey = null,
    ): string {
        $number = ++self::$pairingRowNumber;
        $row = new DataImportRow(Uuid::uuid7(), $import, $sheet, $number, 1, 1);
        $row->kind = $kind;
        $row->parsedVersion = 1;
        $row->parsed = array_filter(['name' => $name, 'pieces' => $pieces, 'brand' => $brand, ...$more], static fn (mixed $value): bool => $value !== null);
        $row->puzzleKey = ImportPuzzleKey::of($name, $pieces, $brand);
        $row->sourceKey = $sourceKey ?? '1:' . hash('sha256', 'row-' . $number . '-' . $row->id->toString());
        $row->equalKey = $row->sourceKey;
        $this->pairingEntityManager()->persist($row);
        $this->pairingEntityManager()->flush();

        return $row->id->toString();
    }

    /**
     * The pairing the way the queue runs it: the puzzles built and paired by the exact rules, then the similar-name
     * search until nothing waits.
     */
    private function pair(DataImport|string $import): void
    {
        $importId = is_string($import) ? $import : $import->id->toString();

        $this->handled(self::getContainer()->get(BuildImportPuzzlesHandler::class), new BuildImportPuzzles($importId));

        for ($chunk = 0; $chunk < 50; $chunk++) {
            /** @var array{pairing_run: int, paired: bool} $state */
            $state = $this->pairingDatabase()->fetchAssociative('SELECT pairing_run, paired_at IS NOT NULL AS paired FROM data_import WHERE id = :id', ['id' => $importId]);

            if ($state['paired']) {
                break;
            }

            $this->handled(self::getContainer()->get(MatchImportPuzzlesFuzzyHandler::class), new MatchImportPuzzlesFuzzy($importId, $state['pairing_run']));
        }

        $this->pairingEntityManager()->clear();
    }

    /**
     * A handler the way the bus runs it for a consumed message: on a fresh entity manager, inside a transaction that
     * is flushed and committed after it (the `doctrine_transaction` middleware) - a message the handler sends from
     * inside does not clear the entity manager then (ClearEntityManagerMiddleware).
     */
    private function handled(callable $handler, object $message): void
    {
        $entityManager = $this->pairingEntityManager();
        $entityManager->clear();
        $entityManager->getConnection()->beginTransaction();

        try {
            $handler($message);
            $entityManager->flush();
            $entityManager->getConnection()->commit();
        } catch (\Throwable $refused) {
            // The test goes on with the same entity manager: rolled back, never closed
            $entityManager->getConnection()->rollBack();

            throw $refused;
        } finally {
            $entityManager->clear();
        }
    }

    /**
     * @return array{id: string, tier: null|string, choice: string, puzzle_id: null|string, decided_how: null|string, decided_at: null|string, candidates: list<array{puzzleId: string, how: string, score: null|float, piecesDiffer: bool}>, manufacturer_id: null|string, rows_results: int, rows_owned: int}
     */
    private function importPuzzle(DataImport|string $import, string $name): array
    {
        $row = $this->pairingDatabase()->fetchAssociative(
            'SELECT id, tier, choice, puzzle_id, decided_how, decided_at, candidates, manufacturer_id, rows_results, rows_owned FROM data_import_puzzle WHERE import_id = :importId AND name = :name',
            ['importId' => is_string($import) ? $import : $import->id->toString(), 'name' => $name],
        );
        self::assertIsArray($row, sprintf('The import has no puzzle "%s".', $name));
        self::assertIsString($row['candidates']);
        $row['candidates'] = json_decode($row['candidates'], true, flags: JSON_THROW_ON_ERROR);

        /** @var array{id: string, tier: null|string, choice: string, puzzle_id: null|string, decided_how: null|string, decided_at: null|string, candidates: list<array{puzzleId: string, how: string, score: null|float, piecesDiffer: bool}>, manufacturer_id: null|string, rows_results: int, rows_owned: int} $row */
        return $row;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function pairingCount(string $sql, array $parameters = []): int
    {
        $count = $this->pairingDatabase()->fetchOne($sql, $parameters);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * `data_import.pairing_counters` ("where the rules end").
     *
     * @return array{puzzles: int, tiers: array<string, int>, remembered: int, player: array<string, int>}
     */
    private function pairingCounters(DataImport|string $import): array
    {
        $json = $this->pairingDatabase()->fetchOne('SELECT pairing_counters FROM data_import WHERE id = :id', ['id' => is_string($import) ? $import : $import->id->toString()]);
        self::assertIsString($json);

        /** @var array{puzzles: int, tiers: array<string, int>, remembered: int, player: array<string, int>} $counters */
        $counters = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $counters;
    }

    /**
     * The import and its sheet as the database has them - after pair() the ones a test holds are detached.
     *
     * @return array{DataImport, DataImportSheet}
     */
    private function reloadImport(DataImport|string $import): array
    {
        $import = $this->pairingEntityManager()->find(DataImport::class, is_string($import) ? $import : $import->id->toString());
        self::assertNotNull($import);
        $sheet = $this->pairingEntityManager()->getRepository(DataImportSheet::class)->findOneBy(['import' => $import]);
        self::assertNotNull($sheet);

        return [$import, $sheet];
    }

    /**
     * A valid EAN-13 no fixture puzzle carries: the 12 digits given and their check digit.
     */
    private static function ean(string $twelveDigits): string
    {
        self::assertSame(12, strlen($twelveDigits));
        $sum = 0;

        foreach (str_split($twelveDigits) as $position => $digit) {
            $sum += (int) $digit * ($position % 2 === 0 ? 1 : 3);
        }

        return $twelveDigits . ((10 - $sum % 10) % 10);
    }

    /**
     * @param array{candidates: list<array{puzzleId: string, how: string, score: null|float, piecesDiffer: bool}>, ...} $importPuzzle
     *
     * @return list<string>
     */
    private static function candidateIds(array $importPuzzle): array
    {
        return array_map(static fn (array $candidate): string => $candidate['puzzleId'], $importPuzzle['candidates']);
    }
}
