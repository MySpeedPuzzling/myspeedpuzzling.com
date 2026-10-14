<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use SpeedPuzzling\Web\Results\OnlineReviewFlag;
use SpeedPuzzling\Web\Value\OnlineEventType;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use SpeedPuzzling\Web\Value\OnlineReviewAssessment;
use SpeedPuzzling\Web\Value\RoundStopwatchRefusal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

/**
 * The texts of the organiser's online round pages' JavaScript - the control room (templates/online_rounds/
 * control_texts.html.twig, assets/controllers/online_control_controller.js), its console with live video (the same
 * partial with video_console, assets/online/console_video.js), the review (review_texts.html.twig,
 * online_review_controller.js) and the host's studio (studio_texts.html.twig, online_studio_controller.js): a key the
 * JS asks for but the partial misses would show the key itself on the page. Keys built from a value - a status, a flag,
 * an event type, a refusal - are listed from the PHP side (or the JS module that makes the value), so a new case there
 * fails here until it has its text.
 */
final class OnlineRoundsTextsTest extends KernelTestCase
{
    // A moderator's video connection (assets/online/console_room.js RoomLink)
    private const array CONNECTION_STATES = ['connecting', 'connected', 'reconnecting', 'failed', 'duplicate', 'unavailable', 'sign_in', 'forbidden'];

    // A refused invite / "Thank you" (console_room.js studioRefusal(): the endpoints' codes, then the connection's)
    private const array STUDIO_REFUSALS = ['already_invited', 'not_entered', 'participant_not_found', 'video_unavailable', 'invite_not_found', 'round_not_online', 'sign_in', 'forbidden', 'offline', 'failed'];

    public function testEveryTextTheControlRoomAsksForIsThere(): void
    {
        $texts = $this->render('online_rounds/control_texts.html.twig');
        $used = self::literalKeys(__DIR__ . '/../assets/controllers/online_control_controller.js');

        // Built from a value
        foreach (['waiting', 'scheduled', 'countdown', 'running', 'ended'] as $screen) {
            $used[] = 'status_' . $screen;
        }

        foreach (['ready', 'waiting', 'not_seen'] as $readiness) {
            $used[] = 'tile_' . $readiness;
        }

        foreach (['s', 'min', 'h'] as $unit) {
            $used[] = 'unit_' . $unit;
        }

        foreach (['sign_in', 'forbidden', 'gone'] as $connection) {
            $used[] = 'status_' . $connection;
        }

        // A refused stopwatch action: the stopwatch's own refusals, the controller's errors and the page's own
        foreach (RoundStopwatchRefusal::cases() as $refusal) {
            $used[] = 'refused_' . $refusal->value;
        }

        foreach (['invalid_csrf', 'scheduling_unavailable', 'sign_in', 'forbidden', 'offline', 'failed'] as $error) {
            $used[] = 'error_' . $error;
        }

        $missing = array_values(array_diff(array_unique($used), array_keys($texts)));

        self::assertSame([], $missing, 'texts the control room uses but control_texts.html.twig does not have');
        self::assertGreaterThan(40, count(array_unique($used)));
        self::assertTexts($texts, ['limit', 'count_entered', 'tile_uploads_pending', 'tile_reported_left', 'tile_reported_placed']);
    }

    public function testEveryTextTheReviewAsksForIsThere(): void
    {
        $texts = $this->render('online_rounds/review_texts.html.twig');
        $used = self::literalKeys(__DIR__ . '/../assets/controllers/online_review_controller.js');

        foreach ([OnlineReviewAssessment::STATUS_NEEDS_LOOK, OnlineReviewAssessment::STATUS_CONFIRMED, OnlineReviewAssessment::STATUS_UNFINISHED, OnlineReviewAssessment::STATUS_NO_FINISH] as $status) {
            $used[] = 'status_' . $status;
        }

        foreach ((new \ReflectionClass(OnlineReviewFlag::class))->getConstants() as $code) {
            self::assertIsString($code);
            $used[] = 'flag_' . $code;
        }

        foreach (OnlineEventType::cases() as $type) {
            // Upload reports are not in the timeline - the media rows are
            if ($type !== OnlineEventType::MediaUploaded) {
                $used[] = 'event_' . str_replace('.', '_', $type->value);
            }
        }

        foreach (OnlineMediaKind::cases() as $kind) {
            $used[] = 'photo_' . $kind->value;
        }

        foreach (['button', 'reviewer', 'detection'] as $source) {
            $used[] = 'source_' . $source;
        }

        // A finish candidate's own words (Phase 2: `${key}_candidate`, chosen by the moment's kind)
        foreach (['burst_none', 'burst_pending', 'burst_never_arrived'] as $key) {
            $used[] = $key;
            $used[] = $key . '_candidate';
        }

        foreach (['burst_title_tap', 'burst_title_candidate', 'photo_title_tap', 'photo_title_candidate', 'to_tap', 'to_candidate', 'view_angled', 'view_top_down', 'moment_moved_candidate'] as $key) {
            $used[] = $key;
        }

        $missing = array_values(array_diff(array_unique($used), array_keys($texts)));

        self::assertSame([], $missing, 'texts the review uses but review_texts.html.twig does not have');
        self::assertGreaterThan(50, count(array_unique($used)));
        self::assertTexts($texts, ['flag_camera_lost', 'flag_page_hidden', 'flag_offline', 'result_pieces', 'reported_left', 'reported_placed']);
        self::assertSame('Finished tap reached us %seconds% s late, though the phone was online the whole time', $texts['flag_arrived_late'] ?? null);
    }

    public function testEveryTextTheConsoleAsksForIsThere(): void
    {
        $texts = $this->render('online_rounds/control_texts.html.twig', ['video_console' => true]);
        $used = self::quotedKeys(__DIR__ . '/../assets/online/console_video.js', '/^video_[a-z_]+$/');

        // Built from a value: a tile's video (console_grid.js tileVideoState()), the connection (console_room.js
        // RoomLink), a microphone that failed (console_talk.js MIC_ERRORS), an invitation's state (console_invites.js
        // inviteView(), on air apart), a refused invite / end (console_room.js studioRefusal())
        foreach (['connecting', 'in_studio', 'not_joined', 'no_camera', 'paused', 'loading', 'playing'] as $state) {
            $used[] = 'video_tile_' . $state;
        }

        foreach (self::CONNECTION_STATES as $state) {
            $used[] = 'video_connection_' . $state;
        }

        foreach (['mic_denied', 'mic_missing', 'mic_failed', 'not_connected'] as $error) {
            $used[] = 'video_talk_error_' . $error;
        }

        foreach (['invited', 'joined', 'on_air', 'declined', 'expired', 'ended', 'withdrawn'] as $status) {
            $used[] = 'video_studio_' . $status;
        }

        foreach (self::STUDIO_REFUSALS as $refusal) {
            $used[] = 'video_studio_error_' . $refusal;
        }

        $missing = array_values(array_diff(array_unique($used), array_keys($texts)));

        self::assertSame([], $missing, 'texts the console uses but control_texts.html.twig does not have with video');
        self::assertGreaterThan(50, count(array_unique($used)));
        self::assertTexts($texts, ['limit', 'count_entered', 'tile_uploads_pending', 'tile_reported_left', 'tile_reported_placed']);

        // Without live video: none of them
        self::assertSame([], array_values(array_filter(array_keys($this->render('online_rounds/control_texts.html.twig')), static fn (string $key): bool => str_starts_with($key, 'video_'))));
    }

    public function testEveryTextTheStudioAsksForIsThere(): void
    {
        $texts = $this->render('online_rounds/studio_texts.html.twig');
        $used = [
            ...self::quotedKeys(__DIR__ . '/../assets/controllers/online_studio_controller.js', '/^(stage|controls|lists|status|error|connection)_[a-z_]+$/'),
            ...array_values(array_intersect(['live', 'not_live', 'audience', 'working', 'thank_you', 'withdraw'], self::quotedKeys(__DIR__ . '/../assets/controllers/online_studio_controller.js', '/^[a-z_]+$/'))),
        ];

        foreach (self::CONNECTION_STATES as $state) {
            $used[] = 'connection_' . $state;
        }

        foreach (['camera_denied', 'camera_missing', 'camera_failed', 'share_failed', 'not_connected'] as $error) {
            $used[] = 'error_' . $error;
        }

        foreach (self::STUDIO_REFUSALS as $refusal) {
            $used[] = 'error_end_' . $refusal;
        }

        // The earlier invitations (console_studio.js studioSections())
        foreach (['declined', 'expired', 'ended', 'withdrawn'] as $status) {
            $used[] = 'status_' . $status;
        }

        $missing = array_values(array_diff(array_unique($used), array_keys($texts)));

        self::assertSame([], $missing, 'texts the studio uses but studio_texts.html.twig does not have');
        self::assertGreaterThan(40, count(array_unique($used)));
        self::assertContains('audience', $used);
        self::assertTexts($texts, ['audience']);
    }

    /**
     * Plural texts are browser_translation() objects with their message and locale, every other text a string
     *
     * @param array<string, mixed> $texts
     * @param list<string> $plural
     */
    private static function assertTexts(array $texts, array $plural): void
    {
        foreach ($texts as $key => $text) {
            if (in_array($key, $plural, true)) {
                self::assertIsArray($text, $key);
                self::assertSame('en', $text['locale'] ?? null, $key);
                self::assertIsString($text['message'] ?? null, $key);
                self::assertStringContainsString('|', $text['message'], $key);
            } else {
                self::assertIsString($text, $key);
                self::assertStringNotContainsString('online_rounds.', $text, $key . ' - an untranslated key');
                self::assertStringNotContainsString('competition.stopwatch.', $text, $key . ' - an untranslated key');
            }
        }
    }

    /**
     * The keys a controller asks for with t('…') / tc('…'), and the 'done_…' keys it passes on
     *
     * @return list<string>
     */
    private static function literalKeys(string $file): array
    {
        $source = (string) file_get_contents($file);
        preg_match_all("/\\bt[c]?\\('([A-Za-z0-9_]+)'/", $source, $calls);
        preg_match_all("/'(done_[a-z_]+)'/", $source, $done);

        return [...$calls[1], ...$done[1]];
    }

    /**
     * The single-quoted strings of a JS file that look like text keys (a ternary between two keys included)
     *
     * @return list<string>
     */
    private static function quotedKeys(string $file, string $pattern): array
    {
        preg_match_all("/'([a-z][a-z0-9_]*)'/", (string) file_get_contents($file), $matches);

        return array_values(array_unique(array_filter($matches[1], static fn (string $key): bool => preg_match($pattern, $key) === 1)));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function render(string $template, array $context = []): array
    {
        $container = self::bootKernel()->getContainer();
        $request = Request::create('/en/online-rounds/x/control');
        $request->setLocale('en');
        $container->get('request_stack')->push($request);
        $container->get('router')->getContext()->setParameter('_locale', 'en');

        $texts = json_decode(self::getContainer()->get(Environment::class)->render($template, $context), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($texts);

        /** @var array<string, mixed> $texts */
        return $texts;
    }
}
