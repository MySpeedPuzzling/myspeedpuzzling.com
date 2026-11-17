<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Symfony\Component\HttpFoundation\Request;

/**
 * The language for a link without one (`/compete/{token}`, the printed competition tags `/live/{cid}/t/12`): the
 * signed-in player's profile language, else the browser's first one the site speaks, else English.
 */
final readonly class PreferredLocale
{
    /** English first: getPreferredLanguage() falls back to the first entry when nothing matches */
    public const array LOCALES = ['en', 'cs', 'de', 'es', 'fr', 'ja'];

    public function __construct(
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    public function of(Request $request): string
    {
        $locale = $this->retrieveLoggedUserProfile->getProfile()?->locale;

        if (in_array($locale, self::LOCALES, true)) {
            return $locale;
        }

        return $request->getPreferredLanguage(self::LOCALES) ?? 'en';
    }
}
