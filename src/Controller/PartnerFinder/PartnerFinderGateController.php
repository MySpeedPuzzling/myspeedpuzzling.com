<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PartnerFinder;

use SpeedPuzzling\Web\Query\GetPartnerFinderEvents;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerFinderUrls;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The partner finder's gate (docs/features/partner-finder.md "Part D as built"): what it is in three steps, a picker of
 * every event with a finder and the events with the most people looking - the page marketing links to. Indexable, the
 * same HTML for everybody (nothing of the viewer is read), ONE statement (GetPartnerFinderEvents).
 *
 * `?event=<competition id>` is the picker without JavaScript: 302 to that event's finder; an id without a finder (passed
 * meanwhile, a draft, nonsense) shows the page again.
 */
final class PartnerFinderGateController extends AbstractController
{
    public const int MOST_LOOKING_LIMIT = 6;

    public function __construct(
        readonly private GetPartnerFinderEvents $getPartnerFinderEvents,
        readonly private PartnerFinderUrls $partnerFinderUrls,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/hledani-partnera',
            'en' => '/en/partner-finder',
            'es' => '/es/buscar-companero',
            'ja' => '/ja/パートナー探し',
            'fr' => '/fr/trouver-un-partenaire',
            'de' => '/de/partnersuche',
        ],
        name: 'partner_finder',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        $gate = $this->getPartnerFinderEvents->all();
        $picked = $request->query->all()['event'] ?? null;

        if (is_string($picked) && $picked !== '') {
            $event = $gate->find($picked);
            $finderUrl = $event !== null
                ? $this->partnerFinderUrls->forSlugs($event->event->reference->slug, $event->event->reference->seriesSlug)
                : null;

            if ($finderUrl !== null) {
                return new RedirectResponse($finderUrl);
            }
        }

        return $this->render('partner_finder/gate.html.twig', [
            'gate' => $gate,
            'most_looking' => $gate->mostLooking(self::MOST_LOOKING_LIMIT),
        ]);
    }
}
