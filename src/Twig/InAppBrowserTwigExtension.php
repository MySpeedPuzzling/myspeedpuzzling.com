<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Value\InAppBrowser;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `in_app_browser()` - the social app whose built-in browser renders the page,
 * or null. Read from the User-Agent, so only pages that are never shared-cached
 * may depend on it: the auth pages are `no-store` (NativeAuthPageSubscriber).
 */
final class InAppBrowserTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('in_app_browser', $this->detect(...)),
            new TwigFunction('chrome_intent_url', $this->chromeIntentUrl(...)),
        ];
    }

    public function detect(): null|InAppBrowser
    {
        return InAppBrowser::fromUserAgent($this->requestStack->getMainRequest()?->headers->get('User-Agent'));
    }

    /**
     * The current page, handed to Chrome (Android's escape from a web view).
     */
    public function chromeIntentUrl(InAppBrowser $inAppBrowser): null|string
    {
        $request = $this->requestStack->getMainRequest();

        if ($request === null) {
            return null;
        }

        return $inAppBrowser->chromeIntentUrl(
            $request->getScheme(),
            $request->getHttpHost(),
            $request->getRequestUri(),
        );
    }
}
