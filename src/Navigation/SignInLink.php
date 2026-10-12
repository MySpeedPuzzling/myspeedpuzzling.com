<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sign in, coming back to the page it was opened on (README §4.12, NAV-09) - the guests' Menu card (MENU-01) and their
 * Profile tab (NAV-01). The link depends on the URL only, never on the visitor, so guest HTML stays shared-cacheable.
 */
final class SignInLink
{
    public static function href(UrlGeneratorInterface $urlGenerator, null|Request $request): string
    {
        $return = $request !== null ? ReturnUrl::tryFrom($request->getRequestUri()) : null;

        // Pointing back at the sign-in page itself would loop (LoginEntryPoint's rule)
        if ($return === null || str_contains($return->path, '/login')) {
            return $urlGenerator->generate('login');
        }

        return $urlGenerator->generate('login', ['return' => $return->path]);
    }
}
