<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The bracket (competition toolkit A9, A-round-formats.md §3.5): the public page of an event with declared advancement
 * steps - stages as columns, a box per round, the qualified of a round only once its results are published - 404 for
 * an event without steps or that is not public, the links (event page, round page, organiser's steps page), the sitemap,
 * the statement budget.
 *
 * The Results Cup: Group A (published: Anna 1st, Ben + Cara 2nd, Anna and Ben qualified), Group B (not published: Gina,
 * Hugo qualified) → Final; Pairs (not published) → Pairs Final.
 */
final class PublicBracketTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;
    use QueryCountAssertions;

    private const string CUP = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string URL = '/en/events/results-cup/bracket';

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->overrideFeatureFlagEnv('COMPETITION_TOOLKIT_FOR_ORGANISERS', false);
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
    }

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testStagesRoundsAndQualifiedOfPublishedRoundsOnly(): void
    {
        $this->declareSteps();

        $crawler = $this->browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();

        // Two brackets - solo first (its first round runs first), then pairs
        self::assertSame(['solo', 'duo'], $crawler->filter('[data-bracket-category]')->each(static fn (Crawler $heading): string => (string) $heading->attr('data-bracket-category')));
        $brackets = $crawler->filter('[data-bracket]');
        self::assertCount(2, $brackets);
        self::assertSame([['Group A', 'Group B'], ['Final']], self::stages($brackets->eq(0)));
        self::assertSame([['Pairs'], ['Pairs Final']], self::stages($brackets->eq(1)));
        self::assertSame(
            [strtolower(OfficialResultsFixture::ROUND_GROUP_A) . ':' . strtolower(OfficialResultsFixture::ROUND_FINAL), strtolower(OfficialResultsFixture::ROUND_GROUP_B) . ':' . strtolower(OfficialResultsFixture::ROUND_FINAL)],
            $brackets->eq(0)->filter('[data-bracket-line]')->each(static fn (Crawler $line): string => (string) $line->attr('data-bracket-line')),
        );

        // Group A is published: its qualified, marked, with the round page linked
        $groupA = $this->box($crawler, OfficialResultsFixture::ROUND_GROUP_A);
        self::assertSame('published', $groupA->attr('data-bracket-round-status'));
        self::assertSame('qualified', $groupA->filter('[data-bracket-entries]')->attr('data-bracket-entries'));
        self::assertSame([['1', 'Anna Fast'], ['2', 'Ben Steady']], self::entries($groupA));
        self::assertCount(2, $groupA->filter('[data-bracket-qualified]'));
        self::assertSame('/en/events/results-cup/results/group-a', $groupA->filter('[data-bracket-round-link]')->attr('href'));

        // Group B is not published: neither its qualified nor any other name, no link
        $groupB = $this->box($crawler, OfficialResultsFixture::ROUND_GROUP_B);
        self::assertSame('finished', $groupB->attr('data-bracket-round-status'));
        self::assertCount(0, $groupB->filter('[data-bracket-entry]'));
        self::assertCount(1, $groupB->filter('[data-bracket-not-published]'));
        self::assertCount(0, $groupB->filter('[data-bracket-round-link]'));
        $content = (string) $this->browser->getResponse()->getContent();
        foreach (['Gina Quick', 'Hugo Slow', 'Ivan Last', 'Puzzle Sharks', 'Edge Hunters'] as $unpublished) {
            self::assertStringNotContainsString($unpublished, $content, 'An unpublished round names ' . $unpublished);
        }

        // Who feeds the final
        self::assertStringContainsString('Top 2 of each round: Group A, Group B', $this->box($crawler, OfficialResultsFixture::ROUND_FINAL)->filter('[data-bracket-feed]')->text());
        self::assertStringContainsString('The qualified of Pairs', $this->box($crawler, OfficialResultsFixture::ROUND_PAIRS_FINAL)->filter('[data-bracket-feed]')->text());

        // Group B published: its qualified appear too
        $this->publish(OfficialResultsFixture::ROUND_GROUP_B);
        $crawler = $this->browser->request('GET', self::URL);
        self::assertSame([['1', 'Gina Quick'], ['2', 'Hugo Slow']], self::entries($this->box($crawler, OfficialResultsFixture::ROUND_GROUP_B)));
    }

    public function testARoundThatQualifiedNobodyShowsItsTopThree(): void
    {
        $this->declareSteps();
        $this->database->executeStatement('UPDATE competition_participant_round SET qualified_at = NULL WHERE round_id = :id', ['id' => OfficialResultsFixture::ROUND_GROUP_A]);

        $crawler = $this->browser->request('GET', self::URL);
        $groupA = $this->box($crawler, OfficialResultsFixture::ROUND_GROUP_A);
        self::assertSame('top', $groupA->filter('[data-bracket-entries]')->attr('data-bracket-entries'));
        // Ben and Cara share second; Dan's unfinished result is 4th
        self::assertSame([['1', 'Anna Fast'], ['2', 'Ben Steady'], ['2', 'Cara Tied']], self::entries($groupA));
        self::assertCount(0, $groupA->filter('[data-bracket-qualified]'));
    }

    public function testNoStepsNoDraftsNoUnapprovedEvents(): void
    {
        // No steps: no page, no link anywhere
        $this->browser->request('GET', self::URL);
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('GET', '/en/events/results-cup');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-event-bracket-link]');
        self::assertSelectorNotExists('[data-event-standings]');
        $this->browser->request('GET', '/en/events/results-cup/results/group-a');
        self::assertSelectorNotExists('[data-round-bracket]');

        $this->declareSteps();
        $this->browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();

        $this->database->executeStatement('UPDATE competition SET is_draft = true WHERE id = :id', ['id' => self::CUP]);
        $this->browser->request('GET', self::URL);
        self::assertResponseStatusCodeSame(404);
        // Its team neither - like the round results and standings pages, nothing of an event that is not public is shown
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->browser->request('GET', self::URL);
        self::assertResponseStatusCodeSame(404);

        $this->database->executeStatement('UPDATE competition SET is_draft = false, approved_at = NULL WHERE id = :id', ['id' => self::CUP]);
        $this->browser->request('GET', self::URL);
        self::assertResponseStatusCodeSame(404);
    }

    public function testEveryDeclaredStepBrokenLeavesThePageEmpty(): void
    {
        // A step left pointing at a round of another category (a later round change) - the organiser sees it marked
        DeclaredAdvancementStep::insert($this->database, self::CUP, [OfficialResultsFixture::ROUND_GROUP_A], [OfficialResultsFixture::ROUND_PAIRS_FINAL]);

        $crawler = $this->browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-bracket-empty]');
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testTheEventRoundAndStepsPagesLinkTheBracket(): void
    {
        DeclaredAdvancementStep::insert($this->database, self::CUP, [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B], [OfficialResultsFixture::ROUND_FINAL], qualifyCount: 2);

        $crawler = $this->browser->request('GET', '/en/events/results-cup');
        self::assertSame([self::URL], $crawler->filter('[data-event-bracket-link]')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        // No standings published - the row holds the bracket only
        self::assertSelectorNotExists('[data-event-standing-link]');

        $crawler = $this->browser->request('GET', '/en/events/results-cup/results/group-a');
        self::assertSame([self::URL], $crawler->filter('[data-round-bracket-link]')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        // Not part of a step: the pairs round
        $this->publish(OfficialResultsFixture::ROUND_PAIRS);
        $this->browser->request('GET', '/en/events/results-cup/results/pairs');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-round-bracket]');

        // The organiser's steps page: the bracket with every round's progress (published or not) and the public page
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $this->browser->request('GET', '/en/manage-event-advancement/' . self::CUP);
        self::assertResponseIsSuccessful();
        self::assertSame(self::URL, $crawler->filter('[data-public-bracket-link]')->attr('href'));
        $organiser = $crawler->filter('[data-organiser-bracket]');
        self::assertSame([['Group A', 'Group B'], ['Final']], self::stages($organiser->filter('[data-bracket]')));
        self::assertSame('Results 3 / 3 · qualified 2', trim($this->box($organiser, OfficialResultsFixture::ROUND_GROUP_B)->filter('[data-bracket-progress]')->text()));
        self::assertCount(0, $organiser->filter('[data-bracket-entry]'), 'the organiser\'s bracket counts, it does not list');
    }

    public function testTheSitemapListsBracketsWithAPublishedRound(): void
    {
        DeclaredAdvancementStep::insert($this->database, self::CUP, [OfficialResultsFixture::ROUND_PAIRS], [OfficialResultsFixture::ROUND_PAIRS_FINAL]);

        // Neither pairs round is published
        $this->browser->request('GET', '/sitemap-events.xml');
        self::assertStringNotContainsString(self::URL . '<', (string) $this->browser->getResponse()->getContent());
        $crawler = $this->browser->request('GET', self::URL);
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));

        $this->publish(OfficialResultsFixture::ROUND_PAIRS);
        $this->browser->request('GET', '/sitemap-events.xml');
        self::assertStringContainsString(self::URL . '<', (string) $this->browser->getResponse()->getContent());
        $crawler = $this->browser->request('GET', self::URL);
        self::assertCount(0, $crawler->filter('meta[name="robots"][content="noindex, follow"]'));
    }

    public function testAnEditionsBracketLivesUnderTheSeriesUrl(): void
    {
        $edition = CompetitionSeriesFixture::EDITION_EJJ_68;
        $finalId = Uuid::uuid7()->toString();
        $this->database->executeStatement(
            "INSERT INTO competition_round (id, competition_id, name, slug, starts_at) SELECT :id, competition_id, 'EJJ Final', 'ejj-final', starts_at + INTERVAL '3 hours' FROM competition_round WHERE id = :round",
            ['id' => $finalId, 'round' => CompetitionSeriesFixture::ROUND_EJJ_68],
        );
        $category = $this->database->fetchOne('SELECT category FROM competition_round WHERE id = :id', ['id' => CompetitionSeriesFixture::ROUND_EJJ_68]);
        $this->database->executeStatement('UPDATE competition_round SET category = :category WHERE id = :id', ['category' => $category, 'id' => $finalId]);
        DeclaredAdvancementStep::insert($this->database, $edition, [CompetitionSeriesFixture::ROUND_EJJ_68], [$finalId], qualifyCount: 10);

        $url = '/en/series/euro-jigsaw-jam-series/ejj-68-february-2026/bracket';
        $crawler = $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-bracket-stage]'));

        $crawler = $this->browser->request('GET', '/en/series/euro-jigsaw-jam-series/ejj-68-february-2026');
        self::assertSame([$url], $crawler->filter('[data-event-bracket-link]')->each(static fn (Crawler $link): string => (string) $link->attr('href')));

        // The event URL of an edition redirects to the series URL
        $this->browser->request('GET', '/en/events/ejj-68-february-2026/bracket');
        self::assertResponseRedirects($url, 301);
    }

    /**
     * Visibility, the event (which brings the rounds of its steps along), the steps, the rounds and one statement for
     * the entrants of the published solo rounds - whatever the number of rounds; a guest pays nothing else.
     */
    public function testTheStatementBudget(): void
    {
        $this->declareSteps();
        $guest = $this->measure(self::URL);

        // Two more published rounds - one of them pairs: one more statement (OfficialEntrantsView's pairs/teams query)
        $this->publish(OfficialResultsFixture::ROUND_GROUP_B);
        self::assertSame($guest, $this->measure(self::URL), 'another published solo round costs nothing');
        $this->publish(OfficialResultsFixture::ROUND_PAIRS);
        self::assertSame($guest + 1, $this->measure(self::URL), 'the first published pair round costs the pairs statement');

        $this->database->executeStatement('UPDATE competition_round SET results_published_at = NULL WHERE competition_id = :id', ['id' => self::CUP]);
        self::assertSame($guest - 1, $this->measure(self::URL), 'nothing published: no entrants statement');
        self::assertLessThanOrEqual(5, $guest - 1, 'visibility, event, steps, rounds (+ the session) - got ' . ($guest - 1));
    }

    private function declareSteps(): void
    {
        DeclaredAdvancementStep::insert($this->database, self::CUP, [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B], [OfficialResultsFixture::ROUND_FINAL], qualifyCount: 2);
        DeclaredAdvancementStep::insert($this->database, self::CUP, [OfficialResultsFixture::ROUND_PAIRS], [OfficialResultsFixture::ROUND_PAIRS_FINAL], position: 1);
    }

    private function publish(string $roundId): void
    {
        $this->database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => $roundId]);
    }

    private function measure(string $url): int
    {
        $this->browser->request('GET', $url);
        $this->startCountingQueries($this->browser);
        $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->queryCount($this->browser);
    }

    private function box(Crawler $crawler, string $roundId): Crawler
    {
        $box = $crawler->filter('[data-bracket-round="' . strtolower($roundId) . '"]');
        self::assertCount(1, $box, 'No box for round ' . $roundId);

        return $box;
    }

    /**
     * @return list<list<string>> the round names per stage
     */
    private static function stages(Crawler $bracket): array
    {
        return $bracket->filter('[data-bracket-stage]')->each(static fn (Crawler $stage): array => $stage->filter('.bracket-round-name')->each(static fn (Crawler $name): string => trim($name->text())));
    }

    /**
     * @return list<array{string, string}> rank, first name of each shown entry
     */
    private static function entries(Crawler $box): array
    {
        return $box->filter('[data-bracket-entry]')->each(static fn (Crawler $entry): array => [
            trim((string) $entry->attr('data-bracket-entry-rank')),
            trim($entry->filter('.lb-name')->first()->text()),
        ]);
    }
}
