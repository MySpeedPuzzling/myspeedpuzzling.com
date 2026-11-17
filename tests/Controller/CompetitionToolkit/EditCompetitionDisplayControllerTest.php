<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddCompetitionDisplay;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DisplayKind;
use SpeedPuzzling\Web\Value\DisplayPreset;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The display design editor page (D-displays-overlays.md §8.1 `edit_competition_display`, competition toolkit D6):
 * only for COMPETITION_TOOLKIT_MANAGE on the display's own event; the page hands the lazy editor controller the
 * stored design, the display's state and the save / upload / design URLs; the list and the settings page link to it.
 */
final class EditCompetitionDisplayControllerTest extends WebTestCase
{
    private const string EVENT = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;

    public function testAnAdminOpensTheEditorWithTheStoredDesign(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay(DisplayPreset::ClassicTimer);

        $page = $browser->request('GET', '/en/display-editor/' . $displayId);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');

        $editor = $page->filter('[data-controller="display-editor"]');
        self::assertCount(1, $editor);

        /** @var array<string, mixed> $config */
        $config = json_decode((string) $editor->attr('data-display-editor-config-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(self::EVENT, $config['competitionId']);
        self::assertSame('screen', $config['kind']);
        self::assertSame(1, $config['designVersion']);
        self::assertIsArray($config['design']);
        self::assertSame(1, $config['design']['schema']);
        self::assertNotEmpty($config['design']['elements'], 'the preset');
        self::assertSame('/en/display-editor/' . $displayId . '/save', $config['saveUrl']);
        self::assertSame('/en/display-editor/' . $displayId . '/image', $config['uploadUrl']);
        self::assertIsString($config['designUrl']);
        self::assertMatchesRegularExpression('#^/display/[A-Za-z0-9_-]+/design$#', $config['designUrl']);
        self::assertIsString($config['csrfToken']);
        self::assertNotSame('', $config['csrfToken']);

        // The display's own state, without its Mercure subscription
        self::assertIsArray($config['state']);
        self::assertArrayHasKey('competition', $config['state']);
        self::assertArrayHasKey('texts', $config['state']);
        self::assertArrayNotHasKey('mercure', $config['state']);

        // The schema-driven panel's texts, by key
        self::assertIsArray($config['labels']);
        self::assertSame('Timer', $config['labels']['type.timer']);
        self::assertSame('Font size (px at 1080p)', $config['labels']['style.fontSize']);

        // The display's link opens in a new tab
        $open = $page->filter('[data-display-editor-open]');
        self::assertStringStartsWith('http://localhost/display/', (string) $open->attr('href'));
        self::assertSame('_blank', $open->attr('target'));
    }

    public function testTheEditorScriptIsLoadedOnlyByItsLazyController(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay(DisplayPreset::Blank);

        $browser->request('GET', '/en/display-editor/' . $displayId);
        $html = (string) $browser->getResponse()->getContent();
        self::assertResponseIsSuccessful();
        // Not the display page's own entry: the editor runs inside the site, through a lazy controller of `app`
        self::assertStringNotContainsString('build/display.js', $html);

        $source = (string) file_get_contents(__DIR__ . '/../../../assets/controllers/display_editor_controller.js');
        self::assertStringStartsWith("/* stimulusFetch: 'lazy' */", $source);

        // Other pages do not mention the editor at all
        $page = $browser->request('GET', '/en/manage-displays/' . self::EVENT);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('data-controller="display-editor"', (string) $browser->getResponse()->getContent());
        self::assertSame('/en/display-editor/' . $displayId, $page->filter('[data-display="' . $displayId . '"] [data-display-edit-link]')->attr('href'));

        $page = $browser->request('GET', '/en/display-editor/' . $displayId . '/settings');
        self::assertResponseIsSuccessful();
        self::assertSame('/en/display-editor/' . $displayId, $page->filter('[data-display-edit-link]')->attr('href'));
    }

    public function testAnOverlayEditor(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $displayId = Uuid::uuid7();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(
            new AddCompetitionDisplay($displayId, self::EVENT, DisplayKind::Overlay, 'OBS', 'de', null, DisplayPreset::ObsCornerTimer),
        );

        $page = $browser->request('GET', '/en/display-editor/' . $displayId->toString());

        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $config */
        $config = json_decode((string) $page->filter('[data-controller="display-editor"]')->attr('data-display-editor-config-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('overlay', $config['kind']);
        self::assertIsArray($config['state']);
        self::assertSame('de', $config['state']['locale']);
    }

    public function testThePreviewStateIsWhatTheDisplayItselfGets(): void
    {
        // Secret puzzles and unpublished results follow the display's own (public) rules - R §1.5
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay(DisplayPreset::TimerWithLiveBoard);

        $page = $browser->request('GET', '/en/display-editor/' . $displayId);
        self::assertResponseIsSuccessful();
        /** @var array{state: array<string, mixed>, designUrl: string} $config */
        $config = json_decode((string) $page->filter('[data-controller="display-editor"]')->attr('data-display-editor-config-value'), true, flags: JSON_THROW_ON_ERROR);

        $browser->request('GET', str_replace('/design', '/state', $config['designUrl']));
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $state */
        $state = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        foreach (['competition', 'round', 'puzzles', 'anyPuzzleRevealed', 'results', 'images', 'texts', 'qr'] as $key) {
            self::assertEquals($state[$key], $config['state'][$key], $key);
        }
    }

    public function testTheOrganiserOpensIt(): void
    {
        $browser = $this->browser(self::ORGANISER);
        $displayId = $this->addDisplay(DisplayPreset::Blank);

        $browser->request('GET', '/en/display-editor/' . $displayId);
        self::assertResponseIsSuccessful();
    }

    public function testAnotherPlayerIsRefused(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_REGULAR);
        $displayId = $this->addDisplay(DisplayPreset::Blank);

        $browser->request('GET', '/en/display-editor/' . $displayId);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAGuestIsSentToSignIn(): void
    {
        $browser = self::createClient();
        $displayId = $this->addDisplay(DisplayPreset::Blank);

        $browser->request('GET', '/en/display-editor/' . $displayId);
        self::assertResponseRedirects();
        self::assertStringStartsWith('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testAnUnknownDisplayIs404(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/display-editor/' . Uuid::uuid7()->toString());
        self::assertResponseStatusCodeSame(404);
    }

    private function addDisplay(DisplayPreset $preset): string
    {
        $displayId = Uuid::uuid7();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(
            new AddCompetitionDisplay($displayId, self::EVENT, DisplayKind::Screen, 'Hall A', 'en', null, $preset),
        );

        return $displayId->toString();
    }

    private function browser(string $player): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $player);

        return $browser;
    }
}
