<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `chat_links` - a chat message as HTML with its web addresses as links. The text is escaped first and only
 * http(s) addresses become links, so nothing a player types turns into markup. Our own pages open in place,
 * other sites in a new tab with nofollow/ugc.
 */
final class ChatLinksTwigExtension extends AbstractExtension
{
    // Matched on the escaped text: ends at whitespace or an escaped < > " ' (never part of an address as typed)
    private const string URL_PATTERN = '~\bhttps?://(?:(?!&(?:lt|gt|quot|apos);)[^\s<])+~iu';

    // Punctuation that ends the sentence rather than the address: "see https://example.com." / "(https://x.com)"
    private const string TRAILING_PATTERN = '~(?:[.,;:!?]|&amp;|\))$~';

    public function __construct(
        readonly private RequestStack $requestStack,
    ) {
    }

    /**
     * @return array<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('chat_links', $this->linkify(...), ['is_safe' => ['html']]),
        ];
    }

    public function linkify(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        return (string) preg_replace_callback(self::URL_PATTERN, function (array $match): string {
            $url = $match[0];
            $trailing = '';

            while (preg_match(self::TRAILING_PATTERN, $url, $end) === 1) {
                // A closing bracket belongs to the address while it has an opening one ("…/Puzzle_(game)")
                if ($end[0] === ')' && substr_count($url, '(') >= substr_count($url, ')')) {
                    break;
                }

                $url = substr($url, 0, -strlen($end[0]));
                $trailing = $end[0] . $trailing;
            }

            if (preg_match('~^https?://.~i', $url) !== 1) {
                return $match[0];
            }

            $attributes = $this->isOwnSite($url) ? '' : ' target="_blank" rel="noopener noreferrer nofollow ugc"';

            return '<a href="' . $url . '"' . $attributes . '>' . $url . '</a>' . $trailing;
        }, $escaped);
    }

    private function isOwnSite(string $escapedUrl): bool
    {
        $host = parse_url(html_entity_decode($escapedUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_HOST);

        if (!is_string($host)) {
            return false;
        }

        $host = strtolower($host);

        if ($host === 'myspeedpuzzling.com' || str_ends_with($host, '.myspeedpuzzling.com')) {
            return true;
        }

        return $host === $this->requestStack->getMainRequest()?->getHost();
    }
}
