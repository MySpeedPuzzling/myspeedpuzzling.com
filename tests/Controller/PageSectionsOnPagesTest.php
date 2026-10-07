<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPageSection;
use SpeedPuzzling\Web\Message\ChangePageSectionVisibility;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\PageSectionType;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Content sections are opt-in (docs/features/competitions-management/public-page.md): an event, edition or series page
 * without one runs exactly the statements it ran before they existed - the "has sections" flag rides on the
 * competition/series row the page reads anyway - and renders no section markup at all. The budgets below were measured
 * on main before page sections (2026-10-07) and must not grow.
 */
final class PageSectionsOnPagesTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string EVENT_URL = '/en/events/czech-nationals-2024';
    private const string EDITION_URL = '/en/series/puzzle-meetup-prague/puzzle-meetup-1';
    private const string SERIES_URL = '/en/series/puzzle-meetup-prague';

    #[DataProvider('provideUntouchedPages')]
    public function testAnUntouchedPageRunsWhatItRanBefore(string $url, bool $signedIn, int $statements): void
    {
        $browser = self::createClient();

        if ($signedIn) {
            TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        }

        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSame($statements, $this->queryCount($browser), $url . ': a page without sections must run the same statements as before');

        // The sections table is only ever asked whether a section exists, inside the competition/series statement
        foreach ($this->executedSql($browser) as $sql) {
            if (str_contains($sql, 'competition_page_section')) {
                self::assertStringContainsString('AS has_page_sections', $sql);
            }
        }

        self::assertCount(0, $crawler->filter('[data-page-sections], [data-page-section]'));
    }

    /**
     * @return iterable<string, array{string, bool, int}>
     */
    public static function provideUntouchedPages(): iterable
    {
        yield 'event with rounds' => ['/en/events/wjpc-2024', false, 16];
        yield 'event' => [self::EVENT_URL, false, 13];
        yield 'online event' => ['/en/events/euro-jigsaw-jam', false, 10];
        yield 'edition' => [self::EDITION_URL, false, 12];
        yield 'online edition' => ['/en/series/euro-jigsaw-jam-series/ejj-68-february-2026', false, 11];
        yield 'series' => [self::SERIES_URL, false, 4];
        yield 'event, signed in' => [self::EVENT_URL, true, 19];
        yield 'edition, signed in' => [self::EDITION_URL, true, 18];
        yield 'series, signed in' => [self::SERIES_URL, true, 8];
    }

    public function testSectionsShowRightAfterTheDescriptionForOneMoreStatement(): void
    {
        $browser = self::createClient();
        $this->add(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, null, PageSectionType::RichText, 'House rules', [
            'html' => '<h3>Arrive early</h3><ul><li>Bring a mat</li></ul><p><a href="https://example.com/rules">Full rules</a></p>',
        ]);
        $this->add(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, null, PageSectionType::Faq, 'FAQ', [
            'items' => [['question' => 'Is there parking?', 'answer' => "Yes,\nbehind the hall"]],
        ]);
        $hidden = $this->add(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, null, PageSectionType::RichText, 'Draft', ['html' => '<p>Not yet</p>']);
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ChangePageSectionVisibility($hidden, false));

        $browser->request('GET', self::EVENT_URL);
        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', self::EVENT_URL);

        self::assertResponseIsSuccessful();
        self::assertSame(13 + 1, $this->queryCount($browser));

        $sections = $crawler->filter('[data-page-sections] > [data-page-section]');
        self::assertSame(['rich_text', 'faq'], $sections->each(static fn ($section): string => (string) $section->attr('data-page-section')));
        self::assertSame(['House rules', 'FAQ'], $crawler->filter('[data-page-section] > h2')->each(static fn ($heading): string => $heading->text()));
        self::assertStringNotContainsString('Not yet', (string) $browser->getResponse()->getContent());

        $link = $crawler->filter('.page-section-rich-text a');
        self::assertSame('noopener noreferrer nofollow ugc', $link->attr('rel'));
        self::assertSame('_blank', $link->attr('target'));
        self::assertCount(1, $crawler->filter('.page-section-rich-text ul > li'));
        self::assertSame('Is there parking?', $crawler->filter('[data-page-section="faq"] summary')->text());

        // Right after the description, before the puzzles
        $html = (string) $browser->getResponse()->getContent();
        self::assertLessThan(strpos($html, 'data-page-sections'), strpos($html, 'data-event-description'));
        self::assertLessThan(strpos($html, 'Competition puzzles'), strpos($html, 'data-page-sections'));
    }

    public function testAnEditionShowsItsOwnSectionsThenTheSeriesOnes(): void
    {
        $browser = self::createClient();
        $this->add(null, CompetitionSeriesFixture::SERIES_OFFLINE, PageSectionType::RichText, 'Series rules', ['html' => '<p>Always the same rules</p>']);
        $this->add(null, CompetitionSeriesFixture::SERIES_OFFLINE, PageSectionType::Venue, 'Where', ['address' => 'Main square 1, Prague']);
        $this->add(CompetitionSeriesFixture::EDITION_OFFLINE_1, null, PageSectionType::RichText, 'This time', ['html' => '<p>New room</p>']);

        $crawler = $browser->request('GET', self::EDITION_URL);
        self::assertResponseIsSuccessful();
        self::assertSame(['This time', 'Series rules', 'Where'], $crawler->filter('[data-page-section] > h2')->each(static fn ($heading): string => $heading->text()));

        // The series page shows only its own
        $crawler = $browser->request('GET', self::SERIES_URL);
        self::assertResponseIsSuccessful();
        self::assertSame(['Series rules', 'Where'], $crawler->filter('[data-page-section] > h2')->each(static fn ($heading): string => $heading->text()));
        self::assertStringContainsString('Main square 1, Prague', $crawler->filter('[data-page-section="venue"]')->text());
    }

    public function testASeriesSectionCostsAnUntouchedEditionOneStatement(): void
    {
        $browser = self::createClient();
        $this->add(null, CompetitionSeriesFixture::SERIES_OFFLINE, PageSectionType::Links, 'Links', ['links' => [['label' => 'Group', 'url' => 'https://facebook.com/groups/x']]]);

        $browser->request('GET', self::EDITION_URL);
        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', self::EDITION_URL);

        self::assertSame(12 + 1, $this->queryCount($browser));
        self::assertSame('https://facebook.com/groups/x?utm_source=myspeedpuzzling', $crawler->filter('[data-page-section="links"] a')->attr('href'));
    }

    /**
     * @param array<string, mixed> $content
     */
    private function add(null|string $competitionId, null|string $seriesId, PageSectionType $type, string $title, array $content): string
    {
        $sectionId = Uuid::uuid7();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPageSection($sectionId, $competitionId, $seriesId, $type, $title, $content));

        return $sectionId->toString();
    }
}
