<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Sandbox;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\SandboxCatalogueFileInvalid;
use SpeedPuzzling\Web\Services\Sandbox\SandboxCatalogue;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SandboxCatalogueTest extends KernelTestCase
{
    private SandboxCatalogue $catalogue;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->catalogue = self::getContainer()->get(SandboxCatalogue::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheExportCarriesOnlyTheListedColumnsOfTheListedTables(): void
    {
        $tables = [];

        foreach ($this->catalogue->export() as $line) {
            [$table, $json] = explode("\t", $line, 2);
            $row = json_decode($json, true);
            self::assertIsArray($row);
            self::assertArrayHasKey($table, SandboxCatalogue::COLUMNS);
            self::assertSame(SandboxCatalogue::COLUMNS[$table], array_keys($row), $table);
            $tables[$table] = true;
        }

        // The fixtures have brands, puzzles and public events - every one of those tables is exported
        foreach (['manufacturer', 'puzzle', 'competition', 'competition_round'] as $table) {
            self::assertArrayHasKey($table, $tables);
        }
    }

    public function testNoColumnNamesAPlayerAndNoTableHoldsPeople(): void
    {
        foreach (SandboxCatalogue::COLUMNS as $table => $columns) {
            self::assertNotContains($table, ['player', 'user_account', 'puzzle_solving_time', 'competition_participant', 'membership']);

            foreach ($columns as $column) {
                self::assertDoesNotMatchRegularExpression('/(added_by|approved_by|rejected_by|player|user|email|rejection_reason|upload_metadata)/', $column, $table . '.' . $column);
            }
        }
    }

    public function testSecretAndUnapprovedPuzzlesStayOut(): void
    {
        $this->database->executeStatement('UPDATE puzzle SET approved = false WHERE id = :id', ['id' => PuzzleFixture::PUZZLE_500_02]);

        $puzzleIds = $this->exportedIds('puzzle');

        self::assertContains(PuzzleFixture::PUZZLE_500_01, $puzzleIds);
        self::assertNotContains(PuzzleFixture::PUZZLE_500_02, $puzzleIds);
        self::assertNotContains(CompetitionApiFixture::PUZZLE_PLATFORM_HIDDEN, $puzzleIds);
        self::assertNotContains(CompetitionApiFixture::PUZZLE_PLATFORM_IMAGE_HIDDEN, $puzzleIds);

        $roundPuzzleIds = [];

        foreach ($this->catalogue->export() as $line) {
            [$table, $json] = explode("\t", $line, 2);

            if ($table === 'competition_round_puzzle') {
                $row = json_decode($json, true);
                self::assertIsArray($row);
                $roundPuzzleIds[] = $row['puzzle_id'];
            }
        }

        self::assertNotContains(CompetitionApiFixture::PUZZLE_PLATFORM_HIDDEN, $roundPuzzleIds);
    }

    public function testAnImportInsertsNewRowsAndSkipsExistingOnes(): void
    {
        $lines = iterator_to_array($this->catalogue->export(), false);

        $newManufacturerId = Uuid::uuid7()->toString();
        $newPuzzleId = Uuid::uuid7()->toString();
        $lines[] = 'manufacturer' . "\t" . json_encode(['id' => $newManufacturerId, 'name' => 'Sandbox Brand', 'approved' => true, 'added_at' => '2025-01-02T03:04:05', 'logo' => null, 'ean_prefix' => null, 'slug' => 'sandbox-brand']);
        // The puzzle line must come after every manufacturer line and before the later tables - put it right after them
        $puzzleLine = 'puzzle' . "\t" . json_encode([
            'id' => $newPuzzleId, 'pieces_count' => 1000, 'name' => 'Sandbox Puzzle', 'approved' => true, 'image' => null,
            'image_ratio' => null, 'added_at' => '2025-01-02T03:04:05', 'is_available' => true, 'approved_at' => null,
            'manufacturer_id' => $newManufacturerId, 'alternative_names' => [['name' => 'Pískoviště', 'language' => 'cs']],
            'name_language' => 'en', 'names_changed_at' => null, 'search_names' => "sandbox puzzle\npiskoviste", 'search_codes' => null,
            'identification_number' => null, 'ean' => null,
        ]);

        $ordered = self::inExportOrder([...$lines, $puzzleLine]);

        $counts = $this->catalogue->import($ordered);
        self::assertGreaterThan(0, $counts['puzzle']);

        $row = $this->database->fetchAssociative('SELECT name, pieces_count, approved, alternative_names, added_at FROM puzzle WHERE id = :id', ['id' => $newPuzzleId]);
        self::assertIsArray($row);
        self::assertSame('Sandbox Puzzle', $row['name']);
        self::assertSame(1000, $row['pieces_count']);
        self::assertTrue($row['approved']);
        self::assertIsString($row['alternative_names']);
        self::assertSame([['name' => 'Pískoviště', 'language' => 'cs']], json_decode($row['alternative_names'], true));
        self::assertSame('2025-01-02 03:04:05', $row['added_at']);

        // Again - nothing new, nothing doubled
        $before = $this->database->fetchOne('SELECT COUNT(*) FROM puzzle');
        $this->catalogue->import($ordered);
        self::assertSame($before, $this->database->fetchOne('SELECT COUNT(*) FROM puzzle'));
    }

    public function testAColumnTheExportDoesNotNameIsRefused(): void
    {
        $this->expectException(SandboxCatalogueFileInvalid::class);

        $this->catalogue->import(['puzzle' . "\t" . json_encode(['id' => Uuid::uuid7()->toString(), 'added_by_user_id' => PuzzleFixture::PUZZLE_500_01])]);
    }

    public function testATableTheExportDoesNotNameIsRefused(): void
    {
        $this->expectException(SandboxCatalogueFileInvalid::class);

        $this->catalogue->import(['player' . "\t" . json_encode(['id' => Uuid::uuid7()->toString()])]);
    }

    /**
     * @return list<string>
     */
    private function exportedIds(string $wanted): array
    {
        $ids = [];

        foreach ($this->catalogue->export() as $line) {
            [$table, $json] = explode("\t", $line, 2);

            if ($table === $wanted) {
                $row = json_decode($json, true);
                self::assertIsArray($row);
                self::assertIsString($row['id']);
                $ids[] = $row['id'];
            }
        }

        return $ids;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function inExportOrder(array $lines): array
    {
        $order = array_flip(array_keys(SandboxCatalogue::COLUMNS));
        usort($lines, static fn (string $a, string $b): int => ($order[explode("\t", $a, 2)[0]] ?? 0) <=> ($order[explode("\t", $b, 2)[0]] ?? 0));

        return $lines;
    }
}
