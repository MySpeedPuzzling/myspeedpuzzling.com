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
 * The admin approval queue's "Pending Organizations" (docs/features/organizations/README.md "Admin approval queue"):
 * organizations waiting for approval that are not drafts, with Approve / Reject.
 */
final class CompetitionApprovalsOrganizationsTest extends WebTestCase
{
    public function testThePendingOrganizationIsListedButNotTheDraftOne(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', '/admin/competition-approvals');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-pending-organization]')->each(static fn (Crawler $row): null|string => $row->attr('data-pending-organization'));
        self::assertSame([OrganizationFixture::ORGANIZATION_MAPLE_PENDING], $rows, 'not Cedar (a draft), not the approved ones');

        $maple = $crawler->filter('[data-pending-organization="' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING . '"]');
        self::assertStringContainsString(OrganizationFixture::ORGANIZATION_MAPLE_PENDING_NAME, $maple->text());
        self::assertStringContainsString('Community', $maple->text());
        self::assertCount(1, $maple->filter('form[action="/admin/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING . '/approve"]'));
        self::assertCount(1, $crawler->filter('form[action="/admin/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING . '/reject"] textarea[name="reason"]'));
        self::assertStringStartsWith('/en/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING_SLUG, (string) $maple->filter('a')->attr('href'));

        // The organizations come before the series
        $html = (string) $browser->getResponse()->getContent();
        self::assertLessThan(strpos($html, 'Pending Series'), strpos($html, 'Pending Organizations'));
    }

    public function testApprovingFromTheQueueTakesItOut(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', '/admin/competition-approvals');

        $browser->submit($crawler->filter('form[action="/admin/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING . '/approve"]')->form());
        self::assertResponseRedirects('/admin/competition-approvals');

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        self::assertNotNull($connection->fetchOne('SELECT approved_at FROM organization WHERE id = :id', ['id' => OrganizationFixture::ORGANIZATION_MAPLE_PENDING]));

        $crawler = $browser->request('GET', '/admin/competition-approvals');
        self::assertCount(0, $crawler->filter('[data-pending-organization]'));
    }

    public function testOnlyAdminsSeeTheQueue(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $browser->request('GET', '/admin/competition-approvals');

        self::assertResponseStatusCodeSame(403);
    }
}
