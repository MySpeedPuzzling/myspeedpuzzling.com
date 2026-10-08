<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Publish / Unpublish of events, series and organizations (docs/features/organizations/README.md "Drafts",
 * implementation-plan.md 1.12): signed in, the kind's edit voter, the per-id session CSRF token, always a redirect -
 * to `return`, else the item's page - with a flash; a refused Unpublish comes back as a flash naming the reasons.
 */
final class DraftActionsControllerTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, string}> path, CSRF token id
     */
    public static function routes(): iterable
    {
        yield 'publish event' => ['/en/publish-event/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT, 'publish_competition_' . OrganizationFixture::COMPETITION_DRAFT_NIGHT];
        yield 'unpublish event' => ['/en/unpublish-event/' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN, 'unpublish_competition_' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN];
        yield 'publish series' => ['/en/publish-series/' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT, 'publish_competition_series_' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT];
        yield 'unpublish series' => ['/en/unpublish-series/' . OrganizationFixture::SERIES_RIVERBEND_VIRTUAL, 'unpublish_competition_series_' . OrganizationFixture::SERIES_RIVERBEND_VIRTUAL];
        yield 'publish organization' => ['/en/publish-organization/' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT, 'publish_organization_' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT];
        yield 'unpublish organization' => ['/en/unpublish-organization/' . OrganizationFixture::ORGANIZATION_RIVERBEND, 'unpublish_organization_' . OrganizationFixture::ORGANIZATION_RIVERBEND];
    }

    #[DataProvider('routes')]
    public function testAGuestIsSentToSignIn(string $path, string $tokenId): void
    {
        $browser = self::createClient();

        $browser->request('POST', $path, ['_token' => 'whatever']);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    #[DataProvider('routes')]
    public function testAPlayerWhoCannotEditItIsRefused(string $path, string $tokenId): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $token = $this->plantToken($browser, $tokenId);

        $browser->request('POST', $path, ['_token' => $token]);

        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('routes')]
    public function testAMissingOrWrongTokenIsRefused(string $path, string $tokenId): void
    {
        $browser = self::createClient();
        // An admin may edit every one of them - only the token is wrong
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $this->plantToken($browser, $tokenId);

        $browser->request('POST', $path);
        self::assertResponseStatusCodeSame(403);

        $browser->request('POST', $path, ['_token' => 'not-the-token']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testPublishingAnApprovedDraftEventGoesToItsPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $token = $this->plantToken($browser, 'publish_competition_' . OrganizationFixture::COMPETITION_DRAFT_NIGHT);

        $browser->request('POST', '/en/publish-event/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT, ['_token' => $token]);

        self::assertResponseRedirects('/en/events/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT_SLUG);
        self::assertSame([$this->trans('drafts_core.flash.published')], $this->flashes($browser, 'success'));
        self::assertFalse($this->isDraft('competition', OrganizationFixture::COMPETITION_DRAFT_NIGHT));
    }

    public function testPublishingAPendingEventSaysItWaitsForApprovalAndReturns(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $token = $this->plantToken($browser, 'publish_competition_' . OrganizationFixture::COMPETITION_WILLOW_PENDING_DRAFT);

        $browser->request('POST', '/en/publish-event/' . OrganizationFixture::COMPETITION_WILLOW_PENDING_DRAFT, [
            '_token' => $token,
            'return' => '/en/you-organize',
        ]);

        self::assertResponseRedirects('/en/you-organize');
        self::assertSame([$this->trans('drafts_core.flash.published_waiting')], $this->flashes($browser, 'success'));
        self::assertFalse($this->isDraft('competition', OrganizationFixture::COMPETITION_WILLOW_PENDING_DRAFT));
    }

    public function testPublishingADraftEditionGoesToTheEditionPage(): void
    {
        $browser = self::createClient();
        // The maintainer of the organization the series is under
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $token = $this->plantToken($browser, 'publish_competition_' . OrganizationFixture::EDITION_LANTERN_DRAFT);

        $browser->request('POST', '/en/publish-event/' . OrganizationFixture::EDITION_LANTERN_DRAFT, [
            '_token' => $token,
            // Off-site: ignored, the item's page instead
            'return' => '//evil.example/',
        ]);

        self::assertResponseRedirects('/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG . '/' . OrganizationFixture::EDITION_LANTERN_DRAFT_SLUG);
        self::assertSame([$this->trans('drafts_core.flash.published')], $this->flashes($browser, 'success'));
        self::assertFalse($this->isDraft('competition', OrganizationFixture::EDITION_LANTERN_DRAFT));
    }

    public function testUnpublishingAnEventNobodyJoinedMakesItADraft(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $token = $this->plantToken($browser, 'unpublish_competition_' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN);

        $browser->request('POST', '/en/unpublish-event/' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN, ['_token' => $token]);

        self::assertResponseRedirects('/en/events/' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN_SLUG);
        self::assertSame([$this->trans('drafts_core.flash.unpublished')], $this->flashes($browser, 'success'));
        self::assertTrue($this->isDraft('competition', OrganizationFixture::COMPETITION_RIVERBEND_OPEN));
    }

    public function testUnpublishingAnEventSomebodyJoinedIsRefusedWithTheReason(): void
    {
        $browser = self::createClient();
        // Its creator - two people going, one on the waitlist
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $token = $this->plantToken($browser, 'unpublish_competition_' . EventsPageFixture::COMPETITION_RIVERSIDE_OPEN);

        $browser->request('POST', '/en/unpublish-event/' . EventsPageFixture::COMPETITION_RIVERSIDE_OPEN, [
            '_token' => $token,
            'return' => '/en/you-organize',
        ]);

        self::assertResponseRedirects('/en/you-organize');
        self::assertSame([], $this->flashes($browser, 'success'));
        $warnings = $this->flashes($browser, 'warning');
        self::assertCount(1, $warnings);
        self::assertSame(
            $this->trans('drafts_core.flash.cannot_unpublish', ['%reasons%' => $this->trans('drafts_core.blocker.participants')]),
            $warnings[0],
        );
        self::assertFalse($this->isDraft('competition', EventsPageFixture::COMPETITION_RIVERSIDE_OPEN));
    }

    public function testPublishingADraftSeriesGoesToTheSeriesPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $token = $this->plantToken($browser, 'publish_competition_series_' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT);

        $browser->request('POST', '/en/publish-series/' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT, ['_token' => $token]);

        self::assertResponseRedirects('/en/series/' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT_SLUG);
        self::assertSame([$this->trans('drafts_core.flash.published')], $this->flashes($browser, 'success'));
        self::assertFalse($this->isDraft('competition_series', OrganizationFixture::SERIES_QUIET_PINES_DRAFT));
    }

    public function testUnpublishingASeriesWithoutBlockersMakesItADraft(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $token = $this->plantToken($browser, 'unpublish_competition_series_' . OrganizationFixture::SERIES_RIVERBEND_VIRTUAL);

        $browser->request('POST', '/en/unpublish-series/' . OrganizationFixture::SERIES_RIVERBEND_VIRTUAL, [
            '_token' => $token,
            'return' => '/en/you-organize',
        ]);

        self::assertResponseRedirects('/en/you-organize');
        self::assertSame([$this->trans('drafts_core.flash.unpublished')], $this->flashes($browser, 'success'));
        self::assertTrue($this->isDraft('competition_series', OrganizationFixture::SERIES_RIVERBEND_VIRTUAL));
    }

    public function testUnpublishingASeriesWhoseEditionSomebodyJoinedIsRefused(): void
    {
        $browser = self::createClient();
        $this->joinEdition(OrganizationFixture::EDITION_LANTERN_1);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $token = $this->plantToken($browser, 'unpublish_competition_series_' . OrganizationFixture::SERIES_LANTERN_NIGHTS);

        $browser->request('POST', '/en/unpublish-series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS, ['_token' => $token]);

        self::assertResponseRedirects('/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG);
        self::assertSame(
            [$this->trans('drafts_core.flash.cannot_unpublish', ['%reasons%' => $this->trans('drafts_core.blocker.participants')])],
            $this->flashes($browser, 'warning'),
        );
        self::assertFalse($this->isDraft('competition_series', OrganizationFixture::SERIES_LANTERN_NIGHTS));
    }

    public function testPublishingAnApprovedDraftOrganizationGoesToItsPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $token = $this->plantToken($browser, 'publish_organization_' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT);

        $browser->request('POST', '/en/publish-organization/' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT, ['_token' => $token]);

        self::assertResponseRedirects('/en/organizations/' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_SLUG);
        self::assertSame([$this->trans('drafts_core.flash.published')], $this->flashes($browser, 'success'));
        self::assertFalse($this->isDraft('organization', OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT));
    }

    public function testPublishingAPendingDraftOrganizationSaysItWaitsForApproval(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $token = $this->plantToken($browser, 'publish_organization_' . OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT);

        $browser->request('POST', '/en/publish-organization/' . OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT, ['_token' => $token]);

        self::assertResponseRedirects('/en/organizations/' . OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT_SLUG);
        self::assertSame([$this->trans('drafts_core.flash.published_waiting')], $this->flashes($browser, 'success'));
        self::assertFalse($this->isDraft('organization', OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT));
    }

    public function testUnpublishingAnOrganizationIsAlwaysAllowed(): void
    {
        $browser = self::createClient();
        // Its maintainer - an organization goes back to draft even with series and events under it
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $token = $this->plantToken($browser, 'unpublish_organization_' . OrganizationFixture::ORGANIZATION_RIVERBEND);

        $browser->request('POST', '/en/unpublish-organization/' . OrganizationFixture::ORGANIZATION_RIVERBEND, ['_token' => $token]);

        self::assertResponseRedirects('/en/organizations/' . OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG);
        self::assertSame([$this->trans('drafts_core.flash.unpublished')], $this->flashes($browser, 'success'));
        self::assertTrue($this->isDraft('organization', OrganizationFixture::ORGANIZATION_RIVERBEND));
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
     * @param 'competition'|'competition_series'|'organization' $table
     */
    private function isDraft(string $table, string $id): bool
    {
        $isDraft = $this->connection()->fetchOne("SELECT is_draft FROM {$table} WHERE id = :id", ['id' => $id]);
        self::assertIsBool($isDraft);

        return $isDraft;
    }

    private function joinEdition(string $competitionId): void
    {
        $this->connection()->insert('competition_participant', [
            'id' => '018d0099-0000-0000-0000-0000000000a1',
            'name' => 'Lantern Night Guest',
            'country' => 'us',
            'competition_id' => $competitionId,
            'source' => 'imported',
        ]);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return self::getContainer()->get(TranslatorInterface::class)->trans($key, $parameters);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
