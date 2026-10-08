<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The draft banner (docs/features/organizations/README.md "Drafts", P7): the team sees "Draft: only you and your team
 * can see this page." with Publish on a draft's page, "This series is a draft…" with Publish series on an edition of a
 * draft series - both when both apply - announced as a status; a published page has none.
 */
final class DraftBannerTest extends WebTestCase
{
    private const string DRAFT_NIGHT = '/en/events/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT_SLUG;
    private const string LANTERN_DRAFT = '/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG . '/' . OrganizationFixture::EDITION_LANTERN_DRAFT_SLUG;
    private const string QUIET_PINES_SERIES = '/en/series/' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT_SLUG;
    private const string QUIET_PINES_EDITION = self::QUIET_PINES_SERIES . '/' . OrganizationFixture::EDITION_QUIET_PINES_1_SLUG;
    private const string HARBOR_CLUB = '/en/organizations/' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_SLUG;

    #[DataProvider('provideOwnDrafts')]
    public function testADraftsPageShowsTheBannerWithPublish(string $url, string $publishAction, string $viewer): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $viewer);

        $crawler = $browser->request('GET', $url);

        self::assertResponseIsSuccessful();
        $banner = $crawler->filter('[data-draft-banner]');
        self::assertCount(1, $banner);
        self::assertSame('status', $banner->attr('role'));
        self::assertSelectorTextContains('[data-draft-banner-own]', 'Draft: only you and your team can see this page.');
        self::assertSelectorNotExists('[data-draft-banner-series]');

        $form = $crawler->filter('[data-draft-banner-own] form');
        self::assertSame($publishAction, $form->attr('action'));
        self::assertSame('post', $form->attr('method'));
        self::assertNotEmpty($form->filter('input[name="_token"]')->attr('value'));
        self::assertSame($url, $form->filter('input[name="return"]')->attr('value'));
        self::assertSame('Publish', trim($form->filter('button')->text()));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideOwnDrafts(): iterable
    {
        yield 'one-time event, its creator' => [self::DRAFT_NIGHT, '/en/publish-event/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT, PlayerFixture::PLAYER_WITH_STRIPE];
        yield 'one-time event, an admin' => [self::DRAFT_NIGHT, '/en/publish-event/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT, PlayerFixture::PLAYER_ADMIN];
        yield 'edition, a maintainer of the series\' organization' => [self::LANTERN_DRAFT, '/en/publish-event/' . OrganizationFixture::EDITION_LANTERN_DRAFT, PlayerFixture::PLAYER_WITH_FAVORITES];
        yield 'series, its creator' => [self::QUIET_PINES_SERIES, '/en/publish-series/' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT, PlayerFixture::PLAYER_WITH_STRIPE];
        yield 'organization, its creator' => [self::HARBOR_CLUB, '/en/publish-organization/' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT, PlayerFixture::PLAYER_WITH_STRIPE];
    }

    public function testAnEditionOfADraftSeriesShowsTheSeriesBanner(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::QUIET_PINES_EDITION);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-draft-banner-own]');
        self::assertSelectorTextContains('[data-draft-banner-series]', 'This series is a draft: only you and your team can see this page.');
        $form = $crawler->filter('[data-draft-banner-series] form');
        self::assertSame('/en/publish-series/' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT, $form->attr('action'));
        self::assertSame('Publish series', trim($form->filter('button')->text()));
    }

    public function testADraftEditionOfADraftSeriesShowsBothLines(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition SET is_draft = true WHERE id = :id',
            ['id' => OrganizationFixture::EDITION_QUIET_PINES_1],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::QUIET_PINES_EDITION);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-draft-banner]'));
        self::assertSame('/en/publish-event/' . OrganizationFixture::EDITION_QUIET_PINES_1, $crawler->filter('[data-draft-banner-own] form')->attr('action'));
        self::assertSame('/en/publish-series/' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT, $crawler->filter('[data-draft-banner-series] form')->attr('action'));
    }

    public function testAPublishedPageHasNoBanner(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        foreach (['/en/events/' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN_SLUG, '/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG, '/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG . '/lantern-night-one'] as $url) {
            $browser->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('[data-draft-banner]');
        }
    }

    public function testPublishFromTheBannerMakesThePagePublic(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::DRAFT_NIGHT);
        $browser->submit($crawler->filter('[data-draft-banner-own] form')->form());

        self::assertResponseRedirects(self::DRAFT_NIGHT);
        $browser->followRedirect();
        self::assertSelectorNotExists('[data-draft-banner]');
        self::assertSelectorTextContains('main', 'Published - everyone can see it now.');

        $browser->getCookieJar()->clear();
        $browser->request('GET', self::DRAFT_NIGHT);
        self::assertResponseIsSuccessful();
    }

    public function testPublishSeriesFromAnEditionsBanner(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::QUIET_PINES_EDITION);
        $browser->submit($crawler->filter('[data-draft-banner-series] form')->form());

        self::assertResponseRedirects(self::QUIET_PINES_EDITION);

        $browser->getCookieJar()->clear();
        $browser->request('GET', self::QUIET_PINES_EDITION);
        self::assertResponseIsSuccessful();
        $browser->request('GET', self::QUIET_PINES_SERIES);
        self::assertResponseIsSuccessful();
    }

    public function testAForgedPublishIsRefused(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('POST', '/en/publish-event/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT, ['_token' => 'forged', 'return' => self::DRAFT_NIGHT]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $browser->getCookieJar()->clear();
        $browser->request('GET', self::DRAFT_NIGHT);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
