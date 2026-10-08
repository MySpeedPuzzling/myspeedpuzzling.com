<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The structured data of organizations (docs/features/organizations/README.md "SEO"): the organization page's
 * `Organization` (only while publicly visible), and the `organizer` of the Event / EventSeries of its items. Every value
 * through `json_ld`.
 */
final class OrganizationJsonLdTest extends WebTestCase
{
    private const string RIVERBEND = '/en/organizations/' . OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG;

    public function testTheOrganizationPageEmitsItsOrganization(): void
    {
        $browser = self::createClient();

        $organization = self::single('Organization', self::jsonLdBlocks($browser, self::RIVERBEND));

        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND_NAME, $organization['name'] ?? null);
        self::assertSame('RJA', $organization['alternateName'] ?? null);
        self::assertSame('http://localhost' . self::RIVERBEND, $organization['url'] ?? null);
        self::assertIsString($organization['description'] ?? null);
        self::assertStringStartsWith('The puzzle association of Riverbend Valley.', $organization['description']);
        self::assertSame(
            ['https://riverbend-jigsaw.example', 'https://www.instagram.com/riverbendjigsaw', 'https://discord.gg/riverbendjigsaw'],
            $organization['sameAs'] ?? null,
        );
        self::assertSame(['@type' => 'PostalAddress', 'addressRegion' => 'Riverbend Valley', 'addressCountry' => 'US'], $organization['address'] ?? null);
    }

    public function testANameClosingTheScriptElementStaysInsideItsString(): void
    {
        $browser = self::createClient();
        $name = 'Riverbend </script><script>alert(1)</script> Association';
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('UPDATE organization SET name = :name WHERE id = :id', ['name' => $name, 'id' => OrganizationFixture::ORGANIZATION_RIVERBEND]);

        $organization = self::single('Organization', self::jsonLdBlocks($browser, self::RIVERBEND));

        self::assertSame($name, $organization['name'] ?? null);
        self::assertStringNotContainsString('<script>alert(1)', (string) $browser->getResponse()->getContent());
    }

    public function testAPageThatIsNotPublicHasNoOrganization(): void
    {
        $browser = self::createClient();
        self::assertSame([], self::ofType('Organization', self::jsonLdBlocks($browser, '/en/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING_SLUG)), 'waiting for approval');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertSame([], self::ofType('Organization', self::jsonLdBlocks($browser, '/en/organizations/' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_SLUG)), 'a draft, even for its team');
    }

    public function testItsItemsNameItAsTheirOrganizer(): void
    {
        $browser = self::createClient();
        $expected = [
            '@type' => 'Organization',
            'name' => OrganizationFixture::ORGANIZATION_RIVERBEND_NAME,
            'url' => 'http://localhost' . self::RIVERBEND,
        ];

        $event = self::single('Event', self::jsonLdBlocks($browser, '/en/events/' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN_SLUG));
        self::assertSame($expected, $event['organizer'] ?? null, 'a one-time event');

        $edition = self::single('Event', self::jsonLdBlocks($browser, '/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG . '/lantern-night-one'));
        self::assertSame($expected, $edition['organizer'] ?? null, 'an edition - its series\' organization');

        $series = self::single('EventSeries', self::jsonLdBlocks($browser, '/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG));
        self::assertSame($expected, $series['organizer'] ?? null, 'a series');
    }

    public function testADraftOrganizationIsNeverTheOrganizer(): void
    {
        $browser = self::createClient();

        $series = self::single('EventSeries', self::jsonLdBlocks($browser, '/en/series/' . OrganizationFixture::SERIES_HARBOR_CLUB_MEETS_SLUG));
        self::assertNotSame('Organization', is_array($series['organizer'] ?? null) ? ($series['organizer']['@type'] ?? null) : null);

        $edition = self::single('Event', self::jsonLdBlocks($browser, '/en/series/' . OrganizationFixture::SERIES_HARBOR_CLUB_MEETS_SLUG . '/harbor-club-meet-1'));
        self::assertSame(['@type' => 'Organization', 'name' => OrganizationFixture::SERIES_HARBOR_CLUB_MEETS_NAME], $edition['organizer'] ?? null, 'as before: the series');

        // An event without an organization keeps having no organizer
        $event = self::single('Event', self::jsonLdBlocks($browser, '/en/events/hilltop-puzzle-weekend'));
        self::assertArrayNotHasKey('organizer', $event);
    }

    /**
     * Every JSON-LD block of the page, decoded (a block that does not parse fails the test)
     *
     * @return list<array<mixed>>
     */
    private static function jsonLdBlocks(KernelBrowser $browser, string $url): array
    {
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', (string) $browser->getResponse()->getContent(), $matches);

        $blocks = [];

        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $blocks[] = $decoded;
        }

        return $blocks;
    }

    /**
     * @param list<array<mixed>> $blocks
     *
     * @return list<array<mixed>>
     */
    private static function ofType(string $type, array $blocks): array
    {
        return array_values(array_filter($blocks, static fn (array $block): bool => ($block['@type'] ?? null) === $type));
    }

    /**
     * @param list<array<mixed>> $blocks
     *
     * @return array<mixed>
     */
    private static function single(string $type, array $blocks): array
    {
        $found = self::ofType($type, $blocks);

        self::assertCount(1, $found, sprintf('The page emits one %s JSON-LD', $type));

        return $found[0];
    }
}
