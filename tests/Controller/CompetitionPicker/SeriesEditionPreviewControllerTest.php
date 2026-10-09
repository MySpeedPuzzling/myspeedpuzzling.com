<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionPicker;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\RevealRoundPuzzleNow;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The preview line under the picker and S2, the short list shown openly (docs/features/events-page/
 * high-frequency-series.md "The preview line and S2") - scenarios of the H12 matrix on the form's side.
 */
final class SeriesEditionPreviewControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string URL = '/en/competition-picker/series-preview';

    private KernelBrowser $browser;
    private SeriesEditionScenario $scenario;
    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->scenario = new SeriesEditionScenario(self::getContainer());
        $this->today = self::getContainer()->get(ClockInterface::class)->now();
    }

    public function testAnonymousVisitorIsSentToSignIn(): void
    {
        $browser = self::getClient();
        self::assertInstanceOf(KernelBrowser::class, $browser);
        $browser->getCookieJar()->clear();

        $browser->request('GET', self::URL . '?series=' . $this->scenario->series());

        $this->assertResponseRedirects();
    }

    /**
     * H12 scenario 2: editions with dates, rounds and puzzles - matched by the puzzle, whatever the day
     */
    public function testScenario2MatchedByThePuzzle(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $puzzle = $this->scenario->puzzle('Copper Lighthouse');
        $no153 = $this->scenario->edition($seriesId, 'Jam No. 153', $this->day(-9));
        $this->scenario->round($no153, RoundCategory::Solo, $this->day(-9) . ' 19:00', puzzleIds: [$puzzle]);
        $this->scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));

        $crawler = $this->preview(['series' => $seriesId, 'puzzle' => $puzzle, 'date' => $this->formDate(-2), 'people' => '0']);

        $cacheControl = (string) $this->browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        $line = $this->text($crawler, '.sp-line-text');
        self::assertStringContainsString('Lantern Weekly Jam · Jam No. 153', $line);
        self::assertStringEndsWith('· Solo', $line);
        self::assertSame('change', trim($crawler->filter('.sp-line [data-action="series-edition-preview#toggleList"]')->text()));
        // The short list is there, folded behind "change"
        self::assertStringContainsString('d-none', (string) $crawler->filter('[data-series-edition-preview-target="list"]')->attr('class'));
        self::assertCount(2, $crawler->filter('.sp-edition'));
    }

    public function testMatchedByTheDayWhenExactlyOneEditionIsNear(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $this->scenario->edition($seriesId, 'Jam No. 153', $this->day(-9));
        $this->scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));

        $crawler = $this->preview(['series' => $seriesId, 'date' => $this->formDate(-2), 'people' => '1']);

        $line = $this->text($crawler, '.sp-line-text');
        self::assertStringContainsString('Jam No. 154', $line);
        self::assertStringEndsWith('· Pair', $line);
    }

    /**
     * H12 scenario 5: undated placeholder editions without rounds - nothing is guessed, the short list shows openly,
     * nothing preselected
     */
    public function testScenario5UndatedPlaceholdersShowTheOpenChoice(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $this->scenario->edition($seriesId, 'Spring Special', null);
        $this->scenario->edition($seriesId, 'Summer Special', null);

        $crawler = $this->preview(['series' => $seriesId, 'date' => $this->formDate(0), 'people' => '0']);

        $this->assertOpenChoice($crawler, ['Summer Special', 'Spring Special']);
    }

    /**
     * H12 scenario 7: a single undated edition is not guessed either
     */
    public function testScenario7ASingleUndatedEditionIsNotGuessed(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $this->scenario->edition($seriesId, 'Summer Special', null);

        $crawler = $this->preview(['series' => $seriesId, 'date' => $this->formDate(0), 'people' => '0']);

        $this->assertOpenChoice($crawler, ['Summer Special']);
        self::assertStringContainsString('Date not set', $this->text($crawler, '.sp-edition'));
    }

    /**
     * H12 scenario 8: two editions on the same day and category ("Flex" and "Live") - not identified, both listed
     */
    public function testScenario8TwoEditionsOnTheSameDayShowTheOpenChoice(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $flex = $this->scenario->edition($seriesId, 'Jam No. 160 Flex', $this->day(-3));
        $live = $this->scenario->edition($seriesId, 'Jam No. 160 Live', $this->day(-3));
        $this->scenario->round($flex, RoundCategory::Solo, $this->day(-3) . ' 10:00');
        $this->scenario->round($live, RoundCategory::Solo, $this->day(-3) . ' 19:00');

        $crawler = $this->preview(['series' => $seriesId, 'date' => $this->formDate(-3), 'people' => '0']);

        $names = $crawler->filter('.sp-edition-name')->each(static fn (Crawler $node): string => $node->text());
        sort($names);
        self::assertSame(['Jam No. 160 Flex', 'Jam No. 160 Live'], $names);
        self::assertCount(0, $crawler->filter('.sp-line'));
        self::assertStringNotContainsString('d-none', (string) $crawler->filter('[data-series-edition-preview-target="list"]')->attr('class'));
    }

    /**
     * H12 scenario 6: a series without editions - the neutral line only
     */
    public function testScenario6ASeriesWithoutEditionsShowsTheNeutralLineOnly(): void
    {
        $seriesId = $this->scenario->series('Copper Kettle Puzzle Cup');

        $crawler = $this->preview(['series' => $seriesId, 'date' => $this->formDate(0), 'people' => '0']);

        self::assertStringContainsString('No dates are listed for this series yet', $this->text($crawler, '.sp-note'));
        self::assertCount(0, $crawler->filter('.sp-edition'));
        self::assertCount(0, $crawler->filter('.sp-search'));
        self::assertCount(0, $crawler->filter('.sp-line'));
    }

    /**
     * H12 scenario 9: a draft edition is never matched nor listed; a series that is not public shows the neutral line
     */
    public function testScenario9DraftsAreNeverMatchedNorListed(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $this->scenario->edition($seriesId, 'Jam No. 165 Draft', $this->day(-2), draft: true);

        $crawler = $this->preview(['series' => $seriesId, 'date' => $this->formDate(-2), 'people' => '0']);
        self::assertStringNotContainsString('Jam No. 165 Draft', (string) $this->browser->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('.sp-line'));

        $pending = $this->scenario->series('Willow Lane Puzzle Nights', public: false);
        $this->scenario->edition($pending, 'Night 1', $this->day(-2));

        $crawler = $this->preview(['series' => $pending, 'date' => $this->formDate(-2), 'people' => '0']);
        self::assertStringNotContainsString('Night 1', (string) $this->browser->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('.sp-edition'));
    }

    /**
     * H12 scenario 4: a round puzzle still kept secret - no puzzle match before the reveal, and the answer with that
     * puzzle is exactly the answer without a puzzle (which edition holds it never shows); its name nowhere
     */
    public function testScenario4AHiddenRoundPuzzleNeverLeaks(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $secret = $this->scenario->puzzle('Whispering Fjord Secret');
        $holder = $this->scenario->edition($seriesId, 'Jam No. 170', $this->day(-4));
        $round = $this->scenario->round($holder, RoundCategory::Solo, $this->day(-4) . ' 19:00', puzzleIds: [$secret], secret: true);
        $other = $this->scenario->edition($seriesId, 'Jam No. 171', $this->day(-3));
        $this->scenario->round($other, RoundCategory::Solo, $this->day(-3) . ' 19:00');

        // The day after the holder: both editions are within a day - not identified either way
        $withPuzzle = $this->previewHtml(['series' => $seriesId, 'puzzle' => $secret, 'date' => $this->formDate(-3), 'people' => '0']);
        $withoutPuzzle = $this->previewHtml(['series' => $seriesId, 'date' => $this->formDate(-3), 'people' => '0']);

        self::assertSame($withoutPuzzle, $withPuzzle);
        self::assertStringNotContainsString('Whispering Fjord', $withPuzzle);
        self::assertStringNotContainsString('sp-line', $withPuzzle);

        // Revealed: the puzzle decides
        $this->scenario->dispatch(new RevealRoundPuzzleNow($this->scenario->roundPuzzleId($round, $secret)));

        $crawler = $this->preview(['series' => $seriesId, 'puzzle' => $secret, 'date' => $this->formDate(-3), 'people' => '0']);
        self::assertStringContainsString('Jam No. 170', $this->text($crawler, '.sp-line-text'));
        self::assertStringContainsString('Whispering Fjord Secret', $this->text($crawler, '.sp-editions'));
    }

    /**
     * P14: the category is what the save would store - the co-puzzlers added, not the chip
     */
    public function testTheCategoryComesFromTheCoPuzzlersAdded(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $puzzle = $this->scenario->puzzle('Starry Harbor');
        $editionId = $this->scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));
        $this->scenario->round($editionId, RoundCategory::Duo, $this->day(-2) . ' 19:00', puzzleIds: [$puzzle]);

        // Solo: the pairs round holds the puzzle but takes no solo time - nothing is matched
        $crawler = $this->preview(['series' => $seriesId, 'puzzle' => $puzzle, 'date' => $this->formDate(-2), 'people' => '0']);
        self::assertCount(0, $crawler->filter('.sp-line'));

        $crawler = $this->preview(['series' => $seriesId, 'puzzle' => $puzzle, 'date' => $this->formDate(-2), 'people' => '1']);
        self::assertStringEndsWith('· Pair', $this->text($crawler, '.sp-line-text'));

        $crawler = $this->preview(['series' => $seriesId, 'puzzle' => $puzzle, 'date' => $this->formDate(-2), 'people' => '3']);
        self::assertCount(0, $crawler->filter('.sp-line'), 'A team time does not fit a pairs round');
    }

    public function testAnExplicitEditionShowsItsLineAndLetsMySpeedPuzzlingMatchIt(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $editionId = $this->scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));
        $this->scenario->edition($seriesId, 'Jam No. 153', $this->day(-9));
        $draft = $this->scenario->edition($seriesId, 'Jam No. 166 Draft', $this->day(-1), draft: true);

        $crawler = $this->preview(['edition' => $editionId, 'date' => $this->formDate(-9), 'people' => '0']);

        self::assertStringContainsString('Lantern Weekly Jam · Jam No. 154', $this->text($crawler, '.sp-line-text'));
        $button = $crawler->filter('[data-action="series-edition-preview#matchAutomatically"]');
        self::assertSame('Let MySpeedPuzzling match it', trim($button->text()));
        self::assertSame('series:' . $seriesId, $button->attr('data-series-edition-preview-value-param'));

        // Nothing about an edition that is not public
        self::assertSame('', $this->previewHtml(['edition' => $draft, 'people' => '0']));
    }

    public function testAListItemCarriesItsTomSelectOption(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $editionId = $this->scenario->edition($seriesId, 'Spring Special', null);

        $crawler = $this->preview(['series' => $seriesId, 'people' => '0']);
        $item = $crawler->filter('.sp-edition');

        /** @var array{value: string, optgroup: string, text: string} $option */
        $option = json_decode((string) $item->attr('data-series-edition-preview-option-param'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('edition:' . $editionId, $option['value']);
        self::assertSame($seriesId, $option['optgroup']);
        self::assertStringContainsString('Spring Special', $option['text']);

        /** @var array{value: string, label: string} $optgroup */
        $optgroup = json_decode((string) $item->attr('data-series-edition-preview-optgroup-param'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['value' => $seriesId, 'label' => 'Lantern Weekly Jam'], $optgroup);
    }

    public function testTheListSearchAnswersTheListOnly(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $no153 = $this->scenario->edition($seriesId, 'Jam No. 153', $this->day(-9));
        $this->scenario->round($no153, RoundCategory::Solo, $this->day(-9) . ' 19:00', puzzleIds: [$this->scenario->puzzle('Starry Harbor')]);
        $this->scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));

        $crawler = $this->preview(['series' => $seriesId, 'part' => 'list', 'q' => 'starry', 'people' => '0']);

        self::assertSame(['Jam No. 153'], $crawler->filter('.sp-edition-name')->each(static fn (Crawler $node): string => $node->text()));
        self::assertCount(0, $crawler->filter('.sp-preview-body'));

        $this->preview(['series' => $seriesId, 'part' => 'list', 'q' => 'nothing like this', 'people' => '0']);
        self::assertStringContainsString('Nothing matches', (string) $this->browser->getResponse()->getContent());
    }

    /**
     * "Search dates or puzzles…": a month is found in the page's language and in English
     */
    public function testTheListSearchFindsAMonthInThePagesLanguageAndInEnglish(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $this->scenario->edition($seriesId, 'Jam No. 30', '2025-03-04');
        $this->scenario->edition($seriesId, 'Jam No. 31', '2025-10-08');

        foreach (['Oktober', 'October', 'okt'] as $query) {
            $crawler = $this->browser->request('GET', '/de/competition-picker/series-preview?' . http_build_query(['series' => $seriesId, 'part' => 'list', 'q' => $query, 'date' => '10.10.2025', 'people' => '0']));
            self::assertResponseIsSuccessful();
            self::assertSame(['Jam No. 31'], $crawler->filter('.sp-edition-name')->each(static fn (Crawler $node): string => $node->text()), $query);
        }

        $crawler = $this->browser->request('GET', '/de/competition-picker/series-preview?' . http_build_query(['series' => $seriesId, 'part' => 'list', 'q' => 'März', 'people' => '0']));
        self::assertSame(['Jam No. 30'], $crawler->filter('.sp-edition-name')->each(static fn (Crawler $node): string => $node->text()));
    }

    public function testNothingWithoutASeriesOrEdition(): void
    {
        self::assertSame('', $this->previewHtml(['people' => '0']));
        self::assertSame('', $this->previewHtml(['series' => 'not-a-uuid']));
    }

    public function testAtMostThreeStatementsOnTopOfTheSignedInOverhead(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $puzzle = $this->scenario->puzzle('Copper Lighthouse');
        $editionId = $this->scenario->edition($seriesId, 'Jam No. 153', $this->day(-9));
        $this->scenario->round($editionId, RoundCategory::Solo, $this->day(-9) . ' 19:00', puzzleIds: [$puzzle]);

        $overhead = $this->measure(self::URL . '?people=0');
        $matched = $this->measure(self::URL . '?' . http_build_query(['series' => $seriesId, 'puzzle' => $puzzle, 'date' => $this->formDate(-9), 'people' => '0']));
        $explicit = $this->measure(self::URL . '?' . http_build_query(['edition' => $editionId, 'people' => '0']));

        self::assertLessThanOrEqual($overhead + 3, $matched);
        self::assertLessThanOrEqual($overhead + 3, $explicit);
    }

    /**
     * @param list<string> $names
     */
    private function assertOpenChoice(Crawler $crawler, array $names): void
    {
        self::assertStringContainsString("Pick the date if you know it - or just save, and we'll match it later.", $this->text($crawler, '.sp-note'));
        self::assertCount(0, $crawler->filter('.sp-line'), 'Nothing is matched');
        self::assertStringNotContainsString('d-none', (string) $crawler->filter('[data-series-edition-preview-target="list"]')->attr('class'), 'The list shows openly');
        self::assertSame($names, $crawler->filter('.sp-edition-name')->each(static fn (Crawler $node): string => $node->text()));
        // Nothing preselected: plain buttons, none marked
        self::assertCount(0, $crawler->filter('.sp-edition.active, .sp-edition[aria-pressed="true"], input:checked'));
    }

    private function day(int $offset): string
    {
        return $this->today->modify(sprintf('%+d days', $offset))->format('Y-m-d');
    }

    private function formDate(int $offset): string
    {
        return $this->today->modify(sprintf('%+d days', $offset))->format('d.m.Y');
    }

    /**
     * @param array<string, string> $parameters
     */
    private function preview(array $parameters): Crawler
    {
        $crawler = $this->browser->request('GET', self::URL . '?' . http_build_query($parameters));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function previewHtml(array $parameters): string
    {
        $this->preview($parameters);

        return (string) $this->browser->getResponse()->getContent();
    }

    private function text(Crawler $crawler, string $selector): string
    {
        $node = $crawler->filter($selector);
        self::assertGreaterThan(0, $node->count(), 'No ' . $selector);

        return trim((string) preg_replace('/\s+/u', ' ', $node->text()));
    }

    private function measure(string $url): int
    {
        $this->browser->request('GET', $url);
        $this->startCountingQueries($this->browser);
        $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->queryCount($this->browser);
    }
}
