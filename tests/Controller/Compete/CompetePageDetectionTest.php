<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Compete;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineDetectorAssets;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The participant page's finish detection parts (docs/features/online-competitions/README.md §5.7, stream P2I): only
 * when the round's detection is not off does the page carry them - the detection value (where the detector's runtime and
 * models come from: OnlineDetectorAssets, the one place), the checklist's row with its test / retry / skip, the waiting
 * screen's set-up test (stream RP), the running screen's "Finish recorded" and its quiet lines, time's up after a
 * detected finish, the green flash and the texts. A
 * round whose detection is off is the page without them, byte for byte - but for the two texts of the banner such a page
 * shows when the organiser turns detection on ("reload the page to use it", stream SP: built by the script only then,
 * its text can come from nowhere else); the parts are the only difference, and they cost no statement.
 */
final class CompetePageDetectionTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string PAGE = '/en/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS;

    // The only detection texts a page without the detection parts carries: the reload banner's (stream SP)
    private const array RELOAD_TEXTS = ['detection_turned_on', 'detection_turned_on_reload'];

    // Every trace the detection parts leave in the page
    private const array TRACES = [
        'data-compete-detection-value',
        'data-compete-detection=',
        'detectionCheck',
        'compete#detection',
        'compete-detected',
        'compete-detection-line',
        'compete-detection-flash',
        'compete-detection-waiting',
        'detectionWaiting',
        'compete#timeupComplete',
        'jsdelivr',
        'mediapipe',
    ];

    public function testARoundWithoutDetectionHasNoTraceOfIt(): void
    {
        $html = $this->page(OnlineDetectionMode::Off);

        foreach (self::TRACES as $trace) {
            self::assertStringNotContainsString($trace, $html, $trace);
        }

        $texts = self::texts($html);
        self::assertSame(self::RELOAD_TEXTS, array_values(array_filter(array_keys($texts), static fn (string $key): bool => str_starts_with($key, 'detection_'))));
        self::assertSame('The organiser turned on finish detection - reload the page to use it.', $texts['detection_turned_on']);
        self::assertSame('Reload', $texts['detection_turned_on_reload']);
        // The banner itself is built by the script when it is needed - no element of it in the page
        self::assertStringNotContainsString('compete-banner--reload', $html);
    }

    public function testARoundWithDetectionCarriesItsParts(): void
    {
        $html = $this->page(OnlineDetectionMode::On);
        $crawler = new Crawler($html);

        $detection = json_decode((string) $crawler->filter('.compete')->attr('data-compete-detection-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($detection);
        self::assertSame(self::getContainer()->get(OnlineDetectorAssets::class)->urls(), $detection['assets'] ?? null, 'the one place the page loads the detector from');
        self::assertSame([
            'tasksVisionUrl' => 'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@1.1.0/vision_bundle.mjs',
            'wasmBaseUrl' => 'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@1.1.0/wasm',
            'poseModelUrl' => 'https://storage.googleapis.com/mediapipe-models/pose_landmarker/pose_landmarker_lite/float16/1/pose_landmarker_lite.task',
            'gestureModelUrl' => 'https://storage.googleapis.com/mediapipe-models/gesture_recognizer/gesture_recognizer/float16/1/gesture_recognizer.task',
        ], $detection['assets'], 'pinned versions - never latest');

        // The checklist's row, in the checks, before the charger
        $row = $crawler->filter('[data-compete-screen="checklist"] .compete-checks [data-compete-detection="check"]');
        self::assertCount(1, $row);
        self::assertSame('Finish detection', trim($row->filter('.compete-check-label')->text()));
        self::assertCount(1, $row->filter('[data-compete-field="detectionCheck"]'));

        foreach (['detectionTest' => 'compete#detectionTest', 'detectionRetry' => 'compete#detectionRetry', 'detectionSkip' => 'compete#detectionSkip'] as $part => $action) {
            $button = $row->filter(sprintf('[data-compete-show="%s"]', $part));
            self::assertCount(1, $button, $part);
            self::assertNotNull($button->attr('hidden'), $part);
            self::assertSame($action, $button->attr('data-action'));
        }

        // The waiting screen (stream RP): the set-up test offered to a page that came back untested - hidden until the
        // script says so, its "Test" the checklist's action
        $waiting = $crawler->filter('[data-compete-screen="waiting"] [data-compete-detection="waiting"]');
        self::assertCount(1, $waiting);
        self::assertSame('detectionWaiting', $waiting->attr('data-compete-show'));
        self::assertNotNull($waiting->attr('hidden'));
        self::assertSame('Finish detection', trim($waiting->filter('.compete-check-label')->text()));
        self::assertCount(1, $waiting->filter('[data-compete-field="detectionCheck"]'));
        self::assertSame('compete#detectionTest', $waiting->filter('[data-compete-show="detectionTest"]')->attr('data-action'));

        // The running screen: "Finish recorded" above the Finished button, which stays
        $running = $crawler->filter('[data-compete-screen="running"]');
        foreach (['detectionDetected', 'detectionHint', 'detectionSuppressed', 'detectionButtonOnly'] as $part) {
            $element = $running->filter(sprintf('[data-compete-show="%s"]', $part));
            self::assertCount(1, $element, $part);
            self::assertNotNull($element->attr('hidden'), $part);
        }

        self::assertCount(1, $running->filter('[data-compete-show="detectionDetected"] [data-compete-field="detectionRecorded"]'));
        self::assertCount(1, $running->filter('[data-action*="compete#finish"]'), 'the Finished button stays the fallback');
        self::assertStringContainsString('If a piece is missing, keep going and raise your hands again.', $running->text());

        // Time's up after a detected finish
        $timeUp = $crawler->filter('[data-compete-screen="timeup"] [data-compete-show="detectionTimeUp"]');
        self::assertCount(1, $timeUp);
        self::assertNotNull($timeUp->attr('hidden'));
        self::assertSame('compete#timeupComplete', $timeUp->filter('button')->attr('data-action'));

        self::assertCount(1, $crawler->filter('.compete > .compete-detection-flash[aria-hidden="true"]'));

        $texts = self::texts($html);
        self::assertSame('Finish recorded at %time%', $texts['detection_recorded'] ?? null);
        self::assertSame('Preparing the camera check…', $texts['detection_check_loading'] ?? null);

        // Shadow carries the same parts - the mode is read live from the state, shadow shows nothing
        $shadow = $this->page(OnlineDetectionMode::Shadow);
        self::assertSame(self::normalised($html), self::normalised($shadow));
    }

    /**
     * With the parts stripped, the page of a round with detection is the page of one without - nothing else moves
     */
    public function testTheDetectionPartsAreTheOnlyDifference(): void
    {
        $with = $this->page(OnlineDetectionMode::On);
        $without = $this->page(OnlineDetectionMode::Off);

        $stripped = (string) preg_replace([
            '/ data-compete-detection-value="[^"]*"/',
            '/\s*<li class="compete-check" data-compete-detection="check".*?<\/li>/s',
            '/\s*<div class="compete-detected" data-compete-show="detectionDetected".*?<\/div>\s*<p class="compete-detection-line".*?<p class="compete-detection-line".*?<\/p>\s*<p class="compete-detection-line".*?<\/p>/s',
            '/\s*<div class="compete-detected compete-detected--timeup".*?<\/div>/s',
            '/\s*<div class="compete-check compete-detection-waiting".*?<\/span>\s*<\/div>\s*<\/div>/s',
            '/\s*<div class="compete-detection-flash" aria-hidden="true"><\/div>/',
        ], '', $with);

        self::assertSame(self::normalised($without), self::normalised($stripped));

        $textsWithout = self::texts($without);
        $textsWith = self::texts($with);
        self::assertSame($textsWithout, array_slice($textsWith, 0, count($textsWithout), true));
    }

    public function testTheDetectionPartsCostNoStatement(): void
    {
        $with = $this->statementsOf(OnlineDetectionMode::On);
        $without = $this->statementsOf(OnlineDetectionMode::Off);

        self::assertSame($without, $with);
    }

    private function page(OnlineDetectionMode $mode): string
    {
        self::ensureKernelShutdown();
        $browser = self::createClient();
        self::setDetection($this->connection($browser), $mode);
        $browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();

        return (string) $browser->getResponse()->getContent();
    }

    private function statementsOf(OnlineDetectionMode $mode): int
    {
        self::ensureKernelShutdown();
        $browser = self::createClient();
        self::setDetection($this->connection($browser), $mode);
        // Warm-up: whatever the page caches, the counted request finds it cached
        $browser->request('GET', self::PAGE);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }

    private static function setDetection(Connection $connection, OnlineDetectionMode $mode): void
    {
        $connection->update('online_round', ['detection_mode' => $mode->value], ['id' => OnlineRoundsFixture::ONLINE_ROUND_SOLO]);
    }

    private function connection(KernelBrowser $browser): Connection
    {
        return $browser->getContainer()->get(Connection::class);
    }

    /**
     * @return array<string, mixed>
     */
    private static function texts(string $html): array
    {
        $texts = json_decode((string) (new Crawler($html))->filter('.compete')->attr('data-compete-texts-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($texts);

        /** @var array<string, mixed> $texts */
        return $texts;
    }

    /**
     * The page without what differs per request (the state: times, the Mercure token; nonces) and without the texts
     * (compared on their own), whitespace collapsed
     */
    private static function normalised(string $html): string
    {
        $html = (string) preg_replace('/<script type="application\/json" data-compete-target="initialState">.*?<\/script>/s', '<STATE/>', $html);
        $html = (string) preg_replace('/(data-compete-texts-value|(?<=\s)nonce|(?<=\s)content)="[^"]*"/', '$1="X"', $html);

        return (string) preg_replace('/\s+/', ' ', $html);
    }
}
