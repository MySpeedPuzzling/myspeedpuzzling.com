<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PuzzlePickerControllerTest extends WebTestCase
{
    public function testGuestGetsTheIndexableLandingPageWithADemoCard(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/what-to-solve-next');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        self::assertStringContainsString('<meta name="robots" content="index, follow">', $content);
        self::assertStringContainsString('<link rel="canonical" href="http://localhost/en/what-to-solve-next">', $content);
        self::assertStringContainsString('hreflang="cs"', $content);
        self::assertCount(1, $crawler->filter('h1:contains("What should I solve next?")'));

        // Live demo: one visible card + hidden ones, spin + show more, "1 of N"
        self::assertGreaterThan(1, $crawler->filter('.puzzle-picker-card')->count());
        self::assertCount(1, $crawler->filter('[data-puzzle-picker-reveal-target="card"]:not(.d-none)'));
        self::assertCount(1, $crawler->filter('[data-action="puzzle-picker-reveal#reveal"]'));
        self::assertStringContainsString('matching puzzles', $content);
        self::assertStringContainsString('Pick another', $content);
        self::assertStringContainsString('Show 5 more', $content);

        // Marketing sections + conversion path
        self::assertStringContainsString('How it works', $content);
        self::assertStringContainsString('What you can filter', $content);
        self::assertStringContainsString('For members', $content);
        self::assertStringContainsString('Sign in to pick from your own shelf', $content);
        self::assertGreaterThan(0, $crawler->filter('a[href="/login?return=/en/what-to-solve-next"]')->count());

        // Guests never see personal CTAs or personal data
        self::assertStringNotContainsString('puzzle-stopwatch/', $content);
        self::assertStringNotContainsString('/en/puzzle-add/', $content);
        self::assertGreaterThan(0, $crawler->filter('.puzzle-picker-card a[href="/login?return=/en/what-to-solve-next"]')->count(), 'Guest cards lead to sign-in');
        self::assertCount(2, $crawler->filter('#puzzlePickerFilters fieldset[data-picker-group="personal"][disabled]'), 'Shelf and history groups are disabled for guests');
    }

    public function testEveryLocalePathIsServed(): void
    {
        $browser = self::createClient();

        foreach (['/co-skladat-dal', '/en/what-to-solve-next', '/es/que-puzzle-armar', '/fr/quel-puzzle-faire', '/de/welches-puzzle-als-naechstes', '/ja/' . rawurlencode('次のパズル')] as $path) {
            $browser->request('GET', $path);

            self::assertResponseIsSuccessful("{$path} should render");
        }
    }

    #[DataProvider('provideNoindexQueries')]
    public function testAnyQueryParameterMakesThePageNoindexButKeepsTheCanonical(string $query): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/what-to-solve-next?' . $query);

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        self::assertStringContainsString('<meta name="robots" content="noindex, follow">', $content);
        self::assertStringNotContainsString('<meta name="robots" content="index, follow">', $content);
        self::assertStringContainsString('<link rel="canonical" href="http://localhost/en/what-to-solve-next">', $content);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNoindexQueries(): iterable
    {
        yield 'pieces filter' => ['pieces[]=500'];
        yield 'seed only' => ['seed=abcd1234'];
        yield 'brand filter' => ['brand[]=' . ManufacturerFixture::MANUFACTURER_TREFL];
        yield 'garbage' => ['foo=bar'];
    }

    public function testFilterAndSpinLinksAreNofollowAndTheSeedNeverLeaksIntoTheBareUrl(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/what-to-solve-next?pieces[]=500&brand[]=' . ManufacturerFixture::MANUFACTURER_TREFL);

        $this->assertResponseIsSuccessful();

        // Chips: pieces + brand, each removing itself, brand chip labelled by name
        $chips = $crawler->filter('.puzzle-picker-filter-bar a[title="Remove this filter"]');
        self::assertCount(2, $chips);
        self::assertStringContainsString('500 pieces', $chips->eq(0)->text());
        self::assertStringContainsString('Trefl', $chips->eq(1)->text());
        self::assertStringNotContainsString('pieces', (string) $chips->eq(0)->attr('href'));
        self::assertStringContainsString('brand', (string) $chips->eq(0)->attr('href'));

        $crawler->filter('.puzzle-picker-filter-bar a, a:contains("Pick another")')->each(static function (Crawler $link): void {
            self::assertSame('nofollow', $link->attr('rel'), 'Every filter/spin link is nofollow');
        });

        $spin = $crawler->filter('a:contains("Pick another")');
        self::assertCount(1, $spin);
        self::assertMatchesRegularExpression('/seed=[a-z0-9]{4,16}/', (string) $spin->attr('href'));
        self::assertStringContainsString('pieces', (string) $spin->attr('href'), 'Spinning keeps the filters');

        // Reset goes to the bare canonical URL
        self::assertCount(1, $crawler->filter('.puzzle-picker-filter-bar a[href="/en/what-to-solve-next"]'));
    }

    public function testSignedInPlayerGetsTheToolWithTheirOwnData(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/what-to-solve-next?seed=abcd1234');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        self::assertStringContainsString('<meta name="robots" content="noindex, follow">', $content);
        self::assertCount(1, $crawler->filter('h1:contains("What should I solve next?")'));
        self::assertStringNotContainsString('How it works', $content, 'No marketing sections for signed-in players');

        // PLAYER_REGULAR's shelf has 7 pickable puzzles - 6 cards, "1 of 7"
        self::assertCount(6, $crawler->filter('.puzzle-picker-card'));
        self::assertStringContainsString('of 7 matching puzzles', $content);

        // Personal CTAs + personal history on every card of a fully solved shelf
        self::assertCount(6, $crawler->filter('.puzzle-picker-card a[href*="/en/puzzle-stopwatch/"]'));
        self::assertCount(6, $crawler->filter('.puzzle-picker-card a[href*="/en/puzzle-add/"]'));
        self::assertStringContainsString('solved', $content);
        self::assertStringContainsString('In Collection', $content);

        // Default shelf chip with a widening ×
        $chips = $crawler->filter('.puzzle-picker-filter-bar a[title="Remove this filter"]');
        self::assertCount(1, $chips);
        self::assertStringContainsString('My collection', $chips->text());
        self::assertStringContainsString('source=any', (string) $chips->attr('href'));

        // Personal filter groups are enabled
        self::assertCount(0, $crawler->filter('#puzzlePickerFilters fieldset[data-picker-group="personal"][disabled]'));
        self::assertCount(1, $crawler->filter('#puzzlePickerFilters input[name="source"][value="mine"][checked]'));
    }

    public function testEmptyStateWhenNothingMatches(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // Everything on PLAYER_REGULAR's shelf is solved
        $crawler = $browser->request('GET', '/en/what-to-solve-next?solved=never');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        self::assertCount(0, $crawler->filter('.puzzle-picker-card'));
        self::assertStringContainsString('Nothing matches these filters', $content);
        self::assertCount(1, $crawler->filter('.card a[href="/en/what-to-solve-next"]:contains("Reset filters")'));
        self::assertCount(1, $crawler->filter('.card a[href="/en/what-to-solve-next?source=any"]:contains("Pick from all puzzles")'));
    }

    public function testBorrowedPuzzlesAreTheShelfOfAPlayerWithoutCollectionItems(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', '/en/what-to-solve-next');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        // Two borrowed puzzles are the whole shelf: 2 cards, "1 of 2", "Show 1 more"
        self::assertCount(2, $crawler->filter('.puzzle-picker-card'));
        self::assertStringContainsString('of 2 matching puzzles', $content);
        self::assertStringContainsString('Borrowed', $content);
        self::assertCount(1, $crawler->filter('button:contains("Show 1 more")'));
    }

    public function testGuestPersonalFiltersAreIgnored(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/what-to-solve-next?source=mine&solved=never&lent=1');

        $this->assertResponseIsSuccessful();
        self::assertGreaterThan(1, $crawler->filter('.puzzle-picker-card')->count(), 'A guest still gets the demo from all puzzles');
        self::assertCount(0, $crawler->filter('.puzzle-picker-filter-bar a[title="Remove this filter"]'), 'No personal chips for guests');
    }

    public function testEntryPointsLinkToThePicker(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle');
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('h1 + a[href="/en/what-to-solve-next"], .d-flex a[href="/en/what-to-solve-next"]'), 'Small button next to the H1 of the puzzle overview');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-library/' . PlayerFixture::PLAYER_REGULAR);
        $this->assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('a[href="/en/what-to-solve-next"]'), 'Profile dropdown item + library header button on the own library');
        self::assertCount(1, $crawler->filter('.dropdown-menu a[href="/en/what-to-solve-next"]:contains("What next?")'));

        $crawler = $browser->request('GET', '/en/puzzle-library/' . PlayerFixture::PLAYER_WITH_STRIPE);
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/en/what-to-solve-next"]'), 'Only the dropdown item on somebody else\'s library');
    }

    public function testStaticSitemapContainsThePickerInEveryLocale(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/sitemap-static.xml');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        self::assertStringContainsString('/en/what-to-solve-next</loc>', $content);
        self::assertStringContainsString('/co-skladat-dal</loc>', $content);
        self::assertStringContainsString('/de/welches-puzzle-als-naechstes</loc>', $content);
    }

    // ---------------------------------------------------------------------------------------------
    // Insights layer: member / non-member / guest / opted-out member
    // ---------------------------------------------------------------------------------------------

    public function testMemberSeesDifficultyAndTheirPredictionOnTheCard(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->recalculateIntelligence($browser);

        // PLAYER_WITH_STRIPE's 500-piece shelf: 500_01, 500_02 (scored, solved by her -> personal
        // predictions), 500_04, 500_05 (no difficulty -> no prediction possible)
        $crawler = $browser->request('GET', '/en/what-to-solve-next?pieces[]=500&seed=abcd1234');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        self::assertCount(4, $crawler->filter('.puzzle-picker-card'));
        self::assertCount(2, $crawler->filter('.puzzle-picker-card .puzzle-picker-difficulty'), 'Difficulty tier on the two scored puzzles');
        self::assertStringContainsString('Average', $crawler->filter('.puzzle-picker-difficulty')->first()->text());
        self::assertCount(2, $crawler->filter('.puzzle-picker-card .puzzle-picker-prediction'), 'Prediction row on the two puzzles with a prediction');
        self::assertCount(2, $crawler->filter('.puzzle-picker-card .puzzle-picker-prediction-unavailable'));
        self::assertCount(2, $crawler->filter('.puzzle-picker-card .puzzle-picker-prediction-vs-best'), 'Both predicted puzzles have a personal best to compare with');
        self::assertCount(0, $crawler->filter('.puzzle-picker-prediction-locked'));
        self::assertCount(0, $crawler->filter('.puzzle-picker-prediction-opted-out'));
        self::assertStringContainsString('Your prediction:', $content);
        self::assertStringContainsString('previous solve', $content, 'Personalised wording');
        self::assertMatchesRegularExpression('/(slower than your best|could beat your record|right at your best)/', $content);

        // Filters modal: insights group unlocked, prediction controls enabled, personal budget engine
        self::assertCount(0, $crawler->filter('#puzzlePickerFilters fieldset[data-picker-group="insights"][disabled]'));
        self::assertCount(0, $crawler->filter('#puzzlePickerFilters fieldset[data-picker-group="predictions"][disabled]'));
        self::assertCount(0, $crawler->filter('.puzzle-picker-insights-filters [data-bs-target="#membersExclusiveModal"]'));
        self::assertCount(6, $crawler->filter('#puzzlePickerFilters input[name="difficulty[]"]'));
        self::assertCount(3, $crawler->filter('#puzzlePickerFilters input[name="gap"]'));
        self::assertCount(3, $crawler->filter('#puzzlePickerFilters input[name="order"]'));
        self::assertCount(1, $crawler->filter('#puzzlePickerFilters input[name="predicted_max"]'));
        self::assertStringContainsString('Based on your own predicted time', $crawler->filter('.puzzle-picker-budget-engine')->text());
        self::assertStringContainsString('only puzzles with enough data', strtolower($content));

        // "Beat my record" preset is a real link
        $preset = $crawler->filter('a.puzzle-picker-preset');
        self::assertCount(1, $preset);
        self::assertStringContainsString('solved=before', (string) $preset->attr('href'));
        self::assertStringContainsString('gap=slower', (string) $preset->attr('href'));
        self::assertStringContainsString('order=gap_slower', (string) $preset->attr('href'));
    }

    public function testMemberInsightsFiltersAreAppliedAndShownAsChips(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->recalculateIntelligence($browser);

        // PLAYER_WITH_STRIPE has one attempt on each scored puzzle and beat every prediction -> "faster"
        $crawler = $browser->request('GET', '/en/what-to-solve-next?source=any&solved=before&gap=faster&gap_min=2&order=gap_faster&difficulty[]=3&predicted_max=90&seed=abcd1234');

        $this->assertResponseIsSuccessful();

        $labels = self::chipLabels($crawler);

        self::assertContains('Solved before', $labels);
        self::assertContains('Up to 90 min', $labels);
        self::assertContains('Difficulty: Average', $labels);
        self::assertContains('Faster than predicted by 2+ min', $labels);
        self::assertContains('Order: largest gap (I\'m faster)', $labels);

        // Every card that comes back is a scored puzzle she solved and is faster on than predicted
        $cardCount = $crawler->filter('.puzzle-picker-card')->count();
        self::assertGreaterThan(0, $cardCount);
        self::assertCount($cardCount, $crawler->filter('.puzzle-picker-card .puzzle-picker-difficulty'));
        self::assertCount($cardCount, $crawler->filter('.puzzle-picker-card .puzzle-picker-prediction'));
        self::assertCount($cardCount, $crawler->filter('.puzzle-picker-card .puzzle-picker-prediction-vs-best:contains("slower than your best")'), 'Faster than predicted = the prediction is slower than my best');
        self::assertCount(1, $crawler->filter('#puzzlePickerFilters input[name="gap"][value="faster"][checked]'));
        self::assertCount(1, $crawler->filter('#puzzlePickerFilters input[name="order"][value="gap_faster"][checked]'));
        self::assertCount(1, $crawler->filter('#puzzlePickerFilters input[name="difficulty[]"][value="3"][checked]'));
        self::assertSame('2', $crawler->filter('#puzzlePickerFilters input[name="gap_min"]')->attr('value'));
        self::assertSame('90', $crawler->filter('#puzzlePickerFilters input[name="predicted_max"]')->attr('value'));

        // The preset chip is highlighted when its filters are active
        $crawler = $browser->request('GET', '/en/what-to-solve-next?solved=before&gap=slower&order=gap_slower');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('bg-primary', (string) $crawler->filter('a.puzzle-picker-preset')->attr('class'));
    }

    public function testNonMemberGetsOneLockedPredictionRowPerCardAndALockedInsightsGroup(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $this->recalculateIntelligence($browser);

        // Insights params in the URL are ignored server-side: still the whole 7-puzzle shelf
        $crawler = $browser->request('GET', '/en/what-to-solve-next?gap=slower&order=gap_slower&difficulty[]=3&seed=abcd1234');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        self::assertCount(6, $crawler->filter('.puzzle-picker-card'));
        self::assertStringContainsString('of 7 matching puzzles', $content);
        self::assertCount(1, $crawler->filter('.puzzle-picker-filter-bar a[title="Remove this filter"]'), 'Only the default shelf chip - no insights chips');

        // Exactly one lock per card, no difficulty, no prediction numbers
        self::assertCount(6, $crawler->filter('.puzzle-picker-card .puzzle-picker-prediction-locked [data-bs-target="#membersExclusiveModal"]'));
        self::assertStringContainsString('Your time prediction – members only', $content);
        self::assertCount(0, $crawler->filter('.puzzle-picker-difficulty'));
        self::assertCount(0, $crawler->filter('.puzzle-picker-prediction'));
        self::assertCount(0, $crawler->filter('.puzzle-picker-prediction-unavailable'));
        self::assertCount(0, $crawler->filter('.puzzle-picker-prediction-opted-out'));
        self::assertStringNotContainsString('Your prediction:', $content);

        // Filters modal: the whole insights group is one locked block, budget on the community engine
        self::assertCount(1, $crawler->filter('#puzzlePickerFilters .puzzle-picker-insights-locked'));
        self::assertCount(1, $crawler->filter('#puzzlePickerFilters fieldset[data-picker-group="insights"][disabled]'));
        self::assertCount(2, $crawler->filter('#puzzlePickerFilters fieldset[data-picker-group="predictions"][disabled]'));
        self::assertCount(1, $crawler->filter('.puzzle-picker-insights-filters button[data-bs-target="#membersExclusiveModal"]'));
        self::assertCount(1, $crawler->filter('#puzzlePickerFilters input[name="predicted_max"]:not([disabled])'), 'The time budget stays free');
        self::assertStringContainsString('community average', $crawler->filter('.puzzle-picker-budget-engine')->text());

        // Preset chip is the lock -> members modal, not a link
        self::assertCount(0, $crawler->filter('a.puzzle-picker-preset'));
        self::assertCount(1, $crawler->filter('button.puzzle-picker-preset[data-bs-target="#membersExclusiveModal"]'));
    }

    public function testGuestSeesNeitherPredictionNorLockOnTheCards(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/what-to-solve-next?gap=slower&difficulty[]=3&order=gap_slower&predicted_max=60');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        self::assertGreaterThan(0, $crawler->filter('.puzzle-picker-card')->count());
        self::assertCount(0, $crawler->filter('.puzzle-picker-prediction, .puzzle-picker-prediction-locked, .puzzle-picker-prediction-unavailable, .puzzle-picker-prediction-opted-out, .puzzle-picker-difficulty'));
        self::assertStringNotContainsString('members only', $content);

        // Insights group disabled, but no members-modal button for somebody who is not even signed in; no presets
        self::assertCount(1, $crawler->filter('#puzzlePickerFilters fieldset[data-picker-group="insights"][disabled]'));
        self::assertCount(0, $crawler->filter('.puzzle-picker-insights-filters [data-bs-target="#membersExclusiveModal"]'));
        self::assertCount(0, $crawler->filter('.puzzle-picker-preset'));

        // The community budget is the only insights-adjacent filter a guest keeps
        $chips = $crawler->filter('.puzzle-picker-filter-bar a[title="Remove this filter"]');
        self::assertCount(1, $chips);
        self::assertStringContainsString('Up to 60 min', $chips->text());
    }

    public function testMemberWhoOptedOutOfPredictionsKeepsDifficultyButGetsNoPrediction(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->recalculateIntelligence($browser);

        // No fixture player has opted out - flip the flag inside the test transaction
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET time_predictions_opted_out = true WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        $crawler = $browser->request('GET', '/en/what-to-solve-next?pieces[]=500&gap=slower&order=gap_slower&difficulty[]=3&seed=abcd1234');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        // Difficulty filter applied (2 scored 500-piece puzzles on her shelf), gap/order stripped
        self::assertCount(2, $crawler->filter('.puzzle-picker-card'));
        self::assertCount(2, $crawler->filter('.puzzle-picker-card .puzzle-picker-difficulty'));
        self::assertEqualsCanonicalizing(['My collection', '500 pieces', 'Difficulty: Average'], self::chipLabels($crawler));

        // Opt-out notice instead of the prediction, on every card
        self::assertCount(2, $crawler->filter('.puzzle-picker-card .puzzle-picker-prediction-opted-out'));
        self::assertCount(0, $crawler->filter('.puzzle-picker-prediction'));
        self::assertCount(0, $crawler->filter('.puzzle-picker-prediction-locked'));
        self::assertStringContainsString('You have opted out of time predictions', $content);
        self::assertGreaterThan(0, $crawler->filter('.puzzle-picker-prediction-opted-out a[href*="edit-profile"], .puzzle-picker-prediction-opted-out a[href*="/en/"]')->count(), 'Link to the settings');

        // Filters modal: difficulty on, prediction controls off with the notice, community budget engine
        self::assertCount(0, $crawler->filter('#puzzlePickerFilters fieldset[data-picker-group="insights"][disabled]'));
        self::assertCount(2, $crawler->filter('#puzzlePickerFilters fieldset[data-picker-group="predictions"][disabled]'));
        self::assertCount(1, $crawler->filter('#puzzlePickerFilters .puzzle-picker-insights-opted-out'));
        self::assertStringContainsString('community average', $crawler->filter('.puzzle-picker-budget-engine')->text());

        // Preset chip is muted, neither a link nor the members lock
        self::assertCount(0, $crawler->filter('a.puzzle-picker-preset'));
        self::assertCount(0, $crawler->filter('button.puzzle-picker-preset'));
        self::assertCount(1, $crawler->filter('span.puzzle-picker-preset'));
    }

    /**
     * Visible labels of the active-filter chips (without the visually-hidden "remove" suffix).
     *
     * @return list<string>
     */
    private static function chipLabels(Crawler $crawler): array
    {
        return $crawler->filter('.puzzle-picker-filter-bar a[title="Remove this filter"]')->each(
            static fn (Crawler $chip): string => trim(preg_replace('/\s*–\s*Remove this filter\s*$/u', '', $chip->text()) ?? ''),
        );
    }

    /**
     * Baselines / difficulties / ratios are computed by the 15-minute cron in production;
     * tests build them on demand (rolled back with the test transaction).
     */
    private function recalculateIntelligence(KernelBrowser $browser): void
    {
        $browser->getContainer()->get(PuzzleIntelligenceRecalculator::class)->recalculate();
    }
}
