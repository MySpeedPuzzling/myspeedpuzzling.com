<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * The page the Help sheet was opened on (docs/features/navigation README §4.5, implementation plan §7): read from the
 * untrusted ?url= by HelpPageContextFactory. Only a same-site path the router matched survives; anything else is the
 * empty context. The raw parameter is never kept, so it can never be echoed into an href.
 * A page whose URL holds a private token (HelpPageContextFactory::PRIVATE_LINK_ROUTES - delete-account, e-mail
 * preferences, newsletter, unsubscribe links, …) is known by its token-free path only (HELP review Q2): no page path,
 * no route - nothing links to it, nothing sends its URL.
 */
final readonly class HelpPageContext
{
    /**
     * @param null|string $path the page's same-site path with its query (ReturnUrl-validated), e.g. "/en/puzzle/…?x=1"
     * @param null|string $routeName the matched route without its locale suffix (e.g. "puzzle_detail")
     * @param array<string, mixed> $routeParams the matched route's parameters (no "_"-prefixed attributes)
     * @param null|string $puzzleId only for a puzzle page with a valid UUID (the correction row, HELP-02)
     * @param null|string $privateLinkPath a private link page: its route's path with every variable left as its
     *     {placeholder} (e.g. "/en/email-preferences/{token}") - shown in the box and mailed instead of its URL
     */
    public function __construct(
        public null|string $path = null,
        public null|string $routeName = null,
        public array $routeParams = [],
        public null|string $puzzleId = null,
        public null|string $privateLinkPath = null,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    public static function privateLink(string $tokenFreePath): self
    {
        return new self(privateLinkPath: $tokenFreePath);
    }

    /**
     * What the feedback box attaches and the help links carry on: the page's path, or a private link's token-free path
     */
    public function attachedPath(): null|string
    {
        return $this->path ?? $this->privateLinkPath;
    }

    /**
     * The attached page as a person reads it (QA-04 review, item Q-a): the path percent-decoded - a Japanese page
     * reads "/ja/ハブ", not "/ja/%E3%83%8F%E3%83%96". Display only (Twig escapes it): the form still sends
     * attachedPath(). A path that does not decode to valid UTF-8, or would decode into control characters, stays as it
     * is.
     */
    public function attachedPathForDisplay(): null|string
    {
        $path = $this->attachedPath();

        if ($path === null) {
            return null;
        }

        $decoded = rawurldecode($path);

        return mb_check_encoding($decoded, 'UTF-8') && preg_match('~[\x00-\x1F\x7F]~', $decoded) !== 1 ? $decoded : $path;
    }

    public function isEmpty(): bool
    {
        return $this->path === null;
    }
}
