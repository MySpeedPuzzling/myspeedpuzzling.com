<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Tests\UploadsCompetitionLogos;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EditCompetitionSeriesControllerTest extends WebTestCase
{
    use UploadsCompetitionLogos;

    public function testTheUrlFieldShowsTheCurrentSlugBehindTheRealAddress(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/edit-series/' . CompetitionSeriesFixture::SERIES_EJJ);

        $this->assertResponseIsSuccessful();
        self::assertSame('euro-jigsaw-jam-series', $crawler->filter('#competition_form_slug')->attr('value'));
        $this->assertSelectorTextSame('[data-slug-prefix]', 'localhost/en/series/');
    }

    public function testRenamingASeriesKeepsItsUrl(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/edit-series/' . CompetitionSeriesFixture::SERIES_EJJ);
        $browser->submitForm('Save Changes', [
            'competition_form[name]' => 'Euro Jigsaw Jam Monthly',
        ]);

        $this->assertResponseRedirects();
        $after = self::seriesRow(CompetitionSeriesFixture::SERIES_EJJ);
        self::assertSame('Euro Jigsaw Jam Monthly', $after['name']);
        self::assertSame('euro-jigsaw-jam-series', $after['slug']);
    }

    public function testRenamingAnInPersonSeriesKeepsItsUrl(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/edit-series/' . CompetitionSeriesFixture::SERIES_OFFLINE);
        $browser->submitForm('Save Changes', [
            'competition_form[name]' => 'Puzzle Meetup Prague & Brno',
        ]);

        $this->assertResponseRedirects();
        $after = self::seriesRow(CompetitionSeriesFixture::SERIES_OFFLINE);
        self::assertSame('Puzzle Meetup Prague & Brno', $after['name']);
        self::assertSame('puzzle-meetup-prague', $after['slug']);
    }

    public function testASeriesUrlCanBeChangedOnPurposeAndIsNormalised(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/edit-series/' . CompetitionSeriesFixture::SERIES_EJJ);
        $browser->submitForm('Save Changes', [
            'competition_form[slug]' => 'Euro Jigsaw Jam',
        ]);

        $this->assertResponseRedirects();
        self::assertSame('euro-jigsaw-jam', self::seriesRow(CompetitionSeriesFixture::SERIES_EJJ)['slug']);

        $browser->request('GET', '/en/series/euro-jigsaw-jam');
        $this->assertResponseIsSuccessful();
        $browser->request('GET', '/en/series/euro-jigsaw-jam/ejj-69-may-2026');
        $this->assertResponseIsSuccessful();
    }

    public function testAUrlOfAnotherSeriesIsAFormError(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/edit-series/' . CompetitionSeriesFixture::SERIES_EJJ);
        $browser->submitForm('Save Changes', [
            'competition_form[slug]' => 'puzzle-meetup-prague',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'This URL is already taken');
        self::assertSame('euro-jigsaw-jam-series', self::seriesRow(CompetitionSeriesFixture::SERIES_EJJ)['slug']);
    }

    public function testTheCurrentLogoIsShownAndKept(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_series SET logo = :logo WHERE id = :id',
            ['logo' => 'competitions/ejj.png', 'id' => CompetitionSeriesFixture::SERIES_EJJ],
        );

        $crawler = $browser->request('GET', '/en/edit-series/' . CompetitionSeriesFixture::SERIES_EJJ);
        self::assertCount(1, $crawler->filter('img[alt="Current logo"]'));
        $this->assertSelectorTextContains('body', 'Leave empty to keep the current logo.');

        $browser->submitForm('Save Changes');

        $this->assertResponseRedirects();
        self::assertSame('competitions/ejj.png', self::seriesRow(CompetitionSeriesFixture::SERIES_EJJ)['logo']);
    }

    public function testALogoChosenForASaveRefusedForItsUrlIsKeptForTheNextSave(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        // The kept logo lives in the kernel's storage - one kernel for both submits
        $browser->disableReboot();
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_series SET logo = :logo WHERE id = :id',
            ['logo' => 'competitions/ejj.png', 'id' => CompetitionSeriesFixture::SERIES_EJJ],
        );

        $crawler = $browser->request('GET', '/en/edit-series/' . CompetitionSeriesFixture::SERIES_EJJ);
        $form = $crawler->selectButton('Save Changes')->form();
        $form['competition_form[slug]'] = 'puzzle-meetup-prague';
        self::attachLogo($form);
        $crawler = $browser->submit($form);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'This URL is already taken');
        self::assertLogoKept($crawler);
        // The new logo is previewed instead of the stored one
        self::assertCount(0, $crawler->filter('img[alt="Current logo"]'));
        self::assertSame('competitions/ejj.png', self::seriesRow(CompetitionSeriesFixture::SERIES_EJJ)['logo']);

        // The next submit has no file - only the kept logo's token
        $form = $crawler->selectButton('Save Changes')->form();
        $form['competition_form[slug]'] = 'euro-jigsaw-jam-series';
        $browser->submit($form);

        $this->assertResponseRedirects();
        $logo = self::seriesRow(CompetitionSeriesFixture::SERIES_EJJ)['logo'];
        self::assertIsString($logo);
        self::assertStringStartsWith('competitions/' . CompetitionSeriesFixture::SERIES_EJJ . '-', $logo);
    }

    /**
     * @return array<string, mixed>
     */
    private static function seriesRow(string $seriesId): array
    {
        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT name, slug, logo FROM competition_series WHERE id = :id',
            ['id' => $seriesId],
        );
        self::assertIsArray($row);

        return $row;
    }
}
