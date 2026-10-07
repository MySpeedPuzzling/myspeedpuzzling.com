<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AddEditionControllerTest extends WebTestCase
{
    private const string URL = '/en/add-edition/' . CompetitionSeriesFixture::SERIES_EJJ;

    public function testFormStoresTheInfoLinkAndTheDescription(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', self::URL);
        $this->assertResponseIsSuccessful();

        $browser->submitForm('Add Edition', [
            'edition_form[name]' => 'EJJ #70 — June 2026',
            'edition_form[dateFrom]' => '15.06.2026',
            'edition_form[dateTo]' => '15.06.2026',
            'edition_form[description]' => "Theme: forests\nTwo rounds, 500 pieces each.",
            'edition_form[link]' => 'https://eurojj.com/70',
            'edition_form[registrationLink]' => 'https://eurojj.com/70/register',
        ]);

        $this->assertResponseRedirects('/en/manage-series/' . CompetitionSeriesFixture::SERIES_EJJ);

        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT link, description, registration_link, results_link FROM competition WHERE series_id = :seriesId AND name = :name',
            ['seriesId' => CompetitionSeriesFixture::SERIES_EJJ, 'name' => 'EJJ #70 — June 2026'],
        );
        self::assertIsArray($row);
        self::assertSame('https://eurojj.com/70', $row['link']);
        self::assertSame("Theme: forests\nTwo rounds, 500 pieces each.", $row['description']);
        self::assertSame('https://eurojj.com/70/register', $row['registration_link']);
        self::assertNull($row['results_link']);
    }

    public function testEmptyInfoLinkAndDescriptionAreStoredAsNothing(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', self::URL);

        $browser->submitForm('Add Edition', [
            'edition_form[name]' => 'EJJ #71 — July 2026',
            'edition_form[dateFrom]' => '15.07.2026',
            'edition_form[dateTo]' => '15.07.2026',
            'edition_form[description]' => '   ',
            'edition_form[link]' => '',
        ]);

        $this->assertResponseRedirects('/en/manage-series/' . CompetitionSeriesFixture::SERIES_EJJ);

        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT link, description FROM competition WHERE series_id = :seriesId AND name = :name',
            ['seriesId' => CompetitionSeriesFixture::SERIES_EJJ, 'name' => 'EJJ #71 — July 2026'],
        );
        self::assertIsArray($row);
        self::assertNull($row['link']);
        self::assertNull($row['description']);
    }

    public function testInfoLinkMustBeAUrl(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', self::URL);

        $browser->submitForm('Add Edition', [
            'edition_form[name]' => 'EJJ #72 — August 2026',
            'edition_form[dateFrom]' => '15.08.2026',
            'edition_form[dateTo]' => '15.08.2026',
            'edition_form[link]' => 'not a url',
        ]);

        $this->assertResponseStatusCodeSame(422);
        self::assertFalse(self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT id FROM competition WHERE name = :name',
            ['name' => 'EJJ #72 — August 2026'],
        ));
    }

    public function testInfoLinkLongerThanTheColumnIsRefused(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', self::URL);

        $browser->submitForm('Add Edition', [
            'edition_form[name]' => 'EJJ #73 — September 2026',
            'edition_form[dateFrom]' => '15.09.2026',
            'edition_form[dateTo]' => '15.09.2026',
            'edition_form[link]' => 'https://eurojj.com/' . str_repeat('a', 240),
        ]);

        $this->assertResponseStatusCodeSame(422);
    }
}
