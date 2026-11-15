<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Unlisted;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\SuggestUnlistedPuzzlePairings;
use SpeedPuzzling\Web\MessageHandler\SuggestUnlistedPuzzlePairingsHandler;
use SpeedPuzzling\Web\Tests\MessageHandler\DataImport\UnlistedScenario;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Component\DomCrawler\Crawler;

/**
 * docs/features/data-import/unlisted-puzzles.md "The pages": the player's own list and the page of one unlisted
 * puzzle - what they say, "It is this catalogue puzzle" from the page to the profile and back (Undo), and above all
 * **who may open them: the owner, nobody else**. The screens are driven as a browser drives them; the worker's turns
 * are taken by hand with the real handlers. Every puzzle and every row here is made up.
 */
final class UnlistedPuzzlesPagesTest extends WebTestCase
{
    use QueryCountAssertions;
    use UnlistedScenario;

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheOwnerSeesTheirListAndNobodyElseSeesAnythingOfIt(): void
    {
        $owner = $this->seedPairingPlayer('pages-owner');
        $other = $this->seedPairingPlayer('pages-other');
        $this->seedBrand('Zqpages');
        $unlistedId = $this->seedUnlisted($owner, 'Zqpages', [
            ['Zq Pages Quokka Harbour', 750, '2024-01-02', 2000],
            ['Zq Pages Quokka Harbour', 750, '2024-01-05', null],
            ['Zq Pages Wombat Meadow', 500, '2024-02-02', 3000],
        ], owned: ['Zq Pages Quokka Harbour'], wished: ['Zq Pages Numbat Dream'])['Zq Pages Quokka Harbour'];

        // --- The owner ---
        TestingLogin::asPlayer($this->browser, $owner->id->toString());
        $crawler = $this->browser->request('GET', '/en/unlisted-puzzles');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('noindex', (string) $this->browser->getResponse()->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
        self::assertCount(1, $crawler->filter('meta[name="robots"][content="noindex, nofollow"]'));
        self::assertSame(['3', '3'], [$crawler->filter('[data-testid="unlisted-puzzles-page"]')->attr('data-puzzles'), $crawler->filter('[data-testid="unlisted-puzzles-page"]')->attr('data-results')]);
        // The plain line: only they see these, and nothing counts until paired
        self::assertStringContainsString('Only you see these. They do not count in statistics, charts or rankings until you pair them with a catalogue puzzle.', self::spaced($crawler->filter('[data-testid="unlisted-only-you"]')->text()));
        self::assertSame(
            ['Zq Pages Numbat Dream', 'Zq Pages Quokka Harbour', 'Zq Pages Wombat Meadow'],
            $crawler->filter('[data-testid="unlisted-puzzle-name"]')->each(static fn (Crawler $name): string => self::spaced($name->text())),
        );
        $harbour = $crawler->filter('[data-unlisted-puzzle-id="' . $unlistedId . '"]');
        self::assertSame('2', $harbour->attr('data-results'));
        self::assertStringContainsString('Zqpages · Pieces: 750 · Results: 2', self::spaced($harbour->text()));
        self::assertCount(1, $harbour->filter('[data-testid="unlisted-puzzle-owned"]'));
        self::assertCount(1, $crawler->filter('[data-testid="unlisted-puzzle-wish"]'));

        $crawler = $this->browser->request('GET', '/en/unlisted-puzzles/' . $unlistedId);
        self::assertResponseIsSuccessful();
        self::assertSame('Zq Pages Quokka Harbour', self::spaced($crawler->filter('h1')->text()));
        self::assertCount(2, $crawler->filter('[data-testid="unlisted-result"][data-unlisted="true"]'));
        self::assertCount(1, $crawler->filter('[data-testid="unlisted-mark"][data-kind="owned"]'));
        self::assertStringContainsString('results.xlsx', $crawler->filter('[data-testid="unlisted-source"]')->text());
        self::assertCount(1, $crawler->filter('[data-testid="unlisted-pair"]'));
        // "Remove" is a page of its own - nothing is removed by one tap
        self::assertSame('/en/unlisted-puzzles/' . $unlistedId . '/remove', $crawler->filter('[data-testid="unlisted-remove-link"]')->attr('href'));
        self::assertCount(0, $crawler->filter('[data-testid="unlisted-remove-button"]'));
        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');

        // The import's pages lead here, discreetly
        self::assertSame('/en/unlisted-puzzles', $this->browser->request('GET', '/en/import')->filter('[data-testid="unlisted-puzzles-link"]')->attr('href'));

        // --- Another player: every address of it is "not found", and their own list is empty ---
        TestingLogin::asPlayer($this->browser, $other->id->toString());
        $crawler = $this->browser->request('GET', '/en/unlisted-puzzles');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="unlisted-empty"]'));
        self::assertStringNotContainsString('Quokka', (string) $this->browser->getResponse()->getContent());

        foreach ($this->addresses($unlistedId) as [$method, $path]) {
            $this->browser->request($method, $path, ['_token' => $token, 'puzzle_id' => '0193f000-0000-7000-8000-000000000001', 'request_id' => '0193f000-0000-7000-8000-000000000002', 'search' => 'Quokka']);
            // "Not found" - the same answer as for an unlisted puzzle that does not exist
            self::assertResponseStatusCodeSame(404, $method . ' ' . $path);
        }

        $this->browser->request('GET', '/en/unlisted-puzzles/' . $unlistedId);
        self::assertResponseStatusCodeSame(404);

        // Nothing public shows it to them - not the owner's profile, statistics, library, collections or unsolved
        // list, not the catalogue's search, not the feed
        foreach ($this->publicPages($owner) as $path) {
            $this->browser->request('GET', $path);
            self::assertLessThan(500, $this->browser->getResponse()->getStatusCode(), $path);

            // The search box repeats the word that was typed - the puzzles' names are what must not be there
            foreach (['Quokka Harbour', 'Wombat Meadow', 'Numbat Dream', 'Zqpages'] as $name) {
                self::assertStringNotContainsString($name, (string) $this->browser->getResponse()->getContent(), $path);
            }
        }

        // --- A guest: "not found" too, never a sign-in page that would say "there is something here" ---
        $this->browser->getCookieJar()->clear();

        $this->browser->request('GET', '/en/unlisted-puzzles');
        self::assertResponseStatusCodeSame(404);

        foreach ($this->addresses($unlistedId) as [$method, $path]) {
            $this->browser->request($method, $path, ['_token' => $token, 'search' => 'Quokka']);
            self::assertResponseStatusCodeSame(404, $method . ' ' . $path);
        }

        foreach ($this->publicPages($owner) as $path) {
            $this->browser->request('GET', $path);
            foreach (['Quokka Harbour', 'Wombat Meadow', 'Numbat Dream', 'Zqpages'] as $name) {
                self::assertStringNotContainsString($name, (string) $this->browser->getResponse()->getContent(), $path);
            }
        }

        // Nothing was changed by any of those requests
        self::assertSame(3, $this->pairingCount('SELECT COUNT(*) FROM unlisted_puzzle WHERE player_id = :id', ['id' => $owner->id->toString()]));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM unlisted_pairing'));
    }

    public function testTheListCostsTheSameStatementsWhateverItsSize(): void
    {
        $owner = $this->seedPairingPlayer('pages-budget');
        $this->seedBrand('Zqbudget');
        $this->seedUnlisted($owner, 'Zqbudget', [['Zq Budget Puzzle 000', 500, '2024-01-01', 2000]]);
        TestingLogin::asPlayer($this->browser, $owner->id->toString());

        // Once to warm what a first request of a session sets up
        $this->browser->request('GET', '/en/unlisted-puzzles');
        $this->startCountingQueries($this->browser);
        $this->browser->request('GET', '/en/unlisted-puzzles');
        self::assertResponseIsSuccessful();
        $withOne = $this->queryCount($this->browser);

        $rows = [];

        for ($number = 1; $number <= 60; $number++) {
            $rows[] = [sprintf('Zq Budget Puzzle %03d', $number), 500, '2024-01-01', 2000 + $number];
        }

        $this->seedUnlisted($owner, 'Zqbudget', $rows);

        $this->startCountingQueries($this->browser);
        $crawler = $this->browser->request('GET', '/en/unlisted-puzzles');
        self::assertResponseIsSuccessful();
        // 50 a page, the rest on the next one
        self::assertCount(50, $crawler->filter('[data-testid="unlisted-puzzle"]'));
        self::assertSame('61', $crawler->filter('[data-testid="unlisted-puzzles-page"]')->attr('data-puzzles'));
        self::assertSame('/en/unlisted-puzzles?page=2', $crawler->filter('[data-testid="import-pages-next"]')->attr('href'));
        self::assertSame($withOne, $this->queryCount($this->browser), 'The list ran more statements for 61 puzzles than for one.');
        // The page's own two (the numbers, the page of puzzles) on top of what every signed-in page runs
        $this->assertQueryCountAtMost($this->browser, 8, 'The unlisted puzzles list');
        self::assertCount(2, array_filter($this->executedSql($this->browser), static fn (string $sql): bool => str_contains($sql, 'unlisted_puzzle')));

        self::assertCount(11, $this->browser->request('GET', '/en/unlisted-puzzles?page=2')->filter('[data-testid="unlisted-puzzle"]'));
    }

    public function testItIsThisCataloguePuzzleFromThePageToTheProfileAndBack(): void
    {
        $owner = $this->seedPairingPlayer('pages-pair');
        $brand = $this->seedBrand('Zqflow');
        $catalogue = $this->seedCataloguePuzzle('Zq Flow Kinkajou Harbour', 750, $brand);
        $this->seedCataloguePuzzle('Zq Flow Hidden Kinkajou Harbour', 750, $brand, hideUntil: TestClock::now()->modify('+30 days'));
        $unlistedId = $this->seedUnlisted($owner, 'Zqflow', [
            ['Zq Flow Kinkajou Harbor', 750, '2024-01-02', 2400],
            ['Zq Flow Kinkajou Harbor', 750, '2024-01-05', 2500],
        ], owned: ['Zq Flow Kinkajou Harbor'])['Zq Flow Kinkajou Harbor'];
        TestingLogin::asPlayer($this->browser, $owner->id->toString());

        // "Is it one of these?": the catalogue puzzle with the similar name - never the hidden one
        $crawler = $this->browser->request('GET', '/en/unlisted-puzzles/' . $unlistedId);
        self::assertSame([$catalogue], $crawler->filter('[data-testid="unlisted-candidate"]')->each(static fn (Crawler $button): string => (string) $button->attr('data-puzzle-id')));
        self::assertStringNotContainsString('Hidden Kinkajou', (string) $this->browser->getResponse()->getContent());
        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');

        // The search: a POST (what the file wrote never stands in an address), answered 422 with what was found
        $crawler = $this->browser->request('POST', '/en/unlisted-puzzles/' . $unlistedId . '/search', ['_token' => $token, 'search' => 'Kinkajou']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([$catalogue], $crawler->filter('[data-testid="unlisted-search-result"]')->each(static fn (Crawler $button): string => (string) $button->attr('data-puzzle-id')));
        $this->browser->request('POST', '/en/unlisted-puzzles/' . $unlistedId . '/search', ['_token' => 'wrong', 'search' => 'Kinkajou']);
        self::assertResponseStatusCodeSame(403);

        // "It is this one"
        $form = $crawler->filter('[data-testid="unlisted-search-result"]')->closest('form');
        self::assertNotNull($form);
        $this->browser->submit($form->form());
        self::assertResponseRedirects('/en/unlisted-puzzles/' . $unlistedId);
        $crawler = $this->browser->followRedirect();
        self::assertSame('running', $crawler->filter('[data-testid="unlisted-puzzle-page"]')->attr('data-working'));
        self::assertCount(0, $crawler->filter('[data-testid="unlisted-pair"]'));
        self::assertCount(0, $this->resultsOf($owner, $catalogue));

        $pairingId = $this->database->fetchOne('SELECT id FROM unlisted_pairing WHERE unlisted_puzzle_id = :id', ['id' => $unlistedId]);
        self::assertIsString($pairingId);
        $this->workPairing($pairingId);

        // Done: both results are on the profile, the puzzle in the collection - and the unlisted puzzle left the list
        self::assertCount(2, $this->resultsOf($owner, $catalogue));
        $crawler = $this->browser->request('GET', '/en/unlisted-puzzles/' . $unlistedId);
        $done = $crawler->filter('[data-testid="unlisted-pairing"]');
        self::assertSame(['finished', '2', '0', '1'], [$done->attr('data-state'), $done->attr('data-results-added'), $done->attr('data-results-kept'), $done->attr('data-items-added')]);
        self::assertCount(2, $crawler->filter('[data-testid="unlisted-result-real"]'));
        self::assertCount(1, $crawler->filter('[data-testid="unlisted-paired-puzzle"]'));
        self::assertCount(1, $this->browser->request('GET', '/en/unlisted-puzzles')->filter('[data-testid="unlisted-empty"]'));

        // Undo, from the pairing's own card
        $crawler = $this->browser->request('GET', '/en/unlisted-puzzles/' . $unlistedId);
        $this->browser->submit($crawler->filter('[data-testid="unlisted-pairing-undo"]')->form());
        self::assertResponseRedirects('/en/unlisted-puzzles/' . $unlistedId);
        $this->workPairing($pairingId);
        self::assertCount(0, $this->resultsOf($owner, $catalogue));
        $crawler = $this->browser->request('GET', '/en/unlisted-puzzles/' . $unlistedId);
        self::assertSame('undone', $crawler->filter('[data-testid="unlisted-pairing"]')->attr('data-state'));
        self::assertCount(2, $crawler->filter('[data-testid="unlisted-result"][data-unlisted="true"]'));
        self::assertCount(1, $this->browser->request('GET', '/en/unlisted-puzzles')->filter('[data-testid="unlisted-puzzle"]'));

        // Remove, in two steps: first what would go - and looking changes nothing
        $crawler = $this->browser->request('GET', '/en/unlisted-puzzles/' . $unlistedId);
        $crawler = $this->browser->click($crawler->filter('[data-testid="unlisted-remove-link"]')->link());
        self::assertResponseIsSuccessful();
        $removal = $crawler->filter('[data-testid="unlisted-remove-page"]');
        self::assertSame(['2', '1', '0'], [$removal->attr('data-results'), $removal->attr('data-marks'), $removal->attr('data-real')]);
        self::assertSame('Zq Flow Kinkajou Harbor', self::spaced($crawler->filter('[data-testid="unlisted-remove-name"]')->text()));
        self::assertSame('/en/unlisted-puzzles/' . $unlistedId, $crawler->filter('[data-testid="unlisted-remove-cancel"]')->attr('href'));
        self::assertSame(2, $this->pairingCount('SELECT COUNT(*) FROM unlisted_result WHERE unlisted_puzzle_id = :id', ['id' => $unlistedId]));
        // … without the token nothing goes either
        $this->browser->request('POST', '/en/unlisted-puzzles/' . $unlistedId . '/remove', ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(2, $this->pairingCount('SELECT COUNT(*) FROM unlisted_result WHERE unlisted_puzzle_id = :id', ['id' => $unlistedId]));
        // … then the button: back on the list, which is empty again
        $this->browser->submit($crawler->filter('[data-testid="unlisted-remove-button"]')->form());
        self::assertResponseRedirects('/en/unlisted-puzzles');
        self::assertCount(1, $this->browser->followRedirect()->filter('[data-testid="unlisted-empty"]'));
        $this->browser->request('GET', '/en/unlisted-puzzles/' . $unlistedId);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheNotificationOfTheDailyLookLeadsToTheList(): void
    {
        $owner = $this->seedPairingPlayer('pages-told');
        $brand = $this->seedBrand('Zqtold');
        $this->seedUnlisted($owner, 'Zqtold', [['Zq Told Echidna Orchard', 500, '2024-01-01', 2000]]);
        // The catalogue grows by the very puzzle, and the daily look runs
        $this->seedCataloguePuzzle('Zq Told Echidna Orchard', 500, self::getContainer()->get(EntityManagerInterface::class)->find(Manufacturer::class, $brand->id->toString()));
        $this->handled(self::getContainer()->get(SuggestUnlistedPuzzlePairingsHandler::class), new SuggestUnlistedPuzzlePairings());

        TestingLogin::asPlayer($this->browser, $owner->id->toString());
        $crawler = $this->browser->request('GET', '/en/notifications');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Some of your unlisted puzzles may be in the catalogue now.', self::spaced($crawler->filter('body')->text()));
        // It names no puzzle
        self::assertStringNotContainsString('Echidna', (string) $this->browser->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('a[href="/en/unlisted-puzzles"]'));

        // The list says which, the puzzle's page offers it - with "Not this one"
        $crawler = $this->browser->request('GET', '/en/unlisted-puzzles');
        self::assertSame('1', $crawler->filter('[data-testid="unlisted-suggested-line"]')->attr('data-suggested'));
        self::assertCount(1, $crawler->filter('[data-testid="unlisted-puzzle-suggested"]'));
        $crawler = $this->browser->click($crawler->filter('[data-testid="unlisted-puzzle"]')->link());
        self::assertCount(1, $crawler->filter('[data-testid="unlisted-suggestion-yes"]'));
        self::assertSame('Not this one', self::spaced($crawler->filter('[data-testid="unlisted-suggestion-no"]')->text()));
        $this->browser->submit($crawler->filter('[data-testid="unlisted-suggestion-no"]')->form());
        self::assertCount(0, $this->browser->followRedirect()->filter('[data-testid="unlisted-suggestion"]'));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id', ['id' => $owner->id->toString()]));
    }

    public function testThePagesRenderInEveryLanguage(): void
    {
        $owner = $this->seedPairingPlayer('pages-locales');
        $this->seedBrand('Zqlocale');
        $unlistedId = $this->seedUnlisted($owner, 'Zqlocale', [['Zq Locale Tapir', 500, '2024-01-01', 2000]], owned: ['Zq Locale Tapir'])['Zq Locale Tapir'];
        TestingLogin::asPlayer($this->browser, $owner->id->toString());

        foreach (['en', 'cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            foreach (['/unlisted-puzzles', '/unlisted-puzzles/' . $unlistedId, '/unlisted-puzzles/' . $unlistedId . '/remove'] as $path) {
                $this->browser->request('GET', '/' . $locale . $path);
                self::assertResponseIsSuccessful($locale . $path);
                // No text falls back to its key
                self::assertStringNotContainsString('unlisted_puzzles.', (string) $this->browser->getResponse()->getContent(), $locale . $path);
            }
        }
    }

    /**
     * An import of the player whose rows are kept as unlisted puzzles and added - by the real handlers.
     *
     * @param list<array{string, int, string, null|int}> $results name, pieces, day, seconds
     * @param list<string> $owned
     * @param list<string> $wished
     *
     * @return array<string, string> the unlisted puzzle's id by name
     */
    private function seedUnlisted(Player $owner, string $brand, array $results, array $owned = [], array $wished = []): array
    {
        [$import, $sheet] = $this->importOf($owner);
        $names = [];

        foreach ($results as [$name, $pieces, $day, $seconds]) {
            $this->resultRow($import, $sheet, $name, $pieces, $brand, $day, $seconds);
            $names[$name] = $pieces;
        }

        foreach ($owned as $name) {
            $this->ownedRow($import, $sheet, $name, $names[$name] ?? 1000, $brand);
            $names[$name] ??= 1000;
        }

        foreach ($wished as $name) {
            $this->wishRow($import, $sheet, $name, $names[$name] ?? 1000, $brand);
            $names[$name] ??= 1000;
        }

        $this->pair($import);
        $this->keepUnlisted($import, $owner, ...array_map(strval(...), array_keys($names)));
        $this->addAndWork($import, $owner);

        /** @var array<string, string> $ids */
        $ids = $this->database->fetchAllKeyValue('SELECT name, id FROM unlisted_puzzle WHERE player_id = :id', ['id' => $owner->id->toString()]);

        return $ids;
    }

    /**
     * Every address of one unlisted puzzle.
     *
     * @return list<array{string, string}>
     */
    private function addresses(string $unlistedId): array
    {
        return [
            ['GET', '/en/unlisted-puzzles/' . $unlistedId],
            ['POST', '/en/unlisted-puzzles/' . $unlistedId . '/search'],
            ['POST', '/en/unlisted-puzzles/' . $unlistedId . '/pair'],
            ['GET', '/en/unlisted-puzzles/' . $unlistedId . '/remove'],
            ['POST', '/en/unlisted-puzzles/' . $unlistedId . '/remove'],
            ['POST', '/en/unlisted-puzzles/' . $unlistedId . '/not-this-one'],
            ['POST', '/en/unlisted-puzzles/' . $unlistedId . '/pairings/0193f000-0000-7000-8000-000000000003/undo'],
        ];
    }

    /**
     * @return list<string>
     */
    private function publicPages(Player $owner): array
    {
        $id = $owner->id->toString();

        return [
            '/en/player-profile/' . $id,
            '/en/player-statistics/' . $id,
            '/en/puzzle-library/' . $id,
            '/en/player-collections/' . $id,
            '/en/unsolved-puzzles/' . $id,
            '/en/puzzle?search=Quokka',
            '/en/puzzle?search=Numbat',
            '/en/recent-activity',
        ];
    }

    private static function spaced(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
