<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `json_attribute` - a JSON data attribute's whole value, quotes included: `data-x={{ value|json_attribute }}`.
 *
 * In a double-quoted attribute every `"` of the JSON is `&quot;` - six bytes where one would do, and a JSON object of
 * links or labels is mostly quotes. This writes the value in single quotes instead, where `"` needs no escape: `'`,
 * `&`, `<` and `>` are \u escapes inside the JSON strings (JSON_HEX_*), so nothing in the value ends the attribute or
 * starts a character reference, and the attribute reads back as exactly this JSON - every reader JSON.parse()s it to
 * the same data (the chrome's weight, docs/features/navigation README §4.12; the Puzzles place's sections, the Inbox
 * strings, the Menu's paths). Slashes and non-ASCII characters stay as they are.
 */
final class JsonAttributeTwigExtension extends AbstractExtension
{
    private const int FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * @return array<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('json_attribute', self::encode(...), ['is_safe' => ['html']]),
        ];
    }

    public static function encode(mixed $value): string
    {
        return "'" . json_encode($value, self::FLAGS) . "'";
    }
}
