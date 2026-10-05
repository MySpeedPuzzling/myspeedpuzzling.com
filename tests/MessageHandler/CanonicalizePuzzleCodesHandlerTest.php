<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\CanonicalizePuzzleCodes;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleSearchKeys;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class CanonicalizePuzzleCodesHandlerTest extends KernelTestCase
{
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testWritesOnlyFormatOnlyChangesKeepsTheSearchKeysAndReturnsTheUndoRows(): void
    {
        // As typed before the lists - rows an older release or SQL wrote, with their keys built from them
        $this->storeLegacyCodes(PuzzleFixture::PUZZLE_500_01, '0091683108909, 4 005556 147090, 12 556 2', ' rb-500-001 ,RB-500-001');
        $this->storeLegacyCodes(PuzzleFixture::PUZZLE_500_02, '4005556147090 4005555001997', 'Article 30226');
        $this->storeLegacyCodes(PuzzleFixture::PUZZLE_500_03, 'None, 04512', null);

        $written = $this->canonicalize([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_03]);

        self::assertSame([
            ['puzzleId' => PuzzleFixture::PUZZLE_500_01, 'field' => 'ean', 'before' => '0091683108909, 4 005556 147090, 12 556 2', 'after' => '91683108909, 4005556147090, 12 556 2'],
            ['puzzleId' => PuzzleFixture::PUZZLE_500_01, 'field' => 'identification_number', 'before' => ' rb-500-001 ,RB-500-001', 'after' => 'RB-500-001'],
        ], $written);

        // The number that is no barcode keeps its spaces
        self::assertSame(
            ['ean' => '91683108909, 4005556147090, 12 556 2', 'identification_number' => 'RB-500-001', 'search_codes' => PuzzleSearchKeys::codes('0091683108909, 4 005556 147090, 12 556 2', ' rb-500-001 ,RB-500-001')],
            $this->codesOf(PuzzleFixture::PUZZLE_500_01),
        );
        // Two barcodes in one part are no format change, prose in the brand codes is never written
        self::assertSame(
            ['ean' => '4005556147090 4005555001997', 'identification_number' => 'Article 30226', 'search_codes' => PuzzleSearchKeys::codes('4005556147090 4005555001997', 'Article 30226')],
            $this->codesOf(PuzzleFixture::PUZZLE_500_02),
        );
        self::assertSame('None, 04512', $this->codesOf(PuzzleFixture::PUZZLE_500_03)['ean']);
    }

    public function testASecondRunWritesNothing(): void
    {
        $this->storeLegacyCodes(PuzzleFixture::PUZZLE_500_01, '0091683108909', 'rb-500-001');

        self::assertCount(2, $this->canonicalize([PuzzleFixture::PUZZLE_500_01]));
        self::assertSame([], $this->canonicalize([PuzzleFixture::PUZZLE_500_01]));
    }

    private function storeLegacyCodes(string $puzzleId, null|string $ean, null|string $identificationNumber): void
    {
        $this->database->executeStatement(
            'UPDATE puzzle SET ean = :ean, identification_number = :code, search_codes = :keys WHERE id = :id',
            ['ean' => $ean, 'code' => $identificationNumber, 'keys' => PuzzleSearchKeys::codes($ean, $identificationNumber), 'id' => $puzzleId],
        );
    }

    /**
     * @param list<string> $puzzleIds
     *
     * @return list<array{puzzleId: string, field: string, before: null|string, after: null|string}>
     */
    private function canonicalize(array $puzzleIds): array
    {
        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch(new CanonicalizePuzzleCodes($puzzleIds));

        /** @var list<array{puzzleId: string, field: string, before: null|string, after: null|string}> $written */
        $written = $envelope->last(HandledStamp::class)?->getResult();

        return $written;
    }

    /**
     * @return array<string, mixed>
     */
    private function codesOf(string $puzzleId): array
    {
        $row = $this->database->fetchAssociative('SELECT ean, identification_number, search_codes FROM puzzle WHERE id = :id', ['id' => $puzzleId]);
        self::assertIsArray($row);

        return $row;
    }
}
