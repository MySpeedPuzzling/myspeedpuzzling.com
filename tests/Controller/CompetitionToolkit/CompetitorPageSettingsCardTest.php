<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Query\GetCompetitorPageSettings;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CompetitorPageSettings;
use SpeedPuzzling\Web\Value\QrTagMode;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * The "Competitor pages & QR codes" card of the competition toolkit hub (B-qr-competitor-page.md §2, R §1.4): saved =
 * redirect back to the hub, refused = 422, only for COMPETITION_TOOLKIT_MANAGE.
 */
final class CompetitorPageSettingsCardTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    private const string FLAG = 'COMPETITION_TOOLKIT_FOR_ORGANISERS';
    private const string EVENT = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string HUB = '/en/manage-event-toolkit/' . self::EVENT;
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string FORM = 'competitor_page_settings_form';

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testAnUntouchedCompetitionShowsTheDefaults(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);

        $page = $browser->request('GET', self::HUB);

        self::assertResponseIsSuccessful();
        $card = $page->filter('[data-toolkit-card="competitor-pages"]');
        self::assertCount(1, $card->filter('form[data-competitor-pages-settings]'));
        self::assertCount(0, $card->filter('[data-toolkit-placeholder]'));
        // Four modes: "as before" (empty value) checked, every switch off
        self::assertSame(['', 'entrant', 'table', 'round_entry'], $card->filter('input[name="' . self::FORM . '[qrTagMode]"]')->each(static fn ($radio): string => (string) $radio->attr('value')));
        self::assertSame('', $card->filter('input[name="' . self::FORM . '[qrTagMode]"][checked]')->attr('value'));
        self::assertCount(0, $card->filter('input[type="checkbox"][checked]'));
    }

    public function testAnAdminSavesTheSettings(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);
        $page = $browser->request('GET', self::HUB . '?return=/en/events');

        $form = $page->filter('[data-competitor-pages-save]')->form();
        self::field($form, 'qrTagMode')->select('table');
        self::field($form, 'competitorPages')->tick();
        self::field($form, 'liveResultsBeforePublishing')->tick();
        self::field($form, 'timeProposals')->tick();
        $browser->submit($form);

        self::assertResponseRedirects(self::HUB . '?return=/en/events');
        self::assertEquals(
            new CompetitorPageSettings(true, true, true, QrTagMode::Table),
            self::getContainer()->get(GetCompetitorPageSettings::class)->of(self::EVENT),
        );

        $page = $browser->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Competitor page settings saved.');
        self::assertSame('table', $page->filter('input[name="' . self::FORM . '[qrTagMode]"][checked]')->attr('value'));
        self::assertCount(3, $page->filter('[data-toolkit-card="competitor-pages"] input[type="checkbox"][checked]'));
    }

    public function testProposalsWithoutPagesAreSavedOff(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);
        $page = $browser->request('GET', self::HUB);

        $form = $page->filter('[data-competitor-pages-save]')->form();
        self::field($form, 'timeProposals')->tick();
        $browser->submit($form);

        self::assertResponseRedirects();
        self::assertEquals(CompetitorPageSettings::defaults(), self::getContainer()->get(GetCompetitorPageSettings::class)->of(self::EVENT));
    }

    public function testAnUnknownModeIsRefusedWith422(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);
        $page = $browser->request('GET', self::HUB);

        $form = $page->filter('[data-competitor-pages-save]')->form();
        $form->disableValidation();
        self::field($form, 'qrTagMode')->setValue('everywhere');
        self::field($form, 'competitorPages')->tick();
        $browser->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $browser->getCrawler()->filter('[data-competitor-pages-settings] .invalid-feedback, [data-competitor-pages-settings] .is-invalid'));
        self::assertEquals(CompetitorPageSettings::defaults(), self::getContainer()->get(GetCompetitorPageSettings::class)->of(self::EVENT));
    }

    public function testAnOrganiserCannotPostWhileTheFlagIsOff(): void
    {
        $browser = $this->browser(flag: false, player: self::ORGANISER);

        $browser->request('POST', self::HUB, [self::FORM => ['competitorPages' => '1']]);

        self::assertResponseStatusCodeSame(403);
        self::assertEquals(CompetitorPageSettings::defaults(), self::getContainer()->get(GetCompetitorPageSettings::class)->of(self::EVENT));
    }

    public function testNobodyElseCanPostEvenWithTheFlag(): void
    {
        $browser = $this->browser(flag: true, player: PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', self::HUB, [self::FORM => ['competitorPages' => '1']]);

        self::assertResponseStatusCodeSame(403);
        self::assertEquals(CompetitorPageSettings::defaults(), self::getContainer()->get(GetCompetitorPageSettings::class)->of(self::EVENT));
    }

    public function testTheFlagLetsTheOrganiserSave(): void
    {
        $browser = $this->browser(flag: true, player: self::ORGANISER);
        $page = $browser->request('GET', self::HUB);

        $form = $page->filter('[data-competitor-pages-save]')->form();
        self::field($form, 'competitorPages')->tick();
        $browser->submit($form);

        self::assertResponseRedirects(self::HUB);
        self::assertTrue(self::getContainer()->get(GetCompetitorPageSettings::class)->of(self::EVENT)->competitorPages);
    }

    public function testAnOnlineEventGetsNoForm(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);

        $page = $browser->request('GET', '/en/manage-event-toolkit/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-competitor-pages-online]'));
        self::assertCount(0, $page->filter('[data-competitor-pages-settings]'));
    }

    private static function field(Form $form, string $name): ChoiceFormField
    {
        $field = $form[self::FORM . '[' . $name . ']'];
        self::assertInstanceOf(ChoiceFormField::class, $field);

        return $field;
    }

    public function testThePrintTagsLinksLeadToTheKindsWithTheQrModesFirst(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);

        $page = $browser->request('GET', self::HUB);
        self::assertSame(['people', 'lineups', 'tables', 'entries'], $page->filter('[data-print-tags-kind]')->each(static fn ($link): string => (string) $link->attr('data-print-tags-kind')));
        self::assertSame('/en/name-tags/' . self::EVENT . '?kind=lineups', $page->filter('[data-print-tags-kind="lineups"]')->attr('href'));

        self::getContainer()->get(Connection::class)->executeStatement("UPDATE competition SET qr_tag_mode = 'table' WHERE id = :id", ['id' => self::EVENT]);
        $page = $browser->request('GET', self::HUB);
        self::assertSame(['tables', 'people', 'lineups', 'entries'], $page->filter('[data-print-tags-kind]')->each(static fn ($link): string => (string) $link->attr('data-print-tags-kind')));
        self::assertStringContainsString('btn-primary', (string) $page->filter('[data-print-tags-kind="tables"]')->attr('class'));
    }

    private function browser(bool $flag, string $player): KernelBrowser
    {
        // Before the kernel boots - the flag is a container parameter
        $this->overrideFeatureFlagEnv(self::FLAG, $flag);

        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $player);

        return $browser;
    }
}
