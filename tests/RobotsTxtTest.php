<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * public/robots.txt keeps crawlers out of URLs that only burn crawl budget (docs/features/seo/
 * research-2026-09.md §5 T2/T3): the QR code modal, editing a collection comment, player statistics
 * with its endless ?month=&year= periods, and the sign-in pages.
 *
 * Its rules are hand-written path prefixes, so renaming one of those routes would silently reopen
 * it. Every locale path is therefore built from the router here and checked against the rules of
 * the "User-agent: *" group, matched the way Google matches them: a rule is a prefix of path +
 * query, "*" stands for any run of characters, a trailing "$" anchors the end, non-ASCII characters
 * of a rule are compared percent-encoded, the longest matching rule wins and Allow wins a tie.
 */
final class RobotsTxtTest extends KernelTestCase
{
    private const string ROBOTS_TXT = __DIR__ . '/../public/robots.txt';

    private const array LOCALES = ['cs', 'de', 'en', 'es', 'fr', 'ja'];

    private const string SAMPLE_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function provideCrawlWasteRoutes(): array
    {
        return [
            'QR code modal' => ['puzzle_qr_code_modal', ['puzzleId' => self::SAMPLE_ID]],
            'edit collection item comment' => ['edit_collection_item_comment', ['collectionItemId' => self::SAMPLE_ID]],
            'player statistics' => ['player_statistics', ['playerId' => self::SAMPLE_ID]],
        ];
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('provideCrawlWasteRoutes')]
    public function testEveryLocalePathIsDisallowed(string $routeName, array $parameters): void
    {
        foreach ($this->pathInEveryLocale($routeName, $parameters) as $locale => $path) {
            self::assertFalse(
                $this->isAllowed($path),
                sprintf('robots.txt must disallow %s (%s, %s)', rawurldecode($path), $routeName, $locale),
            );
            self::assertFalse(
                $this->isAllowed($path . '?month=6&year=2024'),
                sprintf('robots.txt must disallow %s with a query string', rawurldecode($path)),
            );
        }
    }

    public function testQrCodeImageIsDisallowed(): void
    {
        $path = $this->router()->generate('puzzle_qr_code_image', ['puzzleId' => self::SAMPLE_ID]);

        self::assertFalse($this->isAllowed($path), sprintf('robots.txt must disallow %s', $path));
    }

    public function testSignInIsDisallowed(): void
    {
        $router = $this->router();

        $paths = [
            $router->generate('login'),
            $router->generate('login', ['return' => '/en/puzzle/' . self::SAMPLE_ID]),
            $router->generate('sign_in_link_request'),
        ];

        foreach ($paths as $path) {
            self::assertFalse($this->isAllowed($path), sprintf('robots.txt must disallow %s', $path));
        }
    }

    /**
     * The rules above are "*" patterns and bare prefixes right next to the pages that matter most;
     * none of them may catch a page we want crawled.
     *
     * @return array<string, array{string, array<string, string>}>
     */
    public static function provideCrawlableRoutes(): array
    {
        return [
            'puzzle detail' => ['puzzle_detail', ['puzzleId' => self::SAMPLE_ID]],
            'player profile' => ['player_profile', ['playerId' => self::SAMPLE_ID]],
            'collection detail' => ['collection_detail', ['collectionId' => self::SAMPLE_ID]],
            // Entry point of the QR codes printed from the modal - must keep working for crawlers too
            'printed QR code' => ['puzzle_detail_qr', ['puzzleId' => self::SAMPLE_ID]],
        ];
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('provideCrawlableRoutes')]
    public function testCrawlablePagesStayAllowed(string $routeName, array $parameters): void
    {
        foreach ($this->pathInEveryLocale($routeName, $parameters) as $locale => $path) {
            self::assertTrue(
                $this->isAllowed($path),
                sprintf('robots.txt must not disallow %s (%s, %s)', rawurldecode($path), $routeName, $locale),
            );
        }
    }

    public function testHomepageAndShortQrCodeLinkStayAllowed(): void
    {
        $shortQrCodeLink = $this->router()->generate('puzzle_qr_redirect', ['puzzleId' => self::SAMPLE_ID]);

        self::assertTrue($this->isAllowed($shortQrCodeLink), sprintf('robots.txt must not disallow %s', $shortQrCodeLink));
        self::assertTrue($this->isAllowed('/'), 'robots.txt must not disallow the homepage');
    }

    /**
     * @param array<string, string> $parameters
     * @return array<string, string> locale => generated path (percent-encoded, as a request carries it)
     */
    private function pathInEveryLocale(string $routeName, array $parameters): array
    {
        $router = $this->router();
        $paths = [];

        foreach ($router->getRouteCollection() as $route) {
            $locale = $route->getDefault('_locale');

            if ($route->getDefault('_canonical_route') !== $routeName || !is_string($locale)) {
                continue;
            }

            $paths[$locale] = $router->generate($routeName, [...$parameters, '_locale' => $locale]);
        }

        ksort($paths);
        self::assertSame(self::LOCALES, array_keys($paths), sprintf('%s must exist in every locale', $routeName));

        return $paths;
    }

    private function isAllowed(string $pathWithQuery): bool
    {
        $longestMatch = -1;
        $allowed = true;

        foreach ($this->rulesForEveryUserAgent() as [$directive, $pattern]) {
            if (!$this->ruleMatches($pattern, $pathWithQuery)) {
                continue;
            }

            $length = strlen($pattern);

            if ($length > $longestMatch || ($length === $longestMatch && $directive === 'allow')) {
                $longestMatch = $length;
                $allowed = $directive === 'allow';
            }
        }

        return $allowed;
    }

    private function ruleMatches(string $pattern, string $pathWithQuery): bool
    {
        $anchored = str_ends_with($pattern, '$');

        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }

        $literals = [];

        foreach (explode('*', $pattern) as $literal) {
            $literals[] = preg_quote($literal, '~');
        }

        return preg_match('~^' . implode('.*', $literals) . ($anchored ? '$' : '') . '~', $pathWithQuery) === 1;
    }

    /**
     * Rules of every group naming "User-agent: *", with non-ASCII bytes percent-encoded (as Google's parser does).
     *
     * @return list<array{string, string}> [directive, path pattern]
     */
    private function rulesForEveryUserAgent(): array
    {
        $content = file_get_contents(self::ROBOTS_TXT);
        self::assertIsString($content);

        $rules = [];
        $groupAgents = [];
        $readingAgents = false;

        foreach (explode("\n", $content) as $line) {
            $comment = strpos($line, '#');

            if ($comment !== false) {
                $line = substr($line, 0, $comment);
            }

            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $field = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));

            // Consecutive User-agent lines share the group that follows them
            if ($field === 'user-agent') {
                if ($readingAgents === false) {
                    $groupAgents = [];
                }

                $groupAgents[] = $value;
                $readingAgents = true;

                continue;
            }

            $readingAgents = false;

            if (($field !== 'allow' && $field !== 'disallow') || $value === '' || !in_array('*', $groupAgents, true)) {
                continue;
            }

            $encoded = '';

            foreach (str_split($value) as $byte) {
                $encoded .= ord($byte) > 0x7F ? sprintf('%%%02X', ord($byte)) : $byte;
            }

            $rules[] = [$field, $encoded];
        }

        self::assertNotEmpty($rules, 'robots.txt has no rules for "User-agent: *"');

        return $rules;
    }

    private function router(): RouterInterface
    {
        return self::getContainer()->get(RouterInterface::class);
    }
}
