<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\RecordPuzzleImageAnalysis;
use SpeedPuzzling\Web\Query\GetPuzzleImageAnalyses;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\ImageAnalyzer;
use SpeedPuzzling\Web\Value\PuzzleImageAnalysisInput;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The bulk read's page is cut by rows and by bytes - the row crossing the byte cap is the last one, and the cursor
 * leads on to the rest.
 */
final class GetPuzzleImageAnalysesTest extends KernelTestCase
{
    private const array PUZZLES = [
        PuzzleFixture::PUZZLE_500_01,
        PuzzleFixture::PUZZLE_500_02,
        PuzzleFixture::PUZZLE_500_03,
        PuzzleFixture::PUZZLE_500_04,
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);
        $bus = self::getContainer()->get(MessageBusInterface::class);

        foreach (self::PUZZLES as $index => $puzzleId) {
            $image = 'bytes-cap-' . $index . '.jpg';
            $database->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => $image, 'id' => $puzzleId]);

            // ~1 KB of result each
            $bus->dispatch(new RecordPuzzleImageAnalysis(new PuzzleImageAnalysisInput(
                $puzzleId,
                ImageAnalyzer::EmbedDinov2,
                'v1',
                PuzzleImageFingerprint::of($image),
                null,
                ['cls' => str_repeat('A', 1000)],
            )));
        }
    }

    public function testAPageEndsOnceItsResultsPassTheByteCap(): void
    {
        $query = self::getContainer()->get(GetPuzzleImageAnalyses::class);

        // 2,500 bytes: the first, the second, and the third that crosses the cap
        $first = $query->page(ImageAnalyzer::EmbedDinov2, 1000, null, false, maxBytes: 2500);
        self::assertSame(array_slice(self::PUZZLES, 0, 3), array_map(static fn ($analysis): string => $analysis->puzzleId, $first['items']));
        self::assertSame(PuzzleFixture::PUZZLE_500_03, $first['nextCursor']);

        $second = $query->page(ImageAnalyzer::EmbedDinov2, 1000, $first['nextCursor'], false, maxBytes: 2500);
        self::assertSame([PuzzleFixture::PUZZLE_500_04], array_map(static fn ($analysis): string => $analysis->puzzleId, $second['items']));
        self::assertNull($second['nextCursor']);

        // A single row bigger than the cap still comes - one row a page at least
        $tiny = $query->page(ImageAnalyzer::EmbedDinov2, 1000, null, false, maxBytes: 10);
        self::assertCount(1, $tiny['items']);
        self::assertSame(PuzzleFixture::PUZZLE_500_01, $tiny['nextCursor']);

        // The row cap still applies
        $byRows = $query->page(ImageAnalyzer::EmbedDinov2, 2, null, false);
        self::assertCount(2, $byRows['items']);
        self::assertSame(PuzzleFixture::PUZZLE_500_02, $byRows['nextCursor']);
    }
}
