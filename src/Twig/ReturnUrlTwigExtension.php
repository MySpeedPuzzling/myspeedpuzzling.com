<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Value\ReturnUrl;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Templates render `?return=` values straight into href attributes, which makes
 * every one of them an off-site link waiting to happen. Funnel them through
 * ReturnUrl so a hostile value degrades to the caller's fallback instead of
 * pointing a "Back" button at somebody else's site.
 */
final class ReturnUrlTwigExtension extends AbstractExtension
{
    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('safe_return_url', $this->safeReturnUrl(...)),
        ];
    }

    public function safeReturnUrl(null|string $value, null|string $fallback = null): null|string
    {
        $returnUrl = ReturnUrl::tryFrom($value);

        if ($returnUrl === null) {
            return $fallback;
        }

        return $returnUrl->path;
    }
}
