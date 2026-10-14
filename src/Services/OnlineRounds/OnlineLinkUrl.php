<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The address of a personal link (docs/features/online-competitions/README.md §5.4): the participant's competition page
 * (`compete_page`), absolute - it is copied into e-mails, printed and put into QR codes. In the current request's
 * language - or without one (neutral(), stream RP: `compete_link`, the page then speaks the participant's language).
 * The one place that builds it: the links page, its CSV (neutral) and QR sheet, the participants sheet's column
 * (GetParticipantsSheetState, through generate()) and the event page's "Open my competition page" (Twig
 * `online_link_url()`).
 */
readonly final class OnlineLinkUrl
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function of(string $token): string
    {
        return self::generate($this->urlGenerator, $token);
    }

    public static function generate(UrlGeneratorInterface $urlGenerator, string $token): string
    {
        return $urlGenerator->generate('compete_page', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * Without a language ("myspeedpuzzling.com/compete/…" - CompeteLinkController): the participant's own profile or
     * browser picks it, never the organiser's page that made the link
     */
    public function neutral(string $token): string
    {
        return $this->urlGenerator->generate('compete_link', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * The address without its scheme, for typing it from paper ("myspeedpuzzling.com/en/compete/…")
     */
    public static function short(string $url): string
    {
        return (string) preg_replace('#^https?://#', '', $url);
    }
}
