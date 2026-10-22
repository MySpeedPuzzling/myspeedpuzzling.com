<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PartnerFinder;

use SpeedPuzzling\Web\Query\GetPartnerCardData;
use SpeedPuzzling\Web\Query\PartnerFinderEligibility;
use SpeedPuzzling\Web\Results\CompetitionEvent;
use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Results\EventAttendance;
use SpeedPuzzling\Web\Results\PartnerCardData;
use SpeedPuzzling\Web\Results\PartnerFinderCard;
use SpeedPuzzling\Web\Results\PartnerFinderOffer;
use SpeedPuzzling\Web\Value\PartnerCardState;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The partner finder of an event or edition page (docs/features/partner-finder.md "Part B"):
 * - an event that cannot have a finder (PartnerFinderEligibility::mightQualify() on what the page loaded, or a page
 *   that is not public) - null, no query
 * - a guest - the plain "Find a partner" link, no count, no query
 * - a signed-in player - GetPartnerCardData, one statement, which also says whether the event really qualifies
 */
readonly final class PartnerFinderCardResolver
{
    public function __construct(
        private PartnerFinderEligibility $eligibility,
        private GetPartnerCardData $getPartnerCardData,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param list<EditionRoundDetail> $rounds every round of the event (the page has them already)
     * @param null|string $seriesSlug the series of an edition page - its finder lives under the series URL
     */
    public function forEventPage(
        CompetitionEvent $event,
        array $rounds,
        bool $isPubliclyVisible,
        null|string $viewerPlayerId,
        EventAttendance $attendance,
        bool $justJoined,
        null|string $seriesSlug = null,
    ): null|PartnerFinderCard {
        $categories = array_map(static fn (EditionRoundDetail $round) => $round->category, $rounds);

        if ($isPubliclyVisible === false || $this->eligibility->mightQualify($event, $categories) === false) {
            return null;
        }

        $data = null;

        if ($viewerPlayerId !== null) {
            $data = $this->getPartnerCardData->forViewer($event->id, $viewerPlayerId);

            // The SQL rule said no (an edition of a series not approved, …) - no finder at all
            if ($data === null) {
                return null;
            }
        }

        $state = self::stateFor($data, $attendance);

        return new PartnerFinderCard(
            finderUrl: $this->finderUrl($event, $seriesSlug),
            listingFormUrl: $this->url('partner_listing_form', ['competitionId' => $event->id]),
            offer: PartnerFinderOffer::fromRoundCategories($categories),
            data: $data,
            state: $state,
            highlighted: $state === PartnerCardState::Invite && $justJoined,
        );
    }

    /**
     * Which card (null = none): no card for a guest or a player in a pair/team entry; a listed player gets the status
     * line (not closable); a closed card stays closed (D8); going - or any state of a managed registration, pairs often
     * cannot register without a partner (D2) - is invited; not going sees the card only when somebody is listed.
     */
    public static function stateFor(null|PartnerCardData $data, EventAttendance $attendance): null|PartnerCardState
    {
        if ($data === null || $data->viewerEntered) {
            return null;
        }

        if ($data->isListed()) {
            return PartnerCardState::Listed;
        }

        if ($data->cardDismissed) {
            return null;
        }

        if ($attendance->isGoing || $attendance->registration !== null) {
            return PartnerCardState::Invite;
        }

        return $data->listingCount > 0 ? PartnerCardState::Browse : null;
    }

    private function finderUrl(CompetitionEvent $event, null|string $seriesSlug): null|string
    {
        if ($event->slug === null) {
            return null;
        }

        if ($event->seriesId !== null) {
            return $seriesSlug !== null
                ? $this->url('edition_partners', ['seriesSlug' => $seriesSlug, 'editionSlug' => $event->slug])
                : null;
        }

        return $this->url('event_partners', ['slug' => $event->slug]);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function url(string $route, array $parameters): null|string
    {
        // TODO(partner finder merge): the finder page and the listing form are built by part A in parallel - drop this
        // guard once they are merged (docs/features/partner-finder.md "Part B as built")
        try {
            return $this->urlGenerator->generate($route, $parameters);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
