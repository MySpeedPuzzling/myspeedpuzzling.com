<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every plural message of the online competition pages - the `compete` root (the participant's page) and the
 * `online_rounds` root (the organiser's pages), docs/features/online-competitions/ - renders for every count in every
 * language. Symfony's plural format (TranslatorTrait::trans(), mirrored in the browser by assets/translation_choice.js)
 * tries the explicit intervals first ({1}, [2,4], ]1,Inf[), then the locale's plural position among the other parts:
 * when nothing fits, PHP throws (a 500) and the browser shows a bare number. A part starting with one ASCII word and a
 * colon ("Zeitlimit: 1 Minute") is read as a labelled part - the word disappears.
 *
 * A message of the two roots holding a `|` in any language is a plural message here, so the keys added later are
 * covered without a list. Each one is rendered through the real translator in every language of translations/ with
 * the counts 0-30, 100 and 1000 and every placeholder filled.
 */
final class OnlinePluralMessagesTest extends KernelTestCase
{
    private const array ROOTS = ['compete.', 'online_rounds.'];

    private const array EXTRA_COUNTS = [100, 1000];

    public function testEveryPluralMessageRendersForEveryCountInEveryLanguage(): void
    {
        $translator = self::translator();
        $messages = self::onlineMessages($translator);
        $plural = self::pluralKeys($messages);
        $failures = [];

        foreach (array_keys($messages) as $locale) {
            foreach ($plural as $key) {
                $parameters = self::placeholdersOf($key, $messages);

                foreach ([...range(0, 30), ...self::EXTRA_COUNTS] as $count) {
                    $parameters['%count%'] = $count;

                    try {
                        $text = $translator->trans($key, $parameters, 'messages', $locale);
                    } catch (\Throwable $e) {
                        $failures[] = sprintf('%s %s (count %d): %s', $locale, $key, $count, $e->getMessage());

                        continue;
                    }

                    if (trim($text) === '') {
                        $failures[] = sprintf('%s %s (count %d): an empty text', $locale, $key, $count);
                    }
                }
            }
        }

        self::assertSame([], $failures, 'plural messages that do not render for every count');
        // Both roots hold plural messages - none would mean the catalogues were not read
        self::assertGreaterThan(10, count($plural));
        self::assertGreaterThanOrEqual(6, count($messages), 'every language of translations/');
    }

    public function testNoPluralPartIsReadAsALabel(): void
    {
        $messages = self::onlineMessages(self::translator());
        $failures = [];

        foreach (self::pluralKeys($messages) as $key) {
            foreach ($messages as $locale => $catalogue) {
                if (!isset($catalogue[$key])) {
                    continue;
                }

                foreach (self::parts($catalogue[$key]) as $part) {
                    // An interval part starts with {, [ or ] - only the other parts are read for a label
                    if (preg_match('/^\w+\:/', $part) === 1) {
                        $failures[] = sprintf('%s %s: "%s"', $locale, $key, $part);
                    }
                }
            }
        }

        self::assertSame([], $failures, 'plural parts starting with "word:" lose the word (write the label another way, or start the part with an interval)');
    }

    private static function translator(): TranslatorInterface&TranslatorBagInterface
    {
        return self::getContainer()->get(TranslatorInterface::class);
    }

    /**
     * The messages of the two roots each language defines itself (no fallback), by locale
     *
     * @return array<string, array<string, string>>
     */
    private static function onlineMessages(TranslatorBagInterface $translator): array
    {
        $files = glob(__DIR__ . '/../translations/messages.*.yml');
        self::assertIsArray($files);
        $messages = [];

        foreach ($files as $file) {
            self::assertSame(1, preg_match('/messages\.([a-z]{2}(?:_[A-Z]{2})?)\.yml$/', $file, $match), $file);
            $locale = $match[1];
            $messages[$locale] = [];

            foreach ($translator->getCatalogue($locale)->all('messages') as $key => $message) {
                if (is_string($message) && self::isOnlineKey((string) $key)) {
                    $messages[$locale][(string) $key] = $message;
                }
            }
        }

        ksort($messages);

        return $messages;
    }

    private static function isOnlineKey(string $key): bool
    {
        foreach (self::ROOTS as $root) {
            if (str_starts_with($key, $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keys whose message has a `|` in any language
     *
     * @param array<string, array<string, string>> $messages
     * @return list<string>
     */
    private static function pluralKeys(array $messages): array
    {
        $keys = [];

        foreach ($messages as $catalogue) {
            foreach ($catalogue as $key => $message) {
                if (str_contains($message, '|')) {
                    $keys[$key] = true;
                }
            }
        }

        $keys = array_keys($keys);
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
