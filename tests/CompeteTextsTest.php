<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

/**
 * The texts of the participant page's JavaScript (templates/compete/_texts.html.twig) are one JSON object holding every
 * text assets/controllers/compete_controller.js asks for - a key the JS uses but the partial misses would show the key
 * itself to a participant in the middle of a round. Plural texts come as browser_translation() objects
 * ({message, locale}); the checklist rows' texts are `check_<row>_<code>` for every code of CHECK_CODES in
 * assets/online/compete_flow.js. Live video's texts (`video_…`) come only on a page with its video parts (a media server
 * configured), finish detection's (`detection_…`, the checklist row's `detection_check_<code>` for every code of
 * CHECK_CODES in assets/online/compete_detection.js) only on a page whose round detects raised hands - without them the
 * JSON is the Phase 1 JSON, but for the reload banner's two texts (stream SP: a page without the detection parts whose
 * round turns detection on offers a reload - it can not know beforehand that it will).
 */
final class CompeteTextsTest extends KernelTestCase
{
    // Keys built from a prefix in the JS - each listed value must exist
    private const array DYNAMIC = [
        // compete_flow.js readPieces() reasons
        'pieces_invalid_' => ['empty', 'not_a_number', 'too_many'],
        // the connect answers shown as a line (compete_controller.js paintConnect())
        'connect_' => ['belongs_to_other', 'is_other_participant'],
        // the status line's connection (compete_flow.js connectionStatus())
        'status_' => ['online', 'offline', 'problem'],
        // live video (assets/online/compete_video.js): the quiet line, the invitation's ends and errors, the studio panel
        'video_status_' => ['connecting', 'live', 'weak', 'retrying', 'off'],
        'video_outcome_' => ['thanks', 'left', 'moved_on', 'expired', 'withdrawn', 'declined', 'unavailable'],
        'video_invite_error_' => ['offline', 'failed'],
        'video_studio_' => ['joining', 'on_air', 'leaving'],
    ];

    // Chosen in a ternary in assets/online/compete_detection_page.js
    private const array DETECTION_INDIRECT = ['detection_test', 'detection_test_again'];

    // Chosen in a ternary or passed on - not visible to the t('…') pattern
    private const array INDIRECT = ['pieces_question_left', 'pieces_question_placed', 'pieces_reported_left', 'pieces_reported_placed'];

    private const array PLURAL = ['time_limit', 'status_pending', 'check_uploads_pending', 'check_uploads_refused', 'video_strip_title'];

    // The reload banner's texts - on every page (stream SP)
    private const array RELOAD_TEXTS = ['detection_turned_on', 'detection_turned_on_reload'];

    public function testEveryTextThePageScriptUsesIsInTheJson(): void
    {
        $texts = $this->render();
        $used = [];

        foreach ([__DIR__ . '/../assets/controllers/compete_controller.js', __DIR__ . '/../assets/online/compete_video.js', __DIR__ . '/../assets/online/compete_video_page.js', __DIR__ . '/../assets/online/compete_detection_page.js'] as $file) {
            preg_match_all("/\\bt[c]?\\('([A-Za-z0-9_]+)'/", (string) file_get_contents($file), $matches);
            $used = [...$used, ...$matches[1]];
        }

        foreach (self::DYNAMIC as $prefix => $values) {
            foreach ($values as $value) {
                $used[] = $prefix . $value;
            }
        }

        foreach (self::checkCodes() as $row => $codes) {
            foreach ($codes as $code) {
                $used[] = 'check_' . $row . '_' . $code;
            }
        }

        foreach (self::detectionCheckCodes() as $code) {
            $used[] = 'detection_check_' . $code;
        }

        $used = [...$used, ...self::INDIRECT, ...self::DETECTION_INDIRECT];
        $missing = array_values(array_diff(array_unique($used), array_keys($texts)));

        self::assertSame([], $missing, 'texts the JS uses but templates/compete/_texts.html.twig does not have');
        self::assertGreaterThan(50, count(array_unique($used)));
    }

    public function testWithoutLiveVideoNoVideoTextIsThereAndThePhaseOneTextsComeFirst(): void
    {
        $without = $this->render(video: false, detection: false);
        $with = $this->render(detection: false);

        self::assertSame([], array_values(array_filter(array_keys($without), static fn (string $key): bool => str_starts_with($key, 'video_'))));
        self::assertSame($without, array_slice($with, 0, count($without), true), 'the Phase 1 texts, then the video ones');
        self::assertSame([], array_values(array_filter(array_keys(array_slice($with, count($without), null, true)), static fn (string $key): bool => !str_starts_with($key, 'video_'))));
    }

    public function testWithoutDetectionNoDetectionTextIsThereAndItsTextsComeLast(): void
    {
        $without = $this->render(detection: false);
        $with = $this->render();

        self::assertSame(self::RELOAD_TEXTS, array_values(array_filter(array_keys($without), static fn (string $key): bool => str_starts_with($key, 'detection_'))));
        self::assertSame($without, array_slice($with, 0, count($without), true), 'every other text, then the detection ones');
        $added = array_keys(array_slice($with, count($without), null, true));
        self::assertNotSame([], $added);
        self::assertSame([], array_values(array_filter($added, static fn (string $key): bool => !str_starts_with($key, 'detection_'))));

        self::assertSame('Finish recorded at %time%', $with['detection_recorded'] ?? null);
        self::assertSame('Raise both hands for a second (%seconds% s)', $with['detection_check_testing'] ?? null);
        self::assertSame('Detection may miss your finish - use the Finished button', $with['detection_check_failed'] ?? null);
    }

    public function testPluralTextsCarryTheirMessageAndLocaleAndPlainTextsAreStrings(): void
    {
        $texts = $this->render();

        foreach ($texts as $key => $text) {
            if (in_array($key, self::PLURAL, true)) {
                self::assertIsArray($text, $key);
                self::assertSame('en', $text['locale'] ?? null, $key);
                $message = $text['message'] ?? null;
                self::assertIsString($message, $key);
                self::assertStringContainsString('%count%', $message, $key);
            } else {
                self::assertIsString($text, $key);
                self::assertStringNotContainsString('compete.', $text, $key . ' - an untranslated key');
            }
        }

        self::assertSame(['message' => 'Time limit: 1 minute|Time limit: %count% minutes', 'locale' => 'en'], $texts['time_limit'] ?? null);
        self::assertSame(['message' => '1 other participant|%count% other participants', 'locale' => 'en'], $texts['video_strip_title'] ?? null);
        self::assertSame('Your time in the studio is over - thanks!', $texts['video_outcome_moved_on'] ?? null);
        self::assertSame('On - %width% × %height%', $texts['check_camera_live'] ?? null);
        self::assertSame('Synced (± %ms% ms)', $texts['check_clock_synced'] ?? null);
        self::assertSame('I tapped by mistake (%seconds%)', $texts['undo'] ?? null);
    }

    /**
     * CHECK_CODES of assets/online/compete_detection.js - a plain list of strings.
     *
     * @return list<string>
     */
    private static function detectionCheckCodes(): array
    {
        $source = (string) file_get_contents(__DIR__ . '/../assets/online/compete_detection.js');
        self::assertSame(1, preg_match('/export const CHECK_CODES = (\[.*?\]);/s', $source, $match), 'CHECK_CODES in compete_detection.js');
        $codes = json_decode(str_replace("'", '"', $match[1]), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($codes);
        self::assertGreaterThanOrEqual(10, count($codes));

        return array_values(array_map(strval(...), array_filter($codes, is_string(...))));
    }

    /**
     * CHECK_CODES of assets/online/compete_flow.js - a plain object literal of string lists.
     *
     * @return array<string, list<string>>
     */
    private static function checkCodes(): array
    {
        $source = (string) file_get_contents(__DIR__ . '/../assets/online/compete_flow.js');
        self::assertSame(1, preg_match('/export const CHECK_CODES = (\{.*?\});/s', $source, $match), 'CHECK_CODES in compete_flow.js');

        $json = (string) preg_replace(["/'/", '/(\w+):/', '/,(\s*[\]}])/'], ['"', '"$1":', '$1'], $match[1]);
        $codes = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($codes);
        self::assertSame(['camera', 'clock', 'wake', 'connection', 'uploads', 'emptyMat'], array_keys($codes));

        $result = [];

        foreach ($codes as $row => $list) {
            self::assertIsArray($list);
            $result[(string) $row] = array_values(array_map(strval(...), array_filter($list, is_string(...))));
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function render(bool $video = true, bool $detection = true): array
    {
        $container = self::bootKernel()->getContainer();
        $request = Request::create('/en/compete/x');
        $request->setLocale('en');
        $container->get('request_stack')->push($request);
        $container->get('router')->getContext()->setParameter('_locale', 'en');

        // The page passes `video` only with a media server configured (CompetePageController)
        // The page passes `detection` only when the round's detection is not off (CompetePageController)
        $json = self::getContainer()->get(Environment::class)->render('compete/_texts.html.twig', [
            ...($video ? ['video' => ['urls' => []]] : []),
            ...($detection ? ['detection' => ['assets' => []]] : []),
        ]);
        $texts = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($texts);

        /** @var array<string, mixed> $texts */
        return $texts;
    }
}
