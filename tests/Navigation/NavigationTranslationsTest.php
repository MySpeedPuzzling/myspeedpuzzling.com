<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use SpeedPuzzling\Web\Navigation\FooterColumn;
use SpeedPuzzling\Web\Navigation\MenuGroup;
use SpeedPuzzling\Web\Navigation\NavigationRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every text of the navigation exists in all 6 languages and is translated (docs/features/navigation DOC-02): each
 * registry entry's label, description and synonyms, the Menu's group headings, the footer's column headings, and the
 * navigation's own namespaces - its chrome, Menu, chips and launch notes (nav.*), the role rows (nav_admin.*), the
 * Community place (community_page.*) and the "Where did it go?" page (where_did_it_go.*).
 *
 * Each language's own catalogue must define the key (no fallback to English), with a non-empty text that is not the
 * English one - unless it holds nothing to translate or tools/translation-same-as-english.php lists it with a reason
 * (the moderator and admin rows stay English as a whole prefix). The same list and rule are used by
 * tools/check-new-translations.php, which checks every key a branch adds.
 */
final class NavigationTranslationsTest extends KernelTestCase
{
    private const array LOCALES = ['en', 'cs', 'de', 'es', 'fr', 'ja'];
    private const string DOMAIN = 'messages';
    private const array PREFIXES = ['nav.', 'nav_admin.', 'community_page.', 'where_did_it_go.'];

    // Keys outside those namespaces added by the navigation: the footer's "All pages" link (FOOT-01)
    private const array KEYS = ['footer.all_pages'];

    private const string SAME_AS_ENGLISH = __DIR__ . '/../../tools/translation-same-as-english.php';

    public function testEveryNavigationTextExistsAndIsTranslatedInEveryLanguage(): void
    {
        $translator = self::translator();
        $rule = self::sameAsEnglish();
        $english = $translator->getCatalogue('en');
        $keys = self::navigationKeys();
        $failures = [];

        foreach ($keys as $key) {
            if (!$english->defines($key, self::DOMAIN) || trim($english->get($key, self::DOMAIN)) === '') {
                $failures[] = sprintf('en %s: missing or empty', $key);

                continue;
            }

            $englishText = $english->get($key, self::DOMAIN);

            foreach (array_diff(self::LOCALES, ['en']) as $locale) {
                $catalogue = $translator->getCatalogue($locale);

                if (!$catalogue->defines($key, self::DOMAIN)) {
                    $failures[] = sprintf('%s %s: missing', $locale, $key);

                    continue;
                }

                $text = $catalogue->get($key, self::DOMAIN);

                if (trim($text) === '') {
                    $failures[] = sprintf('%s %s: empty', $locale, $key);

                    continue;
                }

                if (
                    mb_strtolower(trim($text)) === mb_strtolower(trim($englishText))
                    && !self::nothingToTranslate($text, $rule)
                    && !self::allowListed($rule['entries'], $key, $locale)
                ) {
                    $failures[] = sprintf('%s %s: still English "%s"', $locale, $key, $text);
                }
            }
        }

        self::assertSame([], $failures, 'navigation texts missing or left in English (translate them, or list a word that truly is the same in tools/translation-same-as-english.php)');
        // The registry and the namespaces were read: hundreds of keys, the role rows among them
        self::assertGreaterThan(300, count($keys));
        self::assertContains('nav_admin.admin_vouchers', $keys);
        self::assertContains('nav.row.organized_events', $keys);
    }

    public function testEventsYouOrganizeIsTranslatedAndOnlyTheRoleRowsStayEnglish(): void
    {
        $translator = self::translator();
        $registry = self::getContainer()->get(NavigationRegistry::class);
        $organized = $registry->byId('organized_events');
        self::assertNotNull($organized);
        self::assertFalse($organized->englishOnly);

        foreach (array_diff(self::LOCALES, ['en']) as $locale) {
            self::assertNotSame(
                $translator->trans($organized->label, [], self::DOMAIN, 'en'),
                $translator->trans($organized->label, [], self::DOMAIN, $locale),
                $locale . ': "Events you organize" is a Menu row of organisers, translated like every other row',
            );
        }

        // A prefix entry of the list is the moderator and admin rows' one, and covers no other row
        foreach (self::sameAsEnglish()['entries'] as $entry) {
            if ($entry['prefix'] === null) {
                continue;
            }

            self::assertSame('nav_admin.', $entry['prefix']);

            foreach ($registry->all() as $navEntry) {
                self::assertSame($navEntry->englishOnly, str_starts_with($navEntry->label, $entry['prefix']), $navEntry->id);
            }
        }
    }

    public function testEveryEntryOfTheSameAsEnglishListNamesAnExistingKey(): void
    {
        $english = self::translator()->getCatalogue('en');
        $englishKeys = array_map(strval(...), array_keys($english->all(self::DOMAIN)));

        foreach (self::sameAsEnglish()['entries'] as $index => $entry) {
            $label = sprintf('entry #%d', $index + 1);

            if ($entry['key'] !== null) {
                self::assertTrue($english->defines($entry['key'], $entry['domain']), $label . ': ' . $entry['key'] . ' is not an English key (renamed?)');
            } else {
                self::assertNotSame([], array_filter($englishKeys, static fn (string $key): bool => str_starts_with($key, (string) $entry['prefix'])), $label . ': no English key starts with ' . $entry['prefix']);
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function navigationKeys(): array
    {
        $keys = [];

        foreach (self::getContainer()->get(NavigationRegistry::class)->all() as $entry) {
            foreach ([$entry->label, $entry->description, $entry->synonyms] as $key) {
                if ($key !== null) {
                    $keys[$key] = true;
                }
            }
        }

        $english = self::translator()->getCatalogue('en');

        foreach (MenuGroup::cases() as $group) {
            // Groups without a heading (Home, You, Inbox, Log out) have no key: menuPath() falls back to docTitle()
            if ($english->defines($group->titleKey(), self::DOMAIN)) {
                $keys[$group->titleKey()] = true;
            }
        }

        foreach (FooterColumn::cases() as $column) {
            if ($column->headingKey() !== null) {
                $keys[$column->headingKey()] = true;
            }
        }

        foreach (array_keys($english->all(self::DOMAIN)) as $key) {
            foreach (self::PREFIXES as $prefix) {
                if (str_starts_with((string) $key, $prefix)) {
                    $keys[(string) $key] = true;
                }
            }
        }

        foreach (self::KEYS as $key) {
            $keys[$key] = true;
        }

        $keys = array_map(strval(...), array_keys($keys));
        sort($keys);

        return $keys;
    }

    private static function translator(): TranslatorInterface&TranslatorBagInterface
    {
        // The framework's translator (a TranslatorBagInterface): getCatalogue() reads one language without fallback
        return self::getContainer()->get(TranslatorInterface::class);
    }

    /**
     * tools/translation-same-as-english.php, its shape checked
     *
     * @return array{not_text: list<string>, brands: list<string>, leftover: string, entries: list<array{key: null|string, prefix: null|string, domain: string, locales: list<string>, reason: string}>}
     */
    private static function sameAsEnglish(): array
    {
        $list = require self::SAME_AS_ENGLISH;
        self::assertIsArray($list);

        $leftover = $list['leftover'] ?? null;
        self::assertIsString($leftover);

        $entries = [];
        $rawEntries = $list['entries'] ?? null;
        self::assertIsArray($rawEntries);

        foreach (array_values($rawEntries) as $index => $entry) {
            $label = sprintf('tools/translation-same-as-english.php entry #%d', $index + 1);
            self::assertIsArray($entry, $label);
            $key = $entry['key'] ?? null;
            $prefix = $entry['prefix'] ?? null;
            $domain = $entry['domain'] ?? self::DOMAIN;
            $reason = $entry['reason'] ?? null;
            self::assertTrue(is_string($key) xor is_string($prefix), $label . ': a key or a prefix');
            self::assertTrue($key === null || is_string($key), $label);
            self::assertTrue($prefix === null || is_string($prefix), $label);
            self::assertIsString($domain, $label);
            self::assertIsString($reason, $label);
            self::assertNotSame('', trim($reason), $label . ': the reason');

            $entries[] = [
                'key' => $key,
                'prefix' => $prefix,
                'domain' => $domain,
                'locales' => self::strings($entry['locales'] ?? null, $label . ' locales'),
                'reason' => $reason,
            ];

            self::assertNotSame([], $entries[array_key_last($entries)]['locales'], $label . ': the languages where it stays English');
            self::assertSame([], array_diff($entries[array_key_last($entries)]['locales'], array_diff(self::LOCALES, ['en'])), $label . ': languages other than en');
        }

        return [
            'not_text' => self::strings($list['not_text'] ?? null, 'not_text'),
            'brands' => self::strings($list['brands'] ?? null, 'brands'),
            'leftover' => $leftover,
            'entries' => $entries,
        ];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values, string $label): array
    {
        self::assertIsArray($values, $label);
        $strings = [];

        foreach ($values as $value) {
            self::assertIsString($value, $label);
            $strings[] = $value;
        }

        return $strings;
    }

    /**
     * Placeholders, plural intervals, HTML, brand names, digits, punctuation and symbols only (the list file's rule)
     *
     * @param array{not_text: list<string>, brands: list<string>, leftover: string, ...} $rule
     */
    private static function nothingToTranslate(string $text, array $rule): bool
    {
        $rest = (string) preg_replace($rule['not_text'], ' ', $text);

        foreach ($rule['brands'] as $brand) {
            $rest = (string) preg_replace('/\b' . preg_quote($brand, '/') . '\b/iu', ' ', $rest);
        }

        return preg_match($rule['leftover'], $rest) === 1;
    }

    /**
     * @param list<array{key: null|string, prefix: null|string, domain: string, locales: list<string>, reason: string}> $entries
     */
    private static function allowListed(array $entries, string $key, string $locale): bool
    {
        foreach ($entries as $entry) {
            $matches = $entry['key'] !== null ? $entry['key'] === $key : str_starts_with($key, (string) $entry['prefix']);

            if ($entry['domain'] === self::DOMAIN && $matches && in_array($locale, $entry['locales'], true)) {
                return true;
            }
        }

        return false;
    }
}
