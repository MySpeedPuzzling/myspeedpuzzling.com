<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\PuzzleImageChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\RecordPuzzleImageAnalyses;
use SpeedPuzzling\Web\Message\RecordPuzzleImageAnalysis;
use SpeedPuzzling\Web\Query\GetPuzzleImageAnalyses;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\ImageAnalyzer;
use SpeedPuzzling\Web\Value\PuzzleImageAnalysisInput;
use SpeedPuzzling\Web\Value\PuzzleImageAnalysisOutcome;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Storing analyses of puzzle box images (docs/features/internal-api.md, "Puzzle image analysis"): one current row per
 * puzzle and analyzer, replaced by a newer push; only for the puzzle's current picture and never for a hidden image;
 * a batch stores what it can and tells why it did not store the rest.
 */
final class RecordPuzzleImageAnalysisHandlerTest extends KernelTestCase
{
    private const string IMAGE = 'ravensburger-test-500-018d0003-0a1b2c3d.jpg';

    private const string HIDDEN_IMAGE = 'secret-image.jpg';

    private const string UNKNOWN_PUZZLE = '018d0003-0000-0000-0000-0000000009ff';

    protected function setUp(): void
    {
        self::bootKernel();

        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => self::IMAGE, 'id' => PuzzleFixture::PUZZLE_500_01]);
        $database->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => self::HIDDEN_IMAGE, 'id' => PuzzleFixture::PUZZLE_HIDDEN_IMAGE]);
    }

    public function testStoresTheFirstAnalysisAndReplacesItWithTheNextOne(): void
    {
        $this->dispatch(new RecordPuzzleImageAnalysis(self::input(ImageAnalyzer::Colors, 'colors-1', ['palette' => ['#ff0000']])));
        $this->dispatch(new RecordPuzzleImageAnalysis(self::input(ImageAnalyzer::Colors, 'colors-2', ['palette' => ['#00ff00']], new DateTimeImmutable('2026-10-18T08:00:00+02:00'))));

        self::assertSame(1, $this->rowsOf(PuzzleFixture::PUZZLE_500_01));

        $stored = self::getContainer()->get(GetPuzzleImageAnalyses::class)->one(PuzzleFixture::PUZZLE_500_01, ImageAnalyzer::Colors);
        self::assertNotNull($stored);
        self::assertSame('colors-2', $stored->analyzerVersion);
        self::assertSame(['palette' => ['#00ff00']], $stored->result);
        self::assertTrue($stored->current);
        self::assertSame('2026-10-18 06:00:00', $stored->analyzedAt?->format('Y-m-d H:i:s'));
    }

    public function testEachAnalyzerHasItsOwnRow(): void
    {
        $this->dispatch(new RecordPuzzleImageAnalysis(self::input(ImageAnalyzer::Colors, 'v1', ['palette' => []])));
        $this->dispatch(new RecordPuzzleImageAnalysis(self::input(ImageAnalyzer::Artwork, 'v1', ['bbox' => ['x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.8]])));

        self::assertSame(2, $this->rowsOf(PuzzleFixture::PUZZLE_500_01));
        self::assertSame(['artwork', 'colors'], array_keys(self::getContainer()->get(GetPuzzleImageAnalyses::class)->ofPuzzle(PuzzleFixture::PUZZLE_500_01)));
    }

    public function testRefusesAnAnalysisOfAnotherPicture(): void
    {
        try {
            $this->dispatch(new RecordPuzzleImageAnalysis(self::input(ImageAnalyzer::Tags, 'v1', ['tags' => ['cats']], fingerprint: PuzzleImageFingerprint::of('the-old-picture.jpg'))));
            self::fail('An analysis of another picture must be refused');
        } catch (PuzzleImageChangedMeanwhile $exception) {
            self::assertSame(PuzzleImageFingerprint::of(self::IMAGE), $exception->currentFingerprint);
        }

        self::assertSame(0, $this->rowsOf(PuzzleFixture::PUZZLE_500_01));
    }

    public function testRefusesAPuzzleWithoutImage(): void
    {
        $this->expectException(PuzzleImageChangedMeanwhile::class);

        $this->dispatch(new RecordPuzzleImageAnalysis(new PuzzleImageAnalysisInput(PuzzleFixture::PUZZLE_500_02, ImageAnalyzer::Tags, 'v1', 'whatever', null, ['tags' => []])));
    }

    public function testAHiddenImageReadsAsUnknown(): void
    {
        try {
            $this->dispatch(new RecordPuzzleImageAnalysis(new PuzzleImageAnalysisInput(
                PuzzleFixture::PUZZLE_HIDDEN_IMAGE,
                ImageAnalyzer::Tags,
                'v1',
                PuzzleImageFingerprint::of(self::HIDDEN_IMAGE),
                null,
                ['tags' => ['secret']],
            )));
            self::fail('A hidden image must never take an analysis');
        } catch (PuzzleNotFound) {
        }

        self::assertSame(0, $this->rowsOf(PuzzleFixture::PUZZLE_HIDDEN_IMAGE));
    }

    public function testRefusesAnUnknownPuzzle(): void
    {
        $this->expectException(PuzzleNotFound::class);

        $this->dispatch(new RecordPuzzleImageAnalysis(new PuzzleImageAnalysisInput(self::UNKNOWN_PUZZLE, ImageAnalyzer::Tags, 'v1', 'x', null, ['tags' => []])));
    }

    public function testABatchStoresWhatItCanAndSaysWhyNotTheRest(): void
    {
        $envelope = $this->dispatch(new RecordPuzzleImageAnalyses([
            0 => self::input(ImageAnalyzer::Colors, 'v1', ['palette' => ['#000000']]),
            2 => self::input(ImageAnalyzer::Tags, 'v1', ['tags' => ['cats']], fingerprint: 'stale'),
            3 => new PuzzleImageAnalysisInput(self::UNKNOWN_PUZZLE, ImageAnalyzer::Tags, 'v1', 'x', null, ['tags' => []]),
            4 => new PuzzleImageAnalysisInput(PuzzleFixture::PUZZLE_HIDDEN_IMAGE, ImageAnalyzer::Tags, 'v1', PuzzleImageFingerprint::of(self::HIDDEN_IMAGE), null, ['tags' => []]),
            // The same puzzle and analyzer again - the later one wins, no unique violation
            5 => self::input(ImageAnalyzer::Colors, 'v2', ['palette' => ['#ffffff']]),
        ]));

        $handled = $envelope->last(HandledStamp::class);
        self::assertInstanceOf(HandledStamp::class, $handled);
        /** @var array<int, PuzzleImageAnalysisOutcome> $outcomes */
        $outcomes = $handled->getResult();

        self::assertSame(
            [0 => 'stored', 2 => 'stale_fingerprint', 3 => 'not_found', 4 => 'not_found', 5 => 'stored'],
            array_map(static fn (PuzzleImageAnalysisOutcome $outcome): string => $outcome->status, $outcomes),
        );
        self::assertSame(PuzzleImageFingerprint::of(self::IMAGE), $outcomes[2]->currentFingerprint);

        self::assertSame(1, $this->rowsOf(PuzzleFixture::PUZZLE_500_01));
        self::assertSame(0, $this->rowsOf(PuzzleFixture::PUZZLE_HIDDEN_IMAGE));
        self::assertSame('v2', self::getContainer()->get(GetPuzzleImageAnalyses::class)->one(PuzzleFixture::PUZZLE_500_01, ImageAnalyzer::Colors)?->analyzerVersion);
    }

    /**
     * A merge deletes the merged puzzle - its analyses go with it (PuzzleMergeForeignKeyCoverageTest), never blocking it
     */
    public function testTheAnalysesAreDeletedWithThePuzzle(): void
    {
        $onDelete = self::getContainer()->get(Connection::class)->fetchOne(<<<SQL
SELECT c.confdeltype
FROM pg_constraint c
WHERE c.conrelid = 'puzzle_image_analysis'::regclass AND c.confrelid = 'puzzle'::regclass AND c.contype = 'f'
SQL);

        self::assertSame('c', $onDelete);
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function input(
        ImageAnalyzer $analyzer,
        string $version,
        array $result,
        null|DateTimeImmutable $analyzedAt = null,
        null|string $fingerprint = null,
    ): PuzzleImageAnalysisInput {
        return new PuzzleImageAnalysisInput(
            PuzzleFixture::PUZZLE_500_01,
            $analyzer,
            $version,
            $fingerprint ?? PuzzleImageFingerprint::of(self::IMAGE),
            $analyzedAt?->setTimezone(new DateTimeZone('UTC')),
            $result,
        );
    }

    private function dispatch(object $message): Envelope
    {
        return self::getContainer()->get(MessageBusInterface::class)->dispatch($message);
    }

    private function rowsOf(string $puzzleId): int
    {
        $count = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM puzzle_image_analysis WHERE puzzle_id = :id',
            ['id' => $puzzleId],
        );
        assert(is_int($count));

        return $count;
    }
}
