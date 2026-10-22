<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PartnerFinder;

use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Exceptions\DraftNotVisible;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The partner finder page of a one-time event (docs/features/partner-finder.md); an edition's lives under its series.
 */
final class EventPartnersController extends AbstractPartnerFinderPageController
{
    #[Route(
        path: [
            'cs' => '/eventy/{slug}/partaci',
            'en' => '/en/events/{slug}/partners',
            'es' => '/es/eventos/{slug}/companeros',
            'ja' => '/ja/イベント/{slug}/partners',
            'fr' => '/fr/evenements/{slug}/partenaires',
            'de' => '/de/veranstaltungen/{slug}/partner',
        ],
        name: 'event_partners',
        methods: ['GET'],
    )]
    public function __invoke(
        // An edition's slug is unique only within its series - a standalone event holding the slug wins
        #[MapEntity(expr: 'repository.findOneBy({"slug": slug}, {"series": "DESC"})')] Competition $competition,
        Request $request,
    ): Response {
        // A draft has no finder - for its team neither (PartnerFinderEligibility); no redirect telling an edition's
        // draft series URL either
        if ($competition->isHiddenAsDraft()) {
            throw new DraftNotVisible();
        }

        if ($competition->series !== null && $competition->series->slug !== null && $competition->slug !== null) {
            return $this->redirectToRoute('edition_partners', [
                'seriesSlug' => $competition->series->slug,
                'editionSlug' => $competition->slug,
            ], Response::HTTP_MOVED_PERMANENTLY);
        }

        return $this->renderFinder($competition, $request);
    }
}
