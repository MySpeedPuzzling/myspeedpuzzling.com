<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Results\PlayerResultsPage;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Services\PlayerResults\PlayerResultsBuilder;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Tests\DataFixtures\DuplicateResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PlayerResultsCriteria;
use SpeedPuzzling\Web\Value\PlayerResultsSort;
use SpeedPuzzling\Web\Value\SearchText;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A player's Results (docs/features/navigation PROF-05a): PlayerResultsBuilder and the page it renders. Every
 * assertion of the profile list's component tests is ported here (PlayerSolvedPuzzles{Paging,Search,PhotoLink,
 * PiecesFilter,Difficulty}Test, PairsAndTeamsFiltersTest::testProfileListsCanBeNarrowedToOnePair,
 * FirstTriesForPairsAndTeamsTest's profile cases) - on the Results semantics: the state is the URL, a By-puzzle row
 * leads with the player's BEST attempt and every sort orders by it, Newest is the default (D11, decision 69, logged
 * as a deliberate change), and the search is PuzzleTextSearch (PROF-05d). The component tests stay, unchanged, on the
 * builder's legacy mode (decision 97).
 *
 * PLAYER_REGULAR has 9 solo puzzles (Puzzle 1, 2, 3 and 7 several times) and 2 pair results in the fixtures.
 */
final class PlayerResultsBuilderTest extends WebTestCase
{
    // Paging: 60 copies of one of the regular player's puzzles with his solo time on each - two pages and a bit
    private const int EXTRA_PUZZLES = 60;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    // ---------------------------------------------------------------- Paging (PlayerSolvedPuzzlesPagingTest)

    public function testFirstRowsThenShowMore(): void
    {
        $this->addPuzzlesWithSoloTimes(PlayerFixture::PLAYER_REGULAR);

        $results = $this->build(null, []);
        $total = $results->rowsCount();

        self::assertGreaterThan(PlayerResultsPage::PAGE_SIZE, $total);
        self::assertCount(PlayerResultsPage::PAGE_SIZE, $results->shownRows());
        self::assertCount($total, $results->soloRows, 'Filters and the tab count cover the whole history');
        self::assertSame(min(PlayerResultsPage::PAGE_SIZE, $total - PlayerResultsPage::PAGE_SIZE), $results->moreCount());

        // Ranks are looked up for the rows on screen only - every shown timed solo row has one
        $shownPuzzleIds = array_map(static fn (array $row): string => $row[0]->puzzleId, array_values($results->shownRows()));
        self::assertSame([], array_diff(array_keys($results->ranking), $shownPuzzleIds));
        self::assertNotSame([], $results->ranking);

        $crawler = $this->page(null, []);
        self::assertCount(PlayerResultsPage::PAGE_SIZE, $this->rows($crawler));
        self::assertStringContainsString('Showing 50 of ' . $total, $crawler->filter('#player-results-more')->text());
        self::assertStringContainsString('(' . $total . ')', $crawler->filter('[data-results-category="solo"]')->text(), 'The solo tab counts every puzzle');
        $more = $crawler->filter('a[data-results-more]');
        self::assertCount(1, $more, 'Show more is a real link');
        self::assertSame(['page' => '2'], $this->queryOf($more->attr('href')));

        // ?page=2 renders rows 1…100
        $results = $this->build(null, ['page' => '2']);
        self::assertCount(min(2 * PlayerResultsPage::PAGE_SIZE, $total), $results->shownRows());
        self::assertGreaterThan(PlayerResultsPage::PAGE_SIZE, count($results->ranking), 'The added rows have their ranks too');

        $crawler = $this->page(null, ['page' => '2']);
        self::assertCount($total, $this->rows($crawler));
        self::assertCount(0, $crawler->filter('a[data-results-more]'), 'Everything shown, no link');

        // A page beyond the end is the last page
        self::assertSame(2, $this->build(null, ['page' => '40'])->page);
    }

    public function testShownRowsBelongToTheirList(): void
    {
        $this->addPuzzlesWithSoloTimes(PlayerFixture::PLAYER_REGULAR);

        // The brand select sends "" for "All brands" - the same list, the rows stay
        self::assertSame(2, $this->build(null, ['brand' => '', 'page' => '2'])->page);

        $crawler = $this->page(null, ['page' => '2', 'q' => 'paging']);

        // Another sort, view, tab, chip or "Clear all" is another list - from its top again
        $links = $crawler->filter('a[data-results-sort], a[data-results-view], a[data-results-category], a[data-results-chip], a[data-results-clear]')
            ->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        self::assertGreaterThan(10, count($links));

        foreach ($links as $href) {
            self::assertArrayNotHasKey('page', $this->queryOf($href), $href);
        }

        // A search or filter submit too: the form carries no page
        self::assertCount(0, $crawler->filter('#player-results-form [name="page"]'));
    }

    public function testAFilterNarrowsTheWholeHistoryNotTheShownRows(): void
    {
        $this->addPuzzlesWithSoloTimes(PlayerFixture::PLAYER_REGULAR);

        // Every copied puzzle is named "… paging N": the search finds all of them, also those beyond the first rows
        $results = $this->build(null, ['q' => 'paging']);

        self::assertSame(self::EXTRA_PUZZLES, $results->rowsCount());
        self::assertCount(PlayerResultsPage::PAGE_SIZE, $results->shownRows());
    }

    // ------------------------------------------- Search (PlayerSolvedPuzzlesSearchTest + PROF-05d)

    /**
     * @param list<string> $expectedPuzzleIds
     */
    #[DataProvider('provideSearches')]
    public function testSearchFindsEveryNameEansAndBrandCodes(string $search, array $expectedPuzzleIds): void
    {
        $results = $this->build(null, ['q' => $search]);

        $puzzleIds = array_values(array_unique(array_map(static fn (array $row): string => $row[0]->puzzleId, array_values($results->soloRows))));
        sort($puzzleIds);
        sort($expectedPuzzleIds);

        self::assertSame($expectedPuzzleIds, $puzzleIds);
    }

    /**
     * PLAYER_REGULAR solved PUZZLE_1000_02 ("Puzzle 7", Czech "Kouzelná zahrada", German "Zauberhafter Garten"),
     * PUZZLE_300 ("Puzzle 11", "Kouzelna zahrada", EAN 4005556202027) and PUZZLE_500_01 ("Puzzle 1", brand code
     * RB-500-001)
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideSearches(): iterable
    {
        yield 'the main title' => ['puzzle 7', [PuzzleFixture::PUZZLE_1000_02]];
        // PUZZLE_300 ("Puzzle 11") carries the same name without accents
        yield 'the Czech name without accents' => ['KOUZELNA zahrada', [PuzzleFixture::PUZZLE_1000_02, PuzzleFixture::PUZZLE_300]];
        yield 'the Czech name with accents' => ['kouzelná', [PuzzleFixture::PUZZLE_1000_02, PuzzleFixture::PUZZLE_300]];
        yield 'the German name' => ['zauberhafter', [PuzzleFixture::PUZZLE_1000_02]];
        yield 'full-width letters' => ['Ｇａｒｔｅｎ', [PuzzleFixture::PUZZLE_1000_02]];
        yield 'a part of the brand code (5 letters and digits)' => ['rb-500', [PuzzleFixture::PUZZLE_500_01]];
        yield 'words of two names' => ['zahrada zauberhafter', []];
        // PROF-05d: PuzzleTextSearch - EANs and whole brand codes too
        yield 'the EAN' => [PuzzleFixture::EAN_PUZZLE_300, [PuzzleFixture::PUZZLE_300]];
        yield 'the EAN with leading zeros' => ['00' . PuzzleFixture::EAN_PUZZLE_300, [PuzzleFixture::PUZZLE_300]];
        yield 'the whole brand code' => ['RB-500-001', [PuzzleFixture::PUZZLE_500_01]];
        // Behaviour change (logged): the profile's own search matched any part of a brand code; PuzzleTextSearch
        // matches a part of a code only from 5 letters/digits (PuzzleSearchQuery::MIN_CODE_PART_LENGTH)
        yield 'a part of a brand code shorter than 5' => ['rb-5', []];
        yield 'no match' => ['nothing like this', []];
    }

    public function testTheSearchNarrowsPairAndTeamRowsToo(): void
    {
        $results = $this->build(null, ['q' => 'puzzle 8', 'category' => 'duo']);

        self::assertNotSame([], $results->duoRows);
        self::assertSame([PuzzleFixture::PUZZLE_1000_03], array_values(array_unique(array_map(static fn (array $row): string => $row[0]->puzzleId, $results->duoRows))));
        self::assertSame([], $results->soloRows, 'The regular player has no solo result of Puzzle 8');
    }

    // ------------------------------------------------ Photo link (PlayerSolvedPuzzlesPhotoLinkTest)

    public function testOwnFinishedPhotoOpensTheLargeStrippedPresetNeverTheOriginal(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE puzzle_solving_time SET finished_puzzle_photo = :photo WHERE player_id = :playerId AND team IS NULL',
            ['photo' => 'players/regular/finished-with-exif.jpg', 'playerId' => PlayerFixture::PLAYER_REGULAR],
        );

        $crawler = $this->page(PlayerFixture::PLAYER_REGULAR, []);
        $links = $crawler->filter('#player-results a.gallery-item')->each(static fn (Crawler $link): null|string => $link->attr('href'));

        // The photo opens the 1200 px preset with the metadata stripped, never the uploaded original,
        // which can still carry EXIF including the GPS position of the player's home
        $largeImage = self::getContainer()->get(ImageThumbnailTwigExtension::class)
            ->thumbnailUrl('players/regular/finished-with-exif.jpg', 'puzzle_large');
        self::assertNotSame([], $links);
        self::assertSame(array_fill(0, count($links), $largeImage), $links);
        self::assertStringNotContainsString('/original/', $crawler->filter('#player-results')->html());

        // Nobody else gets the photo
        self::assertCount(0, $this->page(null, [])->filter('#player-results a.gallery-item'));
    }

    // ------------------------------------------- Piece count (PlayerSolvedPuzzlesPiecesFilterTest)

    public function testOnlyChipsWithResultsAreOfferedAndTheActiveOneStays(): void
    {
        $results = $this->build(null, []);
        $all = $this->piecesCounts($results);
        $offered = array_map(static fn (PiecesRange $preset): string => $preset->toParam(), $results->availablePiecePresets);

        foreach (PiecesRange::presets() as $preset) {
            $hasResults = array_filter($all, $preset->contains(...)) !== [];
            self::assertSame($hasResults, in_array($preset->toParam(), $offered, true), $preset->toParam());
        }

        // A chip the player has nothing for still shows while it is selected
        $results = $this->build(null, ['pieces' => '99-100']);
        $offered = array_map(static fn (PiecesRange $preset): string => $preset->toParam(), $results->availablePiecePresets);
        self::assertContains('99-100', $offered);

        // ... and on the page, checked
        $crawler = $this->page(null, ['pieces' => '99-100']);
        self::assertCount(1, $crawler->filter('#player-results-form input[name="pieces"][value="99-100"][checked]'));
    }

    public function testChipAndCustomRangeFilterTheResults(): void
    {
        self::assertContains(500, $this->piecesCounts($this->build(null, [])));

        $results = $this->build(null, ['pieces' => '500']);
        self::assertSame(500, $results->criteria->pieces?->minPieces);
        self::assertSame(500, $results->criteria->pieces->maxPieces);
        self::assertNotSame([], $this->piecesCounts($results));
        self::assertSame([500], array_values(array_unique($this->piecesCounts($results))));
        self::assertSame(1, $results->criteria->activeFiltersCount());

        $results = $this->build(null, ['pieces_min' => '501', 'pieces_max' => '']);
        self::assertSame('501-', $results->criteria->pieces?->toParam());

        foreach ($this->piecesCounts($results) as $piecesCount) {
            self::assertGreaterThan(500, $piecesCount);
        }

        // "Clear all"
        $crawler = $this->page(null, ['pieces_min' => '501']);
        $clear = $crawler->filter('#player-results a[data-results-clear]')->first()->attr('href');
        self::assertSame([], $this->queryOf((string) $clear));
        $results = $this->build(null, $this->queryOf((string) $clear));
        self::assertNull($results->criteria->pieces);
        self::assertSame(0, $results->criteria->activeFiltersCount());
    }

    // --------------------------------------------- Difficulty (PlayerSolvedPuzzlesDifficultyTest)

    public function testMemberSeesTheTierOnEveryThumbnail(): void
    {
        $crawler = $this->page(PlayerFixture::PLAYER_WITH_STRIPE, [], recalculate: true);

        $rows = $this->rows($crawler);
        self::assertGreaterThan(0, $rows->count());
        self::assertCount($rows->count(), $crawler->filter('#player-results .ps-table [data-testid="difficulty-corner"]'));
        self::assertNotSame([$this->unknown()], array_values(array_unique($this->cornerNames($crawler))), 'Some of the player\'s puzzles are rated');

        self::assertGreaterThan(0, $crawler->filter('input[name="difficultyTiers[]"]')->count());
        self::assertCount(0, $crawler->filter('fieldset[disabled] input[name="difficultyTiers[]"]'));
    }

    public function testMemberNarrowsTheResultsToTiers(): void
    {
        $crawler = $this->page(PlayerFixture::PLAYER_WITH_STRIPE, [], recalculate: true);
        $allRows = $this->rows($crawler)->count();

        $rated = array_values(array_diff($this->cornerNames($crawler), [$this->unknown()]));
        self::assertNotSame([], $rated);
        $tier = $this->tierNamed($rated[0]);

        $crawler = $this->page(PlayerFixture::PLAYER_WITH_STRIPE, ['difficultyTiers' => [(string) $tier->value]]);

        self::assertGreaterThan(0, $this->rows($crawler)->count());
        self::assertSame([$rated[0]], array_values(array_unique($this->cornerNames($crawler))));
        self::assertSame('1', $crawler->filter('[data-testid="results-filters-count"]')->text());
        self::assertCount(1, $crawler->filter('input[name="difficultyTiers[]"][value="' . $tier->value . '"][checked]'));
        self::assertCount(1, $crawler->filter('a[data-results-chip="difficultyTiers:' . $tier->value . '"]'));

        // "0" = not rated yet
        $names = $this->cornerNames($this->page(PlayerFixture::PLAYER_WITH_STRIPE, ['difficultyTiers' => ['0']]));
        self::assertSame([], array_values(array_diff($names, [$this->unknown()])));

        // "Clear all"
        $clear = (string) $crawler->filter('#player-results a[data-results-clear]')->first()->attr('href');
        $crawler = $this->page(PlayerFixture::PLAYER_WITH_STRIPE, $this->queryOf($clear));
        self::assertCount($allRows, $this->rows($crawler));
        self::assertCount(0, $crawler->filter('[data-testid="results-filters-count"]'));
        self::assertSame([], $this->build(PlayerFixture::PLAYER_WITH_STRIPE, $this->queryOf($clear))->criteria->difficultyTiers);
    }

    public function testMemberIsOfferedTheTiersThePlayerHasAndTheSelectedOne(): void
    {
        $crawler = $this->page(PlayerFixture::PLAYER_WITH_STRIPE, [], recalculate: true);

        $offered = $this->offered($crawler);
        $shown = array_map(fn (string $name): string => $this->valueNamed($name), $this->cornerNames($crawler));
        self::assertSame([], array_values(array_diff($shown, $offered)), 'Every tier on a thumbnail has its chip');

        $missing = array_values(array_diff(['1', '2', '3', '4', '5', '6', '0'], $offered));
        self::assertNotSame([], $missing, 'The fixture player does not have a result in every tier');

        // A tier the player has nothing in still shows while it is selected
        $crawler = $this->page(PlayerFixture::PLAYER_WITH_STRIPE, ['difficultyTiers' => [$missing[0]]]);
        self::assertContains($missing[0], $this->offered($crawler));
        self::assertCount(0, $this->rows($crawler)->filter('[data-testid="difficulty-corner"]'));
        self::assertCount(1, $crawler->filter('[data-testid="results-empty-filtered"]'), 'The empty state names the filters');
    }

    public function testUnknownDifficultyValuesAreDropped(): void
    {
        $results = $this->build(PlayerFixture::PLAYER_WITH_STRIPE, ['difficultyTiers' => ['9', 'x', '3', '3', '0', '-1', '12']]);

        self::assertSame(['0', '3'], $results->criteria->difficultyTiers);
    }

    public function testWithoutMembershipThereAreNoTiersAndTheFilterIsIgnored(): void
    {
        foreach ([null, PlayerFixture::PLAYER_REGULAR] as $viewer) {
            $crawler = $this->page($viewer, []);
            $allRows = $this->rows($crawler)->count();

            self::assertGreaterThan(0, $allRows);
            self::assertCount(0, $crawler->filter('[data-testid="difficulty-corner"]'));
            self::assertCount(7, $crawler->filter('fieldset[disabled] input[name="difficultyTiers[]"]'));

            $crawler = $this->page($viewer, ['difficultyTiers' => ['6']]);
            self::assertCount($allRows, $this->rows($crawler));
            self::assertCount(0, $crawler->filter('[data-testid="results-filters-count"]'));
            self::assertCount(0, $crawler->filter('a[data-results-chip]'), 'A dropped filter is no chip');
        }
    }

    public function testMemberSortsTheResultsByDifficulty(): void
    {
        self::getContainer()->get(PuzzleIntelligenceRecalculator::class)->recalculate();
        $this->spreadDifficulties();

        $crawler = $this->page(PlayerFixture::PLAYER_WITH_STRIPE, []);
        self::assertCount(1, $crawler->filter('a[data-results-sort="easiest"]'));
        self::assertCount(1, $crawler->filter('a[data-results-sort="hardest"]'));
        self::assertCount(0, $crawler->filter('[data-results-sort-locked]'));

        $easiest = $this->cornerTiers($this->page(PlayerFixture::PLAYER_WITH_STRIPE, ['sort' => 'easiest']));
        $this->assertDifficultyOrder($easiest, hardestFirst: false);
        self::assertGreaterThan(2, count(array_unique(array_diff($easiest, [0]))), 'Several rated tiers to order');
        self::assertContains(0, $easiest, 'A puzzle not rated yet');

        $crawler = $this->page(PlayerFixture::PLAYER_WITH_STRIPE, ['sort' => 'hardest']);
        $this->assertDifficultyOrder($this->cornerTiers($crawler), hardestFirst: true);
        self::assertStringContainsString('Hardest first', $crawler->filter('#player-results-sort')->text());

        // Pairs are sorted the same way
        $duo = $this->cornerTiers($this->page(PlayerFixture::PLAYER_WITH_STRIPE, ['sort' => 'hardest', 'category' => 'duo']));
        self::assertGreaterThan(1, count($duo));
        $this->assertDifficultyOrder($duo, hardestFirst: true);

        // Together with the difficulty filter
        $rated = array_values(array_unique(array_diff($easiest, [0])));
        $filtered = $this->cornerTiers($this->page(PlayerFixture::PLAYER_WITH_STRIPE, ['sort' => 'hardest', 'difficultyTiers' => [(string) $rated[0], (string) $rated[1], '0']]));
        self::assertSame([], array_values(array_diff($filtered, [$rated[0], $rated[1], 0])));
        $this->assertDifficultyOrder($filtered, hardestFirst: true);
    }

    public function testWithoutMembershipTheDifficultySortIsLockedAndIgnored(): void
    {
        foreach ([null, PlayerFixture::PLAYER_REGULAR] as $viewer) {
            $crawler = $this->page($viewer, []);
            $newest = $this->rows($crawler)->each(static fn (Crawler $row): string => $row->html());

            self::assertCount(0, $crawler->filter('a[data-results-sort="easiest"], a[data-results-sort="hardest"]'));
            self::assertCount(2, $crawler->filter('#player-results-sort + .dropdown-menu [data-bs-target="#membersExclusiveModal"]'));

            // A sent (or left over) difficulty sort falls back to the default
            $crawler = $this->page($viewer, ['sort' => 'hardest']);
            self::assertSame(PlayerResultsSort::Newest, $this->build($viewer, ['sort' => 'hardest'])->sort);
            self::assertSame($newest, $this->rows($crawler)->each(static fn (Crawler $row): string => $row->html()));
        }
    }

    // ------------------------------------ Pairs and teams (PairsAndTeamsFiltersTest + FirstTriesForPairsAndTeamsTest)

    public function testResultsCanBeNarrowedToOnePair(): void
    {
        // PLAYER_WITH_FAVORITES has no membership: the pair/team filter is free
        $this->addPairTime('auth0|fav004', ['#admin'], PuzzleFixture::PUZZLE_1500_01);
        $this->addPairTime('auth0|fav004', ['#admin'], PuzzleFixture::PUZZLE_1500_02);
        $otherPairTeamId = $this->addPairTime('auth0|fav004', ['Grandma'], PuzzleFixture::PUZZLE_2000);

        $crawler = $this->page(PlayerFixture::PLAYER_WITH_FAVORITES, [], player: PlayerFixture::PLAYER_WITH_FAVORITES);
        $options = $crawler->filter('select[name="team"] option');
        // "All" + the two pairs, the one with more results first, each named by the other person
        self::assertCount(3, $options);
        self::assertStringContainsString('Admin User (2)', $options->eq(1)->text());
        self::assertStringContainsString('Grandma (1)', $options->eq(2)->text());

        $crawler = $this->page(PlayerFixture::PLAYER_WITH_FAVORITES, ['category' => 'duo', 'team' => $otherPairTeamId], player: PlayerFixture::PLAYER_WITH_FAVORITES);
        $html = $crawler->filter('#player-results')->html();
        self::assertStringContainsString('Grandma', $html);
        self::assertStringNotContainsString(PuzzleFixture::PUZZLE_1500_01, $html);
        self::assertStringContainsString(PuzzleFixture::PUZZLE_2000, $html);
        self::assertCount(1, $crawler->filter('select[name="team"] option[value="' . $otherPairTeamId . '"][selected]'));
        self::assertStringContainsString('Grandma', $crawler->filter('a[data-results-chip="team"]')->text(), 'The chip names the pair');

        // Without a tab, a pair filter opens the Pairs tab
        self::assertSame('duo', $this->build(PlayerFixture::PLAYER_WITH_FAVORITES, ['team' => $otherPairTeamId], PlayerFixture::PLAYER_WITH_FAVORITES)->category);
    }

    public function testPairRowsKeepOnlyFirstTriesLedByTheFirstTryAndTheSwitchStaysOnAcrossTabs(): void
    {
        // With Grandma: a first try of PUZZLE_500_04 and a faster retry later, PUZZLE_500_05 only as a retry
        $firstTry = $this->addGroupTime('auth0|stripe005', ['Grandma'], PuzzleFixture::PUZZLE_500_04, 3600, daysAgo: 10, firstAttempt: true);
        $retry = $this->addGroupTime('auth0|stripe005', ['Grandma'], PuzzleFixture::PUZZLE_500_04, 2400, daysAgo: 2);
        $retryOnly = $this->addGroupTime('auth0|stripe005', ['Grandma'], PuzzleFixture::PUZZLE_500_05, 1800, daysAgo: 1);

        $crawler = $this->page(PlayerFixture::PLAYER_WITH_STRIPE, ['first' => '1', 'category' => 'duo'], player: PlayerFixture::PLAYER_WITH_STRIPE);

        // The first try leads the row although the retry is faster - its time and its result detail
        self::assertSame([$firstTry], $this->rowTimeIds($crawler));
        self::assertStringContainsString('01:00:00', $crawler->filter('#player-results .ps-time-value')->text());
        self::assertStringContainsString('Pair (1)', $crawler->filter('[data-testid="results-categories"]')->text());

        // The switch is not turned off by leaving the Pairs tab: every tab link keeps it
        foreach (['solo', 'duo'] as $category) {
            self::assertSame('1', $this->queryOf((string) $crawler->filter('[data-results-category="' . $category . '"]')->attr('href'))['first'] ?? null, $category);
        }

        // Without the switch the pair's best attempt leads (Results semantics) - the retry; newest first
        self::assertSame([$retryOnly, $retry], $this->rowTimeIds($this->page(PlayerFixture::PLAYER_WITH_STRIPE, ['category' => 'duo'], player: PlayerFixture::PLAYER_WITH_STRIPE)));
    }

    public function testBothSwitchesNeedOneAttemptThatIsBothOnPairsAndTeams(): void
    {
        // Pair: a boxed first try and an unboxed retry - first try and unboxed, but never both at once
        $this->addGroupTime('auth0|stripe005', ['Grandma'], PuzzleFixture::PUZZLE_1000_05, 7200, daysAgo: 5, firstAttempt: true);
        $unboxedRetry = $this->addGroupTime('auth0|stripe005', ['Grandma'], PuzzleFixture::PUZZLE_1000_05, 6000, daysAgo: 1, unboxed: true);
        // Team: one attempt that is both
        $unboxedFirstTry = $this->addGroupTime('auth0|stripe005', ['Grandma', 'Grandpa'], PuzzleFixture::PUZZLE_300, 1500, daysAgo: 3, firstAttempt: true, unboxed: true);

        $member = PlayerFixture::PLAYER_WITH_STRIPE;
        self::assertSame([$unboxedRetry], $this->rowTimeIds($this->page($member, ['category' => 'duo', 'unboxed' => '1'], player: $member)));

        $crawler = $this->page($member, ['category' => 'duo', 'unboxed' => '1', 'first' => '1'], player: $member);
        self::assertSame([], $this->rowTimeIds($crawler));
        self::assertStringContainsString('Team (1)', $crawler->filter('[data-testid="results-categories"]')->text());

        self::assertSame([$unboxedFirstTry], $this->rowTimeIds($this->page($member, ['category' => 'group', 'unboxed' => '1', 'first' => '1'], player: $member)));
    }

    public function testFirstTriesAreMembersOnlyOnEveryTab(): void
    {
        // PLAYER_WITH_FAVORITES has no membership: the switch is disabled for them and a sent value is dropped
        $this->addGroupTime('auth0|stripe005', ['Grandma'], PuzzleFixture::PUZZLE_500_04, 3600, daysAgo: 10, firstAttempt: true);
        $retryOnly = $this->addGroupTime('auth0|stripe005', ['Grandma'], PuzzleFixture::PUZZLE_500_05, 1800, daysAgo: 1);

        $crawler = $this->page(PlayerFixture::PLAYER_WITH_FAVORITES, ['category' => 'duo', 'first' => '1'], player: PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertCount(2, $this->rowTimeIds($crawler));
        self::assertContains($retryOnly, $this->rowTimeIds($crawler));
        self::assertCount(1, $crawler->filter('fieldset[disabled] input[name="first"]'));
        self::assertFalse($this->build(PlayerFixture::PLAYER_WITH_FAVORITES, ['category' => 'duo', 'first' => '1'], PlayerFixture::PLAYER_WITH_STRIPE)->criteria->onlyFirstTries);
    }

    public function testPairFilterNarrowsPairsAndTeamsButNeverHidesSoloResults(): void
    {
        // A pair/team page links its members' results narrowed to that pair (?team=…&category=duo)
        $withGrandma = $this->addGroupTime('auth0|stripe005', ['Grandma'], PuzzleFixture::PUZZLE_500_04, 3600, daysAgo: 10);
        $this->addGroupTime('auth0|stripe005', ['Grandpa'], PuzzleFixture::PUZZLE_500_05, 1800, daysAgo: 1);
        $teamId = $this->teamOf($withGrandma);
        $viewer = PlayerFixture::PLAYER_WITH_FAVORITES;
        $player = PlayerFixture::PLAYER_WITH_STRIPE;

        $unfiltered = $this->page($viewer, [], player: $player);
        $soloRows = $this->rowTimeIds($unfiltered);
        self::assertNotEmpty($soloRows);

        $crawler = $this->page($viewer, ['category' => 'duo', 'team' => $teamId], player: $player);

        self::assertSame([$withGrandma], $this->rowTimeIds($crawler));
        $soloTab = $crawler->filter('[data-results-category="solo"]');
        self::assertSame(trim($unfiltered->filter('[data-results-category="solo"]')->text()), trim($soloTab->text()));
        self::assertSame('a', $soloTab->nodeName(), 'The Solo tab can be opened');

        self::assertSame($soloRows, $this->rowTimeIds($this->page($viewer, ['category' => 'solo', 'team' => $teamId], player: $player)));

        // "Clear all" clears the pair/team filter too - it is counted among the active filters
        $clear = (string) $crawler->filter('#player-results a[data-results-clear]')->first()->attr('href');
        self::assertSame(['category' => 'duo'], $this->queryOf($clear));
        self::assertNull($this->build($viewer, $this->queryOf($clear), $player)->criteria->team);
        self::assertCount(2, $this->rowTimeIds($this->page($viewer, $this->queryOf($clear), player: $player)));
    }

    // ----------------------------------------------------------------- Results semantics (D11)

    /**
     * Deliberate change from the profile list (D11, decision 69): a By-puzzle row leads with the player's best attempt
     * whatever the sort, and the sort orders the rows by that attempt
     */
    public function testEveryByPuzzleRowLeadsWithTheBestAttemptUnderEverySort(): void
    {
        $fastest = $this->fastestSoloTimes(PlayerFixture::PLAYER_REGULAR);
        self::assertGreaterThan(0, count(array_filter($this->soloAttemptsCounts(PlayerFixture::PLAYER_REGULAR), static fn (int $count): bool => $count > 1)), 'Puzzles with several attempts to lead');

        foreach (['newest', 'oldest', 'fastest', 'slowest', 'fastest_ppm', 'slowest_ppm', 'name', 'rank', 'attempts'] as $sort) {
            $results = $this->build(null, ['sort' => $sort]);
            self::assertSame($sort, $results->sort->value);

            foreach ($results->soloRows as $row) {
                self::assertSame($fastest[$row[0]->puzzleId], $row[0]->time, $sort . ': ' . $row[0]->puzzleName);
            }

            $leading = array_map(static fn (array $row): SolvedPuzzle => $row[0], array_values($results->soloRows));
            $attempts = array_map(static fn (array $row): int => count($row), array_values($results->soloRows));
            self::assertSame(self::expectedOrder($sort, $leading, $attempts, $results), array_map(static fn (SolvedPuzzle $p): string => $p->timeId, $leading), $sort);
        }
    }

    public function testNewestIsTheDefaultSort(): void
    {
        $crawler = $this->page(null, []);

        self::assertSame(PlayerResultsSort::Newest, $this->build(null, [])->sort);
        self::assertStringContainsString('Newest', $crawler->filter('#player-results-sort')->text());
        self::assertSame($this->rowTimeIds($this->page(null, ['sort' => 'newest'])), $this->rowTimeIds($crawler));
        self::assertSame([], $this->queryOf((string) $crawler->filter('a[data-results-sort="newest"]')->attr('href')), 'The default needs no parameter');
    }

    public function testEveryResultHasOneRowPerResultUnderMonthHeadings(): void
    {
        $solo = self::getContainer()->get(Connection::class)->fetchFirstColumn(
            "SELECT id FROM puzzle_solving_time WHERE player_id = :id AND puzzling_type = 'solo'",
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );

        $results = $this->build(null, ['view' => 'every']);
        self::assertCount(count($solo), $results->soloRows);
        self::assertSame([], $results->ranking, 'No rank on Every result rows');

        $crawler = $this->page(null, ['view' => 'every']);
        $timeIds = $this->rowTimeIds($crawler);
        sort($timeIds);
        sort($solo);
        self::assertSame($solo, $timeIds);
        self::assertGreaterThan(0, $crawler->filter('#player-results tr.prs-month-row')->count(), 'Newest: month headings');
        self::assertCount(0, $crawler->filter('#player-results .pr-chip-rank'));
        self::assertCount(0, $crawler->filter('#player-results .lb-attempts-badge'), 'One result per row - no attempts badge');

        // Only by date
        self::assertCount(0, $this->page(null, ['view' => 'every', 'sort' => 'fastest'])->filter('#player-results tr.prs-month-row'));

        // A sort for By puzzle only falls back to the default
        self::assertSame(PlayerResultsSort::Newest, $this->build(null, ['view' => 'every', 'sort' => 'attempts'])->sort);
        self::assertCount(0, $crawler->filter('a[data-results-sort="attempts"], a[data-results-sort="rank"]'));
    }

    public function testEveryResultUnderFirstTriesShowsOnlyTheFirstTries(): void
    {
        $results = $this->build(PlayerFixture::PLAYER_WITH_STRIPE, ['view' => 'every', 'first' => '1']);

        self::assertNotSame([], $results->soloRows);

        foreach ($results->soloRows as $row) {
            self::assertTrue($row[0]->firstAttempt);
        }
    }

    public function testEqualRowsKeepOneOrderEndingOnTheIds(): void
    {
        // Two copies with the same time and date as the original: only the ids tell them apart. Their results' ids go
        // the other way than their puzzles' ids, so the history's own order (by result id) is not the expected one
        $copies = $this->addPuzzlesWithSoloTimes(PlayerFixture::PLAYER_REGULAR, copies: 2);
        sort($copies);
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement('UPDATE puzzle_solving_time SET id = :id WHERE puzzle_id = :puzzleId', ['id' => 'ffffffff-ffff-4fff-bfff-ffffffffffff', 'puzzleId' => $copies[0]]);
        $database->executeStatement('UPDATE puzzle_solving_time SET id = :id WHERE puzzle_id = :puzzleId', ['id' => '00000000-0000-4000-8000-000000000001', 'puzzleId' => $copies[1]]);

        foreach (['newest', 'oldest', 'fastest', 'slowest', 'attempts'] as $sort) {
            $order = array_map(static fn (array $row): string => $row[0]->puzzleId, array_values($this->build(null, ['sort' => $sort])->soloRows));
            self::assertSame($copies, array_values(array_filter($order, static fn (string $id): bool => in_array($id, $copies, true))), $sort . ': equal rows in id order');
            self::assertSame($order, array_map(static fn (array $row): string => $row[0]->puzzleId, array_values($this->build(null, ['sort' => $sort])->soloRows)), 'A reload keeps the order');
        }
    }

    public function testThePlayersFirstTabWithResultsIsTheDefault(): void
    {
        // A player with pair results only
        $results = $this->build(null, [], DuplicateResultsFixture::PLAYER_TWINS_TEAMMATE);

        self::assertSame('duo', $results->defaultCategory);
        self::assertSame('duo', $results->category);
        self::assertNotSame([], $results->duoRows);

        // Its tab link carries no category, the empty Solo tab cannot be opened
        $crawler = $this->page(null, [], player: DuplicateResultsFixture::PLAYER_TWINS_TEAMMATE);
        self::assertSame('span', $crawler->filter('[data-results-category="solo"]')->nodeName());
        self::assertSame([], $this->queryOf((string) $crawler->filter('[data-results-category="duo"]')->attr('href')));
    }

    // ------------------------------------------------------------------------------- helpers

    /**
     * @param array<array-key, mixed> $query
     */
    private function build(null|string $viewerId, array $query, string $playerId = PlayerFixture::PLAYER_REGULAR): PlayerResultsPage
    {
        $container = self::getContainer();
        // A request's worth of fresh services: the viewer decides what the queries leave out
        $container->get('services_resetter')->reset();

        if ($viewerId !== null) {
            // Creates the viewer's account when the fixtures have none, then signs it in for this "request"
            TestingLogin::asPlayer($this->browser, $viewerId);
            $userId = $container->get(PlayerRepository::class)->get($viewerId)->userId;
            $account = $container->get(UserAccountRepository::class)->findByUserId((string) $userId);
            self::assertNotNull($account);
            $container->get('security.token_storage')->setToken(new UsernamePasswordToken($account, 'main', $account->getRoles()));
        }

        $viewer = $container->get(RetrieveLoggedUserProfile::class)->getProfile();
        self::assertSame($viewerId, $viewer?->playerId);

        return $container->get(PlayerResultsBuilder::class)->build($playerId, PlayerResultsCriteria::fromQuery($query, $viewer?->activeMembership === true));
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private function page(null|string $viewerId, array $query, bool $recalculate = false, string $player = PlayerFixture::PLAYER_REGULAR): Crawler
    {
        if ($recalculate) {
            self::getContainer()->get(PuzzleIntelligenceRecalculator::class)->recalculate();
        }

        if ($viewerId !== null) {
            TestingLogin::asPlayer($this->browser, $viewerId);
        }

        $crawler = $this->browser->request('GET', '/en/player-results/' . $player . ($query !== [] ? '?' . http_build_query($query) : ''));
        self::assertResponseIsSuccessful();
        $this->browser->getCookieJar()->clear();

        return $crawler;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function queryOf(null|string $url): array
    {
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function rows(Crawler $crawler): Crawler
    {
        return $crawler->filter('#player-results .ps-table tbody tr')->reduce(static fn (Crawler $row): bool => $row->filter('td.ps-image')->count() > 0);
    }

    /**
     * @return list<string>
     */
    private function rowTimeIds(Crawler $crawler): array
    {
        return $crawler->filter('#player-results a.ps-time-value')->each(static fn (Crawler $link): string => basename((string) $link->attr('href')));
    }

    /**
     * @return list<int>
     */
    private function piecesCounts(PlayerResultsPage $results): array
    {
        $counts = [];

        foreach ([...$results->soloRows, ...$results->duoRows, ...$results->teamRows] as $row) {
            foreach ($row as $solvedPuzzle) {
                $counts[] = $solvedPuzzle->piecesCount;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, int> puzzle id => the player's fastest solo time on it
     */
    private function fastestSoloTimes(string $playerId): array
    {
        /** @var array<string, int> $times */
        $times = self::getContainer()->get(Connection::class)->fetchAllKeyValue(
            "SELECT puzzle_id, MIN(seconds_to_solve) FROM puzzle_solving_time WHERE player_id = :id AND puzzling_type = 'solo' GROUP BY puzzle_id",
            ['id' => $playerId],
        );

        return $times;
    }

    /**
     * @return array<string, int>
     */
    private function soloAttemptsCounts(string $playerId): array
    {
        /** @var array<string, int> $counts */
        $counts = self::getContainer()->get(Connection::class)->fetchAllKeyValue(
            "SELECT puzzle_id, COUNT(*) FROM puzzle_solving_time WHERE player_id = :id AND puzzling_type = 'solo' GROUP BY puzzle_id",
            ['id' => $playerId],
        );

        return $counts;
    }

    /**
     * The order the sort promises, computed here from the leading attempts: its key, then the puzzle and result ids
     *
     * @param list<SolvedPuzzle> $leading
     * @param list<int> $attempts
     * @return list<string> time ids
     */
    private static function expectedOrder(string $sort, array $leading, array $attempts, PlayerResultsPage $results): array
    {
        $date = static fn (SolvedPuzzle $p): DateTimeImmutable => $p->finishedAt ?? $p->trackedAt;
        $ppm = static fn (SolvedPuzzle $p): null|float => $p->time ? $p->piecesCount * 60 / $p->time : null;
        $nullsLast = static fn (null|int|float $a, null|int|float $b, bool $desc = false): int => ($a === null || $b === null)
            ? ($a === null ? 1 : 0) - ($b === null ? 1 : 0)
            : ($desc ? $b <=> $a : $a <=> $b);
        $ranks = $sort === 'rank' ? self::getContainer()->get(GetRanking::class)->allForPlayer($results->playerId) : [];

        $indexes = array_keys($leading);
        usort($indexes, static function (int $i, int $j) use ($sort, $leading, $attempts, $date, $ppm, $nullsLast, $ranks): int {
            $a = $leading[$i];
            $b = $leading[$j];
            $comparison = match ($sort) {
                'newest' => $date($b) <=> $date($a),
                'oldest' => $date($a) <=> $date($b),
                'fastest' => $nullsLast($a->time, $b->time) ?: $date($a) <=> $date($b),
                'slowest' => $nullsLast($a->time, $b->time, true) ?: $date($a) <=> $date($b),
                'fastest_ppm' => $nullsLast($ppm($a), $ppm($b), true) ?: $date($a) <=> $date($b),
                'slowest_ppm' => $nullsLast($ppm($a), $ppm($b)) ?: $date($a) <=> $date($b),
                'name' => SearchText::fold($a->puzzleName) <=> SearchText::fold($b->puzzleName),
                'rank' => $nullsLast($ranks[$a->puzzleId]->rank ?? null, $ranks[$b->puzzleId]->rank ?? null)
                    ?: ($ranks[$b->puzzleId]->totalPlayers ?? 0) <=> ($ranks[$a->puzzleId]->totalPlayers ?? 0),
                'attempts' => $attempts[$j] <=> $attempts[$i] ?: $date($b) <=> $date($a),
                default => 0,
            };

            return $comparison ?: $a->puzzleId <=> $b->puzzleId ?: $a->timeId <=> $b->timeId;
        });

        return array_map(static fn (int $i): string => $leading[$i]->timeId, $indexes);
    }

    /**
     * Copies of one of the player's puzzles and of their solo time on it (rolled back with the test's transaction)
     *
     * @return list<string> the copies' puzzle ids
     */
    private function addPuzzlesWithSoloTimes(string $playerId, int $copies = self::EXTRA_PUZZLES): array
    {
        $database = self::getContainer()->get(Connection::class);

        /** @var array{id: string, puzzle_id: string} $time */
        $time = $database->fetchAssociative(
            "SELECT id, puzzle_id FROM puzzle_solving_time WHERE player_id = :playerId AND puzzling_type = 'solo' AND seconds_to_solve IS NOT NULL ORDER BY id LIMIT 1",
            ['playerId' => $playerId],
        );

        $database->executeStatement(
            'CREATE TEMP TABLE paging_puzzle ON COMMIT DROP AS SELECT puzzle.*, g AS copy FROM puzzle, generate_series(1, :copies) g WHERE puzzle.id = :puzzleId',
            ['copies' => $copies, 'puzzleId' => $time['puzzle_id']],
        );
        $database->executeStatement("UPDATE paging_puzzle SET id = gen_random_uuid(), name = 'Copy paging ' || copy, search_names = search_names || E'\\n' || 'paging ' || copy");
        $database->executeStatement(
            'CREATE TEMP TABLE paging_time ON COMMIT DROP AS SELECT pst.*, pp.id AS copy_puzzle_id FROM puzzle_solving_time pst, paging_puzzle pp WHERE pst.id = :timeId',
            ['timeId' => $time['id']],
        );
        $database->executeStatement('UPDATE paging_time SET id = gen_random_uuid(), puzzle_id = copy_puzzle_id');
        $database->executeStatement('ALTER TABLE paging_puzzle DROP COLUMN copy');
        $database->executeStatement('ALTER TABLE paging_time DROP COLUMN copy_puzzle_id');
        $database->executeStatement('INSERT INTO puzzle SELECT * FROM paging_puzzle');
        $database->executeStatement('INSERT INTO puzzle_solving_time SELECT * FROM paging_time');

        /** @var list<string> $ids */
        $ids = $database->fetchFirstColumn('SELECT id FROM paging_puzzle');

        return $ids;
    }

    /**
     * @param array<string> $groupPlayers
     */
    private function addPairTime(string $userId, array $groupPlayers, string $puzzleId): string
    {
        $timeId = Uuid::uuid7();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: null,
            time: '05:00:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));

        $teamId = self::getContainer()->get(Connection::class)->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId->toString()]);
        self::assertIsString($teamId);

        return $teamId;
    }

    /**
     * Added through the bus like the add form (pair/team assembled around the tracker), then dated and flagged by SQL
     *
     * @param array<string> $groupPlayers
     */
    private function addGroupTime(
        string $userId,
        array $groupPlayers,
        string $puzzleId,
        int $seconds,
        int $daysAgo,
        bool $firstAttempt = false,
        bool $unboxed = false,
    ): string {
        $timeId = Uuid::uuid7();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: null,
            // A distinct time each: the handler takes an identical copy within seconds for a resent form
            time: gmdate('H:i:s', $seconds),
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));

        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE puzzle_solving_time SET seconds_to_solve = :seconds, first_attempt = :firstAttempt, unboxed = :unboxed, finished_at = now() - make_interval(days => :daysAgo) WHERE id = :id',
            ['seconds' => $seconds, 'firstAttempt' => $firstAttempt ? 'true' : 'false', 'unboxed' => $unboxed ? 'true' : 'false', 'daysAgo' => $daysAgo, 'id' => $timeId->toString()],
        );

        return $timeId->toString();
    }

    private function teamOf(string $timeId): string
    {
        $teamId = self::getContainer()->get(Connection::class)->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertIsString($teamId);

        return $teamId;
    }

    /**
     * The fixture's puzzles mostly share one tier: rate every solved puzzle across the tiers, the regular player's
     * first solo puzzle not at all (rolled back after the test)
     */
    private function spreadDifficulties(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $scores = [0.6, 0.8, 1.0, 1.2, 1.3, 1.5];

        foreach ($connection->fetchFirstColumn('SELECT DISTINCT puzzle_id FROM puzzle_solving_time ORDER BY puzzle_id') as $index => $puzzleId) {
            $score = $scores[$index % count($scores)];
            $connection->executeStatement(
                "INSERT INTO puzzle_difficulty (puzzle_id, difficulty_tier, difficulty_score, confidence, sample_size, computed_at)
                VALUES (:puzzleId, :tier, :score, 'high', 10, NOW())
                ON CONFLICT (puzzle_id) DO UPDATE SET difficulty_tier = EXCLUDED.difficulty_tier, difficulty_score = EXCLUDED.difficulty_score",
                ['puzzleId' => $puzzleId, 'tier' => DifficultyTier::fromScore($score)->value, 'score' => $score],
            );
        }

        $connection->executeStatement(
            'DELETE FROM puzzle_difficulty WHERE puzzle_id = (SELECT puzzle_id FROM puzzle_solving_time WHERE player_id = :player AND team IS NULL ORDER BY puzzle_id LIMIT 1)',
            ['player' => PlayerFixture::PLAYER_REGULAR],
        );
    }

    /**
     * @param list<int> $tiers tier value per row, 0 = not rated yet
     */
    private function assertDifficultyOrder(array $tiers, bool $hardestFirst): void
    {
        $rated = array_values(array_filter($tiers, static fn (int $tier): bool => $tier !== 0));
        self::assertSame($rated, array_slice($tiers, 0, count($rated)), 'Not rated yet comes last');

        $expected = $rated;
        $hardestFirst ? rsort($expected) : sort($expected);
        self::assertSame($expected, $rated);
    }

    /**
     * @return list<int> tier value on each row's thumbnail, 0 = not rated yet
     */
    private function cornerTiers(Crawler $crawler): array
    {
        return array_map(fn (string $name): int => (int) $this->valueNamed($name), $this->cornerNames($crawler));
    }

    /**
     * @return list<string> tier name on each row's thumbnail
     */
    private function cornerNames(Crawler $crawler): array
    {
        return $this->rows($crawler)->filter('[data-testid="difficulty-corner"]')->each(static fn (Crawler $corner): string => (string) $corner->attr('title'));
    }

    /**
     * @return list<string>
     */
    private function offered(Crawler $crawler): array
    {
        return $crawler->filter('input[name="difficultyTiers[]"]')->each(static fn (Crawler $input): string => (string) $input->attr('value'));
    }

    private function unknown(): string
    {
        return $this->translator()->trans('puzzle_intelligence.difficulty.tiers.unknown', locale: 'en');
    }

    private function tierNamed(string $name): DifficultyTier
    {
        foreach (DifficultyTier::cases() as $tier) {
            if ($this->translator()->trans($tier->translationKey(), locale: 'en') === $name) {
                return $tier;
            }
        }

        self::fail('No tier is called ' . $name);
    }

    private function valueNamed(string $name): string
    {
        return $name === $this->unknown() ? '0' : (string) $this->tierNamed($name)->value;
    }

    private function translator(): TranslatorInterface
    {
        return self::getContainer()->get(TranslatorInterface::class);
    }
}
