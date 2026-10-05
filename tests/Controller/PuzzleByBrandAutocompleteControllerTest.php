<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\PuzzleChoicesBuilder;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PuzzleByBrandAutocompleteControllerTest extends WebTestCase
{
    public function testSearchableTextHoldsNoMarkupAndNoPiecesLabel(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER);

        $this->assertResponseIsSuccessful();

        $data = self::options($browser);

        self::assertNotEmpty($data);

        foreach ($data as $option) {
            // The form types point Tom Select's `searchField` at these - `text` is markup and must never be searched
            foreach (PuzzleChoicesBuilder::SEARCH_FIELDS as $searchField) {
                self::assertArrayHasKey($searchField['field'], $option);
            }

            self::assertNotSame('', $option['name']);
            // The transitional `search` key of phase 1b is gone - `name` marks a server-built option
            self::assertArrayNotHasKey('search', $option);

            foreach ([$option['name'], $option['names'], $option['codes']] as $searchable) {
                self::assertStringNotContainsStringIgnoringCase('pieces', $searchable);
                self::assertStringNotContainsString('<', $searchable);
            }
        }
    }

    public function testEveryNameAndEveryCodeIsSearchable(): void
    {
        $browser = self::createClient();

        // Names of other boxes in their own field, so their number never weighs on a typed main title
        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_TREFL);
        $option = self::option($browser, PuzzleFixture::PUZZLE_1000_02);
        self::assertSame('Puzzle 7', $option['name']);
        self::assertSame(PuzzleFixture::NAME_CS_MAGIC_GARDEN . "\n" . PuzzleFixture::NAME_DE_MAGIC_GARDEN, $option['names']);

        // Both editions' barcodes and brand codes
        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER);
        $option = self::option($browser, PuzzleFixture::PUZZLE_1000_05);
        self::assertSame(PuzzleFixture::EANS_PUZZLE_1000_05 . "\n" . PuzzleFixture::BRAND_CODES_PUZZLE_1000_05, $option['codes']);
        self::assertSame('', $option['names']);
        self::assertSame(1000, $option['piecesCount']);
    }

    public function testPlayerTypedNamesAndCodesAreEscaped(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE puzzle SET name = :name, alternative_names = jsonb_build_array(jsonb_build_object('name', CAST(:alternativeName AS TEXT), 'language', 'cs')), identification_number = :code, ean = :ean WHERE id = :id",
            [
                'name' => '<img src=x onerror=alert(1)>',
                'alternativeName' => '<b onmouseover=alert(2)>Kočky</b>',
                'code' => '<script>alert(3)</script>',
                'ean' => '"><svg onload=alert(4)>',
                'id' => PuzzleFixture::PUZZLE_500_02,
            ],
        );

        // Czech pages add the Czech name after the main title - both names end up in the option there
        foreach (['/en/', '/cs/'] as $prefix) {
            $browser->request('GET', $prefix . 'puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER);
            $this->assertResponseIsSuccessful();

            $option = self::option($browser, PuzzleFixture::PUZZLE_500_02);

            self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $option['text']);
            self::assertStringContainsString('&lt;script&gt;alert(3)&lt;/script&gt;', $option['text']);
            self::assertStringContainsString('&quot;&gt;&lt;svg onload=alert(4)&gt;', $option['text']);

            foreach (['<img src=x', '<script', '<svg', '<b '] as $markup) {
                self::assertStringNotContainsString($markup, $option['text']);
            }

            // Plain text: Tom Select only searches these, never renders them
            self::assertSame('<img src=x onerror=alert(1)>', $option['name']);
            self::assertSame('<b onmouseover=alert(2)>Kočky</b>', $option['names']);
            self::assertSame("\"><svg onload=alert(4)>\n<script>alert(3)</script>", $option['codes']);
        }

        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt; <small lang="cs">(&lt;b onmouseover=alert(2)&gt;Kočky&lt;/b&gt;)</small>', $option['text']);
        // "pieces" in the page language
        self::assertStringContainsString('500<span class="no-highlight">&nbsp;dílků</span>', $option['text']);
    }

    /**
     * The main title first in every language, the viewer's language after it - as in every list. PUZZLE_1000_02 is
     * Trefl "Puzzle 7", also "Kouzelná zahrada" (cs) and "Zauberhafter Garten" (de).
     */
    public function testLabelIsTheMainTitleWithTheNameInTheViewersLanguage(): void
    {
        $browser = self::createClient();
        $label = static function (string $prefix) use ($browser): string {
            $browser->request('GET', $prefix . 'puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_TREFL);
            self::assertResponseIsSuccessful();

            return self::option($browser, PuzzleFixture::PUZZLE_1000_02)['text'];
        };

        self::assertStringContainsString('<span class="h6">Puzzle 7</span>', $label('/en/'), 'an English page for a guest');
        self::assertStringContainsString('<span class="h6">Puzzle 7 <small lang="cs">(Kouzelná zahrada)</small></span>', $label('/cs/'));
        self::assertStringContainsString('<span class="h6">Puzzle 7 <small lang="de">(Zauberhafter Garten)</small></span>', $label('/de/'));

        // A Czech player on an English page reads the Czech box
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        self::assertStringContainsString('<span class="h6">Puzzle 7 <small lang="cs">(Kouzelná zahrada)</small></span>', $label('/en/'));

        // A player from the United Kingdom has the English main title only
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertStringContainsString('<span class="h6">Puzzle 7</span>', $label('/en/'));
    }

    public function testSecretPuzzleIsNotListed(): void
    {
        $browser = self::createClient();
        self::hide(PuzzleFixture::PUZZLE_500_02);

        // An unapproved puzzle stays listed, a secret one does not
        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_UNAPPROVED);
        self::assertContains(PuzzleFixture::PUZZLE_UNAPPROVED, array_column(self::options($browser), 'value'));

        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER);
        $values = array_column(self::options($browser), 'value');
        self::assertContains(PuzzleFixture::PUZZLE_500_01, $values);
        self::assertNotContains(PuzzleFixture::PUZZLE_500_02, $values);

        // Naming a competition is not enough - only who may edit it gets its secret puzzles
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER . '&competition=' . CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertNotContains(PuzzleFixture::PUZZLE_500_02, array_column(self::options($browser), 'value'));
    }

    public function testOrganiserAddingToARoundGetsTheCompetitionsSecretPuzzles(): void
    {
        $browser = self::createClient();
        self::hide(PuzzleFixture::PUZZLE_500_02);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        // PUZZLE_500_02 is in the WJPC 2024 qualification round, not in any round of the Czech nationals
        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER . '&competition=' . CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertContains(PuzzleFixture::PUZZLE_500_02, array_column(self::options($browser), 'value'));

        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER . '&competition=' . CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024);
        self::assertNotContains(PuzzleFixture::PUZZLE_500_02, array_column(self::options($browser), 'value'));
    }

    public function testMaintainerWhoIsNoAdminGetsTheSecretPuzzlesOfTheirCompetition(): void
    {
        $browser = self::createClient();
        self::hide(PuzzleFixture::PUZZLE_500_02);

        // PLAYER_REGULAR maintains COMPETITION_UNAPPROVED - put the secret puzzle into a round of it
        $connection = self::getContainer()->get(Connection::class);
        $roundId = Uuid::uuid7()->toString();
        $connection->executeStatement(
            "INSERT INTO competition_round (id, name, minutes_limit, starts_at, competition_id) VALUES (:id, 'Final', 60, '2999-01-01 10:00:00', :competition)",
            ['id' => $roundId, 'competition' => CompetitionFixture::COMPETITION_UNAPPROVED],
        );
        $connection->executeStatement(
            'INSERT INTO competition_round_puzzle (id, round_id, puzzle_id) VALUES (:id, :round, :puzzle)',
            ['id' => Uuid::uuid7()->toString(), 'round' => $roundId, 'puzzle' => PuzzleFixture::PUZZLE_500_02],
        );

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER . '&competition=' . CompetitionFixture::COMPETITION_UNAPPROVED);
        self::assertContains(PuzzleFixture::PUZZLE_500_02, array_column(self::options($browser), 'value'));

        // Another player naming the same competition gets nothing secret
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER . '&competition=' . CompetitionFixture::COMPETITION_UNAPPROVED);
        self::assertNotContains(PuzzleFixture::PUZZLE_500_02, array_column(self::options($browser), 'value'));
    }

    public function testUnknownBrandIsNotFound(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=nonsense');

        $this->assertResponseStatusCodeSame(404);
    }

    private static function hide(string $puzzleId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE puzzle SET hide_until = '2999-01-01 00:00:00' WHERE id = :id",
            ['id' => $puzzleId],
        );
    }

    /**
     * @return list<array{value: string, text: string, name: string, names: string, codes: string, piecesCount: int}>
     */
    private static function options(KernelBrowser $browser): array
    {
        /** @var array{results: list<array{value: string, text: string, name: string, names: string, codes: string, piecesCount: int}>} $data */
        $data = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $data['results'];
    }

    /**
     * @return array{value: string, text: string, name: string, names: string, codes: string, piecesCount: int}
     */
    private static function option(KernelBrowser $browser, string $puzzleId): array
    {
        foreach (self::options($browser) as $option) {
            if ($option['value'] === $puzzleId) {
                return $option;
            }
        }

        self::fail("Puzzle $puzzleId is not listed");
    }
}
