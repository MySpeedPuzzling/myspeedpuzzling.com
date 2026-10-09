<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Message\AddCompetitionRoundWithPuzzles;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The three restructuring pages (docs/features/organizations/README.md "Restructuring tools", D13): who reaches them,
 * what their selects offer, every refusal as a form error (422), success as a redirect.
 */
final class RestructurePagesTest extends WebTestCase
{
    public function testMoveEditionOffersTheSeriesTheViewerManages(): void
    {
        $browser = self::createClient();
        $path = '/en/move-edition/' . OrganizationFixture::EDITION_LANTERN_1;

        $browser->request('GET', $path);
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', $path);
        self::assertResponseStatusCodeSame(403);

        // The maintainer of the organization: its series, not the edition's own, nothing else
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $browser->request('GET', $path);
        self::assertResponseIsSuccessful();
        $options = $crawler->filter('select[name="move_edition_form[seriesId]"] option')->extract(['value']);
        self::assertContains(OrganizationFixture::SERIES_RIVERBEND_VIRTUAL, $options);
        self::assertNotContains(OrganizationFixture::SERIES_LANTERN_NIGHTS, $options);
        self::assertNotContains(CompetitionSeriesFixture::SERIES_EJJ, $options);

        // A one-time event has no such page
        $browser->request('GET', '/en/move-edition/' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN);
        self::assertResponseStatusCodeSame(404);
    }

    public function testMoveEditionAsksForANewAddressWhenItsOwnIsTaken(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition SET slug = 'virtual-contest-1' WHERE id = :id",
            ['id' => OrganizationFixture::EDITION_LANTERN_1],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/move-edition/' . OrganizationFixture::EDITION_LANTERN_1);
        $form = $crawler->selectButton($this->trans('restructure.move_edition.submit'))->form();
        $form->setValues(['move_edition_form[seriesId]' => OrganizationFixture::SERIES_RIVERBEND_VIRTUAL]);
        $crawler = $browser->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($this->trans('restructure.move_edition.slug_taken'), $crawler->filter('form')->text());
        // A free address in that series is suggested, with the address it would live at
        self::assertSame('virtual-contest-1-2', $crawler->filter('input[name="move_edition_form[slug]"]')->attr('value'));
        self::assertStringEndsWith('/en/series/' . OrganizationFixture::SERIES_RIVERBEND_VIRTUAL_SLUG . '/', trim($crawler->filter('[data-slug-prefix]')->text()));

        $form = $crawler->selectButton($this->trans('restructure.move_edition.submit'))->form();
        $form->setValues(['move_edition_form[seriesId]' => OrganizationFixture::SERIES_RIVERBEND_VIRTUAL]);
        $form['move_edition_form[slug]'] = 'Lantern Night Online';
        $browser->submit($form);

        self::assertResponseRedirects('/en/series/' . OrganizationFixture::SERIES_RIVERBEND_VIRTUAL_SLUG . '/lantern-night-online');
    }

    public function testMoveEditionIntoASeriesWithoutAnAddressGoesToItsManagePage(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_series SET slug = NULL WHERE id = :id',
            ['id' => OrganizationFixture::SERIES_RIVERBEND_VIRTUAL],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/move-edition/' . OrganizationFixture::EDITION_LANTERN_1);
        $form = $crawler->selectButton($this->trans('restructure.move_edition.submit'))->form();
        $form->setValues(['move_edition_form[seriesId]' => OrganizationFixture::SERIES_RIVERBEND_VIRTUAL]);
        $browser->submit($form);

        self::assertResponseRedirects('/en/manage-series/' . OrganizationFixture::SERIES_RIVERBEND_VIRTUAL);
        self::assertSame(OrganizationFixture::SERIES_RIVERBEND_VIRTUAL, $this->seriesOf(OrganizationFixture::EDITION_LANTERN_1));
    }

    public function testMoveEditionRefusesASeriesItDoesNotOffer(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', '/en/move-edition/' . OrganizationFixture::EDITION_LANTERN_1);
        $form = $crawler->selectButton($this->trans('restructure.move_edition.submit'))->form();
        $form->disableValidation();
        $form->setValues(['move_edition_form[seriesId]' => CompetitionSeriesFixture::SERIES_EJJ]);
        $browser->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(OrganizationFixture::SERIES_LANTERN_NIGHTS, $this->seriesOf(OrganizationFixture::EDITION_LANTERN_1));
    }

    public function testMoveRoundMovesItAndShowsEveryRefusalAsAFormError(): void
    {
        $browser = self::createClient();
        // An edition whose own name says its series
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition SET name = 'Riverbend Virtual Contest 2' WHERE id = :id",
            ['id' => OrganizationFixture::EDITION_VIRTUAL_NEXT],
        );

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/move-round/' . OrganizationFixture::ROUND_DRAFT_NIGHT);
        self::assertResponseStatusCodeSame(403);

        // The target holds a solo round with the round's puzzle already
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddCompetitionRoundWithPuzzles(new AddCompetitionRound(
            roundId: Uuid::uuid7(),
            competitionId: OrganizationFixture::COMPETITION_RIVERBEND_OPEN,
            name: 'Spring Open Final',
            minutesLimit: 90,
            startsAt: new DateTimeImmutable('+60 days'),
            timezone: 'America/New_York',
            badgeBackgroundColor: null,
            badgeTextColor: null,
        ), [PuzzleFixture::PUZZLE_3000]));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/move-round/' . OrganizationFixture::ROUND_DRAFT_NIGHT);
        self::assertResponseIsSuccessful();
        $options = $crawler->filter('select[name="move_round_form[competitionId]"] option')->extract(['value']);
        self::assertContains(OrganizationFixture::COMPETITION_RIVERBEND_OPEN, $options);
        // An edition whose name says its series: not repeated; the day never breaks (non-breaking hyphens), no ids
        $labels = $crawler->filter('select[name="move_round_form[competitionId]"] option')->each(static fn (Crawler $option): string => $option->text());
        self::assertSame([], array_values(array_filter($labels, static fn (string $label): bool => preg_match('/#[0-9a-f]{4}$/', $label) === 1)));
        $virtualNext = $crawler->filter('select[name="move_round_form[competitionId]"] option[value="' . OrganizationFixture::EDITION_VIRTUAL_NEXT . '"]')->text();
        self::assertSame(1, substr_count($virtualNext, 'Riverbend Virtual Contest'));
        self::assertStringNotContainsString(' - ', str_replace("\u{2011}", '', $virtualNext));
        self::assertContains(OrganizationFixture::EDITION_LANTERN_1, $options);
        self::assertNotContains(OrganizationFixture::COMPETITION_DRAFT_NIGHT, $options);
        self::assertNotContains(CompetitionFixture::COMPETITION_WJPC_2024, $options);

        $form = $crawler->selectButton($this->trans('restructure.move_round.submit'))->form();
        $form->setValues(['move_round_form[competitionId]' => OrganizationFixture::COMPETITION_RIVERBEND_OPEN]);
        $crawler = $browser->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Spring Open Final', $crawler->filter('form')->text());

        $form = $crawler->selectButton($this->trans('restructure.move_round.submit'))->form();
        $form->setValues(['move_round_form[competitionId]' => OrganizationFixture::EDITION_LANTERN_1]);
        $browser->submit($form);
        self::assertResponseRedirects('/en/manage-event-rounds/' . OrganizationFixture::EDITION_LANTERN_1);
        self::assertSame(
            OrganizationFixture::EDITION_LANTERN_1,
            self::getContainer()->get(Connection::class)->fetchOne('SELECT competition_id FROM competition_round WHERE id = :id', ['id' => OrganizationFixture::ROUND_DRAFT_NIGHT]),
        );
    }

    public function testMoveRoundRefusesARoundWithEntries(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        // Said up front - no picker
        $crawler = $browser->request('GET', '/en/move-round/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        self::assertResponseIsSuccessful();
        self::assertSame($this->trans('restructure.move_round.refused.has_entries'), trim($crawler->filter('[data-round-has-entries]')->text()));
        self::assertCount(0, $crawler->filter('select[name="move_round_form[competitionId]"]'));

        // A post anyway is refused
        $browser->request('POST', '/en/move-round/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, ['move_round_form' => ['competitionId' => OrganizationFixture::COMPETITION_RIVERBEND_OPEN]]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testTurnIntoAnOrganizationAsAnAdmin(): void
    {
        $browser = self::createClient();
        $path = '/en/series-to-organization/' . CompetitionSeriesFixture::SERIES_OFFLINE;

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', $path);
        self::assertResponseStatusCodeSame(403);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSame('Puzzle Meetup Prague', $crawler->filter('input[name="create_organization_from_series_form[name]"]')->attr('value'));
        self::assertSame('puzzle-meetup-prague', $crawler->filter('input[name="create_organization_from_series_form[newSeriesSlug]"]')->attr('value'));

        $form = $crawler->selectButton($this->trans('restructure.to_organization.submit'))->form();
        $form['create_organization_from_series_form[name]'] = 'Prague Puzzle Club';
        $form['create_organization_from_series_form[slug]'] = OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG;
        $crawler = $browser->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($this->trans('restructure.to_organization.slug_taken'), $crawler->filter('form')->text());

        $form = $crawler->selectButton($this->trans('restructure.to_organization.submit'))->form();
        $form['create_organization_from_series_form[slug]'] = 'puzzle-meetup-prague';
        $form['create_organization_from_series_form[newSeriesName]'] = 'Prague Puzzle Meetup Nights';
        $form['create_organization_from_series_form[newSeriesSlug]'] = 'prague-puzzle-meetup-nights';
        $browser->submit($form);

        self::assertResponseRedirects('/en/organizations/puzzle-meetup-prague');
        self::assertSame(
            'approved',
            self::getContainer()->get(Connection::class)->fetchOne(
                "SELECT CASE WHEN approved_at IS NULL THEN 'pending' ELSE 'approved' END FROM organization WHERE slug = 'puzzle-meetup-prague'",
            ),
        );

        // The old series address leads to the organization
        $browser->request('GET', '/en/series/puzzle-meetup-prague');
        self::assertResponseRedirects('/en/organizations/puzzle-meetup-prague', 301);
    }

    public function testTurnIntoAnOrganizationByItsCreatorWaitsForApproval(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/series-to-organization/' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT);
        // Prefilled from the series; the region cleared = none (never the series' location again)
        self::assertSame('Quiet Pines', $crawler->filter('input[name="create_organization_from_series_form[region]"]')->attr('value'));
        $form = $crawler->selectButton($this->trans('restructure.to_organization.submit'))->form();
        $form['create_organization_from_series_form[name]'] = 'Quiet Pines Puzzle Guild';
        $form['create_organization_from_series_form[region]'] = '';
        $browser->submit($form);

        self::assertResponseRedirects('/en/organizations/quiet-pines-puzzle-guild');
        self::assertQueuedEmailCount(1);
        $organization = self::getContainer()->get(Connection::class)->fetchAssociative("SELECT approved_at, region, country_code FROM organization WHERE slug = 'quiet-pines-puzzle-guild'");
        self::assertIsArray($organization);
        self::assertNull($organization['approved_at']);
        self::assertNull($organization['region']);
        self::assertSame('de', $organization['country_code']);
    }

    public function testADraftOrganizationIsLinkedOnlyForItsTeam(): void
    {
        $browser = self::createClient();
        $path = '/en/series-to-organization/' . OrganizationFixture::SERIES_HARBOR_CLUB_MEETS;
        $organizationLink = 'a[href="/en/organizations/' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_SLUG . '"]';

        // A maintainer of the series who is not on the organization's team
        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO competition_series_maintainer (competition_series_id, player_id) VALUES (:seriesId, :playerId)',
            ['seriesId' => OrganizationFixture::SERIES_HARBOR_CLUB_MEETS, 'playerId' => PlayerFixture::PLAYER_REGULAR],
        );

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_NAME, $crawler->filter('.ev-restructure')->text());
        self::assertCount(0, $crawler->filter('.ev-restructure ' . $organizationLink));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', $path);
        self::assertCount(1, $crawler->filter('.ev-restructure ' . $organizationLink));
    }

    public function testASeriesOfAnOrganizationShowsWhereItBelongsAndRefusesThePost(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $path = '/en/series-to-organization/' . OrganizationFixture::SERIES_LANTERN_NIGHTS;

        $crawler = $browser->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(OrganizationFixture::ORGANIZATION_RIVERBEND_NAME, $crawler->filter('.ev-restructure')->text());
        self::assertCount(0, $crawler->filter('form[name="create_organization_from_series_form"]'));

        $browser->request('POST', $path, ['create_organization_from_series_form' => ['name' => 'Another']]);
        self::assertResponseStatusCodeSame(422);
    }

    private function seriesOf(string $competitionId): mixed
    {
        return self::getContainer()->get(Connection::class)->fetchOne('SELECT series_id FROM competition WHERE id = :id', ['id' => $competitionId]);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return self::getContainer()->get(TranslatorInterface::class)->trans($key, $parameters);
    }
}
