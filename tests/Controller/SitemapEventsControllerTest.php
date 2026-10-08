<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetCompetitionSlugsForSitemap;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SitemapEventsControllerTest extends WebTestCase
{
    public function testListsEveryEditionOfAnApprovedSeries(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/sitemap-events.xml');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        // Editions are never approved individually - the series approval makes them public
        self::assertStringContainsString('/en/series/euro-jigsaw-jam-series/ejj-68-february-2026</loc>', $content);
        self::assertStringContainsString('/en/series/euro-jigsaw-jam-series/ejj-69-may-2026</loc>', $content);
        self::assertStringContainsString('/en/series/puzzle-meetup-prague/puzzle-meetup-1</loc>', $content);
        self::assertStringContainsString('/en/series/berlin-puzzle-cup/berlin-puzzle-cup-2026</loc>', $content);
        self::assertStringContainsString('/de/series/euro-jigsaw-jam-series/ejj-68-february-2026</loc>', $content);
    }

    public function testLeavesOutEditionsOfAnUnapprovedSeries(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/sitemap-events.xml');

        $this->assertResponseIsSuccessful();
        self::assertStringNotContainsString('/pending-puzzle-league', (string) $browser->getResponse()->getContent());
    }

    public function testLeavesOutEditionsOfARejectedSeriesAndRejectedEditions(): void
    {
        $browser = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'UPDATE competition_series SET rejected_at = NOW() WHERE id = :id',
            ['id' => CompetitionSeriesFixture::SERIES_PAST_ONLY],
        );
        $connection->executeStatement(
            'UPDATE competition SET rejected_at = NOW() WHERE id = :id',
            ['id' => CompetitionSeriesFixture::EDITION_EJJ_69],
        );

        $browser->request('GET', '/sitemap-events.xml');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('/berlin-puzzle-cup', $content);
        self::assertStringNotContainsString('/ejj-69-may-2026</loc>', $content);
        self::assertStringContainsString('/en/series/euro-jigsaw-jam-series/ejj-68-february-2026</loc>', $content);
    }

    /**
     * Organizations (docs/features/organizations/README.md): publicly visible ones in every locale; a draft, one waiting
     * for approval or a rejected one never
     */
    public function testListsPublicOrganizationsOnly(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/sitemap-events.xml');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('/organizace/' . OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG . '</loc>', $content);
        self::assertStringContainsString('/en/organizations/' . OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG . '</loc>', $content);
        self::assertStringContainsString('/de/organisationen/' . OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG . '</loc>', $content);
        self::assertStringNotContainsString(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_SLUG, $content);
        self::assertStringNotContainsString(OrganizationFixture::ORGANIZATION_MAPLE_PENDING_SLUG, $content);
        self::assertStringNotContainsString(OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT_SLUG, $content);
    }

    /**
     * Drafts (docs/features/organizations/README.md "Drafts"): no draft one-time event, draft edition, draft series or
     * edition of one - their published neighbours stay
     */
    public function testLeavesOutDrafts(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/sitemap-events.xml');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString(OrganizationFixture::COMPETITION_DRAFT_NIGHT_SLUG, $content);
        self::assertStringNotContainsString(OrganizationFixture::EDITION_LANTERN_DRAFT_SLUG, $content);
        self::assertStringNotContainsString(OrganizationFixture::SERIES_QUIET_PINES_DRAFT_SLUG, $content);
        self::assertStringNotContainsString('old-harbor-draft-classic', $content);
        self::assertStringContainsString('/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG . '</loc>', $content);
        self::assertStringContainsString('/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG . '/lantern-night-one</loc>', $content);
        self::assertStringContainsString('/en/events/' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN_SLUG . '</loc>', $content);
        // A draft organization hides only itself
        self::assertStringContainsString('/en/series/' . OrganizationFixture::SERIES_HARBOR_CLUB_MEETS_SLUG . '</loc>', $content);
    }

    public function testListsTheArchiveYearsInEveryLocale(): void
    {
        $browser = self::createClient();
        $lastYear = (int) self::getContainer()->get(ClockInterface::class)->now()->format('Y') - 1;

        $browser->request('GET', '/sitemap-events.xml');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        foreach ([$lastYear, $lastYear - 1] as $year) {
            self::assertStringContainsString('/eventy/archiv/' . $year . '</loc>', $content);
            self::assertStringContainsString('/en/events/archive/' . $year . '</loc>', $content);
            self::assertStringContainsString('/es/eventos/archivo/' . $year . '</loc>', $content);
            self::assertStringContainsString('/ja/' . rawurlencode('イベント') . '/' . rawurlencode('アーカイブ') . '/' . $year . '</loc>', $content);
            self::assertStringContainsString('/fr/evenements/archives/' . $year . '</loc>', $content);
            self::assertStringContainsString('/de/veranstaltungen/archiv/' . $year . '</loc>', $content);
        }

        // Exactly the years the archive page answers for - no future year, no year without past events
        preg_match_all('#/en/events/archive/(\d{4})</loc>#', $content, $matches);
        $years = array_map(intval(...), $matches[1]);
        self::assertSame(self::getContainer()->get(GetCompetitionSlugsForSitemap::class)->archiveYears(), $years);
        self::assertNotContains($lastYear + 2, $years);
        self::assertNotContains(1999, $years);
    }
}
