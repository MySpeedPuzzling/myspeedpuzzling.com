<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Value\OauthProvider;

final class MicrosoftLoginAuthenticator extends SocialLoginAuthenticator
{
    public function provider(): OauthProvider
    {
        return OauthProvider::Microsoft;
    }
}
