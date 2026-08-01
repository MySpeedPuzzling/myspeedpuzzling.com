<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Sends an unauthenticated visitor to the login page, carrying where they were
 * headed in `?return=` - no session, no cookie.
 *
 * Replaces Auth0EntryPoint, which wrote a client-writable auth0_redirect_target
 * cookie that nothing was willing to honor (it was an open redirect, so
 * LoginFormAuthenticator deliberately ignored it), and pairs with
 * SessionFreeExceptionListener, which stops Symfony writing the same
 * destination into the session as _security.main.target_path.
 *
 * Why it matters beyond tidiness: a protected URL is reachable by anyone,
 * crawlers included, and there are 332 IsGranted/denyAccessUnlessGranted sites.
 * Every anonymous hit on one used to mint a persistent session row purely to
 * remember a destination the visitor will never come back for. See
 * docs/features/return-url.md.
 */
final readonly class LoginEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function start(Request $request, null|AuthenticationException $authException = null): Response
    {
        return new RedirectResponse(
            $this->urlGenerator->generate('login', $this->returnParameter($request)),
        );
    }

    /**
     * @return array<string, string>
     */
    private function returnParameter(Request $request): array
    {
        // Same conditions Symfony's own setTargetPath() applies: replaying a
        // non-safe method or an XHR after login would be wrong, not helpful.
        if (!$request->isMethodSafe() || $request->isXmlHttpRequest()) {
            return [];
        }

        // getRequestUri() is path + query, built by us from the actual request,
        // so it is same-origin by construction. Validated anyway - it is about
        // to become a redirect target, and this is the one place where being
        // wrong hands an attacker a post-login redirect.
        $returnUrl = ReturnUrl::tryFrom($request->getRequestUri());

        if ($returnUrl === null) {
            return [];
        }

        // Pointing back at the login page itself would loop
        if (str_contains($returnUrl->path, '/login')) {
            return [];
        }

        return ['return' => $returnUrl->path];
    }
}
