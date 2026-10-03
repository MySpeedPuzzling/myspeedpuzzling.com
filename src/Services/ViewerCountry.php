<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Value\CountryCode;

/**
 * The signed-in viewer's own country, from their profile - the "home" chip of the Players page's scope switch
 * (docs/features/players-page/README.md). Guests get a guess in the browser instead (players_scope_guess Stimulus
 * controller): guest HTML is shared-cached, so the server must not vary it by Accept-Language.
 */
readonly final class ViewerCountry
{
    public function __construct(
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    public function fromProfile(): null|CountryCode
    {
        return CountryCode::fromCode($this->retrieveLoggedUserProfile->getProfile()?->country);
    }

    public function isSignedIn(): bool
    {
        return $this->retrieveLoggedUserProfile->getProfile() !== null;
    }
}
