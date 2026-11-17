<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\AnalyticsStudio;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Analytics studio" on the public pages (docs/features/competitions-management/analytics-studio.md §8, S4): a link per
 * slugged round on the event and edition pages' rounds timeline (RoundsTimelineBuilder, results or not - commentators
 * prepare before the round) and one line on the round results page - `rel="nofollow"`, built from data the pages
 * already have (DetailPagesQueryBudgetTest and OfficialRoundResultsGoldenTest pin that). No link on a page that is not
 * public, nor for a round without a slug - its studio would answer 404.
 */
final class AnalyticsStudioPublicLinksTest extends WebTestCase
{
    private const string LINK = '[data-analytics-studio-link]';
    private const string WJPC = '/en/events/wjpc-2024';
    private const string SEASON_ONE = '/en/series/moonlight-sprint-league/season-one';

    public function testTheEventPageLinksTheStudioOfEverySluggedRound(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::WJPC);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [self::WJPC . '/analytics-studio/qualification-round', self::WJPC . '/analytics-studio/final-round'],
            self::hrefs($crawler),
        );
        $link = $crawler->filter('#round-' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION . ' ' . self::LINK);
        self::assertSame('nofollow', $link->attr('rel'));
        self::assertSame('Analytics studio', trim($link->text()));

        // The link leads to the studio
        $browser->request('GET', (string) $link->attr('href'));
        self::assertResponseIsSuccessful();
    }

    public function testTheStudioIsLinkedWithoutResultsButNotForARoundWithoutASlug(): void
    {
        $browser = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        // The final has no results any more - its studio stays linked
        $connection->executeStatement(
            'UPDATE puzzle_solving_time SET competition_round_id = NULL WHERE competition_round_id = :roundId',
            ['roundId' => CompetitionRoundFixture::ROUND_WJPC_FINAL],
        );

        $crawler = $browser->request('GET', self::WJPC);
        self::assertCount(0, $crawler->filter('#round-' . CompetitionRoundFixture::ROUND_WJPC_FINAL . ' [data-round-results-link]'));
        self::assertCount(1, $crawler->filter('#round-' . CompetitionRoundFixture::ROUND_WJPC_FINAL . ' ' . self::LINK));

        // Without a slug the round has no studio page
        $connection->executeStatement('UPDATE competition_round SET slug = NULL WHERE id = :id', ['id' => CompetitionRoundFixture::ROUND_WJPC_FINAL]);

        $crawler = $browser->request('GET', self::WJPC);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#round-' . CompetitionRoundFixture::ROUND_WJPC_FINAL), 'the round is on the page');
        self::assertSame([self::WJPC . '/analytics-studio/qualification-round'], self::hrefs($crawler));
    }

    public function testTheEditionPageLinksEveryRoundAlsoBeforeItStarts(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::SEASON_ONE);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                self::SEASON_ONE . '/analytics-studio/sprint-1',
                self::SEASON_ONE . '/analytics-studio/sprint-2',
                self::SEASON_ONE . '/analytics-studio/sprint-3',
                self::SEASON_ONE . '/analytics-studio/sprint-4',
            ],
            self::hrefs($crawler),
        );
        // Sprint 4 has not started - no results link, the studio is there
        $later = $crawler->filter('#round-' . EventsPageFixture::ROUND_SPRINT_4);
        self::assertCount(0, $later->filter('[data-round-results-link]'));
        self::assertCount(1, $later->filter(self::LINK));

        // The Czech page links the Czech studio
        $crawler = $browser->request('GET', '/serie/moonlight-sprint-league/season-one');
        self::assertSame('/serie/moonlight-sprint-league/season-one/analytics-studio/sprint-1', $crawler->filter(self::LINK)->first()->attr('href'));
        self::assertSame('Analytické studio', trim($crawler->filter(self::LINK)->first()->text()));
    }

    public function testNoLinkOnAPageThatIsNotPublic(): void
    {
        $browser = self::createClient();
        $connection = self::getContainer()->get(Connection::class);

        // Waiting for approval
        $connection->executeStatement('UPDATE competition SET approved_at = NULL WHERE id = :id', ['id' => CompetitionFixture::COMPETITION_WJPC_2024]);
        $crawler = $browser->request('GET', self::WJPC);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(self::LINK));

        // A rejected edition
        $connection->executeStatement(
            'UPDATE competition SET rejected_at = ' . TestClock::SQL_NOW . ' WHERE id = :id',
            ['id' => EventsPageFixture::EDITION_SPRINT_SEASON],
        );
        $crawler = $browser->request('GET', self::SEASON_ONE);
        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('li.ev-round'));
        self::assertCount(0, $crawler->filter(self::LINK));
    }

    public function testADraftShowsItsTeamNoLink(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $page = '/en/events/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT_SLUG;

        $crawler = $browser->request('GET', $page);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#round-' . OrganizationFixture::ROUND_DRAFT_NIGHT), 'the team sees the round');
        self::assertCount(0, $crawler->filter(self::LINK));

        self::publish($browser, OrganizationFixture::COMPETITION_DRAFT_NIGHT);
        $crawler = $browser->request('GET', $page);
        self::assertSame([$page . '/analytics-studio/main-round'], self::hrefs($crawler), 'No canary: published, the round is linked');
    }

    public function testTheRoundPagesLinkTheirStudio(): void
    {
        $browser = self::createClient();

        foreach (
            [
            self::WJPC . '/results/qualification-round' => self::WJPC . '/analytics-studio/qualification-round',
            self::SEASON_ONE . '/results/sprint-1' => self::SEASON_ONE . '/analytics-studio/sprint-1',
            '/serie/moonlight-sprint-league/season-one/vysledky/sprint-1' => '/serie/moonlight-sprint-league/season-one/analytics-studio/sprint-1',
            ] as $roundPage => $studio
        ) {
            $crawler = $browser->request('GET', $roundPage);
            self::assertResponseIsSuccessful();

            $line = $crawler->filter(self::LINK);
            self::assertCount(1, $line, $roundPage);
            self::assertSame([$studio], $line->filter('a')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
            self::assertSame('nofollow', $line->filter('a')->attr('rel'));

            $browser->request('GET', $studio);
            self::assertResponseIsSuccessful($studio);
        }
    }

    /**
     * @return list<string>
     */
    private static function hrefs(Crawler $crawler): array
    {
        return $crawler->filter(self::LINK)->each(static fn (Crawler $link): string => (string) $link->attr('href'));
    }

    private static function publish(KernelBrowser $browser, string $competitionId): void
    {
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition SET is_draft = false WHERE id = :id',
            ['id' => $competitionId],
        );
    }
}
