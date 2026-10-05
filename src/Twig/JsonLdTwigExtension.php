<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `json_ld` - a value inside `<script type="application/ld+json">`. Player-typed text (puzzle names, event
 * descriptions) goes in there, and a script element ends at the first `</script` - and `<!--<script` makes the parser
 * swallow the rest of the page. `json_encode` leaves `<!--` alone: here `< > & ' "` are \u escapes, the same JSON for
 * every reader, with nothing the HTML parser reacts to. For script elements only - the result is no attribute value.
 */
final class JsonLdTwigExtension extends AbstractExtension
{
    private const int FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * @return array<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('json_ld', self::encode(...), ['is_safe' => ['html']]),
        ];
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, self::FLAGS);
    }
}
