<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$translationsDir = __DIR__ . '/../translations';
$outputFile = __DIR__ . '/../translations-missing.json';

$locales = ['cs', 'en', 'de', 'es', 'fr', 'ja'];

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

foreach ($allKeysByDomain as $domain => $keys) {
    foreach (array_keys($keys) as $key) {
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
            $fullKey = $domain . '.' . $key;
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
