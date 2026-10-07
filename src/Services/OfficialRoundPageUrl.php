<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Results\CompetitionReference;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The public page of one round's results (event_round_results for a standalone event, edition_round_results for an
 * edition of a series) - what the organiser's results tools link as "public page". Null when the event or the round
 * has no address (no slug): then there is no page to link.
 */
readonly final class OfficialRoundPageUrl
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function of(CompetitionReference $competition, null|string $roundSlug): null|string
    {
        if ($roundSlug === null || $roundSlug === '' || $competition->slug === null) {
            return null;
        }

        return match ($competition->routeName()) {
            'event_detail' => $this->urlGenerator->generate('event_round_results', [
                'slug' => $competition->slug,
                'roundSlug' => $roundSlug,
            ]),
            'edition_detail' => $this->urlGenerator->generate('edition_round_results', [
                'seriesSlug' => (string) $competition->seriesSlug,
                'editionSlug' => $competition->slug,
                'roundSlug' => $roundSlug,
            ]),
            default => null,
        };
    }
}
