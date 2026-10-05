<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\ConsoleCommands;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleSearchKeys;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The cleanup runs with --write on production: its safety rails end to end.
 */
final class CanonicalizePuzzleCodesConsoleCommandTest extends KernelTestCase
{
    private CommandTester $commandTester;

    private Connection $database;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());
        $this->commandTester = new CommandTester($application->find('myspeedpuzzling:canonicalize-puzzle-codes'));
        $this->database = self::getContainer()->get(Connection::class);

        // As typed before the lists: a format-only change in each field, and what is left to a person
        $this->database->executeStatement(
            'UPDATE puzzle SET ean = :ean, identification_number = :code, search_codes = :keys WHERE id = :id',
            [
                'ean' => '0091683108909, 6000-5468',
                'code' => 'rb-500-001',
                'keys' => PuzzleSearchKeys::codes('0091683108909, 6000-5468', 'rb-500-001'),
                'id' => PuzzleFixture::PUZZLE_500_01,
            ],
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function testADryRunWritesNothingAndReports(): void
    {
        $before = $this->codes();
        $report = $this->path('report');

        $this->commandTester->execute(['--report' => $report]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertSame($before, $this->codes(), 'not a single row changed');
        self::assertStringContainsString('Dry run', $this->commandTester->getDisplay());
        self::assertStringContainsString(PuzzleFixture::PUZZLE_500_01 . ',"Puzzle 1",ean,"0091683108909, 6000-5468",91683108909,ean_catalogue_number,6000-5468,rb-500-001,"RB-500-001, 6000-5468"', (string) file_get_contents($report));
    }

    public function testWriteWithoutAnUndoFileIsRefused(): void
    {
        $before = $this->codes();

        $this->commandTester->execute(['--write' => true]);

        self::assertSame(Command::INVALID, $this->commandTester->getStatusCode());
        self::assertSame($before, $this->codes());
    }

    public function testAnExistingUndoFileIsRefusedAndKept(): void
    {
        $before = $this->codes();
        $undo = $this->path('undo');
        file_put_contents($undo, "an interrupted run's values\n");

        $this->commandTester->execute(['--write' => true, '--undo' => $undo]);

        self::assertSame(Command::INVALID, $this->commandTester->getStatusCode());
        self::assertSame($before, $this->codes());
        self::assertSame("an interrupted run's values\n", file_get_contents($undo));
    }

    public function testWriteKeepsEveryValueBeforeAndASecondRunWritesNothing(): void
    {
        $undo = $this->path('undo');

        $this->commandTester->execute(['--write' => true, '--undo' => $undo]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertSame(
            ['ean' => '91683108909, 6000-5468', 'identification_number' => 'RB-500-001'],
            $this->database->fetchAssociative('SELECT ean, identification_number FROM puzzle WHERE id = :id', ['id' => PuzzleFixture::PUZZLE_500_01]),
        );
        self::assertSame(implode("\n", [
            'puzzle_id,field,before,after',
            PuzzleFixture::PUZZLE_500_01 . ',ean,"0091683108909, 6000-5468","91683108909, 6000-5468"',
            PuzzleFixture::PUZZLE_500_01 . ',identification_number,rb-500-001,RB-500-001',
        ]) . "\n", file_get_contents($undo));

        $after = $this->codes();
        $secondUndo = $this->path('undo-second');

        $this->commandTester->execute(['--write' => true, '--undo' => $secondUndo]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertSame($after, $this->codes());
        self::assertSame("puzzle_id,field,before,after\n", file_get_contents($secondUndo));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function codes(): array
    {
        return $this->database->fetchAllAssociative('SELECT id, ean, identification_number, search_codes FROM puzzle ORDER BY id');
    }

    private function path(string $name): string
    {
        $path = sys_get_temp_dir() . '/canonicalize-' . $name . '-' . bin2hex(random_bytes(6)) . '.csv';
        $this->files[] = $path;

        return $path;
    }
}
