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
 * assets/online/compete_flow.js.
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
    ];

    // Chosen in a ternary or passed on - not visible to the t('…') pattern
    private const array INDIRECT = ['pieces_question_left', 'pieces_question_placed', 'pieces_reported_left', 'pieces_reported_placed'];

    private const array PLURAL = ['time_limit', 'status_pending', 'check_uploads_pending', 'check_uploads_refused'];

    public function testEveryTextThePageScriptUsesIsInTheJson(): void
    {
        $texts = $this->render();
        $used = [];

        foreach ([__DIR__ . '/../assets/controllers/compete_controller.js'] as $file) {
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

        $used = [...$used, ...self::INDIRECT];
        $missing = array_values(array_diff(array_unique($used), array_keys($texts)));

        self::assertSame([], $missing, 'texts the JS uses but templates/compete/_texts.html.twig does not have');
        self::assertGreaterThan(50, count(array_unique($used)));
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
        self::assertSame('On - %width% × %height%', $texts['check_camera_live'] ?? null);
        self::assertSame('Synced (± %ms% ms)', $texts['check_clock_synced'] ?? null);
        self::assertSame('I tapped by mistake (%seconds%)', $texts['undo'] ?? null);
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
    private function render(): array
    {
        $container = self::bootKernel()->getContainer();
        $request = Request::create('/en/compete/x');
        $request->setLocale('en');
        $container->get('request_stack')->push($request);
        $container->get('router')->getContext()->setParameter('_locale', 'en');

        $json = self::getContainer()->get(Environment::class)->render('compete/_texts.html.twig');
        $texts = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($texts);

        /** @var array<string, mixed> $texts */
        return $texts;
    }
}
