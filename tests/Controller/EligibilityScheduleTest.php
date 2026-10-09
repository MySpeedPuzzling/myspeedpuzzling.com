<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Who can enter" and "When it happens" (docs/features/organizations/README.md): the forms save them, the byline of
 * the series, edition and event pages and the events page's rows show them; an edition without its own shows its
 * series'.
 */
final class EligibilityScheduleTest extends WebTestCase
{
    private const string LANTERN_1_SLUG = 'lantern-night-one';

    public function testTheSeriesPageShowsWhoCanEnterAndWhenItHappens(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG);
        self::assertResponseIsSuccessful();

        $byline = $crawler->filter('.ev-detail-header .ev-detail-byline');
        self::assertSame('Who can enter: 18+', $byline->filter('[data-eligibility]')->text());
        self::assertSame('When it happens: Second Thursday of the month, 7:30 pm', $byline->filter('[data-schedule]')->text());
    }

    public function testAnEditionShowsItsOwnElseItsSeries(): void
    {
        $browser = self::createClient();
        $url = '/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG . '/' . self::LANTERN_1_SLUG;

        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame('Who can enter: 18+', $crawler->filter('.ev-detail-byline [data-eligibility]')->text());
        // "When it happens" belongs to the series page
        self::assertCount(0, $crawler->filter('[data-schedule]'));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/edit-event/' . OrganizationFixture::EDITION_LANTERN_1);
        self::assertSelectorTextContains('form', "Leave empty to use the series' setting.");
        $browser->submitForm('Save Changes', ['competition_form[eligibility]' => '18+, ID at the door']);
        self::assertResponseRedirects();

        $crawler = $browser->request('GET', $url);
        self::assertSame('Who can enter: 18+, ID at the door', $crawler->filter('.ev-detail-byline [data-eligibility]')->text());
    }

    public function testTheEventPageAndTheEventsPageRows(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/events/' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN_SLUG);
        self::assertResponseIsSuccessful();
        self::assertSame('Who can enter: Residents of Riverbend Valley', $crawler->filter('.ev-detail-byline [data-eligibility]')->text());

        $crawler = $browser->request('GET', '/en/events');
        $tags = $crawler->filter('.ev-tag-eligibility')->each(static fn (Crawler $tag): string => trim($tag->text()));
        self::assertContains('Who can enter: Residents of Riverbend Valley', $tags);
        self::assertContains('Who can enter: 18+', $tags);
    }

    public function testNothingToShowNoByline(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/series/' . OrganizationFixture::SERIES_HARBOR_CLUB_MEETS_SLUG);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-eligibility], [data-schedule]'));
    }

    public function testTheSeriesFormSavesBoth(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/edit-series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS);
        self::assertSame('18+', $crawler->filter('input[name="competition_form[eligibility]"]')->attr('value'));
        self::assertSame('Second Thursday of the month, 7:30 pm', $crawler->filter('input[name="competition_form[schedule]"]')->attr('value'));

        $browser->submitForm('Save Changes', [
            'competition_form[eligibility]' => '',
            'competition_form[schedule]' => 'Second Thursday of the month, 8 pm',
        ]);
        self::assertResponseRedirects();

        /** @var array{eligibility: null|string, schedule: null|string} $row */
        $row = $this->connection()->fetchAssociative('SELECT eligibility, schedule FROM competition_series WHERE id = ?', [OrganizationFixture::SERIES_LANTERN_NIGHTS]);
        self::assertNull($row['eligibility']);
        self::assertSame('Second Thursday of the month, 8 pm', $row['schedule']);
    }

    public function testTooLongIsAFormError(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/edit-series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS);
        $browser->submitForm('Save Changes', [
            'competition_form[eligibility]' => str_repeat('a', 121),
            'competition_form[schedule]' => str_repeat('b', 161),
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAnEventAddedWithoutRecurringKeepsNoSchedule(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/add-event');
        // Typed into "When it happens", then "Recurring" left unticked: an event, no error, nothing kept
        $browser->submitForm('Submit for Approval', [
            'competition_form[name]' => 'Single Puzzle Night',
            'competition_form[isOnline]' => '0',
            'competition_form[location]' => 'Brno',
            'competition_form[dateFrom]' => '15.06.2027',
            'competition_form[dateTo]' => '15.06.2027',
            'competition_form[schedule]' => str_repeat('b', 300),
            'competition_form[eligibility]' => '18+',
        ]);

        self::assertResponseRedirects();
        self::assertSame('18+', $this->connection()->fetchOne('SELECT eligibility FROM competition WHERE name = ?', ['Single Puzzle Night']));
        self::assertFalse($this->connection()->fetchOne('SELECT 1 FROM competition_series WHERE name = ?', ['Single Puzzle Night']));
    }

    public function testTheAddEditionFormSavesItsOwnAndADraft(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/add-edition/' . OrganizationFixture::SERIES_LANTERN_NIGHTS);
        self::assertSelectorExists('a[data-add-editions-link][href^="/en/add-editions/' . OrganizationFixture::SERIES_LANTERN_NIGHTS . '"]');

        $browser->submitForm('Save as draft', [
            'edition_form[name]' => 'Lantern Night Halloween',
            'edition_form[dateFrom]' => '30.10.2027',
            'edition_form[dateTo]' => '30.10.2027',
            'edition_form[eligibility]' => '18+, costumes welcome',
        ]);
        self::assertResponseRedirects('/en/manage-series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS);
        self::assertQueuedEmailCount(0);

        /** @var array{eligibility: null|string, is_draft: bool} $row */
        $row = $this->connection()->fetchAssociative('SELECT eligibility, is_draft FROM competition WHERE name = ?', ['Lantern Night Halloween']);
        self::assertSame('18+, costumes welcome', $row['eligibility']);
        self::assertTrue($row['is_draft']);

        $browser->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Saved as a draft');
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
