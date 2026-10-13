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
 * control_texts.html.twig, assets/controllers/online_control_controller.js) and the review (review_texts.html.twig,
 * online_review_controller.js): a key the JS asks for but the partial misses would show the key itself on the page.
 * Keys built from a value - a status, a flag, an event type, a refusal - are listed from the PHP side, so a new case
 * there fails here until it has its text.
 */
final class OnlineRoundsTextsTest extends KernelTestCase
{
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
        self::assertTexts($texts, ['limit', 'count_entered', 'tile_uploads_pending']);
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

        $missing = array_values(array_diff(array_unique($used), array_keys($texts)));

        self::assertSame([], $missing, 'texts the review uses but review_texts.html.twig does not have');
        self::assertGreaterThan(50, count(array_unique($used)));
        self::assertTexts($texts, ['flag_camera_lost', 'flag_page_hidden', 'flag_offline', 'result_pieces']);
        self::assertSame('Arrived %seconds% s after its moment while the phone kept sending', $texts['flag_arrived_late'] ?? null);
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
     * @return array<string, mixed>
     */
    private function render(string $template): array
    {
        $container = self::bootKernel()->getContainer();
        $request = Request::create('/en/online-rounds/x/control');
        $request->setLocale('en');
        $container->get('request_stack')->push($request);
        $container->get('router')->getContext()->setParameter('_locale', 'en');

        $texts = json_decode(self::getContainer()->get(Environment::class)->render($template), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($texts);

        /** @var array<string, mixed> $texts */
        return $texts;
    }
}
