<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PartnerFinder;

use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Exceptions\PartnerFinderNotAvailable;
use SpeedPuzzling\Web\Query\GetPartnerListings;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerFinderUrls;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * The partner finder page of one event (docs/features/partner-finder.md, "Part A as built"): one statement besides the
 * event's own load - the teaser for a guest, the listings for a signed-in viewer - which also answers whether the event
 * has a finder (404 otherwise). Never indexed. The one-time event and the series edition routes share it.
 */
abstract class AbstractPartnerFinderPageController extends AbstractController
{
    private GetPartnerListings $getPartnerListings;

    private RetrieveLoggedUserProfile $retrieveLoggedUserProfile;

    private PartnerFinderUrls $partnerFinderUrls;

    #[Required]
    public function setPartnerFinderPageServices(
        GetPartnerListings $getPartnerListings,
        RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        PartnerFinderUrls $partnerFinderUrls,
    ): void {
        $this->getPartnerListings = $getPartnerListings;
        $this->retrieveLoggedUserProfile = $retrieveLoggedUserProfile;
        $this->partnerFinderUrls = $partnerFinderUrls;
    }

    /**
     * @throws PartnerFinderNotAvailable
     */
    protected function renderFinder(Competition $competition, Request $request): Response
    {
        $competitionId = $competition->id->toString();
        $viewer = $this->retrieveLoggedUserProfile->getProfile();
        $finderUrl = $this->partnerFinderUrls->finder($competition);
        $eventUrl = $this->partnerFinderUrls->event($competition);

        $series = $competition->series !== null && $competition->series->slug !== null ? [
            'name' => $competition->series->name,
            'url' => $this->generateUrl('competition_series_detail', ['slug' => $competition->series->slug]),
        ] : null;

        $parameters = [
            'competition_id' => $competitionId,
            'event_name' => $competition->name,
            'series' => $series,
            'event_url' => $eventUrl,
            'finder_url' => $finderUrl,
            'registration_managed' => $competition->registrationManaged,
        ];

        if ($viewer === null) {
            $teaser = $this->getPartnerListings->teaserForEvent($competitionId) ?? throw new PartnerFinderNotAvailable();

            $response = $this->render('partner_finder/finder.html.twig', $parameters + [
                'teaser' => $teaser,
                'listings' => null,
                'countries' => $this->countries($teaser->countries, $request->getLocale()),
            ]);
        } else {
            $listings = $this->getPartnerListings->forEvent($competitionId, $viewer->playerId, $viewer->activeMembership)
                ?? throw new PartnerFinderNotAvailable();

            $response = $this->render('partner_finder/finder.html.twig', $parameters + [
                'teaser' => null,
                'listings' => $listings,
                'viewer_is_member' => $viewer->activeMembership,
                'countries' => $this->countries($listings->countryCounts(), $request->getLocale()),
            ]);
        }

        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }

    /**
     * @param array<string, int> $counts country code => listings
     *
     * @return list<array{code: string, name: string, count: int}>
     */
    private function countries(array $counts, string $locale): array
    {
        $countries = [];

        foreach ($counts as $code => $count) {
            $country = CountryCode::fromCode($code);

            if ($country !== null) {
                $countries[] = ['code' => $country->name, 'name' => $country->localizedName($locale), 'count' => $count];
            }
        }

        return $countries;
    }
}
