<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\RecordPuzzleDifficultyEstimates;
use SpeedPuzzling\Web\Query\GetPuzzleDifficultyEstimates;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleDifficultyEstimateInput;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The read side for the app's image prior (implementation-plan.md §5.4): only an estimate of the puzzle's current
 * picture, never one of a secret puzzle or picture - whatever is stored.
 */
final class GetPuzzleDifficultyEstimatesTest extends KernelTestCase
{
    private const array IMAGES = [
        PuzzleFixture::PUZZLE_500_01 => 'box-500-01-018d0003-eeee0001.jpg',
        PuzzleFixture::PUZZLE_500_02 => 'box-500-02-018d0003-eeee0002.jpg',
        PuzzleFixture::PUZZLE_500_03 => 'box-500-03-018d0003-eeee0003.jpg',
        PuzzleFixture::PUZZLE_1000_01 => 'box-1000-01-018d0003-eeee0004.jpg',
    ];

    protected function setUp(): void
    {
        self::bootKernel();

        foreach (self::IMAGES as $puzzleId => $image) {
            $this->database()->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => $image, 'id' => $puzzleId]);
        }

        $estimates = [];

        foreach (array_keys(self::IMAGES) as $index => $puzzleId) {
            $estimates[] = new PuzzleDifficultyEstimateInput(
                puzzleId: $puzzleId,
                model: 'ridge-siglip2-dinov2',
                modelVersion: '1.0.0',
                logDifficulty: 0.1 * $index,
                logP10: 0.1 * $index - 0.2,
                logP90: 0.1 * $index + 0.25,
                outOfFold: $index % 2 === 0,
                trainingSize: 4979,
                imageFingerprint: PuzzleImageFingerprint::of(self::IMAGES[$puzzleId]),
                computedAt: null,
            );
        }

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new RecordPuzzleDifficultyEstimates($estimates));
    }

    public function testReadsTheEstimatesOfManyPuzzlesAtOnce(): void
    {
        $estimates = self::getContainer()->get(GetPuzzleDifficultyEstimates::class)->forPuzzles([
            PuzzleFixture::PUZZLE_500_01,
            strtoupper(PuzzleFixture::PUZZLE_500_02),
            PuzzleFixture::PUZZLE_500_05,
            'not-an-id',
        ]);

        self::assertSame([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02], self::sortedKeys($estimates));

        $second = $estimates[PuzzleFixture::PUZZLE_500_02];
        self::assertSame('ridge-siglip2-dinov2', $second->model);
        self::assertSame('1.0.0', $second->modelVersion);
        self::assertEqualsWithDelta(0.1, $second->logDifficulty, 0.0000001);
        self::assertEqualsWithDelta(-0.1, $second->logP10, 0.0000001);
        self::assertEqualsWithDelta(0.35, $second->logP90, 0.0000001);
        self::assertFalse($second->outOfFold);
        self::assertTrue($estimates[PuzzleFixture::PUZZLE_500_01]->outOfFold);
        self::assertSame(4979, $second->trainingSize);
        self::assertSame(PuzzleImageFingerprint::of(self::IMAGES[PuzzleFixture::PUZZLE_500_02]), $second->imageFingerprint);

        self::assertSame([], self::getContainer()->get(GetPuzzleDifficultyEstimates::class)->forPuzzles([]));
    }

    public function testOnlyTheCurrentPictureAndNeverASecretPuzzle(): void
    {
        $database = $this->database();
        // A new picture - the estimate of the old one no longer counts
        $database->executeStatement("UPDATE puzzle SET image = 'box-500-01-new.jpg' WHERE id = :id", ['id' => PuzzleFixture::PUZZLE_500_01]);
        // Secret since the push: the picture, or the whole puzzle
        $database->executeStatement("UPDATE puzzle SET hide_image_until = " . TestClock::SQL_NOW . " + INTERVAL '30 days' WHERE id = :id", ['id' => PuzzleFixture::PUZZLE_500_02]);
        $database->executeStatement("UPDATE puzzle SET hide_until = " . TestClock::SQL_NOW . " + INTERVAL '30 days' WHERE id = :id", ['id' => PuzzleFixture::PUZZLE_500_03]);
        // A reveal in the past hides nothing
        $database->executeStatement("UPDATE puzzle SET hide_image_until = " . TestClock::SQL_NOW . " - INTERVAL '1 day' WHERE id = :id", ['id' => PuzzleFixture::PUZZLE_1000_01]);

        $estimates = self::getContainer()->get(GetPuzzleDifficultyEstimates::class)->forPuzzles(array_keys(self::IMAGES));

        self::assertSame([PuzzleFixture::PUZZLE_1000_01], array_keys($estimates));
        self::assertSame(4, $database->fetchOne('SELECT COUNT(*) FROM puzzle_difficulty_estimate'), 'stored all the same - the reader decides');
    }

    /**
     * @param array<string, mixed> $estimates
     *
     * @return list<string>
     */
    private static function sortedKeys(array $estimates): array
    {
        $keys = array_map(strval(...), array_keys($estimates));
        sort($keys);

        return $keys;
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
