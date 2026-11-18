<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The old "Add event" address - the classic create form was retired on 2026-11-18 (Jan: "the wizard to be default and no
 * 'Use classic form' button - the classic form ever makes sense only for some specific update"). Every request leads to
 * the event creation wizard (docs/features/competitions-management/creation-wizard.md "Classic create retired"), so old
 * bookmarks and links keep working:
 *
 * - `?recurring=1` (the classic form's "Recurring" ticked) → the wizard's series Basics;
 * - anything else (`?classic=1` included) → the wizard's entry, "What are you organizing?";
 * - `?organization=` is kept on both (the wizard pre-selects it only for the organization's team).
 *
 * A POST (a classic form left open in a tab) creates nothing: 303 to the same place, where the event is set up again -
 * never half saved.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class AddCompetitionController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/pridat-udalost',
            'en' => '/en/add-event',
            'es' => '/es/add-event',
            'ja' => '/ja/add-event',
            'fr' => '/fr/add-event',
            'de' => '/de/add-event',
        ],
        name: 'add_competition',
    )]
    public function __invoke(Request $request): Response
    {
        $parameters = array_filter(['organization' => $request->query->getString('organization')]);
        $route = $request->query->getString('recurring') === '1' ? 'event_wizard_series_basics' : 'event_wizard';

        return $this->redirectToRoute(
            $route,
            $parameters,
            $request->isMethod('GET') ? Response::HTTP_FOUND : Response::HTTP_SEE_OTHER,
        );
    }
}
