<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SearchPuzzleByEanControllerTest extends WebTestCase
{
    public function testAScannedCodeIsFoundAsIs(): void
    {
        $data = self::lookup(PuzzleFixture::EAN_PUZZLE_300);

        self::assertSame([PuzzleFixture::PUZZLE_300], array_column($data['puzzles'], 'id'));
        self::assertNull($data['correctedEan']);
    }

    /**
     * Android's parity misread of EAN_PUZZLE_300 (FindPuzzlesByScannedEan): the add form selects the right puzzle
     * instead of offering to add a new one with the wrong code.
     */
    public function testAMisreadCodeFindsThePuzzleOfTheCodeOnTheBox(): void
    {
        $data = self::lookup('0045570202027');

        self::assertSame([PuzzleFixture::PUZZLE_300], array_column($data['puzzles'], 'id'));
        self::assertSame(PuzzleFixture::EAN_PUZZLE_300, $data['correctedEan']);
        self::assertSame('0045570202027', $data['ean']);
    }

    /**
     * @return array{puzzles: list<array{id: string}>, brands: list<array<mixed>>, ean: string, correctedEan: null|string}
     */
    private static function lookup(string $ean): array
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/puzzle-by-ean-search/' . $ean);
        self::assertResponseIsSuccessful();

        /** @var array{puzzles: list<array{id: string}>, brands: list<array<mixed>>, ean: string, correctedEan: null|string} $data */
        $data = json_decode((string) $browser->getResponse()->getContent(), true);

        return $data;
    }
}
