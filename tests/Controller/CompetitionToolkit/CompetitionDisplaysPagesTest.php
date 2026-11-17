<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionDisplay;
use SpeedPuzzling\Web\Message\AddCompetitionDisplay;
use SpeedPuzzling\Web\Query\GetCompetitionDisplays;
use SpeedPuzzling\Web\Results\CompetitionDisplayOverview;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DisplayFollowMode;
use SpeedPuzzling\Web\Value\DisplayKind;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The Displays card of the competition toolkit hub and the displays pages (D-displays-overlays.md §8.1, R §1.4 card 2):
 * forms redirect back on success (a new display: into its editor) and answer 422 when refused, everything only for
 * COMPETITION_TOOLKIT_MANAGE on the display's own event.
 */
final class CompetitionDisplaysPagesTest extends WebTestCase
{
    private const string EVENT = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string HUB = '/en/manage-event-toolkit/' . self::EVENT;
    private const string LIST = '/en/manage-displays/' . self::EVENT;
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string ADD_FORM = 'add_competition_display_form';
    private const string SETTINGS_FORM = 'competition_display_settings_form';

    public function testTheHubCardOfAnEventWithoutDisplays(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);

        $page = $browser->request('GET', self::HUB);

        self::assertResponseIsSuccessful();
        $card = $page->filter('[data-toolkit-card="displays"]');
        self::assertCount(0, $card->filter('[data-toolkit-placeholder]'));
        self::assertCount(1, $card->filter('[data-displays-empty]'));
        self::assertCount(1, $card->filter('form[data-display-add-form]'));
        self::assertSame('/en/manage-displays/' . self::EVENT . '/add?return=' . self::HUB, $card->filter('form[data-display-add-form]')->attr('action'));
        self::assertSame(self::LIST, $card->filter('[data-displays-page-link]')->attr('href'));
    }

    public function testAnAdminAddsADisplayFromTheHub(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $page = $browser->request('GET', self::HUB);

        $form = $page->filter('[data-display-add]')->form([
            self::ADD_FORM . '[kind]' => 'overlay',
            self::ADD_FORM . '[name]' => 'OBS corner',
            self::ADD_FORM . '[locale]' => 'de',
        ]);
        $browser->submit($form);

        // Straight into the editor of the new display (D §8.1, D6F) - not back to the hub
        $displays = $this->displays();
        self::assertCount(1, $displays);
        self::assertResponseRedirects('/en/display-editor/' . $displays[0]->id, 303);
        self::assertSame('OBS corner', $displays[0]->name);
        self::assertSame(DisplayKind::Overlay, $displays[0]->kind);
        self::assertSame('de', $displays[0]->locale);

        $browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-success', 'Display added.');
        self::assertSelectorExists('[data-display-editor-page]');

        $page = $browser->request('GET', self::HUB);
        $row = $page->filter('[data-toolkit-card="displays"] [data-display="' . $displays[0]->id . '"]');
        self::assertCount(1, $row);
        self::assertSame('OBS corner', $row->filter('[data-display-name]')->text());
        self::assertSame('overlay', $row->filter('[data-display-kind]')->attr('data-display-kind'));
        self::assertSame('http://localhost/display/' . $displays[0]->token, $row->filter('[data-controller="copy-link"]')->attr('data-copy-link-url-value'));
    }

    public function testTheAddFormDefaultsToTheRequestLanguage(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);

        $page = $browser->request('GET', '/de/manage-displays/' . self::EVENT);

        self::assertResponseIsSuccessful();
        self::assertSame('de', $page->filter('select[name="' . self::ADD_FORM . '[locale]"] option[selected]')->attr('value'));
        self::assertSame('screen', $page->filter('input[name="' . self::ADD_FORM . '[kind]"][checked]')->attr('value'));
    }

    public function testAnAddWithoutANameIsRefusedWith422(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $page = $browser->request('GET', self::LIST);

        $form = $page->filter('[data-display-add]')->form([self::ADD_FORM . '[name]' => '   ']);
        $browser->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $browser->getCrawler()->filter('[data-competition-displays] form[data-display-add-form] .is-invalid'));
        self::assertSame([], $this->displays());
    }

    public function testTheTwentiethDisplayHidesTheFormAndAnotherAddIsRefused(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $page = $browser->request('GET', self::LIST);
        $form = $page->filter('[data-display-add]')->form([self::ADD_FORM . '[name]' => 'One too many']);

        for ($i = 1; $i <= CompetitionDisplay::MAX_PER_COMPETITION; $i++) {
            $this->addDisplay('Display ' . $i);
        }

        $browser->submit($form);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertCount(CompetitionDisplay::MAX_PER_COMPETITION, $this->displays());
        $page = $browser->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'An event can have at most 20 displays.');
        self::assertCount(0, $page->filter('form[data-display-add-form]'));
        self::assertCount(1, $page->filter('[data-displays-limit]'));
    }

    public function testTheRowButtonsDuplicateRegenerateAndDelete(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay('Hall A');
        $oldToken = $this->displays()[0]->token;

        $page = $browser->request('GET', self::HUB);
        $browser->submit($page->filter('[data-display="' . $displayId . '"] [data-display-duplicate]')->form());
        self::assertResponseRedirects(self::HUB, 303);
        self::assertSame(['Hall A', 'Hall A (copy)'], array_map(static fn (CompetitionDisplayOverview $display): string => $display->name, $this->displays()));

        $page = $browser->request('GET', self::LIST);
        $browser->submit($page->filter('[data-display="' . $displayId . '"] [data-display-regenerate]')->form());
        self::assertResponseRedirects(self::LIST, 303);
        $tokens = array_map(static fn (CompetitionDisplayOverview $display): string => $display->token, $this->displays());
        self::assertNotContains($oldToken, $tokens);

        $page = $browser->request('GET', self::LIST);
        $browser->submit($page->filter('[data-display="' . $displayId . '"] [data-display-delete]')->form());
        self::assertResponseRedirects(self::LIST, 303);
        self::assertSame(['Hall A (copy)'], array_map(static fn (CompetitionDisplayOverview $display): string => $display->name, $this->displays()));
    }

    public function testARowButtonWithoutItsTokenIsRefused(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay('Hall A');

        $browser->request('POST', '/en/display-editor/' . $displayId . '/delete', ['_token' => 'nope']);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->displays());
    }

    public function testTheSettingsPinARoundAndComeBack(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay('Hall A');

        $page = $browser->request('GET', '/en/display-editor/' . $displayId . '/settings?return=' . rawurlencode(self::HUB));
        self::assertResponseIsSuccessful();
        // Only this event's rounds are offered
        $offered = $page->filter('select[name="' . self::SETTINGS_FORM . '[pinnedRoundId]"] option')->each(static fn ($option): string => (string) $option->attr('value'));
        self::assertContains(OfficialResultsFixture::ROUND_FINAL, $offered);
        self::assertNotContains(CompetitionRoundFixture::ROUND_CZECH_FINAL, $offered);

        $form = $page->filter('[data-display-settings-save]')->form([
            self::SETTINGS_FORM . '[name]' => 'Main hall',
            self::SETTINGS_FORM . '[locale]' => 'fr',
            self::SETTINGS_FORM . '[followMode]' => 'pinned',
            self::SETTINGS_FORM . '[pinnedRoundId]' => OfficialResultsFixture::ROUND_FINAL,
        ]);
        $browser->submit($form);

        self::assertResponseRedirects(self::HUB, 303);
        $display = $this->displays()[0];
        self::assertSame('Main hall', $display->name);
        self::assertSame('fr', $display->locale);
        self::assertSame(DisplayFollowMode::Pinned, $display->followMode);
        self::assertSame(OfficialResultsFixture::ROUND_FINAL, $display->pinnedRoundId);
    }

    public function testTheSettingsFollowASubsetOfRounds(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay('Hall A');

        $page = $browser->request('GET', '/en/display-editor/' . $displayId . '/settings');
        $form = $page->filter('[data-display-settings-save]')->form();
        $boxes = $form[self::SETTINGS_FORM . '[roundIds]'];
        self::assertIsArray($boxes);
        self::assertInstanceOf(ChoiceFormField::class, $boxes[0]);
        $boxes[0]->tick();
        $browser->submit($form);

        self::assertResponseRedirects(self::LIST, 303);
        $display = $this->displays()[0];
        self::assertSame(DisplayFollowMode::Current, $display->followMode);
        self::assertNotNull($display->followRoundIds);
        self::assertCount(1, $display->followRoundIds);

        $page = $browser->followRedirect();
        self::assertStringContainsString('Shows the current round of:', $page->filter('[data-display="' . $displayId . '"] [data-display-follow]')->text());
    }

    public function testPinnedWithoutARoundOrWithAForeignRoundIsRefusedWith422(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay('Hall A');
        $page = $browser->request('GET', '/en/display-editor/' . $displayId . '/settings');

        $form = $page->filter('[data-display-settings-save]')->form([self::SETTINGS_FORM . '[followMode]' => 'pinned']);
        $browser->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-display-settings-form]', 'Choose the round to show.');

        $form = $page->filter('[data-display-settings-save]')->form();
        $form->disableValidation();
        $form->setValues([self::SETTINGS_FORM . '[followMode]' => 'pinned', self::SETTINGS_FORM . '[pinnedRoundId]' => CompetitionRoundFixture::ROUND_CZECH_FINAL]);
        $browser->submit($form);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(DisplayFollowMode::Current, $this->displays()[0]->followMode);
    }

    public function testNobodyElseGetsIn(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_REGULAR);
        $displayId = $this->addDisplay('Hall A');

        $browser->request('GET', self::LIST);
        self::assertResponseStatusCodeSame(403);
        $browser->request('GET', '/en/display-editor/' . $displayId . '/settings');
        self::assertResponseStatusCodeSame(403);
        $browser->request('POST', '/en/display-editor/' . $displayId . '/delete', []);
        self::assertResponseStatusCodeSame(403);

        self::assertCount(1, $this->displays());
    }

    public function testTheOrganiserAdds(): void
    {
        $browser = $this->browser(self::ORGANISER);
        $page = $browser->request('GET', self::LIST);

        $browser->submit($page->filter('[data-display-add]')->form([self::ADD_FORM . '[name]' => 'Projector']));

        self::assertSame(['Projector'], array_map(static fn (CompetitionDisplayOverview $display): string => $display->name, $this->displays()));
        self::assertResponseRedirects('/en/display-editor/' . $this->displays()[0]->id, 303);
        $browser->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testANewDisplayStartsFromTheChosenPreset(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $page = $browser->request('GET', self::LIST);

        // Grouped by the kind each preset is for, Blank first
        self::assertSame('blank', $page->filter('select[name="' . self::ADD_FORM . '[preset]"] option[selected]')->attr('value'));
        self::assertCount(7, $page->filter('select[name="' . self::ADD_FORM . '[preset]"] option'));

        $browser->submit($page->filter('[data-display-add]')->form([
            self::ADD_FORM . '[kind]' => 'overlay',
            self::ADD_FORM . '[preset]' => 'obs_leaderboard_sidebar',
            self::ADD_FORM . '[name]' => 'OBS sidebar',
        ]));

        $displayId = $this->displays()[0]->id;
        self::assertResponseRedirects('/en/display-editor/' . $displayId, 303);
        /** @var array{design: string, uses_live_results: bool} $row */
        $row = self::getContainer()->get(Connection::class)->fetchAssociative('SELECT design, uses_live_results FROM competition_display WHERE id = :id', ['id' => $displayId]);
        self::assertStringContainsString('leaderboard', $row['design']);
        self::assertTrue($row['uses_live_results']);
    }

    public function testAPresetForTheOtherKindIsRefused(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $page = $browser->request('GET', self::LIST);

        $page = $browser->submit($page->filter('[data-display-add]')->form([
            self::ADD_FORM . '[kind]' => 'screen',
            self::ADD_FORM . '[preset]' => 'obs_corner_timer',
            self::ADD_FORM . '[name]' => 'Wrong',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('This design is for the other type of display.', $page->filter('[data-display-add-form]')->text());
        self::assertSame([], $this->displays());
    }

    public function testAnUnknownDisplayIsNotFound(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/display-editor/' . Uuid::uuid7()->toString() . '/settings');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnotherEventIsUntouched(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $this->addDisplay('Hall A');

        $page = $browser->request('GET', '/en/manage-event-toolkit/' . CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-toolkit-card="displays"] [data-displays-empty]'));
        self::assertCount(0, $page->filter('[data-display]'));
    }

    private function addDisplay(string $name): string
    {
        $displayId = Uuid::uuid7();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(
            new AddCompetitionDisplay($displayId, self::EVENT, DisplayKind::Screen, $name, 'en', null),
        );

        return $displayId->toString();
    }

    /**
     * @return list<CompetitionDisplayOverview>
     */
    private function displays(): array
    {
        return self::getContainer()->get(GetCompetitionDisplays::class)->ofCompetition(self::EVENT);
    }

    private function browser(string $player): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $player);

        return $browser;
    }
}
