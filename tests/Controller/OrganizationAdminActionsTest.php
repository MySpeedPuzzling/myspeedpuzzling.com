<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Approving, rejecting and deleting an organization (docs/features/organizations/README.md "Approval", P2, P4,
 * implementation-plan.md 1.12), and the draft guard of the organization page every later version keeps: a draft is 404
 * for everyone but its team, one waiting for approval is reachable with `noindex`.
 */
final class OrganizationAdminActionsTest extends WebTestCase
{
    public function testAnAdminApprovesAnOrganizationAndItsPendingSeries(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('POST', '/admin/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING . '/approve');

        self::assertResponseRedirects('/admin/competition-approvals');
        self::assertSame([$this->trans('organization.flash.approved')], $this->flashes($browser, 'success'));
        self::assertNotNull($this->column('organization', 'approved_at', OrganizationFixture::ORGANIZATION_MAPLE_PENDING));
        // P2: its series waiting for approval is approved with it
        self::assertNotNull($this->column('competition_series', 'approved_at', OrganizationFixture::SERIES_MAPLE_PENDING));
    }

    public function testApprovingReturnsToAValidReturnOnly(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('POST', '/admin/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING . '/approve', ['return' => '/en/you-organize']);
        self::assertResponseRedirects('/en/you-organize');

        // Approved already - nothing to do, still a redirect; an off-site return is ignored
        $browser->request('POST', '/admin/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING . '/approve', ['return' => 'https://evil.example/']);
        self::assertResponseRedirects('/admin/competition-approvals');
    }

    public function testOnlyAdminsApproveAndReject(): void
    {
        $browser = self::createClient();

        // A guest is sent to sign in
        $browser->request('POST', '/admin/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING . '/approve');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));

        // Its own creator is no admin
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $browser->request('POST', '/admin/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING . '/approve');
        self::assertResponseStatusCodeSame(403);

        $browser->request('POST', '/admin/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING . '/reject', ['reason' => 'A duplicate.']);
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->column('organization', 'approved_at', OrganizationFixture::ORGANIZATION_MAPLE_PENDING));
        self::assertNull($this->column('organization', 'rejected_at', OrganizationFixture::ORGANIZATION_MAPLE_PENDING));
    }

    public function testRejectingNeedsAReason(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('POST', '/admin/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING . '/reject', ['reason' => '  ']);

        self::assertResponseRedirects('/admin/competition-approvals');
        self::assertSame([$this->trans('competition.flash.rejection_reason_required')], $this->flashes($browser, 'danger'));
        self::assertNull($this->column('organization', 'rejected_at', OrganizationFixture::ORGANIZATION_MAPLE_PENDING));

        $browser->request('POST', '/admin/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING . '/reject', [
            'reason' => 'Please add a website.',
            'return' => '/en/events',
        ]);

        self::assertResponseRedirects('/en/events');
        self::assertSame([$this->trans('organization.flash.rejected')], $this->flashes($browser, 'success'));
        self::assertNotNull($this->column('organization', 'rejected_at', OrganizationFixture::ORGANIZATION_MAPLE_PENDING));
        self::assertSame('Please add a website.', $this->column('organization', 'rejection_reason', OrganizationFixture::ORGANIZATION_MAPLE_PENDING));
        self::assertNull($this->column('organization', 'approved_at', OrganizationFixture::ORGANIZATION_MAPLE_PENDING));
    }

    public function testTheCreatorDeletesAnEmptyOrganization(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $token = $this->plantToken($browser, 'delete_organization_' . OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT);

        $browser->request('POST', '/en/delete-organization/' . OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT, ['_token' => $token]);

        self::assertResponseRedirects('/en/you-organize');
        self::assertSame([$this->trans('organization.flash.deleted')], $this->flashes($browser, 'success'));
        self::assertFalse($this->exists(OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT));
    }

    public function testDeletingReturnsToAValidReturn(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $token = $this->plantToken($browser, 'delete_organization_' . OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT);

        $browser->request('POST', '/en/delete-organization/' . OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT, [
            '_token' => $token,
            'return' => '/en/events',
        ]);

        self::assertResponseRedirects('/en/events');
        self::assertFalse($this->exists(OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT));
    }

    public function testDeletingChecksTheToken(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->plantToken($browser, 'delete_organization_' . OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT);

        $browser->request('POST', '/en/delete-organization/' . OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT, ['_token' => 'not-the-token']);

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->exists(OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT));
    }

    public function testAMaintainerCannotDeleteTheOrganization(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $token = $this->plantToken($browser, 'delete_organization_' . OrganizationFixture::ORGANIZATION_RIVERBEND);

        $browser->request('POST', '/en/delete-organization/' . OrganizationFixture::ORGANIZATION_RIVERBEND, ['_token' => $token]);

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->exists(OrganizationFixture::ORGANIZATION_RIVERBEND));
    }

    public function testAnOrganizationWithSeriesOrEventsIsNotDeleted(): void
    {
        $browser = self::createClient();
        // Its creator - but two series and a one-time event are under it
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $token = $this->plantToken($browser, 'delete_organization_' . OrganizationFixture::ORGANIZATION_RIVERBEND);

        $browser->request('POST', '/en/delete-organization/' . OrganizationFixture::ORGANIZATION_RIVERBEND, [
            '_token' => $token,
            'return' => '/en/you-organize',
        ]);

        self::assertResponseRedirects('/en/you-organize');
        self::assertSame([], $this->flashes($browser, 'success'));
        self::assertSame([$this->trans('organization.flash.not_empty')], $this->flashes($browser, 'warning'));
        self::assertTrue($this->exists(OrganizationFixture::ORGANIZATION_RIVERBEND));
    }

    public function testADraftOrganizationPageIsOnlyForItsTeam(): void
    {
        $path = '/en/organizations/' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_SLUG;
        $browser = self::createClient();

        $browser->request('GET', $path);
        self::assertResponseStatusCodeSame(404);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', $path);
        self::assertResponseStatusCodeSame(404);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
        self::assertSelectorExists('h1[data-organization-id="' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT . '"]');
    }

    public function testAPublishedOrganizationPageIsPublicAndIndexable(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/organizations/' . OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', OrganizationFixture::ORGANIZATION_RIVERBEND_NAME);
        self::assertSelectorNotExists('meta[name="robots"][content="noindex, nofollow"]');
    }

    public function testAnOrganizationWaitingForApprovalIsReachableButNotIndexed(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING_SLUG);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', OrganizationFixture::ORGANIZATION_MAPLE_PENDING_NAME);
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
    }

    public function testAnUnknownOrganizationIsNotFound(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/organizations/no-such-organization');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The per-id session token, as a page would render it - put into the signed-in browser's session
     */
    private function plantToken(KernelBrowser $browser, string $tokenId): string
    {
        $session = $browser->getContainer()->get('session.factory')->createSession();
        $cookie = $browser->getCookieJar()->get($session->getName());
        self::assertNotNull($cookie, 'Sign in first - the token goes into the signed-in session.');

        $token = 'test-token-' . bin2hex(random_bytes(8));

        $session->setId($cookie->getValue());
        $session->start();
        $session->set('_csrf/' . $tokenId, $token);
        $session->save();

        return $token;
    }

    /**
     * @return list<mixed>
     */
    private function flashes(KernelBrowser $browser, string $type): array
    {
        /** @var Session $session */
        $session = $browser->getRequest()->getSession();

        return array_values($session->getFlashBag()->peek($type));
    }

    /**
     * @param 'organization'|'competition_series' $table
     */
    private function column(string $table, string $column, string $id): mixed
    {
        return $this->connection()->fetchOne("SELECT {$column} FROM {$table} WHERE id = :id", ['id' => $id]);
    }

    private function exists(string $organizationId): bool
    {
        return $this->connection()->fetchOne('SELECT 1 FROM organization WHERE id = :id', ['id' => $organizationId]) !== false;
    }

    private function trans(string $key): string
    {
        return self::getContainer()->get(TranslatorInterface::class)->trans($key);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
