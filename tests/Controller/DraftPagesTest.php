<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * A draft's page exists only for its team and admins (docs/features/organizations/README.md "Drafts", P5/P7): 404
 * (DraftNotVisible) for a guest and for a signed-in player who is not on the team, `noindex` for the team - and none of
 * the redirects that would tell a draft's URL (an edition reached by its slug at /events/…, the legacy edition URL, the
 * round results of an edition) answers anybody else.
 */
final class DraftPagesTest extends WebTestCase
{
    private const string DRAFT_NIGHT = '/en/events/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT_SLUG;
    private const string LANTERN_DRAFT = '/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG . '/' . OrganizationFixture::EDITION_LANTERN_DRAFT_SLUG;
    private const string QUIET_PINES_SERIES = '/en/series/' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT_SLUG;
    private const string QUIET_PINES_EDITION = self::QUIET_PINES_SERIES . '/' . OrganizationFixture::EDITION_QUIET_PINES_1_SLUG;
    private const string HARBOR_CLUB = '/en/organizations/' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_SLUG;

    #[DataProvider('provideDraftPages')]
    public function testOnlyTheTeamAndAdminsSeeADraftsPage(string $url, string $name, string $teamMember): void
    {
        $browser = self::createClient();

        $browser->request('GET', $url);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'A guest sees a draft.');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', $url);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'A player who is not on the team sees a draft.');

        foreach ([$teamMember, PlayerFixture::PLAYER_ADMIN] as $viewer) {
            TestingLogin::asPlayer($browser, $viewer);
            $browser->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', $name);
            self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
            self::assertSelectorExists('[data-draft-banner]');
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideDraftPages(): iterable
    {
        yield 'draft one-time event - its creator' => [self::DRAFT_NIGHT, OrganizationFixture::COMPETITION_DRAFT_NIGHT_NAME, PlayerFixture::PLAYER_WITH_STRIPE];
        yield 'draft edition - its series\' creator' => [self::LANTERN_DRAFT, OrganizationFixture::EDITION_LANTERN_DRAFT_NAME, PlayerFixture::PLAYER_WITH_STRIPE];
        yield 'draft edition - a maintainer of the series\' organization' => [self::LANTERN_DRAFT, OrganizationFixture::EDITION_LANTERN_DRAFT_NAME, PlayerFixture::PLAYER_WITH_FAVORITES];
        yield 'draft series - its creator' => [self::QUIET_PINES_SERIES, OrganizationFixture::SERIES_QUIET_PINES_DRAFT_NAME, PlayerFixture::PLAYER_WITH_STRIPE];
        yield 'edition of a draft series - the series\' creator' => [self::QUIET_PINES_EDITION, OrganizationFixture::EDITION_QUIET_PINES_1_NAME, PlayerFixture::PLAYER_WITH_STRIPE];
        yield 'draft organization - its creator' => [self::HARBOR_CLUB, OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_NAME, PlayerFixture::PLAYER_WITH_STRIPE];
    }

    /**
     * An edition's slug at /en/events/… redirects to the edition's page - not for a draft, whose series URL it would tell
     */
    #[DataProvider('provideRedirectsOfDrafts')]
    public function testNoRedirectTellsADraftsUrl(string $url, string $target): void
    {
        $browser = self::createClient();

        $browser->request('GET', $url);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', $url);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // Its team is redirected as before
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', $url);
        self::assertResponseRedirects($target, Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRedirectsOfDrafts(): iterable
    {
        yield 'draft edition by its slug at /events' => ['/en/events/' . OrganizationFixture::EDITION_LANTERN_DRAFT_SLUG, self::LANTERN_DRAFT];
        yield 'edition of a draft series by its slug at /events' => ['/en/events/' . OrganizationFixture::EDITION_QUIET_PINES_1_SLUG, self::QUIET_PINES_EDITION];
        yield 'legacy URL of a draft edition' => ['/en/edition/' . OrganizationFixture::EDITION_LANTERN_DRAFT, self::LANTERN_DRAFT];
        yield 'legacy URL of an edition of a draft series' => ['/en/edition/' . OrganizationFixture::EDITION_QUIET_PINES_1, self::QUIET_PINES_EDITION];
        yield 'round results of a draft edition at /events' => ['/en/events/' . OrganizationFixture::EDITION_LANTERN_DRAFT_SLUG . '/results/main', self::LANTERN_DRAFT . '/results/main'];
    }

    /**
     * Round results of a draft: 404 for everybody - the team too, as for any event that is not public
     */
    public function testRoundResultsOfADraftAreNobodysPage(): void
    {
        $browser = self::createClient();
        $urls = [
            self::DRAFT_NIGHT . '/results/main-round',
            self::QUIET_PINES_EDITION . '/results/main-round',
        ];

        foreach ([null, PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE] as $viewer) {
            if ($viewer !== null) {
                TestingLogin::asPlayer($browser, $viewer);
            }

            foreach ($urls as $url) {
                $browser->request('GET', $url);
                self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $url);
            }
        }
    }

    /**
     * The other public doors to an event: the shared round stopwatch names it, a scanned name tag and a "leave" POST
     * redirect to its page - for a draft none of them tells its name or URL to anybody but its team
     */
    public function testNoOtherDoorTellsADraftsNameOrUrl(): void
    {
        $browser = self::createClient();
        $stopwatch = '/en/round-stopwatch/' . OrganizationFixture::ROUND_DRAFT_NIGHT;
        $nameTag = '/en/live/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT . '/p/018d0042-0000-0000-0000-0000000000ff';

        $browser->request('GET', $stopwatch);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $browser->request('GET', $nameTag);
        self::assertResponseRedirects('/en/events');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', $stopwatch);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $browser->request('GET', $nameTag);
        self::assertResponseRedirects('/en/events');
        $browser->request('POST', '/en/leave-event/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT);
        self::assertResponseRedirects('/en/events');

        // Its team projects the stopwatch as before
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', $stopwatch);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', OrganizationFixture::COMPETITION_DRAFT_NIGHT_NAME);
    }

    /**
     * A published item next to the drafts is unchanged: the Lantern series and its published editions answer everybody
     */
    public function testPublishedNeighboursAnswerEverybody(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-draft-banner]');

        $browser->request('GET', '/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG . '/lantern-night-one');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-draft-banner]');

        $browser->request('GET', '/en/events/lantern-night-one');
        self::assertResponseRedirects('/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG . '/lantern-night-one', Response::HTTP_MOVED_PERMANENTLY);

        // A draft organization hides only itself: its published series stays public
        $browser->request('GET', '/en/series/' . OrganizationFixture::SERIES_HARBOR_CLUB_MEETS_SLUG);
        self::assertResponseIsSuccessful();
    }
}
