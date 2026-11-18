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
 * The "Organization" select of the event and series edit forms (docs/features/organizations/README.md "Forms"): editing
 * moves an item in or out, an edition shows its series' organization read-only. Creating under an organization is the
 * event creation wizard's (its entry picks the organization - EventWizardTest, RetiredClassicCreateTest; the classic add
 * form these tests also covered was retired 2026-11-18).
 */
final class CompetitionFormsOrganizationTest extends WebTestCase
{
    private const string SELECT = 'select[name="competition_form[organizationId]"]';

    public function testEditingMovesAnEventOutOfItsOrganizationAndBackIn(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $url = '/en/edit-event/' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN;

        $crawler = $browser->request('GET', $url);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $crawler->filter(self::SELECT . ' option[selected]')->attr('value'));

        $browser->submitForm('Save Changes', ['competition_form[organizationId]' => '']);
        self::assertResponseRedirects($url);
        self::assertNull($this->competitionRow(OrganizationFixture::COMPETITION_RIVERBEND_OPEN_NAME)['organization_id']);

        $browser->request('GET', $url);
        $browser->submitForm('Save Changes', ['competition_form[organizationId]' => OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT]);
        self::assertResponseRedirects($url);
        self::assertSame(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT, $this->competitionRow(OrganizationFixture::COMPETITION_RIVERBEND_OPEN_NAME)['organization_id']);
    }

    public function testMovingAPendingEventUnderAnApprovedOrganizationApprovesIt(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $url = '/en/edit-event/' . OrganizationFixture::COMPETITION_WILLOW_PENDING_DRAFT;

        $browser->request('GET', $url);
        $browser->submitForm('Save Changes', ['competition_form[organizationId]' => OrganizationFixture::ORGANIZATION_RIVERBEND]);
        self::assertResponseRedirects($url);

        $row = $this->competitionRow(OrganizationFixture::COMPETITION_WILLOW_PENDING_DRAFT_NAME);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $row['organization_id']);
        self::assertNotNull($row['approved_at']);
        // Still a draft - publishing is the organiser's step
        self::assertTrue($row['is_draft']);
    }

    public function testEditingASeriesMovesItIntoAnOrganization(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $url = '/en/edit-series/' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT;

        $crawler = $browser->request('GET', $url);
        self::assertSame('', (string) $crawler->filter(self::SELECT . ' option')->first()->attr('value'));
        self::assertSame([], $this->selectedValues($crawler));

        $browser->submitForm('Save Changes', ['competition_form[organizationId]' => OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT]);
        self::assertResponseRedirects('/en/manage-series/' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT);

        self::assertSame(
            OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT,
            $this->connection()->fetchOne('SELECT organization_id FROM competition_series WHERE id = ?', [OrganizationFixture::SERIES_QUIET_PINES_DRAFT]),
        );
    }

    public function testAnEditorOffTheTeamKeepsTheOrganizationOrMovesTheEventOut(): void
    {
        $browser = self::createClient();
        $this->connection()->insert('competition_maintainer', [
            'competition_id' => OrganizationFixture::COMPETITION_RIVERBEND_OPEN,
            'player_id' => PlayerFixture::PLAYER_REGULAR,
        ]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $url = '/en/edit-event/' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN;

        // The current organization is offered (and kept on save) though the player is not on its team
        $crawler = $browser->request('GET', $url);
        self::assertSame(['', OrganizationFixture::ORGANIZATION_RIVERBEND], $this->optionValues($crawler));

        $browser->submitForm('Save Changes', ['competition_form[name]' => 'Riverbend Spring Open 2027']);
        self::assertResponseRedirects($url);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $this->competitionRow('Riverbend Spring Open 2027')['organization_id']);
    }

    public function testAnEditionShowsItsSeriesOrganizationReadOnly(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/edit-event/' . OrganizationFixture::EDITION_LANTERN_1);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(self::SELECT));
        self::assertStringContainsString(OrganizationFixture::ORGANIZATION_RIVERBEND_NAME, $crawler->filter('[data-series-organization]')->text());
    }

    public function testADraftOrganizationIsNamedOnTheEditionFormForItsTeamOnly(): void
    {
        $browser = self::createClient();
        // A maintainer of the series who is not on the (draft) organization's team
        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO competition_series_maintainer (competition_series_id, player_id) VALUES (:seriesId, :playerId)',
            ['seriesId' => OrganizationFixture::SERIES_HARBOR_CLUB_MEETS, 'playerId' => PlayerFixture::PLAYER_REGULAR],
        );
        $url = '/en/edit-event/' . OrganizationFixture::EDITION_HARBOR_CLUB_1;

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-series-organization]'));
        self::assertStringNotContainsString(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_NAME, (string) $browser->getResponse()->getContent());

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', $url);
        self::assertStringContainsString(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_NAME, $crawler->filter('[data-series-organization]')->text());
    }

    /**
     * @return list<string>
     */
    private function optionValues(Crawler $crawler): array
    {
        return $crawler->filter(self::SELECT . ' option')->each(static fn (Crawler $option): string => (string) $option->attr('value'));
    }

    /**
     * The chosen organization - none when "None" is (the empty value)
     *
     * @return list<string>
     */
    private function selectedValues(Crawler $crawler): array
    {
        return array_values(array_filter(
            $crawler->filter(self::SELECT . ' option[selected]')->each(static fn (Crawler $option): string => (string) $option->attr('value')),
            static fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * @return array{organization_id: null|string, approved_at: null|string, is_draft: bool, eligibility: null|string}
     */
    private function competitionRow(string $name): array
    {
        $row = $this->connection()->fetchAssociative('SELECT organization_id, approved_at, is_draft, eligibility FROM competition WHERE name = ?', [$name]);
        self::assertIsArray($row, $name);

        /** @var array{organization_id: null|string, approved_at: null|string, is_draft: bool, eligibility: null|string} $row */
        return $row;
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
