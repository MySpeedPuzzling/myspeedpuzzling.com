<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Message\RebuildPuzzleSearchKeys;
use SpeedPuzzling\Web\Query\GetPuzzlesForSearchKeys;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class RebuildPuzzleSearchKeysHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;
    private GetPuzzlesForSearchKeys $getPuzzlesForSearchKeys;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->getPuzzlesForSearchKeys = self::getContainer()->get(GetPuzzlesForSearchKeys::class);
    }

    public function testPuzzlesWithoutKeysGetThemAndARunAgainChangesNothing(): void
    {
        $puzzleIds = [PuzzleFixture::PUZZLE_1000_02, PuzzleFixture::PUZZLE_1000_05, PuzzleFixture::PUZZLE_500_01];
        $expected = $this->keysOf($puzzleIds);

        // What the migration leaves, and what the previous release writes during the deploy overlap
        $this->database->executeStatement(
            'UPDATE puzzle SET search_names = NULL, search_codes = NULL WHERE id IN (:ids)',
            ['ids' => $puzzleIds],
            ['ids' => ArrayParameterType::STRING],
        );
        self::assertSame(3, $this->getPuzzlesForSearchKeys->countWithoutNameKey());
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        self::assertSame(3, $this->rebuild([...$puzzleIds, PuzzleFixture::PUZZLE_300]));
        self::assertSame($expected, $this->keysOf($puzzleIds));
        self::assertSame(0, $this->getPuzzlesForSearchKeys->countWithoutNameKey());
        self::assertSame("\npuzzle 7\nkouzelna zahrada\nzauberhafter garten\n", $expected[PuzzleFixture::PUZZLE_1000_02]['search_names']);

        self::assertSame(0, $this->rebuild($puzzleIds));
        self::assertSame($expected, $this->keysOf($puzzleIds));
    }

    public function testThePuzzlesAreReadInBatchesById(): void
    {
        $all = $this->database->fetchFirstColumn('SELECT id FROM puzzle ORDER BY id');

        $batches = [];
        $afterId = null;

        while (($batch = $this->getPuzzlesForSearchKeys->idsAfter($afterId, 7)) !== []) {
            self::assertLessThanOrEqual(7, count($batch));
            $batches[] = $batch;
            $afterId = $batch[array_key_last($batch)];
        }

        self::assertSame($all, array_merge(...$batches));
        self::assertSame(count($all), $this->getPuzzlesForSearchKeys->count());
    }

    /**
     * @param list<string> $puzzleIds
     */
    private function rebuild(array $puzzleIds): int
    {
        $result = $this->messageBus->dispatch(new RebuildPuzzleSearchKeys($puzzleIds))->last(HandledStamp::class)?->getResult();
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertIsInt($result);

        return $result;
    }

    /**
     * @param list<string> $puzzleIds
     *
     * @return array<string, array{search_names: null|string, search_codes: null|string}>
     */
    private function keysOf(array $puzzleIds): array
    {
        $keys = [];

        foreach ($puzzleIds as $puzzleId) {
            /** @var array{search_names: null|string, search_codes: null|string} $row */
            $row = $this->database->fetchAssociative('SELECT search_names, search_codes FROM puzzle WHERE id = :id', ['id' => $puzzleId]);
            $keys[$puzzleId] = $row;
        }

        return $keys;
    }
}
