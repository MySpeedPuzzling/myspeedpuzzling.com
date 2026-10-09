<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Organized by …" in the byline of the series, edition and event pages, and the organization's crumb
 * (docs/features/organizations/README.md, P20): a link to a publicly visible organization for everyone; a draft,
 * pending or rejected one only for its team, marked "Not public".
 */
final class OrganizedByTest extends WebTestCase
{
    private const string RIVERBEND_PAGE = '/en/organizations/' . OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG;
    private const string HARBOR_MEETS = '/en/series/' . OrganizationFixture::SERIES_HARBOR_CLUB_MEETS_SLUG;
    private const string HARBOR_PAGE = '/en/organizations/' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_SLUG;

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRiverbendPages(): iterable
    {
        yield 'series' => ['/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG];
        yield 'edition' => ['/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG . '/lantern-night-one'];
        yield 'one-time event' => ['/en/events/' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN_SLUG];
    }

    #[DataProvider('provideRiverbendPages')]
    public function testThePagesOfAPublicOrganizationsItemsNameIt(string $url): void
    {
        $browser = self::createClient();
        $crawler = $this->page($browser, $url);

        $byline = $crawler->filter('.ev-detail-byline [data-organized-by="' . OrganizationFixture::ORGANIZATION_RIVERBEND . '"]');
        self::assertCount(1, $byline);
        self::assertSame('Organized by ' . OrganizationFixture::ORGANIZATION_RIVERBEND_NAME, trim($byline->text()));
        self::assertSame(self::RIVERBEND_PAGE, $byline->filter('a')->attr('href'));
        self::assertCount(0, $byline->filter('.ev-tag-not-public'));

        self::assertCount(1, $crawler->filter('.ev-crumbs a[href="' . self::RIVERBEND_PAGE . '"]'), 'the crumb');
    }

    public function testADraftOrganizationIsNotNamedToAGuestUntilPublished(): void
    {
        $browser = self::createClient();

        $crawler = $this->page($browser, self::HARBOR_MEETS);
        self::assertCount(0, $crawler->filter('[data-organized-by]'));
        self::assertCount(0, $crawler->filter('.ev-crumbs a[href="' . self::HARBOR_PAGE . '"]'));
        self::assertStringNotContainsString(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_NAME, (string) $browser->getResponse()->getContent());

        // Canary: published by SQL, it is named - its draft flag is what hid it
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('UPDATE organization SET is_draft = false WHERE id = :id', ['id' => OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT]);

        $crawler = $this->page($browser, self::HARBOR_MEETS);
        self::assertCount(1, $crawler->filter('[data-organized-by="' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT . '"]'));
        self::assertCount(1, $crawler->filter('.ev-crumbs a[href="' . self::HARBOR_PAGE . '"]'));
    }

    public function testItsTeamSeesTheDraftOrganizationMarkedNotPublic(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $this->page($browser, self::HARBOR_MEETS);

        $byline = $crawler->filter('[data-organized-by="' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT . '"]');
        self::assertCount(1, $byline);
        self::assertSame(self::HARBOR_PAGE, $byline->filter('a')->attr('href'));
        self::assertSame('Not public', $byline->filter('.ev-tag-not-public')->text());
        self::assertCount(1, $crawler->filter('.ev-crumbs a[href="' . self::HARBOR_PAGE . '"]'));
    }

    public function testSomeoneOffTheTeamDoesNotSeeIt(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->page($browser, self::HARBOR_MEETS);

        self::assertCount(0, $crawler->filter('[data-organized-by]'));
    }

    public function testItemsWithoutAnOrganizationHaveNoByline(): void
    {
        $browser = self::createClient();
        $crawler = $this->page($browser, '/en/series/harbor-jigsaw-nights');

        self::assertCount(0, $crawler->filter('[data-organized-by]'));
        self::assertSame(['Events'], $crawler->filter('.ev-crumbs a')->each(static fn (Crawler $link): string => $link->text()));
    }

    private function page(KernelBrowser $browser, string $url): Crawler
    {
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
