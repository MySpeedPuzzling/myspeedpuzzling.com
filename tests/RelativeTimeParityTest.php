<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Services\RelativeTimeFormatter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The feed's "… ago" labels count up in the browser (assets/relative_time.js) after the server rendered
 * them with Twig `|ago`; both must word every label the same, or a label changes wording the second
 * JavaScript takes over. Runs the real module under node against RelativeTimeFormatter, in every
 * locale, with the hand-written `time` translations and their {1} / [2, Inf] intervals.
 */
final class RelativeTimeParityTest extends KernelTestCase
{
    private const array LOCALES = ['cs', 'de', 'en', 'es', 'fr', 'ja'];

    // Every unit with its singular, the cs 2-4 and 5+ forms, the {1}/{2} day intervals and each unit's last second
    private const array ELAPSED_SECONDS = [
        0, 1, 2, 3, 4, 5, 11, 21, 22, 59,
        60, 61, 119, 120, 121, 179, 180, 299, 300, 1199, 3599,
        3600, 3601, 7199, 7200, 10800, 14400, 18000, 39600, 79200, 86399,
        86400, 172799, 172800, 259200, 345600, 432000, 950400, 1814400, 2419199,
    ];

    // Different month lengths behind each "now", PHP counting days across them
    private const array NOWS = ['2026-03-20 12:00:00', '2026-10-02 08:30:15'];

    public function testTheBrowserWordsEveryLabelLikeTwigAgo(): void
    {
        $formatter = $this->formatter();
        $cases = [];
        $expected = [];

        foreach (self::LOCALES as $locale) {
            $messages = $formatter->browserMessages($locale);

            foreach (self::NOWS as $now) {
                $nowAt = new DateTimeImmutable($now, new DateTimeZone('UTC'));

                foreach (self::ELAPSED_SECONDS as $elapsed) {
                    $cases[] = ['elapsed' => $elapsed, 'messages' => $messages, 'locale' => $locale];
                    $expected[] = sprintf('%s %d s: %s', $locale, $elapsed, $formatter->formatDiff($nowAt->modify(sprintf('-%d seconds', $elapsed)), $nowAt, $locale));
                }
            }
        }

        $actual = [];

        foreach ($this->runInNode($cases) as $index => $result) {
            $actual[] = sprintf('%s %d s: %s', $cases[$index]['locale'], $cases[$index]['elapsed'], $result['text'] ?? 'null');
        }

        self::assertSame($expected, $actual);
    }

    public function testMonthsStayWithTheServerAndAClockRunningSlowReadsNow(): void
    {
        $messages = $this->formatter()->browserMessages('en');
        $elapsed = [28 * 86400, 400 * 86400, -5, -0.4, 59.25, 61, 3600, 86400 * 27 + 3600];
        $cases = array_map(static fn (int|float $seconds): array => ['elapsed' => $seconds, 'messages' => $messages, 'locale' => 'en'], $elapsed);

        self::assertSame([
            ['text' => null, 'changesIn' => null],
            ['text' => null, 'changesIn' => null],
            ['text' => 'now', 'changesIn' => 6],
            ['text' => 'now', 'changesIn' => 1.4],
            ['text' => '59 seconds ago', 'changesIn' => 0.75],
            ['text' => '1 minute ago', 'changesIn' => 59],
            ['text' => '1 hour ago', 'changesIn' => 3600],
            // 27 days 1 hour: the next change is "28 days", which is the server's again
            ['text' => '27 days ago', 'changesIn' => 82800],
        ], $this->runInNode($cases));
    }

    private function formatter(): RelativeTimeFormatter
    {
        return self::getContainer()->get(RelativeTimeFormatter::class);
    }

    /**
     * @param list<array{elapsed: int|float, messages: array<string, string>, locale: string}> $cases
     * @return list<array{text: null|string, changesIn: null|int|float}>
     */
    private function runInNode(array $cases): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/relative-time-harness.mjs']);
        $process->setInput((string) json_encode($cases, JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<array{text: null|string, changesIn: null|int|float}> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
