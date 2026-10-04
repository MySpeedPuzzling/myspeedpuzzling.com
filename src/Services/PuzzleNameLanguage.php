<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Value\CountryLanguage;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Which language a puzzle's second name line is in (docs/features/puzzle-names/README.md "Display"): the page
 * language, and on English pages a signed-in player's country language - a Czech player on `/en/` gets the Czech
 * name. English itself is never a second line: the main title is the English one.
 *
 * Live Component re-renders have the locale from their `/{_locale}/_components/...` URL. Without a request (e-mails
 * rendered by a worker, console) there is no second line.
 */
readonly final class PuzzleNameLanguage
{
    private const string ENGLISH = 'en';

    public function __construct(
        private RequestStack $requestStack,
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    /**
     * The viewer's language - lists, pickers, the puzzle page header. The profile is the one the request loaded
     * already (UserLocaleListener), no query of its own.
     */
    public function forViewer(): null|string
    {
        $locale = $this->requestStack->getCurrentRequest()?->getLocale();

        if ($locale === null) {
            return null;
        }

        if ($locale !== self::ENGLISH) {
            return $locale;
        }

        $language = CountryLanguage::of($this->retrieveLoggedUserProfile->getProfile()?->country);

        return $language !== self::ENGLISH ? $language : null;
    }

    /**
     * The page language only, whoever looks - what crawlers see (`<title>`, meta description, structured data), the
     * same HTML for every guest of one URL.
     */
    public function forPage(): null|string
    {
        $locale = $this->requestStack->getCurrentRequest()?->getLocale();

        return $locale !== self::ENGLISH ? $locale : null;
    }
}
