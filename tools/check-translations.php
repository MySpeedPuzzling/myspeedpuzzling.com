<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$translationsDir = __DIR__ . '/../translations';
$outputFile = __DIR__ . '/../translations-missing.json';

$locales = ['cs', 'en', 'de', 'es', 'fr', 'ja'];

/**
 * Fully-qualified key prefixes ("domain.nested.path") that are intentionally
 * English-only and must NOT be reported as missing in other locales.
 *
 * `messages.guides.*` — the SEO guide pages (/en/guides/*) are English-only by
 * design. Every guide route pins `defaults: ['_locale' => 'en']` and the
 * controllers pin `trans(locale: 'en')`, so a translated value would never
 * render; on top of that, ~2,600 words of guide body copy live as hardcoded
 * English prose in templates/guides/*.twig, not in translation files at all.
 *
 * Why English-only: search authority is scarce for a growing site, so the
 * guides concentrate every backlink and ranking signal on one strong URL per
 * guide (aimed at the high-volume English term "speed puzzling") instead of
 * splitting it across six weaker localized URLs. Translating them is a
 * deliberate future project — extract the prose, drop the locale pins, add
 * hreflang, translate the winners once they rank — not a gap to be filled by
 * this checker. Until that decision is made, filling `guides.*` in other
 * locales produces dead keys. See src/Controller/GuidesController.php.
 *
 * @var list<string>
 */
$ignoredKeyPrefixes = [
    'messages.guides.',
];

/**
 * Flatten nested array keys into dot notation
 * @param array<string, mixed> $array
 * @param string $prefix
 * @return array<string, mixed>
 */
function flattenKeys(array $array, string $prefix = ''): array
{
    $result = [];

    foreach ($array as $key => $value) {
        $newKey = $prefix === '' ? (string) $key : $prefix . '.' . $key;

        if (is_array($value)) {
            $result = array_merge($result, flattenKeys($value, $newKey));
        } else {
            $result[$newKey] = $value;
        }
    }

    return $result;
}

// Discover all translation files and group by domain
$files = glob($translationsDir . '/*.yml');

if ($files === false) {
    echo "Error: Could not read translations directory\n";
    exit(1);
}

// Parse all translation files
// Structure: $languageKeys[domain][locale] = [key => value, ...]
$languageKeys = [];
$allKeysByDomain = [];

foreach ($files as $file) {
    $filename = basename($file);

    // Parse filename: messages.cs.yml -> domain=messages, locale=cs
    if (preg_match('/^(.+)\.([a-z]{2})\.yml$/', $filename, $matches) !== 1) {
        echo "Warning: Skipping file with unexpected format: $filename\n";
        continue;
    }

    $domain = $matches[1];
    $locale = $matches[2];

    if (!in_array($locale, $locales, true)) {
        echo "Warning: Unknown locale '$locale' in file: $filename\n";
        continue;
    }

    $yaml = Yaml::parseFile($file);

    if (!is_array($yaml)) {
        echo "Warning: Empty or invalid YAML in file: $filename\n";
        continue;
    }

    $flatKeys = flattenKeys($yaml);

    $languageKeys[$domain][$locale] = $flatKeys;

    // Collect all unique keys per domain
    if (!isset($allKeysByDomain[$domain])) {
        $allKeysByDomain[$domain] = [];
    }

    foreach (array_keys($flatKeys) as $key) {
        $allKeysByDomain[$domain][$key] = true;
    }
}

// Find missing keys
// Structure: $missing["domain.key"] = ["filled" => [...], "missing" => [...]]
$missing = [];
$ignoredCount = 0;

foreach ($allKeysByDomain as $domain => $keys) {
    foreach (array_keys($keys) as $key) {
        $fullKey = $domain . '.' . $key;

        // Intentionally English-only keys are never reported as missing.
        $isIgnored = false;
        foreach ($ignoredKeyPrefixes as $ignoredPrefix) {
            if (str_starts_with($fullKey, $ignoredPrefix)) {
                $isIgnored = true;
                break;
            }
        }

        if ($isIgnored) {
            $ignoredCount++;
            continue;
        }

        $missingIn = [];
        $filledIn = [];

        foreach ($locales as $locale) {
            // Check if this locale has this key in this domain
            if (!isset($languageKeys[$domain][$locale][$key])) {
                $missingIn[] = $locale;
            } else {
                $filledIn[] = [$locale => $languageKeys[$domain][$locale][$key]];
            }
        }

        if ($missingIn !== []) {
            $missing[$fullKey] = [
                'filled' => $filledIn,
                'missing' => $missingIn,
            ];
        }
    }
}

// Sort by key for easier review
ksort($missing);

// Output JSON
$json = json_encode($missing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

if ($json === false) {
    echo "Error: Could not encode JSON\n";
    exit(1);
}

file_put_contents($outputFile, $json . "\n");

// Print summary
$totalMissing = count($missing);
echo "Translation check complete!\n";
echo "Found $totalMissing keys with missing translations.\n";

if ($ignoredCount > 0) {
    echo "Ignored $ignoredCount key(s) that are intentionally English-only (see \$ignoredKeyPrefixes).\n";
}

echo "Output written to: translations-missing.json\n";

// Print per-locale summary
$perLocale = [];
foreach ($locales as $locale) {
    $perLocale[$locale] = 0;
}

foreach ($missing as $entry) {
    foreach ($entry['missing'] as $locale) {
        $perLocale[$locale]++;
    }
}

echo "\nMissing translations per locale:\n";
foreach ($perLocale as $locale => $count) {
    echo "  $locale: $count\n";
}
