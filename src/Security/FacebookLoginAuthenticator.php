<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Value\OauthProvider;

final class FacebookLoginAuthenticator extends SocialLoginAuthenticator
{
    public function provider(): OauthProvider
    {
        return OauthProvider::Facebook;
    }
}
