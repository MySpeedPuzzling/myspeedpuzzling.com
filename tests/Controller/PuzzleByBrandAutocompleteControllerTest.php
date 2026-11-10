<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Services\PuzzleChoicesBuilder;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

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

        // Both editions' barcodes and brand codes, and 17481 as the box prints it - with the EAN's check digit
        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER);
        $option = self::option($browser, PuzzleFixture::PUZZLE_1000_05);
        self::assertSame(PuzzleFixture::EANS_PUZZLE_1000_05 . "\n" . PuzzleFixture::BRAND_CODES_PUZZLE_1000_05 . "\n174812", $option['codes']);
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

    /**
     * The answer is exactly the brand's puzzles as PuzzleChoicesBuilder builds them for the request - whatever the
     * headers around it do.
     *
     * @return iterable<string, array{string, string, null|string}>
     */
    public static function provideBodies(): iterable
    {
        yield 'Ravensburger, English guest' => ['/en/', ManufacturerFixture::MANUFACTURER_RAVENSBURGER, null];
        yield 'Trefl, English guest' => ['/en/', ManufacturerFixture::MANUFACTURER_TREFL, null];
        yield 'Trefl, Czech guest' => ['/cs/', ManufacturerFixture::MANUFACTURER_TREFL, null];
        yield 'Trefl, Czech player on an English page' => ['/en/', ManufacturerFixture::MANUFACTURER_TREFL, PlayerFixture::PLAYER_REGULAR];
        yield 'Ravensburger, Czech player on a Czech page' => ['/cs/', ManufacturerFixture::MANUFACTURER_RAVENSBURGER, PlayerFixture::PLAYER_REGULAR];
    }

    #[DataProvider('provideBodies')]
    public function testBodyIsTheBuiltOptionsOfTheBrand(string $prefix, string $brandId, null|string $playerId): void
    {
        $browser = self::createClient();
        $browser->disableReboot();

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        $browser->request('GET', $prefix . 'puzzle-by-brand-autocomplete/?brand=' . $brandId);
        self::assertResponseIsSuccessful();

        // Built under the same request (locale, signed-in viewer) the controller had
        self::getContainer()->get(RequestStack::class)->push($browser->getRequest());
        $expected = self::getContainer()->get(PuzzleChoicesBuilder::class)->build(
            self::getContainer()->get(SearchPuzzle::class)->byBrandId($brandId),
            trim($prefix, '/'),
        );

        self::assertNotEmpty($expected);
        self::assertSame($expected, self::options($browser));
    }

    public function testAnswerCarriesAnEtagAndMustBeRevalidated(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER);
        self::assertResponseIsSuccessful();
        self::assertCacheHeaders($browser->getResponse());

        // A signed-in player's request uses the session - the ETag and revalidation stay
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER);
        self::assertResponseIsSuccessful();
        self::assertCacheHeaders($browser->getResponse());
    }

    public function testSameEtagAnswers304WithoutBody(): void
    {
        $browser = self::createClient();
        $etag = self::etag($browser, '/en/', ManufacturerFixture::MANUFACTURER_RAVENSBURGER);

        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER, server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
        self::assertSame('', (string) $browser->getResponse()->getContent());

        // The same for a signed-in player
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $playerEtag = self::etag($browser, '/en/', ManufacturerFixture::MANUFACTURER_RAVENSBURGER);
        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER, server: ['HTTP_IF_NONE_MATCH' => $playerEtag]);
        self::assertResponseStatusCodeSame(304);
    }

    public function testANewPuzzleOfTheBrandChangesTheEtag(): void
    {
        $browser = self::createClient();
        $etag = self::etag($browser, '/en/', ManufacturerFixture::MANUFACTURER_TREFL);

        $puzzleId = Uuid::uuid7()->toString();
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO puzzle (id, pieces_count, name, approved, manufacturer_id, alternative_names, is_available)
             VALUES (:id, 500, 'Just Added Puzzle', false, :brand, '[]', false)",
            ['id' => $puzzleId, 'brand' => ManufacturerFixture::MANUFACTURER_TREFL],
        );

        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_TREFL, server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseIsSuccessful();
        self::assertContains($puzzleId, array_column(self::options($browser), 'value'));
        self::assertNotSame($etag, $browser->getResponse()->getEtag());
    }

    /**
     * Every column the option is built from - twice in one test transaction for the name (a row's xmin would not
     * change the second time, its content does)
     *
     * @return iterable<string, list<string>>
     */
    public static function provideEdits(): iterable
    {
        yield 'name' => ["name = 'Renamed Once'"];
        yield 'name twice' => ["name = 'Renamed Once'", "name = 'Renamed Twice'"];
        yield 'other names' => ["alternative_names = '[{\"name\": \"Jiný název\", \"language\": \"cs\"}]'"];
        yield 'pieces' => ['pieces_count = 999'];
        yield 'picture' => ["image = 'puzzles/another.jpg'"];
        yield 'ean' => ["ean = '4005556175512'"];
        yield 'brand code' => ["identification_number = 'XYZ-1'"];
        yield 'picture hidden' => ["hide_image_until = '2999-01-01 00:00:00'"];
        yield 'hidden' => ["hide_until = '2999-01-01 00:00:00'"];
        yield 'moved to another brand' => ['manufacturer_id = \'' . ManufacturerFixture::MANUFACTURER_TREFL . "'"];
    }

    #[DataProvider('provideEdits')]
    public function testAnEditedPuzzleChangesTheEtag(string ...$changes): void
    {
        $browser = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $url = '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER;

        foreach ($changes as $change) {
            $browser->request('GET', $url);
            $etag = $browser->getResponse()->getEtag();
            $body = $browser->getResponse()->getContent();
            self::assertNotNull($etag);

            $connection->executeStatement('UPDATE puzzle SET ' . $change . ' WHERE id = :id', ['id' => PuzzleFixture::PUZZLE_500_01]);

            $browser->request('GET', $url, server: ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertResponseIsSuccessful($change);
            self::assertNotSame($etag, $browser->getResponse()->getEtag(), $change);
            self::assertNotSame($body, $browser->getResponse()->getContent(), $change);
        }
    }

    public function testEtagDiffersByLocaleAndViewerLanguage(): void
    {
        $browser = self::createClient();
        $englishGuest = self::etag($browser, '/en/', ManufacturerFixture::MANUFACTURER_TREFL);
        $czechGuest = self::etag($browser, '/cs/', ManufacturerFixture::MANUFACTURER_TREFL);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $czechPlayerOnEnglish = self::etag($browser, '/en/', ManufacturerFixture::MANUFACTURER_TREFL);

        self::assertCount(3, array_unique([$englishGuest, $czechGuest, $czechPlayerOnEnglish]));

        // The English guest's copy is never confirmed for the Czech player on the same URL
        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_TREFL, server: ['HTTP_IF_NONE_MATCH' => $englishGuest]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('(Kouzelná zahrada)', self::option($browser, PuzzleFixture::PUZZLE_1000_02)['text']);
    }

    public function testAnotherBrandsEtagIsNotAccepted(): void
    {
        $browser = self::createClient();
        $trefl = self::etag($browser, '/en/', ManufacturerFixture::MANUFACTURER_TREFL);

        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER, server: ['HTTP_IF_NONE_MATCH' => $trefl]);
        self::assertResponseIsSuccessful();
        self::assertNotEmpty(self::options($browser));
    }

    public function testTheOrganisersVariantHasNoEtagAndIgnoresIfNoneMatch(): void
    {
        $browser = self::createClient();
        self::hide(PuzzleFixture::PUZZLE_500_02);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $publicEtag = self::etag($browser, '/en/', ManufacturerFixture::MANUFACTURER_RAVENSBURGER);

        $browser->request(
            'GET',
            '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER . '&competition=' . CompetitionFixture::COMPETITION_WJPC_2024,
            server: ['HTTP_IF_NONE_MATCH' => $publicEtag],
        );
        self::assertResponseIsSuccessful();
        self::assertNull($browser->getResponse()->getEtag());
        self::assertContains(PuzzleFixture::PUZZLE_500_02, array_column(self::options($browser), 'value'));

        // A player naming a competition they do not organise gets the public list - and its ETag
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $browser->request(
            'GET',
            '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER . '&competition=' . CompetitionFixture::COMPETITION_WJPC_2024,
        );
        self::assertResponseIsSuccessful();
        self::assertNotNull($browser->getResponse()->getEtag());
        self::assertNotContains(PuzzleFixture::PUZZLE_500_02, array_column(self::options($browser), 'value'));
    }

    public function testUnknownBrandIsNotFound(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=nonsense');

        $this->assertResponseStatusCodeSame(404);
    }

    private static function etag(KernelBrowser $browser, string $prefix, string $brandId): string
    {
        $browser->request('GET', $prefix . 'puzzle-by-brand-autocomplete/?brand=' . $brandId);
        self::assertResponseIsSuccessful();
        $etag = $browser->getResponse()->getEtag();
        self::assertNotNull($etag);

        return $etag;
    }

    private static function assertCacheHeaders(Response $response): void
    {
        self::assertNotNull($response->getEtag());
        self::assertTrue($response->headers->hasCacheControlDirective('private'), (string) $response->headers->get('Cache-Control'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-cache'), (string) $response->headers->get('Cache-Control'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
        self::assertLessThanOrEqual(0, (int) $response->headers->getCacheControlDirective('max-age'));
        self::assertLessThanOrEqual(0, (int) $response->headers->getCacheControlDirective('s-maxage'));
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
