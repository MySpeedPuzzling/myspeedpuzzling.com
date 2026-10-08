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
 * The "Organization" select and "Save as draft" of the event and series forms (docs/features/organizations/README.md
 * "Forms"): the organizations the player is on the team of (admins: every one not rejected), `?organization=`
 * pre-selects, an item created under an approved organization by its team needs no approval, editing moves an item in
 * or out, an edition shows its series' organization read-only.
 */
final class CompetitionFormsOrganizationTest extends WebTestCase
{
    private const string SELECT = 'select[name="competition_form[organizationId]"]';

    public function testATeamMemberAddsAnEventUnderTheOrganizationApprovedAtOnce(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', '/en/add-event');
        // The player's organizations by name, "None" first; pending and draft ones say so
        self::assertSame(['', OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT, OrganizationFixture::ORGANIZATION_MAPLE_PENDING, OrganizationFixture::ORGANIZATION_RIVERBEND], $this->optionValues($crawler));
        self::assertSame(['None', 'Cedar Grove Puzzle Guild (draft)', 'Maple Leaf Puzzlers (waiting for approval)', 'Riverbend Jigsaw Association'], $this->optionLabels($crawler));

        $browser->submitForm('Submit for Approval', [
            'competition_form[name]' => 'Riverbend Autumn Open',
            'competition_form[isOnline]' => '0',
            'competition_form[location]' => 'Riverbend',
            'competition_form[dateFrom]' => '15.06.2027',
            'competition_form[dateTo]' => '15.06.2027',
            'competition_form[organizationId]' => OrganizationFixture::ORGANIZATION_RIVERBEND,
            'competition_form[eligibility]' => 'Residents of Riverbend Valley',
        ]);

        self::assertResponseRedirects();
        // Nothing for an admin to review
        self::assertQueuedEmailCount(0);

        $row = $this->competitionRow('Riverbend Autumn Open');
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $row['organization_id']);
        self::assertNotNull($row['approved_at']);
        self::assertFalse($row['is_draft']);
        self::assertSame('Residents of Riverbend Valley', $row['eligibility']);

        $browser->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'everyone can see it now');
    }

    public function testWithoutOrganizationsThereIsNoSelectAndALinkCannotPickOne(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/add-event?organization=' . OrganizationFixture::ORGANIZATION_RIVERBEND);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(self::SELECT));

        $browser->submitForm('Submit for Approval', [
            'competition_form[name]' => 'Lone Puzzle Evening',
            'competition_form[isOnline]' => '0',
            'competition_form[location]' => 'Brno',
            'competition_form[dateFrom]' => '15.06.2027',
            'competition_form[dateTo]' => '15.06.2027',
        ]);

        self::assertResponseRedirects();
        // Waits for an admin as always
        self::assertQueuedEmailCount(1);
        $row = $this->competitionRow('Lone Puzzle Evening');
        self::assertNull($row['organization_id']);
        self::assertNull($row['approved_at']);
    }

    public function testTheOrganizationOfALinkIsPreSelected(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', '/en/add-event?organization=' . strtoupper(OrganizationFixture::ORGANIZATION_RIVERBEND));
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $crawler->filter(self::SELECT . ' option[selected]')->attr('value'));

        // Not one of the player's: nothing pre-selected
        $crawler = $browser->request('GET', '/en/add-event?organization=' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT);
        self::assertSame([], $this->selectedValues($crawler));
    }

    public function testSaveAsDraftKeepsItToTheTeamAndEmailsNobody(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/add-event');
        $browser->submitForm('Save as draft', [
            'competition_form[name]' => 'Quiet Draft Evening',
            'competition_form[isOnline]' => '0',
            'competition_form[location]' => 'Brno',
            'competition_form[dateFrom]' => '15.06.2027',
            'competition_form[dateTo]' => '15.06.2027',
        ]);

        self::assertResponseRedirects();
        self::assertQueuedEmailCount(0);
        $row = $this->competitionRow('Quiet Draft Evening');
        self::assertTrue($row['is_draft']);
        self::assertNull($row['approved_at']);

        $browser->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Saved as a draft');
    }

    public function testASeriesSavedAsDraftUnderAnOrganization(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/add-event');
        $browser->submitForm('Save as draft', [
            'competition_form[name]' => 'Riverbend Puzzle Mornings',
            'competition_form[isOnline]' => '0',
            'competition_form[isRecurring]' => true,
            'competition_form[location]' => 'Riverbend',
            'competition_form[organizationId]' => OrganizationFixture::ORGANIZATION_RIVERBEND,
            'competition_form[schedule]' => 'Second Saturday of the month, 9 am',
        ]);

        self::assertResponseRedirects();
        self::assertQueuedEmailCount(0);

        /** @var array{organization_id: null|string, approved_at: null|string, is_draft: bool, schedule: null|string} $row */
        $row = $this->connection()->fetchAssociative('SELECT organization_id, approved_at, is_draft, schedule FROM competition_series WHERE name = ?', ['Riverbend Puzzle Mornings']);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $row['organization_id']);
        // Approved under the organization, hidden until published
        self::assertNotNull($row['approved_at']);
        self::assertTrue($row['is_draft']);
        self::assertSame('Second Saturday of the month, 9 am', $row['schedule']);
    }

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

    public function testAdminsChooseAnyOrganizationAndEmailNobody(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/add-event');
        $values = $this->optionValues($crawler);
        foreach ([OrganizationFixture::ORGANIZATION_RIVERBEND, OrganizationFixture::ORGANIZATION_MAPLE_PENDING, OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT] as $organizationId) {
            self::assertContains($organizationId, $values);
        }

        $browser->submitForm('Submit for Approval', [
            'competition_form[name]' => 'Admin Puzzle Evening',
            'competition_form[isOnline]' => '0',
            'competition_form[location]' => 'Brno',
            'competition_form[dateFrom]' => '15.06.2027',
            'competition_form[dateTo]' => '15.06.2027',
        ]);

        self::assertResponseRedirects();
        self::assertQueuedEmailCount(0);
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
     * @return list<string>
     */
    private function optionLabels(Crawler $crawler): array
    {
        return $crawler->filter(self::SELECT . ' option')->each(static fn (Crawler $option): string => trim($option->text()));
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
