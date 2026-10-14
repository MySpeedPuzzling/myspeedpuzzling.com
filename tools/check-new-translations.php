<?php

/**
 * The translation keys a branch adds and removes, checked in every domain and all 6 languages (docs/features/navigation
 * DOC-02). Compares the working tree's translations/ with the same files at a base commit:
 *
 *  - new keys (English now − English at the base) exist with a non-empty value in en, cs, de, es, fr and ja;
 *  - removed keys (English at the base − English now) are gone from every language;
 *  - a new key's translation is not the English text (trimmed, case-insensitive) unless it holds nothing to translate
 *    or tools/translation-same-as-english.php lists it with a reason; an entry there that no value needs any more is
 *    reported too, so the list stays tight.
 *
 * Usage: php tools/check-new-translations.php [--base=<ref>]
 *        default base: `git merge-base HEAD origin/main` (main's own keys arrive with merges and are not the branch's)
 *
 * Prints every miss, exits 1 when there is one. tools/check-translations.php checks the whole catalogue for keys
 * missing in a language; this one checks what a branch changed, including texts left in English.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\Loader\YamlFileLoader;

const LOCALES = ['en', 'cs', 'de', 'es', 'fr', 'ja'];
const TRANSLATION_FILE = '/^([a-z_]+)\.([a-z]{2})\.(yml|yaml|xlf|xliff)$/';

$root = dirname(__DIR__);

/**
 * Runs git in the repository and returns its output (exits on failure)
 *
 * @param list<string> $arguments
 */
function git(string $root, array $arguments): string
{
    $process = proc_open(['git', '-C', $root, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        fwrite(STDERR, "Error: could not run git\n");
        exit(2);
    }

    $output = (string) stream_get_contents($pipes[1]);
    $error = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (proc_close($process) !== 0) {
        fwrite(STDERR, sprintf("Error: git %s failed: %s\n", implode(' ', $arguments), trim($error)));
        exit(2);
    }

    return $output;
}

function loaderFor(string $extension): LoaderInterface
{
    return in_array($extension, ['yml', 'yaml'], true) ? new YamlFileLoader() : new XliffFileLoader();
}

/**
 * The messages one file defines itself, flattened the way the translator reads them
 *
 * @return array<string, string>
 */
function messagesOf(string $file, string $domain, string $locale, string $extension): array
{
    $messages = [];

    foreach (loaderFor($extension)->load($file, $locale, $domain)->all($domain) as $key => $message) {
        $messages[(string) $key] = is_scalar($message) ? (string) $message : '';
    }

    return $messages;
}

/**
 * domain => locale => key => message, from translations/ in the working tree
 *
 * @return array<string, array<string, array<string, string>>>
 */
function workingTreeCatalogues(string $root): array
{
    $catalogues = [];

    foreach (glob($root . '/translations/*') ?: [] as $file) {
        if (preg_match(TRANSLATION_FILE, basename($file), $match) !== 1) {
            continue;
        }

        [, $domain, $locale, $extension] = $match;
        $catalogues[$domain][$locale] = messagesOf($file, $domain, $locale, $extension);
    }

    return $catalogues;
}

/**
 * domain => locale => key => message, from translations/ at the base commit
 *
 * @return array<string, array<string, array<string, string>>>
 */
function baseCatalogues(string $root, string $ref): array
{
    $catalogues = [];
    $directory = sys_get_temp_dir() . '/check-new-translations-' . bin2hex(random_bytes(6));
    mkdir($directory);

    foreach (array_filter(explode("\n", git($root, ['ls-tree', '--name-only', $ref, '--', 'translations/']))) as $path) {
        $name = basename($path);

        if (preg_match(TRANSLATION_FILE, $name, $match) !== 1) {
            continue;
        }

        [, $domain, $locale, $extension] = $match;
        $file = $directory . '/' . $name;
        file_put_contents($file, git($root, ['show', $ref . ':' . $path]));
        $catalogues[$domain][$locale] = messagesOf($file, $domain, $locale, $extension);
        unlink($file);
    }

    rmdir($directory);

    return $catalogues;
}

function normalised(string $message): string
{
    return mb_strtolower(trim($message));
}

/**
 * Nothing to translate: placeholders, plural intervals, HTML, brand names, digits, punctuation, symbols/emoji - the
 * rule of tools/translation-same-as-english.php
 *
 * @param array{not_text: list<string>, brands: list<string>, leftover: string, ...} $rule
 */
function nothingToTranslate(string $message, array $rule): bool
{
    $rest = (string) preg_replace($rule['not_text'], ' ', $message);

    foreach ($rule['brands'] as $brand) {
        $rest = (string) preg_replace('/\\b' . preg_quote($brand, '/') . '\\b/iu', ' ', $rest);
    }

    return preg_match($rule['leftover'], $rest) === 1;
}

/**
 * @param array{key?: string, prefix?: string, domain?: string, locales: list<string>, reason: string} $entry
 */
function entryMatches(array $entry, string $domain, string $key): bool
{
    if (($entry['domain'] ?? 'messages') !== $domain) {
        return false;
    }

    return isset($entry['key']) ? $entry['key'] === $key : str_starts_with($key, (string) ($entry['prefix'] ?? "\0"));
}

/**
 * @param list<array{key?: string, prefix?: string, domain?: string, locales: list<string>, reason: string}> $entries
 */
function allowListed(array $entries, string $domain, string $key, string $locale): bool
{
    foreach ($entries as $entry) {
        if (entryMatches($entry, $domain, $key) && in_array($locale, $entry['locales'], true)) {
            return true;
        }
    }

    return false;
}

function describe(string $message): string
{
    $flat = (string) preg_replace('/\s+/', ' ', trim($message));

    return '"' . (mb_strlen($flat) > 70 ? mb_substr($flat, 0, 67) . '...' : $flat) . '"';
}

$base = null;

$arguments = is_array($_SERVER['argv'] ?? null) ? array_slice($_SERVER['argv'], 1) : [];

foreach ($arguments as $argument) {
    if (is_string($argument) && str_starts_with($argument, '--base=')) {
        $base = substr($argument, strlen('--base='));
    } else {
        fwrite(STDERR, "Usage: php tools/check-new-translations.php [--base=<ref>]\n");
        exit(2);
    }
}

$base = $base !== null && $base !== '' ? $base : trim(git($root, ['merge-base', 'HEAD', 'origin/main']));
$baseCommit = trim(git($root, ['rev-parse', '--short', $base . '^{commit}']));

/** @var array{not_text: list<string>, brands: list<string>, leftover: string, entries: list<array{key?: string, prefix?: string, domain?: string, locales: list<string>, reason: string}>} $sameAsEnglish */
$sameAsEnglish = require __DIR__ . '/translation-same-as-english.php';
$entries = $sameAsEnglish['entries'];

$now = workingTreeCatalogues($root);
$then = baseCatalogues($root, $base);

$misses = ['missing' => [], 'same as English' => [], 'removed but still defined' => [], 'allow-list' => []];

foreach ($entries as $index => $entry) {
    if (isset($entry['key']) === isset($entry['prefix']) || $entry['locales'] === [] || trim($entry['reason']) === '' || array_diff($entry['locales'], array_diff(LOCALES, ['en'])) !== []) {
        $misses['allow-list'][] = sprintf('entry #%d: needs a key or a prefix, languages other than en and a reason', $index + 1);
    }
}

$newCount = 0;
$removedCount = 0;
$domains = array_unique([...array_keys($now), ...array_keys($then)]);
sort($domains);

foreach ($domains as $domain) {
    $english = $now[$domain]['en'] ?? [];
    $englishBefore = $then[$domain]['en'] ?? [];

    foreach (array_keys(array_diff_key($english, $englishBefore)) as $key) {
        $newCount++;

        foreach (LOCALES as $locale) {
            $message = $now[$domain][$locale][$key] ?? null;

            if ($message === null || trim($message) === '') {
                $misses['missing'][] = sprintf('%s %s:%s', $locale, $domain, $key);

                continue;
            }

            if (
                $locale !== 'en'
                && normalised($message) === normalised($english[$key])
                && !nothingToTranslate($message, $sameAsEnglish)
                && !allowListed($entries, $domain, $key, $locale)
            ) {
                $misses['same as English'][] = sprintf('%s %s:%s %s', $locale, $domain, $key, describe($message));
            }
        }
    }

    foreach (array_keys(array_diff_key($englishBefore, $english)) as $key) {
        $removedCount++;

        foreach (LOCALES as $locale) {
            if (isset($now[$domain][$locale][$key])) {
                $misses['removed but still defined'][] = sprintf('%s %s:%s', $locale, $domain, $key);
            }
        }
    }
}

// An entry is needed while some value it covers equals English in each of its languages
foreach ($entries as $index => $entry) {
    $domain = $entry['domain'] ?? 'messages';
    $needed = [];

    foreach ($now[$domain]['en'] ?? [] as $key => $message) {
        if (!entryMatches($entry, $domain, (string) $key)) {
            continue;
        }

        foreach ($entry['locales'] as $locale) {
            if (normalised($now[$domain][$locale][$key] ?? '') === normalised($message)) {
                $needed[$locale] = true;
            }
        }
    }

    foreach (array_diff($entry['locales'], array_keys($needed)) as $locale) {
        $misses['allow-list'][] = sprintf('entry #%d (%s %s): no %s value equals English any more - remove the language', $index + 1, $domain, $entry['key'] ?? ($entry['prefix'] ?? '') . '*', $locale);
    }
}

printf("Base %s, %d new and %d removed key(s) across %d domain(s), languages %s\n", $baseCommit, $newCount, $removedCount, count($domains), implode(' ', LOCALES));

$total = 0;

foreach ($misses as $kind => $lines) {
    $total += count($lines);

    foreach ($lines as $line) {
        printf("  %s: %s\n", $kind, $line);
    }
}

printf(
    "%s: %d missing, %d same as English, %d removed but still defined, %d allow-list problem(s)\n",
    $total === 0 ? 'OK' : 'FAILED',
    count($misses['missing']),
    count($misses['same as English']),
    count($misses['removed but still defined']),
    count($misses['allow-list']),
);

exit($total === 0 ? 0 : 1);
