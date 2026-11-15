<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\MyData;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Message\AllowPrivateProfileViewer;
use SpeedPuzzling\Web\Message\ArchiveCustomField;
use SpeedPuzzling\Web\Tests\ClonesSolvingTimes;
use SpeedPuzzling\Web\Tests\CustomFieldsScenario;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CustomFieldType;
use SpeedPuzzling\Web\Value\MyDataCriteria;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "My data" (docs/features/my-data.md): the signed-in player's own results and puzzles as a table - owner only, the
 * address is the state, read-only.
 */
final class MyDataControllerTest extends WebTestCase
{
    use ClonesSolvingTimes;
    use QueryCountAssertions;

    private const string ME = PlayerFixture::PLAYER_REGULAR;
    private const string ME_USER = PlayerFixture::PLAYER_REGULAR_USER_ID;
    private const string PARTNER = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string COLLECTOR = PlayerFixture::PLAYER_WITH_STRIPE;
    private const array SAME_ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];

    /**
     * Every result the player tracked or is a registered member of - written another way than the page's query (the
     * snapshot's JSON instead of the membership rows).
     */
    private const string MINE = "(pst.player_id = :playerId OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS TEXT))))";

    public function testAGuestIsSentToSignIn(): void
    {
        $browser = self::createClient();

        foreach (['/en/my-data', '/en/my-data?tab=puzzles'] as $url) {
            $browser->request('GET', $url);

            self::assertResponseRedirects();
            self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));
        }

        $browser->request('POST', '/en/my-data/columns');
        self::assertResponseRedirects();
    }

    public function testThePageIsPrivateAndNeverIndexedInEveryLanguage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::ME);

        foreach (['en', 'cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            $crawler = $browser->request('GET', '/' . $locale . '/my-data');

            self::assertResponseIsSuccessful($locale);
            $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
            self::assertStringContainsString('private', $cacheControl);
            self::assertStringContainsString('no-store', $cacheControl);
            self::assertStringContainsString('noindex', (string) $browser->getResponse()->headers->get('X-Robots-Tag'));
            self::assertStringContainsString('noindex', (string) $crawler->filter('meta[name="robots"]')->attr('content'));
            self::assertStringNotContainsString('my_data.', $crawler->filter('[data-testid="my-data"]')->text(), 'every text has a translation in ' . $locale);
        }
    }

    public function testTheResultsAreTheSignedInPlayersOwnAndNoAddressShowsAnybodyElses(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $shared = $scenario->result(self::ME_USER, ['#player3']);
        $alone = $scenario->result(self::ME_USER, time: '04:00:00');
        TestingLogin::asPlayer($browser, self::ME);

        $crawler = $browser->request('GET', '/en/my-data');

        self::assertResponseIsSuccessful();
        $mine = $this->timeIds(self::ME);
        self::assertSame(count($mine), $this->total($crawler));
        self::assertEqualsCanonicalizing($mine, $this->rowIds($crawler));
        // The phone's list holds the same rows
        self::assertSame($this->rowIds($crawler), $this->rowIds($crawler, 'my-data-item'));

        // Somebody else's id in the address changes nothing
        foreach (['?player=' . self::PARTNER, '?playerId=' . self::PARTNER, '?tab=results&id=' . self::COLLECTOR] as $query) {
            self::assertEqualsCanonicalizing($mine, $this->rowIds($browser->request('GET', '/en/my-data' . $query)), $query);
        }

        // The pair partner has the shared result among theirs, and not the other one
        TestingLogin::asPlayer($browser, self::PARTNER);
        $partnerRows = $this->rowIds($browser->request('GET', '/en/my-data'));

        self::assertContains($shared, $partnerRows);
        self::assertNotContains($alone, $partnerRows);
        self::assertEqualsCanonicalizing($this->timeIds(self::PARTNER), $partnerRows);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideBrokenAddresses(): iterable
    {
        yield 'an unknown tab' => ['tab=everything'];
        yield 'an unknown sort' => ['sort=price&dir=sideways'];
        yield 'a sort of another tab' => ['tab=puzzles&sort=time'];
        yield 'arrays instead of texts' => ['tab[]=results&sort[]=date&q[]=x&page[]=2&pieces[]=500'];
        yield 'a page that is no number' => ['page=abc'];
        yield 'a negative page' => ['page=-4'];
        yield 'a page far behind the last row' => ['page=99999'];
        yield 'broken pieces' => ['pieces=many'];
        yield 'a year that is none' => ['year=20261&type=quartet'];
        yield 'a collection that is no id' => ['tab=puzzles&collection=%27%20OR%201=1'];
        yield 'a search of wildcards' => ['q=%25_%5C%27%22'];
        yield 'a search with broken bytes' => ['q=%C3%28%00'];
        yield 'everything at once on the wish list' => ['tab=wishlist&type=duo&first=1&imported=1&collection=main&sort=added&dir=asc'];
    }

    #[DataProvider('provideBrokenAddresses')]
    public function testABrokenAddressShowsTheDefaultsNeverAnError(string $query): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::ME);

        $browser->request('GET', '/en/my-data?' . $query);

        self::assertResponseIsSuccessful($query);
    }

    public function testAnUnknownTabSortAndPageAreTheDefaults(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::ME);

        $default = $this->rowIds($browser->request('GET', '/en/my-data'));

        self::assertNotSame([], $default);
        self::assertSame($default, $this->rowIds($browser->request('GET', '/en/my-data?tab=everything&sort=price&page=99999')));
    }

    public function testEveryFilterOfTheResults(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $pair = $scenario->result(self::ME_USER, ['#player3'], daysAgo: 400);
        $imported = $scenario->result(self::ME_USER, time: '04:00:00');
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement("UPDATE puzzle_solving_time SET created_via = 'spreadsheet_import', comment = 'from my sheet', first_attempt = true, finished_puzzle_photo = 'photo.jpg' WHERE id = :id", ['id' => $imported]);
        TestingLogin::asPlayer($browser, self::ME);

        $pairYear = TestClock::now()->modify('-400 days')->format('Y');
        $puzzleName = $database->fetchOne('SELECT name FROM puzzle WHERE id = :id', ['id' => CustomFieldsScenario::PUZZLE]);
        $brandName = $database->fetchOne('SELECT m.name FROM puzzle p INNER JOIN manufacturer m ON m.id = p.manufacturer_id WHERE p.id = :id', ['id' => CustomFieldsScenario::PUZZLE]);
        assert(is_string($puzzleName) && is_string($brandName));

        $filters = [
            'type=duo' => "pst.puzzling_type = 'duo'",
            'type=solo' => "pst.puzzling_type = 'solo'",
            'year=' . $pairYear => "to_char(COALESCE(pst.finished_at, pst.tracked_at), 'YYYY') = '" . $pairYear . "'",
            'first=1' => 'pst.first_attempt',
            'imported=1' => "pst.created_via = 'spreadsheet_import'",
            'comment=1' => "COALESCE(pst.comment, '') <> ''",
            'photo=1' => 'pst.finished_puzzle_photo IS NOT NULL',
            'pieces=3000' => 'p.pieces_count = 3000',
            'pieces=1000-' => 'p.pieces_count >= 1000',
            'pieces=-500' => 'p.pieces_count <= 500',
            'type=solo&first=1&imported=1' => "pst.puzzling_type = 'solo' AND pst.first_attempt AND pst.created_via = 'spreadsheet_import'",
            'q=no-puzzle-is-called-this' => 'false',
        ];

        foreach ($filters as $query => $condition) {
            $expected = $this->timeIds(self::ME, $condition);
            $crawler = $browser->request('GET', '/en/my-data?' . $query);

            self::assertResponseIsSuccessful($query);
            self::assertSame(count($expected), $this->total($crawler), $query);

            if (count($expected) <= MyDataCriteria::PAGE_SIZE) {
                self::assertEqualsCanonicalizing($expected, $this->rowIds($crawler), $query);
            }
        }

        // The search finds a puzzle by its name and by its brand, whatever the letter case
        foreach ([$puzzleName, mb_strtoupper($puzzleName), mb_strtolower($brandName)] as $typed) {
            $found = $this->rowIds($browser->request('GET', '/en/my-data?pieces=3000&q=' . rawurlencode($typed)));

            self::assertContains($pair, $found, $typed);
            self::assertContains($imported, $found, $typed);
        }

        // The filters really tell rows apart
        self::assertContains($pair, $this->rowIds($browser->request('GET', '/en/my-data?type=duo')));
        self::assertNotContains($imported, $this->rowIds($browser->request('GET', '/en/my-data?type=duo')));
        self::assertSame([$imported], $this->rowIds($browser->request('GET', '/en/my-data?imported=1')));
    }

    public function testEverySortOfTheResultsBothWays(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $scenario->result(self::ME_USER, time: '01:00:00', daysAgo: 30);
        $scenario->result(self::ME_USER, ['#player3'], time: '09:00:00', daysAgo: 1);
        TestingLogin::asPlayer($browser, self::ME);

        $values = [
            'date' => 'COALESCE(pst.finished_at, pst.tracked_at)::text',
            'time' => "lpad(pst.seconds_to_solve::text, 10, '0')",
            'pieces' => "lpad(p.pieces_count::text, 10, '0')",
            'name' => 'p.name',
            'brand' => 'm.name',
        ];

        foreach ($values as $sort => $expression) {
            foreach (['asc', 'desc'] as $direction) {
                $crawler = $browser->request('GET', sprintf('/en/my-data?sort=%s&dir=%s', $sort, $direction));
                self::assertResponseIsSuccessful();

                $shown = $this->valuesOf($this->rowIds($crawler), $expression);
                $sorted = $shown;
                // The database's own order of the values, so that its collation decides - not PHP's
                $sorted = $this->databaseSorted($sorted, $direction);

                self::assertGreaterThan(3, count($shown));
                self::assertSame($sorted, $shown, $sort . ' ' . $direction);
                self::assertSame($direction === 'asc' ? 'ascending' : 'descending', $crawler->filter('th[aria-sort]')->attr('aria-sort'));
            }
        }
    }

    public function testFiftyRowsAPageWithAStableTotalAndTheStateInEveryLink(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $source = $scenario->result(self::ME_USER);

        for ($i = 0; $i < 60; $i++) {
            $this->cloneSolvingTime($source, ['days_ago' => 500 + $i, 'seconds_to_solve' => 4000 + $i]);
        }

        TestingLogin::asPlayer($browser, self::ME);
        $all = $this->timeIds(self::ME);

        $first = $browser->request('GET', '/en/my-data?sort=time&type=solo');
        $solo = $this->timeIds(self::ME, "pst.puzzling_type = 'solo'");

        self::assertCount(MyDataCriteria::PAGE_SIZE, $this->rowIds($first));
        self::assertSame(count($solo), $this->total($first));
        self::assertCount(0, $first->filter('[data-testid="my-data-previous"]'));

        $nextLink = (string) $first->filter('[data-testid="my-data-next"]')->attr('href');
        parse_str((string) parse_url($nextLink, PHP_URL_QUERY), $nextQuery);
        self::assertSame(['sort' => 'time', 'type' => 'solo', 'page' => '2'], $nextQuery, 'the link carries the state and nothing else');

        $second = $browser->request('GET', $nextLink);
        self::assertSame(count($solo), $this->total($second), 'the total does not depend on the page');
        self::assertSame([], array_intersect($this->rowIds($first), $this->rowIds($second)), 'no row is on two pages');
        self::assertCount(1, $second->filter('[data-testid="my-data-previous"]'));

        // Every page together is every row, once
        $seen = [];

        for ($page = 1; $page <= (int) ceil(count($all) / MyDataCriteria::PAGE_SIZE); $page++) {
            $seen = [...$seen, ...$this->rowIds($browser->request('GET', '/en/my-data?page=' . $page))];
        }

        self::assertCount(count($all), $seen);
        self::assertEqualsCanonicalizing($all, $seen);
    }

    public function testThePlayersOwnFieldsAreColumnsAndArchivedOnesAreNot(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::ME, 'Quokka ledger');
        $stars = $scenario->field(self::ME, 'Ocelot stars', CustomFieldType::Rating);
        $archived = $scenario->field(self::ME, 'Dodo archive');
        $onlyItems = $scenario->field(self::ME, 'Tapir shelf', forResults: false, forCollectionItems: true);
        $partnersField = $scenario->field(self::PARTNER, 'Partner notes');
        $timeId = $scenario->result(self::ME_USER, ['#player3']);
        $scenario->setValues($timeId, self::ME, [$notes => 'axolotl-whispers-at-dawn', $stars => '4', $archived => 'dodo-value']);
        $scenario->setValues($timeId, self::PARTNER, [$partnersField => 'partner-secret']);
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ArchiveCustomField($archived, self::ME));
        $scenario->forgetLoadedEntities();
        TestingLogin::asPlayer($browser, self::ME);

        $crawler = $browser->request('GET', '/en/my-data');

        self::assertResponseIsSuccessful();
        $table = $crawler->filter('[data-testid="my-data-table"]');
        self::assertSame('Quokka ledger', trim($table->filter('th[data-column="f:' . $notes . '"]')->text()));
        self::assertSame('axolotl-whispers-at-dawn', trim($table->filter('tr[data-row-id="' . $timeId . '"] td[data-column="f:' . $notes . '"]')->text()));
        self::assertNotSame('', trim($table->filter('tr[data-row-id="' . $timeId . '"] td[data-column="f:' . $stars . '"]')->text()));
        // The phone's row names the filled fields too
        self::assertStringContainsString('Quokka ledger: axolotl-whispers-at-dawn', $crawler->filter('[data-testid="my-data-item"][data-row-id="' . $timeId . '"]')->text());

        $content = (string) $browser->getResponse()->getContent();

        foreach (['Dodo archive', 'dodo-value', $archived, 'Tapir shelf', $onlyItems, 'Partner notes', 'partner-secret', $partnersField] as $notThere) {
            self::assertStringNotContainsString($notThere, $content);
        }

        // The partner's page of the same result: their own field, nothing of mine
        TestingLogin::asPlayer($browser, self::PARTNER);
        $browser->request('GET', '/en/my-data');
        $content = (string) $browser->getResponse()->getContent();

        self::assertStringContainsString('partner-secret', $content);

        foreach (['Quokka ledger', 'axolotl-whispers-at-dawn', $notes, $stars] as $notThere) {
            self::assertStringNotContainsString($notThere, $content);
        }
    }

    public function testAResultThatKnowsOnlyItsMonthShowsOnlyItsMonth(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $timeId = $scenario->result(self::ME_USER);
        $day = $scenario->result(self::ME_USER, time: '04:00:00');
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement("UPDATE puzzle_solving_time SET finished_at = '2021-03-01 00:00:00', finished_at_precision = 'month' WHERE id = :id", ['id' => $timeId]);
        $database->executeStatement("UPDATE puzzle_solving_time SET finished_at = '2021-03-15 10:00:00' WHERE id = :id", ['id' => $day]);
        TestingLogin::asPlayer($browser, self::ME);

        $crawler = $browser->request('GET', '/en/my-data?year=2021');

        self::assertSame('Mar 2021', trim($crawler->filter('tr[data-row-id="' . $timeId . '"] td[data-column="date"]')->text()));
        self::assertSame('2021-03', $crawler->filter('tr[data-row-id="' . $timeId . '"] td[data-column="date"] time')->attr('datetime'));
        self::assertSame('15.03.2021', trim($crawler->filter('tr[data-row-id="' . $day . '"] td[data-column="date"]')->text()));
        self::assertStringContainsString('Mar 2021', $crawler->filter('[data-testid="my-data-item"][data-row-id="' . $timeId . '"]')->text());
    }

    public function testTheColumnChooserIsRememberedAndTakesOnlyColumnsOfTheTab(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::ME, 'Quokka ledger');
        $scenario->result(self::ME_USER);
        TestingLogin::asPlayer($browser, self::ME);

        $crawler = $browser->request('GET', '/en/my-data?sort=time');
        $shownByDefault = $crawler->filter('[data-testid="my-data-table"] thead th')->each(static fn (Crawler $th): string => (string) $th->attr('data-column'));

        self::assertSame(['puzzle', 'brand', 'pieces', 'time', 'date', 'group', 'first_try', 'comment', 'source', 'f:' . $notes], $shownByDefault);

        $form = $crawler->filter('[data-testid="my-data-columns"] form');
        $token = (string) $form->filter('input[name="_token"]')->attr('value');
        $state = (string) $form->filter('input[name="state"]')->attr('value');
        $offered = $form->filter('input[name="columns[]"]')->each(static fn (Crawler $input): string => (string) $input->attr('value'));

        self::assertSame(['brand', 'pieces', 'time', 'date', 'group', 'first_try', 'comment', 'photo', 'source', 'ppm', 'f:' . $notes], $offered);

        // The form is stateless (config/packages/csrf.php): a post from another site saves nothing
        foreach ([['HTTP_REFERER' => 'https://evil.example/page'], ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://evil.example/']] as $server) {
            $browser->request('POST', '/en/my-data/columns', ['_token' => $token, 'state' => $state, 'columns' => $offered, 'shown' => ['time']], [], $server);
            self::assertResponseRedirects();
            self::assertNull($this->storedColumns(self::ME));
        }

        $browser->request('POST', '/en/my-data/columns', [
            '_token' => $token,
            'state' => $state,
            // What the chooser offered, and things it never did
            'columns' => [...$offered, 'collection', 'password', 'f:not-a-field', '<script>', ''],
            'shown' => ['time', 'date', 'ppm', 'f:' . $notes, 'email'],
        ], [], self::SAME_ORIGIN);

        self::assertResponseRedirects('/en/my-data?sort=time');
        self::assertSame(['results' => ['brand', 'pieces', 'group', 'first_try', 'comment', 'photo', 'source']], $this->storedColumns(self::ME));

        $crawler = $browser->request('GET', '/en/my-data?sort=time');
        $shown = $crawler->filter('[data-testid="my-data-table"] thead th')->each(static fn (Crawler $th): string => (string) $th->attr('data-column'));
        self::assertSame(['puzzle', 'time', 'date', 'ppm', 'f:' . $notes], $shown);
        self::assertCount(0, $crawler->filter('[data-testid="my-data-table"] td[data-column="brand"]'));

        // The other tab keeps its defaults, and hiding an own field there is remembered beside it
        $crawler = $browser->request('GET', '/en/my-data?tab=puzzles');
        self::assertCount(1, $crawler->filter('[data-testid="my-data-table"] th[data-column="collection"]'));

        $browser->request('POST', '/en/my-data/columns', ['_token' => $token, 'state' => 'tab=puzzles', 'columns' => ['brand', 'lent'], 'shown' => ['brand']], [], self::SAME_ORIGIN);
        self::assertResponseRedirects('/en/my-data?tab=puzzles');
        self::assertEquals(['results' => ['brand', 'pieces', 'group', 'first_try', 'comment', 'photo', 'source'], 'puzzles' => ['lent']], $this->storedColumns(self::ME));

        // Nobody else's columns moved
        self::assertNull($this->storedColumns(self::PARTNER));
    }

    /**
     * The choice of a field's column outlives the field being away: archived (not offered by the chooser), the
     * chooser saved meanwhile - and the column is still switched off when the field is back.
     */
    public function testSavingTheChooserKeepsTheChoiceOfAFieldItDidNotOffer(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::ME, 'Quokka ledger');
        $other = $scenario->field(self::ME, 'Ocelot stars', CustomFieldType::Rating);
        $scenario->result(self::ME_USER);
        TestingLogin::asPlayer($browser, self::ME);
        $bus = self::getContainer()->get(MessageBusInterface::class);

        $columns = ['brand', 'pieces', 'time', 'date', 'group', 'first_try', 'comment', 'photo', 'source', 'ppm'];
        $browser->request('POST', '/en/my-data/columns', ['_token' => 'csrf-token', 'state' => '', 'columns' => [...$columns, 'f:' . $notes, 'f:' . $other], 'shown' => [...$columns, 'f:' . $other]], [], self::SAME_ORIGIN);
        self::assertSame(['results' => ['f:' . $notes]], $this->storedColumns(self::ME));

        $bus->dispatch(new ArchiveCustomField($notes, self::ME));
        $scenario->forgetLoadedEntities();

        $crawler = $browser->request('GET', '/en/my-data');
        $offered = $crawler->filter('[data-testid="my-data-columns"] input[name="columns[]"]')->each(static fn (Crawler $input): string => (string) $input->attr('value'));
        self::assertNotContains('f:' . $notes, $offered, 'an archived field is not offered');

        // Saved without it: brand goes, the archived field's choice stays, a ticked column comes back
        $browser->request('POST', '/en/my-data/columns', ['_token' => 'csrf-token', 'state' => '', 'columns' => $offered, 'shown' => array_values(array_diff($offered, ['brand']))], [], self::SAME_ORIGIN);
        self::assertSame(['results' => ['brand', 'f:' . $notes]], $this->storedColumns(self::ME));

        // The same when the fields could not be read at all and the form offered the built-in columns only
        $browser->request('POST', '/en/my-data/columns', ['_token' => 'csrf-token', 'state' => '', 'columns' => $columns, 'shown' => $columns], [], self::SAME_ORIGIN);
        self::assertSame(['results' => ['f:' . $notes]], $this->storedColumns(self::ME));

        $bus->dispatch(new ArchiveCustomField($notes, self::ME, archived: false));
        $scenario->forgetLoadedEntities();

        $crawler = $browser->request('GET', '/en/my-data');
        self::assertCount(0, $crawler->filter('[data-testid="my-data-table"] th[data-column="f:' . $notes . '"]'), 'back, and still switched off');
        self::assertCount(1, $crawler->filter('[data-testid="my-data-table"] th[data-column="f:' . $other . '"]'));
        self::assertCount(0, $crawler->filter('[data-testid="my-data-columns"] input[name="shown[]"][value="f:' . $notes . '"][checked]'));
    }

    /**
     * A teammate is named exactly as the player's own profile results name them: one with a private profile is
     * "Hidden Puzzler" - unless they put this player on their allow list.
     */
    public function testATeammateWithAPrivateProfileIsNotNamedUnlessTheyLetThePlayerSeeThem(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $timeId = $scenario->result(self::ME_USER, ['#player3', 'Guest Gwen']);
        TestingLogin::asPlayer($browser, self::ME);
        $with = static fn (Crawler $page): string => $page->filter('tr[data-row-id="' . $timeId . '"] [data-testid="my-data-with"]')->text();

        self::assertSame('with ' . PlayerFixture::PLAYER_WITH_FAVORITES_NAME . ', Guest Gwen', $with($browser->request('GET', '/en/my-data')));

        self::getContainer()->get(Connection::class)->executeStatement('UPDATE player SET is_private = true WHERE id = :id', ['id' => self::PARTNER]);

        $crawler = $browser->request('GET', '/en/my-data');
        self::assertSame('with Hidden Puzzler, Guest Gwen', $with($crawler));
        self::assertStringContainsString('Hidden Puzzler, Guest Gwen', $crawler->filter('[data-testid="my-data-item"][data-row-id="' . $timeId . '"]')->text());
        self::assertStringNotContainsString(PlayerFixture::PLAYER_WITH_FAVORITES_NAME, $crawler->filter('[data-testid="my-data"]')->text(), 'the name is nowhere in the table or the list');
        // The result itself stays the player's own row
        self::assertContains($timeId, $this->rowIds($crawler));

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AllowPrivateProfileViewer(self::PARTNER, self::ME));
        $scenario->forgetLoadedEntities();

        self::assertSame('with ' . PlayerFixture::PLAYER_WITH_FAVORITES_NAME . ', Guest Gwen', $with($browser->request('GET', '/en/my-data')));
    }

    public function testTheYearFilterTakesEveryYearItOffers(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $old = $scenario->result(self::ME_USER);
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE puzzle_solving_time SET finished_at = '1985-07-01 10:00:00', tracked_at = '1985-07-01 10:00:00' WHERE id = :id", ['id' => $old]);
        TestingLogin::asPlayer($browser, self::ME);

        $crawler = $browser->request('GET', '/en/my-data');
        $years = $crawler->filter('#my-data-year option')->each(static fn (Crawler $option): string => (string) $option->attr('value'));

        self::assertSame('1985', end($years));
        self::assertSame([$old], $this->rowIds($browser->request('GET', '/en/my-data?year=1985')));

        // A result dated before the years the filter takes: the select stops where the filter does
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE puzzle_solving_time SET finished_at = '1850-07-01 10:00:00', tracked_at = '1850-07-01 10:00:00' WHERE id = :id", ['id' => $old]);
        $years = $browser->request('GET', '/en/my-data')->filter('#my-data-year option')->each(static fn (Crawler $option): string => (string) $option->attr('value'));

        self::assertSame((string) MyDataCriteria::MIN_YEAR, end($years));
    }

    public function testOnAPhoneOnlyTheSearchIsInSightAndTheFiltersFoldBehindOneDisclosure(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::ME);

        $crawler = $browser->request('GET', '/en/my-data?q=moon&sort=time');
        $more = $crawler->filter('[data-testid="my-data-filters"] details[data-testid="my-data-more"]');

        self::assertCount(1, $more);
        self::assertNull($more->attr('open'), 'a search and a sort are no filters - folded');
        self::assertCount(0, $crawler->filter('[data-testid="my-data-active-filters"]'));
        self::assertCount(0, $more->filter('#my-data-q'), 'the search is outside the disclosure');
        self::assertCount(1, $crawler->filter('[data-testid="my-data-filters"] #my-data-q'));

        foreach (['#my-data-type', '#my-data-year', '#my-data-sort', '[data-testid="my-data-pieces"]', '#my-data-first', 'button[type="submit"]'] as $inside) {
            self::assertCount(1, $more->filter($inside), $inside);
        }

        $crawler = $browser->request('GET', '/en/my-data?type=solo&first=1&pieces=500');
        self::assertNotNull($crawler->filter('details[data-testid="my-data-more"]')->attr('open'));
        self::assertSame('3', trim($crawler->filter('[data-testid="my-data-active-filters"]')->text()));
    }

    public function testThePuzzlesOfTheCollectionsWithTheOwnersFieldsAndTheWishList(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $shelf = $scenario->field(self::COLLECTOR, 'Tapir shelf', forResults: false, forCollectionItems: true);
        $database = self::getContainer()->get(Connection::class);
        /** @var list<string> $items */
        $items = $database->fetchFirstColumn('SELECT id FROM collection_item WHERE player_id = :playerId', ['playerId' => self::COLLECTOR]);
        /** @var list<string> $inPublic */
        $inPublic = $database->fetchFirstColumn('SELECT id FROM collection_item WHERE player_id = :playerId AND collection_id = :collectionId', ['playerId' => self::COLLECTOR, 'collectionId' => CollectionFixture::COLLECTION_PUBLIC]);
        /** @var list<string> $inMain */
        $inMain = $database->fetchFirstColumn('SELECT id FROM collection_item WHERE player_id = :playerId AND collection_id IS NULL', ['playerId' => self::COLLECTOR]);
        self::assertNotSame([], $inPublic);
        self::assertNotSame([], $inMain);
        $scenario->setItemValues($inPublic[0], self::COLLECTOR, [$shelf => 'echidna-under-the-stairs']);
        TestingLogin::asPlayer($browser, self::COLLECTOR);

        $crawler = $browser->request('GET', '/en/my-data?tab=puzzles');

        self::assertResponseIsSuccessful();
        self::assertSame(count($items), $this->total($crawler));
        self::assertEqualsCanonicalizing($items, $this->rowIds($crawler));
        self::assertSame($this->rowIds($crawler), $this->rowIds($crawler, 'my-data-item'));
        self::assertSame('Tapir shelf', trim($crawler->filter('th[data-column="f:' . $shelf . '"]')->text()));
        self::assertSame('echidna-under-the-stairs', trim($crawler->filter('tr[data-row-id="' . $inPublic[0] . '"] td[data-column="f:' . $shelf . '"]')->text()));
        self::assertMatchesRegularExpression('/Solved \d+×|Not solved yet/', $crawler->filter('tr[data-row-id="' . $inPublic[0] . '"] td[data-column="solved"]')->text());

        self::assertEqualsCanonicalizing($inPublic, $this->rowIds($browser->request('GET', '/en/my-data?tab=puzzles&collection=' . CollectionFixture::COLLECTION_PUBLIC)));
        self::assertEqualsCanonicalizing($inMain, $this->rowIds($browser->request('GET', '/en/my-data?tab=puzzles&collection=main')));
        // Somebody else's collection in the address shows nothing of theirs
        self::assertSame([], $this->rowIds($browser->request('GET', '/en/my-data?tab=puzzles&collection=' . CollectionFixture::COLLECTION_PRIVATE)));

        foreach (['added', 'name', 'pieces', 'brand'] as $sort) {
            foreach (['asc', 'desc'] as $direction) {
                $sorted = $browser->request('GET', sprintf('/en/my-data?tab=puzzles&sort=%s&dir=%s', $sort, $direction));
                self::assertResponseIsSuccessful();
                self::assertEqualsCanonicalizing($items, $this->rowIds($sorted), $sort);
            }
        }

        $names = static fn (Crawler $page): array => $page->filter('[data-testid="my-data-row"] th .fw-medium')->each(static fn (Crawler $name): string => $name->text());
        $byName = $names($browser->request('GET', '/en/my-data?tab=puzzles&sort=name'));
        self::assertGreaterThan(1, count(array_unique($byName)));
        self::assertSame(array_reverse($byName), $names($browser->request('GET', '/en/my-data?tab=puzzles&sort=name&dir=desc')));

        // The wish list: its own rows, no own fields
        /** @var list<string> $wishes */
        $wishes = $database->fetchFirstColumn('SELECT id FROM wish_list_item WHERE player_id = :playerId', ['playerId' => self::ME]);
        self::assertNotSame([], $wishes);
        TestingLogin::asPlayer($browser, self::ME);
        $crawler = $browser->request('GET', '/en/my-data?tab=wishlist');

        self::assertEqualsCanonicalizing($wishes, $this->rowIds($crawler));
        self::assertCount(0, $crawler->filter('th[data-own-field]'));
    }

    public function testTheWideTableAndThePhoneListAreBothThereAndCssShowsOne(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::ME);

        $crawler = $browser->request('GET', '/en/my-data');

        self::assertStringContainsString('d-none d-lg-block', (string) $crawler->filter('[data-testid="my-data-table"]')->attr('class'));
        self::assertStringContainsString('d-lg-none', (string) $crawler->filter('[data-testid="my-data-list"]')->attr('class'));
        self::assertCount(0, $crawler->filter('[data-testid="my-data-list"] table'));
        // The direction of the sort is a link beside the select: the newest first by default, one tap turns it round
        self::assertSame('/en/my-data?dir=asc', $crawler->filter('[data-testid="my-data-direction"]')->attr('href'));
        self::assertCount(0, $crawler->filter('[data-testid="my-data-filters"] [name="dir"]'));
        // A row opens the existing result detail
        self::assertStringStartsWith('/en/result/', (string) $crawler->filter('[data-testid="my-data-item"]')->first()->attr('href'));
    }

    public function testTheExportPageLinksToIt(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::ME);

        $crawler = $browser->request('GET', '/en/export-puzzler-data/' . self::ME);

        self::assertSame('/en/my-data', $crawler->filter('[data-testid="my-data-link"]')->attr('href'));
    }

    /**
     * The page is for players with thousands of rows: what it costs does not grow with the rows, the fields or the
     * filled values (docs/features/my-data.md "Statements").
     */
    public function testTheStatementsOfAPageDoNotGrowWithRowsAndFields(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::ME, 'Quokka ledger');
        $itemNotes = $scenario->field(self::ME, 'Tapir shelf', forResults: false, forCollectionItems: true);
        $source = $scenario->result(self::ME_USER);
        $scenario->setValues($source, self::ME, [$notes => 'one']);
        TestingLogin::asPlayer($browser, self::ME);

        $small = [];

        foreach (['results' => '/en/my-data', 'puzzles' => '/en/my-data?tab=puzzles', 'wishlist' => '/en/my-data?tab=wishlist'] as $tab => $url) {
            $small[$tab] = $this->statementsOf($browser, $url);
        }

        // Beside the 4 every signed-in page loads - results: the facts, the page, the fields, the page's values, the
        // chooser (5); puzzles: those + the solve summary + the collections of the filter (7); wish list: the facts,
        // the page, the solve summary, the chooser (4)
        self::assertLessThanOrEqual(9, $small['results']);
        self::assertLessThanOrEqual(11, $small['puzzles']);
        self::assertLessThanOrEqual(8, $small['wishlist']);

        // 120 more results with values, 5 more fields, values on every collection item
        for ($i = 0; $i < 5; $i++) {
            $scenario->field(self::ME, 'More ' . $i);
            $scenario->field(self::ME, 'Shelf ' . $i, forResults: false, forCollectionItems: true);
        }

        $database = self::getContainer()->get(Connection::class);

        for ($i = 0; $i < 120; $i++) {
            $clone = $this->cloneSolvingTime($source, ['days_ago' => 300 + $i]);
            $database->executeStatement(
                'INSERT INTO solving_time_custom_values (id, solving_time_id, player_id, custom_values, updated_at) SELECT gen_random_uuid(), :clone, player_id, custom_values, updated_at FROM solving_time_custom_values WHERE solving_time_id = :source',
                ['clone' => $clone, 'source' => $source],
            );
        }

        $database->executeStatement(
            'UPDATE collection_item SET custom_values = CAST(:values AS jsonb) WHERE player_id = :playerId',
            ['values' => json_encode([$itemNotes => 'top'], JSON_THROW_ON_ERROR), 'playerId' => self::ME],
        );

        $crawler = $browser->request('GET', '/en/my-data');
        self::assertGreaterThan(120, $this->total($crawler));
        self::assertGreaterThan(20, $crawler->filter('td[data-column="f:' . $notes . '"]')->reduce(static fn (Crawler $cell): bool => trim($cell->text()) === 'one')->count());

        foreach (['results' => '/en/my-data', 'puzzles' => '/en/my-data?tab=puzzles', 'wishlist' => '/en/my-data?tab=wishlist'] as $tab => $url) {
            self::assertSame($small[$tab], $this->statementsOf($browser, $url), $tab . ': more rows, fields and values cost no statement more');
        }

        self::assertSame($small['results'], $this->statementsOf($browser, '/en/my-data?page=2&sort=time&type=solo&year=' . TestClock::now()->modify('-320 days')->format('Y')), 'filters, a sort and a page cost no statement more');
        self::assertLessThanOrEqual($small['results'], $this->statementsOf($browser, '/en/my-data?q=a&pieces=1000-&comment=1&first=1'), 'a search costs no statement more');
    }

    private function statementsOf(KernelBrowser $browser, string $url): int
    {
        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url);

        self::assertResponseIsSuccessful($url);

        return $this->queryCount($browser);
    }

    /**
     * @return list<string>
     */
    private function rowIds(Crawler $crawler, string $testId = 'my-data-row'): array
    {
        /** @var list<string> $ids */
        $ids = $crawler->filter('[data-testid="' . $testId . '"]')->each(static fn (Crawler $row): string => (string) $row->attr('data-row-id'));

        return $ids;
    }

    private function total(Crawler $crawler): int
    {
        return (int) $crawler->filter('[data-testid="my-data-summary"]')->attr('data-total');
    }

    /**
     * @return list<string>
     */
    private function timeIds(string $playerId, string $condition = 'true'): array
    {
        /** @var list<string> $ids */
        $ids = self::getContainer()->get(Connection::class)->fetchFirstColumn(
            'SELECT pst.id FROM puzzle_solving_time pst INNER JOIN puzzle p ON p.id = pst.puzzle_id LEFT JOIN manufacturer m ON m.id = p.manufacturer_id WHERE ' . self::MINE . ' AND (' . $condition . ')',
            ['playerId' => $playerId],
        );

        return $ids;
    }

    /**
     * @param list<string> $timeIds
     * @return list<null|string> the value of each row, in the rows' order
     */
    private function valuesOf(array $timeIds, string $expression): array
    {
        /** @var array<string, null|string> $values */
        $values = self::getContainer()->get(Connection::class)->fetchAllKeyValue(
            'SELECT pst.id, ' . $expression . ' FROM puzzle_solving_time pst INNER JOIN puzzle p ON p.id = pst.puzzle_id LEFT JOIN manufacturer m ON m.id = p.manufacturer_id WHERE pst.id IN (:ids)',
            ['ids' => $timeIds],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        return array_map(static fn (string $id): null|string => $values[$id], $timeIds);
    }

    /**
     * @param list<null|string> $values
     * @return list<null|string> sorted by the database (its collation), a value that is not known last
     */
    private function databaseSorted(array $values, string $direction): array
    {
        /** @var list<null|string> $sorted */
        $sorted = self::getContainer()->get(Connection::class)->fetchFirstColumn(
            'SELECT value FROM jsonb_array_elements_text(CAST(:values AS jsonb)) WITH ORDINALITY AS item(value, position) ORDER BY value ' . ($direction === 'asc' ? 'ASC' : 'DESC') . ' NULLS LAST, position',
            ['values' => json_encode($values, JSON_THROW_ON_ERROR)],
        );

        return $sorted;
    }

    /**
     * @return null|array<string, list<string>>
     */
    private function storedColumns(string $playerId): null|array
    {
        $stored = self::getContainer()->get(Connection::class)->fetchOne('SELECT my_data_columns FROM player WHERE id = :id', ['id' => $playerId]);

        if (is_string($stored) === false) {
            return null;
        }

        /** @var array<string, list<string>> $columns */
        $columns = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);

        return $columns;
    }
}
