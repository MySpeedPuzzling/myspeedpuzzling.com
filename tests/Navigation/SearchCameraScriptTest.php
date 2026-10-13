<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Twig\Environment;

/**
 * The page search's camera (templates/navigation/_search_camera.html.twig, README §4.12 "Weight"): both GlobalSearch
 * instances of a member's page keep an empty wrapper and copy the page's one camera <template> in on their first start
 * - one copy of the markup instead of two on every member page. tests/barcode-scanner-harness.mjs runs the real
 * assets/controllers/barcode_scanner_controller.js under Stimulus in jsdom on the pages rendered here:
 * - before any start the page search's scanners survive every page-wide close/pause/resume and a disconnect;
 * - a start copies in exactly the markup each instance rendered itself before (the old GlobalSearch markup, rendered
 *   here), with the HUD's data-nav of its surface, and the camera runs in it - stream, hint, zoom and light on the copied
 *   buttons, stop releases the track, a second start reuses the same copy, a read code is handed over;
 * - the page's own scanners (the add form's modal, multiscan) start in their own markup and copy nothing.
 * The browser part (Chrome's fake camera, a Live re-render while the camera runs): var/navigation-delivery check
 * SEARCH-CAMERA.
 */
final class SearchCameraScriptTest extends WebTestCase
{
    private const string CODE = '4006381333931';

    /** @var null|array<string, mixed> */
    private static null|array $results = null;

    public function testNoCaseThrewOrLoggedAnError(): void
    {
        foreach ($this->results() as $case => $facts) {
            self::assertIsArray($facts, $case);
            self::assertArrayNotHasKey('threw', $facts, $case . ': ' . (is_string($facts['threw'] ?? null) ? $facts['threw'] : ''));
            self::assertSame([], $facts['errors'] ?? null, $case);
        }
    }

    public function testBeforeAnyStartThePageHoldsOneCameraAndEmptyWrappers(): void
    {
        $facts = $this->case('page search before any start');

        self::assertSame(1, $facts['templates']);
        self::assertTrue($facts['templateInHeader']);
        self::assertSame(['topbar/search/torch', 'topbar/search/zoom_out', 'topbar/search/zoom_in'], $facts['templateNavs']);
        self::assertSame([
            ['id' => 'search-scan-topbar', 'liveIgnore' => true, 'children' => 0],
            ['id' => 'search-scan-menu', 'liveIgnore' => true, 'children' => 0],
        ], $facts['wrappers']);
        self::assertSame(0, $facts['pageVideos'], 'No <video> on the page until a scan starts');
        // barcode-scan:close (every page search's opening, the Menu field's focus), pause, resume: nothing copied, no error
        self::assertSame([['children' => 0, 'hidden' => true], ['children' => 0, 'hidden' => true]], $facts['afterClose']);
        self::assertTrue($facts['templateUnchanged']);
    }

    public function testTheTopBarsScanRunsInTheCopiedCamera(): void
    {
        $this->assertTheSearchScannerRuns('topbar', 'menu');
    }

    public function testTheMenusScanRunsInTheCopiedCamera(): void
    {
        $this->assertTheSearchScannerRuns('menu', 'topbar');
    }

    public function testACodeReadThroughTheCopiedCameraIsHandedOver(): void
    {
        $facts = $this->case('page search read');

        // The browser's detector: 10 agreeing frames, then the code goes into the field (change) and to the page
        self::assertGreaterThanOrEqual(10, $facts['reads']);
        self::assertSame(self::CODE, $facts['input']);
        self::assertSame(1, $facts['changes']);
        self::assertSame([self::CODE], $facts['scanned']);
        self::assertIsArray($facts['after']);
        self::assertFalse($facts['after']['shown'], 'The camera closes after a read');
        self::assertSame('ended', $facts['trackEnded']);
    }

    public function testThePagesOwnScannersStartInTheirOwnMarkup(): void
    {
        foreach (['add form', 'multiscan'] as $name) {
            $facts = $this->case('page scanner ' . $name);

            self::assertNull($facts['ownCamera'], $name . ': renders its camera itself');
            self::assertIsArray($facts['started']);
            self::assertTrue($facts['started']['shown'], $name);
            self::assertTrue($facts['started']['streamLive'], $name);
            self::assertTrue($facts['sameVideo'], $name . ': the stream plays in the server-rendered <video>');
            self::assertTrue($facts['nothingCopied'], $name);
            self::assertSame([0, 0], $facts['searchWrappersEmpty'], $name . ': the page search is not touched');
            self::assertTrue($facts['template'], $name . ': a member page with bars has the search camera');
        }
    }

    private function assertTheSearchScannerRuns(string $surface, string $other): void
    {
        $facts = $this->case('page search ' . $surface);
        $navs = [$surface . '/search/torch', $surface . '/search/zoom_out', $surface . '/search/zoom_in'];

        // Copied in once, the very markup the instance rendered itself before, its HUD on this surface
        self::assertTrue($facts['sameAsBefore'], sprintf("copy:\n%s\nbefore:\n%s", self::text($facts['copy']), self::text($facts['before'])));
        self::assertSame(['muted', 'autoplay', 'playsinline'], $facts['videoAttributes']);
        self::assertSame([
            'shown' => true,
            'children' => 4,
            'videos' => 1,
            'streamLive' => true,
            'playing' => true,
            'hintShown' => true,
            'pillShown' => true,
            'torchShown' => true,
            'torchPressed' => 'false',
            'zoomLabel' => 'Zoom 1.5×',
            'hudNavs' => $navs,
            'toggleActive' => true,
        ], $facts['started']);

        // The copied HUD buttons work: zoom in (1.5 → 2.0), the light on - and the camera is asked for both
        self::assertIsArray($facts['hud']);
        self::assertSame('Zoom 2.0×', $facts['hud']['zoomLabel']);
        self::assertSame('true', $facts['hud']['torchPressed']);
        self::assertIsArray($facts['lastApplied']);
        self::assertContains(['zoom' => 2], $facts['lastApplied']);
        self::assertContains(['torch' => true], $facts['lastApplied']);

        // Stop releases the camera; a second start reuses the copy
        self::assertIsArray($facts['stopped']);
        self::assertFalse($facts['stopped']['shown']);
        self::assertFalse($facts['stopped']['streamLive']);
        self::assertFalse($facts['stopped']['toggleActive']);
        self::assertSame('ended', $facts['stopped']['firstTrack']);
        self::assertIsArray($facts['again']);
        self::assertTrue($facts['again']['shown']);
        self::assertTrue($facts['again']['streamLive']);
        self::assertSame(1, $facts['again']['videos']);
        self::assertSame(4, $facts['again']['children'], 'Nothing copied a second time');
        self::assertTrue($facts['sameVideo']);
        self::assertSame(2, $facts['streams']);

        self::assertSame(0, $facts['otherWrapperChildren'], 'The ' . $other . ' search copies only when it starts itself');
        self::assertTrue($facts['templateUnchanged']);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function case(string $name): array
    {
        $facts = $this->results()[$name] ?? null;
        self::assertIsArray($facts, $name);
        self::assertArrayNotHasKey('threw', $facts, $name . ': ' . self::text($facts['threw'] ?? null));

        /** @var array<string, mixed> $facts */
        return $facts;
    }

    /**
     * @return array<string, mixed>
     */
    private function results(): array
    {
        if (self::$results !== null) {
            return self::$results;
        }

        $browser = self::createClient();
        $browser->disableReboot();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $pages = [];

        foreach (['faq' => '/en/faq', 'add' => '/en/puzzle-add', 'multiscan' => '/en/multiscan'] as $name => $url) {
            $browser->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            $pages[$name] = (string) $browser->getResponse()->getContent();
        }

        // What each GlobalSearch instance rendered into its wrapper before the camera became one template per page
        self::getContainer()->get('translator')->setLocale('en');
        $twig = self::getContainer()->get(Environment::class);
        $expected = [];

        foreach (['topbar', 'menu'] as $surface) {
            $expected[$surface] = '<div class="spinner spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>'
                . '<video class="barcode-scanner-video" data-barcode-scanner-target="video" muted autoplay playsinline></video>'
                . '<canvas data-barcode-scanner-target="overlay" class="position-absolute top-0 start-0 w-100"></canvas>'
                . $twig->render('_barcode_scanner_hud.html.twig', ['hud_nav' => $surface . '/search']);
        }

        $node = new ExecutableFinder()->find('node');
        self::assertIsString($node, 'node is required to execute the controller - it is part of the base image');

        $process = new Process([$node, '--no-warnings', dirname(__DIR__) . '/barcode-scanner-harness.mjs']);
        $process->setInput(json_encode(['pages' => $pages, 'expectedWrapper' => $expected], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return self::$results = $decoded;
    }
}
