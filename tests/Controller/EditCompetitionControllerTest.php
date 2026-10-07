<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EditCompetitionControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/edit-event/' . CompetitionFixture::COMPETITION_UNAPPROVED);

        $this->assertResponseRedirects();
    }

    public function testMaintainerCanAccessPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/edit-event/' . CompetitionFixture::COMPETITION_UNAPPROVED);

        $this->assertResponseIsSuccessful();
    }

    public function testNonMaintainerDenied(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/edit-event/' . CompetitionFixture::COMPETITION_WJPC_2024);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testMaintainerCanSubmitForm(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/edit-event/' . CompetitionFixture::COMPETITION_UNAPPROVED);
        $this->assertResponseIsSuccessful();

        $browser->submitForm('Save Changes', [
            'competition_form[name]' => 'Updated Puzzle Event',
            'competition_form[location]' => 'Berlin',
        ]);

        $this->assertResponseRedirects();
        $browser->followRedirect();
        $this->assertResponseIsSuccessful();
    }

    public function testMaintainerCanSubmitWithoutMaintainers(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/edit-event/' . CompetitionFixture::COMPETITION_UNAPPROVED);
        $this->assertResponseIsSuccessful();

        $browser->submitForm('Save Changes', [
            'competition_form[name]' => 'Event Without Maintainers',
            'competition_form[maintainers]' => '',
        ]);

        $this->assertResponseRedirects();
        $browser->followRedirect();
        $this->assertResponseIsSuccessful();
    }

    /**
     * Every edition of an online series is an online event - saving its form used to wipe the edition's dates.
     */
    public function testSavingAnEditionOfAnOnlineSeriesKeepsItsDates(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $before = self::competitionRow(CompetitionSeriesFixture::EDITION_EJJ_69);
        self::assertNotNull($before['date_from']);
        self::assertNotNull($before['date_to']);

        $browser->request('GET', '/en/edit-event/' . CompetitionSeriesFixture::EDITION_EJJ_69);
        $this->assertResponseIsSuccessful();

        $browser->submitForm('Save Changes', [
            'competition_form[name]' => 'EJJ #69 — May 2026 (updated)',
        ]);

        $this->assertResponseRedirects();
        $after = self::competitionRow(CompetitionSeriesFixture::EDITION_EJJ_69);
        self::assertSame('EJJ #69 — May 2026 (updated)', $after['name']);
        self::assertTrue($after['is_online']);
        self::assertSame(self::day($before['date_from']), self::day($after['date_from']));
        self::assertSame(self::day($before['date_to']), self::day($after['date_to']));
    }

    public function testNewDatesOfAnEditionOfAnOnlineSeriesAreStored(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/edit-event/' . CompetitionSeriesFixture::EDITION_EJJ_69);
        $browser->submitForm('Save Changes', [
            'competition_form[dateFrom]' => '14.11.2026',
            'competition_form[dateTo]' => '15.11.2026',
        ]);

        $this->assertResponseRedirects();
        $after = self::competitionRow(CompetitionSeriesFixture::EDITION_EJJ_69);
        self::assertSame('2026-11-14', self::day($after['date_from']));
        self::assertSame('2026-11-15', self::day($after['date_to']));
    }

    public function testSavingAStandaloneOnlineEventKeepsItsDates(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $before = self::competitionRow(CompetitionFixture::COMPETITION_RECURRING_ONLINE);
        self::assertNotNull($before['date_from']);

        $browser->request('GET', '/en/edit-event/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE);
        $this->assertResponseIsSuccessful();
        // Optional for an online event - said right under the date fields
        $this->assertSelectorTextContains('[data-competition-form-target="onlineDatesHelp"]', 'Optional for an online event');

        $browser->submitForm('Save Changes', [
            'competition_form[description]' => 'Still monthly',
        ]);

        $this->assertResponseRedirects();
        $after = self::competitionRow(CompetitionFixture::COMPETITION_RECURRING_ONLINE);
        self::assertSame('Still monthly', $after['description']);
        self::assertTrue($after['is_online']);
        self::assertNull($after['location']);
        self::assertSame(self::day($before['date_from']), self::day($after['date_from']));
        self::assertSame(self::day($before['date_to']), self::day($after['date_to']));
    }

    public function testAnOnlineEventMayHaveNoDates(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/edit-event/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE);
        $browser->submitForm('Save Changes', [
            'competition_form[dateFrom]' => '',
            'competition_form[dateTo]' => '',
        ]);

        $this->assertResponseRedirects();
        $after = self::competitionRow(CompetitionFixture::COMPETITION_RECURRING_ONLINE);
        self::assertNull($after['date_from']);
        self::assertNull($after['date_to']);
    }

    public function testTheUrlFieldShowsTheCurrentSlugBehindTheRealAddress(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/edit-event/' . CompetitionFixture::COMPETITION_WJPC_2024);
        $this->assertResponseIsSuccessful();
        self::assertSame('wjpc-2024', $crawler->filter('#competition_form_slug')->attr('value'));
        $this->assertSelectorTextSame('[data-slug-prefix]', 'localhost/en/events/');

        $crawler = $browser->request('GET', '/en/edit-event/' . CompetitionSeriesFixture::EDITION_EJJ_69);
        self::assertSame('ejj-69-may-2026', $crawler->filter('#competition_form_slug')->attr('value'));
        $this->assertSelectorTextSame('[data-slug-prefix]', 'localhost/en/series/euro-jigsaw-jam-series/');
    }

    public function testRenamingAnEventKeepsItsUrl(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/edit-event/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE);
        $browser->submitForm('Save Changes', [
            'competition_form[name]' => 'Euro Jigsaw Jam Reloaded',
        ]);

        $this->assertResponseRedirects();
        $after = self::competitionRow(CompetitionFixture::COMPETITION_RECURRING_ONLINE);
        self::assertSame('Euro Jigsaw Jam Reloaded', $after['name']);
        self::assertSame('euro-jigsaw-jam', $after['slug']);
    }

    public function testRenamingAnEditionKeepsItsUrl(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/edit-event/' . CompetitionSeriesFixture::EDITION_EJJ_69);
        $browser->submitForm('Save Changes', [
            'competition_form[name]' => 'EJJ #69 — June 2026',
        ]);

        $this->assertResponseRedirects();
        self::assertSame('ejj-69-may-2026', self::competitionRow(CompetitionSeriesFixture::EDITION_EJJ_69)['slug']);
    }

    public function testAnEventUrlCanBeChangedOnPurposeAndIsNormalised(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/edit-event/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE);
        $browser->submitForm('Save Changes', [
            'competition_form[slug]' => '  My New URL ',
        ]);

        $this->assertResponseRedirects();
        self::assertSame('my-new-url', self::competitionRow(CompetitionFixture::COMPETITION_RECURRING_ONLINE)['slug']);

        $browser->request('GET', '/en/events/my-new-url');
        $this->assertResponseIsSuccessful();
    }

    public function testAnEditionUrlCanBeChangedOnPurpose(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        // Used by an edition of another series - an edition's URL is only unique within its own series
        $browser->request('GET', '/en/edit-event/' . CompetitionSeriesFixture::EDITION_EJJ_69);
        $browser->submitForm('Save Changes', [
            'competition_form[slug]' => 'puzzle-meetup-1',
        ]);

        $this->assertResponseRedirects();
        self::assertSame('puzzle-meetup-1', self::competitionRow(CompetitionSeriesFixture::EDITION_EJJ_69)['slug']);

        $browser->request('GET', '/en/series/euro-jigsaw-jam-series/puzzle-meetup-1');
        $this->assertResponseIsSuccessful();
    }

    public function testAnEventUrlOfAnotherEventIsAFormError(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/edit-event/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE);
        $browser->submitForm('Save Changes', [
            'competition_form[name]' => 'Renamed while the URL clashes',
            'competition_form[slug]' => 'WJPC 2024',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'This URL is already taken');
        $after = self::competitionRow(CompetitionFixture::COMPETITION_RECURRING_ONLINE);
        self::assertSame('euro-jigsaw-jam', $after['slug']);
        self::assertSame('Euro Jigsaw Jam', $after['name']);
    }

    public function testAnEditionUrlOfAnotherEditionOfTheSeriesIsAFormError(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/edit-event/' . CompetitionSeriesFixture::EDITION_EJJ_69);
        $browser->submitForm('Save Changes', [
            'competition_form[slug]' => 'ejj-68-february-2026',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'This URL is already taken');
        self::assertSame('ejj-69-may-2026', self::competitionRow(CompetitionSeriesFixture::EDITION_EJJ_69)['slug']);
    }

    public function testAnEditionCannotTakeAStandaloneEventsUrl(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        // /en/events/wjpc-2024 must keep reaching the standalone WJPC 2024, not redirect to the edition
        $browser->request('GET', '/en/edit-event/' . CompetitionSeriesFixture::EDITION_EJJ_69);
        $browser->submitForm('Save Changes', [
            'competition_form[slug]' => 'wjpc-2024',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'This URL is already taken');
        self::assertSame('ejj-69-may-2026', self::competitionRow(CompetitionSeriesFixture::EDITION_EJJ_69)['slug']);
    }

    public function testAStandaloneEventsUrlReachesItEvenWhenAnEditionSharesTheSlug(): void
    {
        $browser = self::createClient();

        // Possible through older generated edition slugs
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition SET slug = 'wjpc-2024' WHERE id = :id",
            ['id' => CompetitionSeriesFixture::EDITION_EJJ_69],
        );

        $browser->request('GET', '/en/events/wjpc-2024');
        $this->assertResponseIsSuccessful();
    }

    public function testAPastedFullAddressKeepsOnlyItsLastPart(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/edit-event/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE);
        $browser->submitForm('Save Changes', [
            'competition_form[slug]' => 'https://myspeedpuzzling.com/en/events/Jigsaw-Jäm-2026/?utm_source=x',
        ]);

        $this->assertResponseRedirects();
        self::assertSame('jigsaw-jam-2026', self::competitionRow(CompetitionFixture::COMPETITION_RECURRING_ONLINE)['slug']);
    }

    public function testAnEmptyOrUnusableUrlIsAFormError(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/edit-event/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE);
        $browser->submitForm('Save Changes', [
            'competition_form[slug]' => '',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'The URL cannot be empty');

        $browser->submitForm('Save Changes', [
            'competition_form[slug]' => '!!! ???',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'Use lower-case letters, digits and hyphens');
        self::assertSame('euro-jigsaw-jam', self::competitionRow(CompetitionFixture::COMPETITION_RECURRING_ONLINE)['slug']);
    }

    public function testTheCurrentLogoIsShownAndKeptWhenNoNewOneIsUploaded(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        self::connection($browser)->executeStatement(
            'UPDATE competition SET logo = :logo WHERE id = :id',
            ['logo' => 'competitions/euro-jigsaw-jam.png', 'id' => CompetitionFixture::COMPETITION_RECURRING_ONLINE],
        );

        $crawler = $browser->request('GET', '/en/edit-event/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE);
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('img[alt="Current logo"]'));
        $this->assertSelectorTextContains('body', 'Leave empty to keep the current logo.');

        $browser->submitForm('Save Changes');

        $this->assertResponseRedirects();
        self::assertSame('competitions/euro-jigsaw-jam.png', self::competitionRow(CompetitionFixture::COMPETITION_RECURRING_ONLINE)['logo']);
    }

    public function testNoLogoNoThumbnail(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/edit-event/' . CompetitionFixture::COMPETITION_UNAPPROVED);

        self::assertCount(0, $crawler->filter('img[alt="Current logo"]'));
        self::assertStringNotContainsString('Leave empty to keep the current logo.', (string) $browser->getResponse()->getContent());
    }

    /**
     * @return array<string, mixed>
     */
    private static function competitionRow(string $competitionId): array
    {
        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT name, slug, description, location, logo, is_online, date_from, date_to FROM competition WHERE id = :id',
            ['id' => $competitionId],
        );
        self::assertIsArray($row);

        return $row;
    }

    /**
     * The day of a stored date - the form works with days, so a time of day in a fixture row does not survive a save
     */
    private static function day(mixed $value): string
    {
        self::assertIsString($value);

        return substr($value, 0, 10);
    }

    private static function connection(KernelBrowser $browser): Connection
    {
        return $browser->getContainer()->get(Connection::class);
    }
}
