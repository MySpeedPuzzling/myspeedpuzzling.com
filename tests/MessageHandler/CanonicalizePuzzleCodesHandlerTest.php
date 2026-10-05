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

    public function testWritesOnlyFormatOnlyChangesAndKeepsTheSearchKeys(): void
    {
        // As typed before the lists - rows an older release or SQL wrote, with their keys built from them
        $this->storeLegacyCodes(PuzzleFixture::PUZZLE_500_01, '0091683108909, 4 005556 157891', ' rb-500-001 ,RB-500-001');
        $this->storeLegacyCodes(PuzzleFixture::PUZZLE_500_02, '4005556147090 4005555001997', 'rb 14709');
        $this->storeLegacyCodes(PuzzleFixture::PUZZLE_500_03, 'None', null);

        $written = $this->canonicalize([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_03]);

        self::assertSame(['ean' => 1, 'identification_number' => 1], $written);

        self::assertSame(
            ['ean' => '91683108909, 4005556157891', 'identification_number' => 'RB-500-001', 'search_codes' => PuzzleSearchKeys::codes('0091683108909, 4 005556 157891', ' rb-500-001 ,RB-500-001')],
            $this->codesOf(PuzzleFixture::PUZZLE_500_01),
        );
        // Two codes in one part are no format change - nothing of the puzzle is written, the brand code neither
        self::assertSame(
            ['ean' => '4005556147090 4005555001997', 'identification_number' => 'rb 14709', 'search_codes' => PuzzleSearchKeys::codes('4005556147090 4005555001997', 'rb 14709')],
            $this->codesOf(PuzzleFixture::PUZZLE_500_02),
        );
        self::assertSame('None', $this->codesOf(PuzzleFixture::PUZZLE_500_03)['ean']);
    }

    public function testASecondRunWritesNothing(): void
    {
        $this->storeLegacyCodes(PuzzleFixture::PUZZLE_500_01, '0091683108909', 'rb-500-001');

        self::assertSame(['ean' => 1, 'identification_number' => 1], $this->canonicalize([PuzzleFixture::PUZZLE_500_01]));
        self::assertSame(['ean' => 0, 'identification_number' => 0], $this->canonicalize([PuzzleFixture::PUZZLE_500_01]));
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
     * @return array{ean: int, identification_number: int}
     */
    private function canonicalize(array $puzzleIds): array
    {
        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch(new CanonicalizePuzzleCodes($puzzleIds));

        /** @var array{ean: int, identification_number: int} $written */
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
