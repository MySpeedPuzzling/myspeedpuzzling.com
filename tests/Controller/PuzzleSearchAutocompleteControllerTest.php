<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Find the puzzle" across every brand (the creation wizard's puzzle pickers, creation-wizard.md "Puzzle search"): the
 * site's own search by name, barcode and brand code, each option with its brand - never a secret puzzle.
 */
final class PuzzleSearchAutocompleteControllerTest extends WebTestCase
{
    public function testAPuzzleIsFoundByItsNameWithItsBrand(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $option = self::option(self::search($browser, 'Puzzle 1'), PuzzleFixture::PUZZLE_500_01);

        self::assertNotNull($option);
        self::assertSame('Puzzle 1', $option['name']);
        self::assertSame(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, $option['brand']);
        self::assertSame('Ravensburger', $option['brandName']);
        self::assertSame(500, $option['piecesCount']);
        self::assertStringContainsString('data-puzzle-option-brand', $option['text']);
        // The best match first: the whole name
        self::assertSame(PuzzleFixture::PUZZLE_500_01, self::search($browser, 'Puzzle 1')[0]['value']);
    }

    public function testAPuzzleIsFoundByItsBarcodeAndItsBrandCode(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $byEan = self::search($browser, PuzzleFixture::EAN_PUZZLE_500_03);
        self::assertSame(PuzzleFixture::PUZZLE_500_03, $byEan[0]['value'] ?? null);

        // Leading zeros of a scanner tolerated
        self::assertSame(PuzzleFixture::PUZZLE_500_03, self::search($browser, '0' . PuzzleFixture::EAN_PUZZLE_500_03)[0]['value'] ?? null);

        $byBrandCode = self::search($browser, 'RB-500-001');
        self::assertSame(PuzzleFixture::PUZZLE_500_01, $byBrandCode[0]['value'] ?? null);
    }

    public function testASecretPuzzleIsNeverFoundAndASecretPictureOnlyByName(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        // hide_until in the future: not even for an admin, not by its name
        self::assertNull(self::option(self::search($browser, 'API Platform Hidden'), CompetitionApiFixture::PUZZLE_PLATFORM_HIDDEN));

        // hide_image_until: found by its name like on the puzzles page, without its picture
        $imageHidden = self::option(self::search($browser, 'API Platform Image Hidden'), CompetitionApiFixture::PUZZLE_PLATFORM_IMAGE_HIDDEN);
        self::assertNotNull($imageHidden);
        self::assertStringContainsString('/img/placeholder-puzzle.jpg', $imageHidden['text']);
    }

    public function testTooShortAQueryAsksNothingAndAGuestSearchesLikeOnThePuzzlesPage(): void
    {
        $browser = self::createClient();

        self::assertSame([], self::search($browser, 'P'));
        self::assertSame([], self::search($browser, '  '));

        // Public like the catalogue and the brand picker
        self::assertNotNull(self::option(self::search($browser, 'Puzzle 1'), PuzzleFixture::PUZZLE_500_01));
    }

    /**
     * @return list<array{value: string, text: string, name: string, brand: string, brandName: string, piecesCount: int}>
     */
    private static function search(KernelBrowser $browser, string $query): array
    {
        $browser->request('GET', '/en/puzzle-search-autocomplete/?query=' . rawurlencode($query));
        self::assertResponseIsSuccessful();

        /** @var array{results: list<array{value: string, text: string, name: string, brand: string, brandName: string, piecesCount: int}>} $data */
        $data = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $data['results'];
    }

    /**
     * @param list<array{value: string, text: string, name: string, brand: string, brandName: string, piecesCount: int}> $results
     * @return null|array{value: string, text: string, name: string, brand: string, brandName: string, piecesCount: int}
     */
    private static function option(array $results, string $puzzleId): null|array
    {
        foreach ($results as $option) {
            if ($option['value'] === $puzzleId) {
                return $option;
            }
        }

        return null;
    }
}
