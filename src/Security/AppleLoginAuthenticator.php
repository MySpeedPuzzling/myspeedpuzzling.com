<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Component\HttpFoundation\Request;

/**
 * Apple's callback differs from Google/Facebook in two settled ways (plan
 * §Provider gotchas): it arrives as a cross-site POST (response_mode=form_post,
 * mandatory once name/email scopes are requested), and the user's name is
 * delivered exactly once - in the `user` field of the FIRST authorization's
 * POST body. The cross-site part is already absorbed by the cache-backed state
 * in the base class; this subclass only captures the one-shot name payload.
 */
final class AppleLoginAuthenticator extends SocialLoginAuthenticator
{
    public function provider(): OauthProvider
    {
        return OauthProvider::Apple;
    }

    protected function appleUserPayload(Request $request): null|string
    {
        $user = $request->request->get('user');

        return is_string($user) && $user !== '' ? $user : null;
    }
}
