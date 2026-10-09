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
 * The organizations directory (docs/features/organizations/README.md "Directory"): publicly visible organizations only,
 * with their counts and next date; linked from the events page's series directory, whose lines say "by …" for a series
 * under a publicly visible organization.
 */
final class OrganizationsDirectoryTest extends WebTestCase
{
    public function testItListsThePubliclyVisibleOrganizationsOnly(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/organizations');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('meta[name="robots"][content="noindex, nofollow"]');
        self::assertSelectorTextContains('h1', 'Organizations');

        $items = $crawler->filter('[data-org-directory-item]')->each(static fn (Crawler $item): null|string => $item->attr('data-org-directory-item'));
        self::assertSame([OrganizationFixture::ORGANIZATION_RIVERBEND], $items, 'not the draft, not the ones waiting for approval');

        $riverbend = $crawler->filter('[data-org-directory-item="' . OrganizationFixture::ORGANIZATION_RIVERBEND . '"]');
        self::assertSame('/en/organizations/' . OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG, $riverbend->filter('.ev-org-dir-name')->attr('href'));
        $text = $riverbend->text();
        self::assertStringContainsString('Association or federation', $text);
        self::assertStringContainsString('Riverbend Valley', $text);
        self::assertStringContainsString('2 series', $text);
        self::assertStringContainsString('1 event', $text);
        self::assertStringStartsWith('Next:', trim($riverbend->filter('[data-org-next="next"]')->text()));

        self::assertCount(0, $crawler->filter('[data-org-add]'), '"Add organization" is for signed-in players');
    }

    public function testAnEmptyDirectoryIsNotIndexedNorInTheSitemap(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/sitemap-static.xml');
        self::assertStringContainsString('/en/organizations<', (string) $browser->getResponse()->getContent());

        // Nothing publicly visible any more
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE organization SET is_draft = true');

        $browser->request('GET', '/en/organizations');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('meta[name="robots"][content="noindex, follow"]');
        self::assertSelectorExists('[data-org-directory-empty]');

        $browser->request('GET', '/sitemap-static.xml');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/en/organizations<', (string) $browser->getResponse()->getContent());
        self::assertStringContainsString('/en/events<', (string) $browser->getResponse()->getContent());
    }

    public function testASignedInPlayerCanAddOne(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/organizations');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('/en/add-organization', (string) $crawler->filter('[data-org-add]')->attr('href'));
    }

    /**
     * Canary: the draft organization is listed once published by SQL - it is its draft flag that keeps it out
     */
    public function testTheDraftOrganizationIsHiddenOnlyByItsDraftFlag(): void
    {
        $browser = self::createClient();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $selector = '[data-org-directory-item="' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT . '"]';

        $connection->executeStatement('UPDATE organization SET is_draft = false WHERE id = :id', ['id' => OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT]);
        $crawler = $browser->request('GET', '/en/organizations');
        self::assertCount(1, $crawler->filter($selector));
        self::assertStringContainsString('Club', $crawler->filter($selector)->text());

        $connection->executeStatement('UPDATE organization SET is_draft = true WHERE id = :id', ['id' => OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT]);
        $crawler = $browser->request('GET', '/en/organizations');
        self::assertCount(0, $crawler->filter($selector));
    }

    public function testTheEventsPageSeriesDirectoryLinksItAndNamesTheOrganization(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/events');

        self::assertResponseIsSuccessful();
        self::assertSame('/en/organizations', $crawler->filter('[data-ev-organizations-link]')->attr('href'));

        $by = $crawler->filter('[data-ev-series-by="' . OrganizationFixture::ORGANIZATION_RIVERBEND . '"]');
        self::assertCount(2, $by, 'both Riverbend series');
        self::assertSame('by ' . OrganizationFixture::ORGANIZATION_RIVERBEND_NAME, trim($by->first()->text()));
        self::assertSame('/en/organizations/' . OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG, $by->first()->filter('a')->attr('href'));
        // The Harbor Puzzle Club is a draft - its published series is listed without it
        self::assertCount(0, $crawler->filter('[data-ev-series-by="' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT . '"]'));
        self::assertStringNotContainsString(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_NAME, (string) $browser->getResponse()->getContent());

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('UPDATE organization SET is_draft = false WHERE id = :id', ['id' => OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT]);
        $crawler = $browser->request('GET', '/en/events');
        self::assertCount(1, $crawler->filter('[data-ev-series-by="' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT . '"]'), 'published: named');
    }

    public function testTheEventsPageSearchFindsTheItemsOfAnOrganizationByItsName(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/events');
        self::assertResponseIsSuccessful();

        $index = self::eventsIndex((string) $browser->getResponse()->getContent());
        $matching = array_values(array_filter($index, static fn (array $entry): bool => str_contains(self::text($entry['x'] ?? null), 'riverbend jigsaw association')));
        $names = array_unique(array_map(static fn (array $entry): string => self::text($entry['n'] ?? null), $matching));
        sort($names);

        self::assertSame([OrganizationFixture::SERIES_LANTERN_NIGHTS_NAME, OrganizationFixture::COMPETITION_RIVERBEND_OPEN_NAME, OrganizationFixture::SERIES_RIVERBEND_VIRTUAL_NAME], $names);
        self::assertNotSame([], array_filter($index, static fn (array $entry): bool => str_contains(self::text($entry['x'] ?? null), ' rja')), 'the short name too');
        self::assertSame([], array_filter($index, static fn (array $entry): bool => str_contains(self::text($entry['x'] ?? null), 'harbor puzzle club')), 'a draft organization is not searchable');
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function eventsIndex(string $html): array
    {
        self::assertSame(1, preg_match('/<script type="application\/json" data-events-index>(.*?)<\/script>/s', $html, $matches), 'the page ships its index');
        $index = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($index);

        /** @var list<array<string, mixed>> $index */
        return $index;
    }
}
