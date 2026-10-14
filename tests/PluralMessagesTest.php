<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every plural message of translations/ - every domain, every root - renders for every count in every language.
 * Symfony's plural format (TranslatorTrait::trans(), mirrored in the browser by assets/translation_choice.js) tries the
 * explicit intervals first ({0}, {1}, [2,4], ]1,Inf[, [0,Inf[), then the locale's plural position among the other
 * parts (Japanese always the first, Czech 1 / 2-4 / 0 and 5+): when nothing fits, PHP throws (a 500, a failed e-mail)
 * and the browser shows a bare number. `{1}one|]1,Inf[many` has no form for 0 - write `{1}one|[0,Inf[many`. A part
 * starting with one ASCII word and a colon ("Zeitlimit: 1 Minute") is read as a labelled part - the word disappears.
 *
 * A message holding a `|` in any language is a plural message here, so keys added later are covered without a list.
 * Each one is rendered through the real translator in every language of translations/ with the counts 0-30, 100 and
 * 1000 and every placeholder filled - also in a language that lacks the key (the fallback's message is then chosen by
 * that language's rules). Only the messages of translations/ are judged, never those of vendor packages.
 * tests/OnlinePluralMessagesTest.php checks the online competition roots the same way.
 */
final class PluralMessagesTest extends KernelTestCase
{
    private const array EXTRA_COUNTS = [100, 1000];

    /**
     * Messages with a literal `|` that are never translated with %count% - not plural messages. domain => key => why
     */
    private const array NOT_PLURAL = [
        'messages' => [
            'for_developers.meta.title' => 'a page title "… / API | MySpeedPuzzling" translated without a count (for-developers.html.twig)',
        ],
    ];

    public function testEveryPluralMessageRendersForEveryCountInEveryLanguage(): void
    {
        $translator = self::translator();
        $failures = [];
        $pluralCount = 0;

        foreach (self::ownMessages() as $domain => $messages) {
            $plural = self::pluralKeys($domain, $messages);
            $pluralCount += count($plural);

            foreach (array_keys($messages) as $locale) {
                foreach ($plural as $key) {
                    $parameters = self::placeholdersOf($key, $messages);

                    foreach ([...range(0, 30), ...self::EXTRA_COUNTS] as $count) {
                        $parameters['%count%'] = $count;

                        try {
                            $text = $translator->trans($key, $parameters, $domain, $locale);
                        } catch (\Throwable $e) {
                            $failures[] = sprintf('%s %s:%s (count %d): %s', $locale, $domain, $key, $count, $e->getMessage());

                            continue;
                        }

                        if (trim($text) === '') {
                            $failures[] = sprintf('%s %s:%s (count %d): an empty text', $locale, $domain, $key, $count);
                        }
                    }
                }
            }
        }

        self::assertSame([], $failures, 'plural messages that do not render for every count');
        // Hundreds of plural messages - a handful would mean the catalogues were not read
        self::assertGreaterThan(300, $pluralCount);
    }

    public function testNoPluralPartIsReadAsALabel(): void
    {
        $failures = [];

        foreach (self::ownMessages() as $domain => $messages) {
            foreach (self::pluralKeys($domain, $messages) as $key) {
                foreach ($messages as $locale => $catalogue) {
                    if (!isset($catalogue[$key])) {
                        continue;
                    }

                    foreach (self::parts($catalogue[$key]) as $part) {
                        // An interval part starts with {, [ or ] - only the other parts are read for a label
                        if (preg_match('/^\w+\:/', $part) === 1) {
                            $failures[] = sprintf('%s %s:%s: "%s"', $locale, $domain, $key, $part);
                        }
                    }
                }
            }
        }

        self::assertSame([], $failures, 'plural parts starting with "word:" lose the word (write the label another way, or start the part with an interval)');
    }

    public function testEveryMessageLeftOutIsStillOneWithALiteralPipeAndNoCount(): void
    {
        $ownMessages = self::ownMessages();

        foreach (self::NOT_PLURAL as $domain => $keys) {
            foreach (array_keys($keys) as $key) {
                $pipe = false;

                foreach ($ownMessages[$domain] ?? [] as $locale => $catalogue) {
                    self::assertArrayHasKey($key, $catalogue, sprintf('%s %s:%s is gone - remove it from NOT_PLURAL', $locale, $domain, $key));
                    self::assertStringNotContainsString('%count%', $catalogue[$key], sprintf('%s %s:%s takes a count - it is a plural message', $locale, $domain, $key));
                    $pipe = $pipe || str_contains($catalogue[$key], '|');
                }

                self::assertTrue($pipe, sprintf('%s:%s holds no "|" any more - remove it from NOT_PLURAL', $domain, $key));
            }
        }
    }

    private static function translator(): TranslatorInterface
    {
        return self::getContainer()->get(TranslatorInterface::class);
    }

    /**
     * The messages each file of translations/ defines itself (no fallback, no vendor package), by domain and locale
     *
     * @return array<string, array<string, array<string, string>>>
     */
    private static function ownMessages(): array
    {
        $files = glob(__DIR__ . '/../translations/*.*.*');
        self::assertIsArray($files);
        $messages = [];

        foreach ($files as $file) {
            self::assertSame(1, preg_match('/\/([a-z_]+)\.([a-z]{2}(?:_[A-Z]{2})?)\.(yml|yaml|xlf|xliff)$/', $file, $match), $file);
            [, $domain, $locale, $extension] = $match;
            $loader = in_array($extension, ['yml', 'yaml'], true) ? new YamlFileLoader() : new XliffFileLoader();

            foreach ($loader->load($file, $locale, $domain)->all($domain) as $key => $message) {
                self::assertIsString($message, sprintf('%s %s:%s', $locale, $domain, $key));
                $messages[$domain][$locale][(string) $key] = $message;
            }
        }

        ksort($messages);

        foreach ($messages as $domain => $catalogues) {
            ksort($catalogues);
            $messages[$domain] = $catalogues;
        }

        self::assertGreaterThanOrEqual(6, count($messages['messages'] ?? []), 'every language of translations/');

        return $messages;
    }

    /**
     * Keys whose message has a `|` in any language, except the NOT_PLURAL ones
     *
     * @param array<string, array<string, string>> $messages
     * @return list<string>
     */
    private static function pluralKeys(string $domain, array $messages): array
    {
        $keys = [];

        foreach ($messages as $catalogue) {
            foreach ($catalogue as $key => $message) {
                if (str_contains($message, '|') && !isset(self::NOT_PLURAL[$domain][$key])) {
                    $keys[$key] = true;
                }
            }
        }

        $keys = array_map(strval(...), array_keys($keys));
        sort($keys);

        return $keys;
    }

    /**
     * Every %placeholder% of the key in any language, filled with a sample value
     *
     * @param array<string, array<string, string>> $messages
     * @return array<string, string|int>
     */
    private static function placeholdersOf(string $key, array $messages): array
    {
        $parameters = [];

        foreach ($messages as $catalogue) {
            preg_match_all('/%[A-Za-z0-9_]+%/', $catalogue[$key] ?? '', $found);

            foreach ($found[0] as $placeholder) {
                $parameters[$placeholder] = 'X';
            }
        }

        return $parameters;
    }

    /**
     * The parts of a plural message as TranslatorTrait::trans() reads them ("||" is an escaped "|")
     *
     * @return list<string>
     */
    private static function parts(string $message): array
    {
        if (preg_match('/^\|++$/', $message) === 1) {
            return explode('|', $message);
        }

        preg_match_all('/(?:\|\||[^\|])++/', $message, $matches);

        return array_map(static fn (string $part): string => trim(str_replace('||', '|', $part)), $matches[0]);
    }
}
