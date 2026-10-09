<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Events;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetOrganizedEvents;
use SpeedPuzzling\Web\Results\OrganizedEvent;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;
use SpeedPuzzling\Web\Security\OrganizationEditVoter;
use SpeedPuzzling\Web\Services\Drafts\UnpublishBlockers;
use SpeedPuzzling\Web\Value\ReturnUrl;
use SpeedPuzzling\Web\Value\UnpublishBlocker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The ⋯ menu of an events page row (docs/features/events-page/README.md, "The ⋯ menu"), loaded on demand so the page
 * carries no forms or session CSRF tokens for rows nobody opens. Inside <turbo-frame id="event-manage-menu"> when
 * the events page asks for it (event_manage_menu_controller.js), a full page with a back link otherwise (no JavaScript).
 * Only items the viewer may use are listed (events/_manage_items.html.twig asks the voters). Of a published event or
 * series, the menu knows whether it could go back to draft (UnpublishBlockers - docs/features/organizations/README.md
 * "The ⋯ menu") and says why not instead of offering Unpublish.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EventManageMenuController extends AbstractController
{
    public const string FRAME_ID = 'event-manage-menu';

    private const int RETURN_TITLE_MAX_LENGTH = 80;

    public function __construct(
        readonly private GetOrganizedEvents $getOrganizedEvents,
        readonly private TranslatorInterface $translator,
        readonly private UnpublishBlockers $unpublishBlockers,
    ) {
    }

    #[Route(
        path: '/{_locale}/event-actions/{kind}/{id}',
        name: 'event_manage_menu',
        requirements: [
            'kind' => 'competition|series|organization',
            'id' => FirstTryConflictsController::ID_REQUIREMENT,
        ],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $kind, string $id): Response
    {
        $id = strtolower($id);

        $this->denyAccessUnlessGranted(match ($kind) {
            'series' => CompetitionSeriesEditVoter::COMPETITION_SERIES_EDIT,
            'organization' => OrganizationEditVoter::ORGANIZATION_EDIT,
            default => CompetitionEditVoter::COMPETITION_EDIT,
        }, $id);

        $items = match ($kind) {
            'series' => $this->getOrganizedEvents->byIds([], [$id]),
            'organization' => $this->getOrganizedEvents->byIds([], [], [$id]),
            default => $this->getOrganizedEvents->byIds([$id], []),
        };

        $item = $items[0] ?? throw new NotFoundHttpException();

        $returnUrl = ReturnUrl::tryFrom($request->query->getString('return'))->path ?? $this->generateUrl('events');
        // Deleting the page the menu was opened on returns to its parent - the deleted page would answer 404
        $deleteReturn = ReturnUrl::tryFrom($request->query->getString('delete_return'))->path ?? $returnUrl;
        $returnTitle = mb_substr(trim($request->query->getString('return_title')), 0, self::RETURN_TITLE_MAX_LENGTH);

        if ($returnTitle === '') {
            $returnTitle = $this->translator->trans('events_page.title');
        }

        $inFrame = $request->headers->get('Turbo-Frame') === self::FRAME_ID;

        $response = $this->render($inFrame ? 'events/_manage_menu_frame.html.twig' : 'events/manage_menu.html.twig', [
            'item' => $item,
            'frame_id' => self::FRAME_ID,
            'return_url' => $returnUrl,
            'return_title' => $returnTitle,
            'delete_return' => $deleteReturn,
            'unpublish_blockers' => $this->unpublishBlockers($item),
        ]);

        // Carries session CSRF tokens of the delete forms: never cached, never shared
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->setVary('Turbo-Frame', false);

        return $response;
    }

    /**
     * What keeps a published item from going back to draft - nothing for a draft (nothing to unpublish) and for an
     * organization (it can always go back)
     *
     * @return list<UnpublishBlocker>
     */
    private function unpublishBlockers(OrganizedEvent $item): array
    {
        if ($item->isDraft || $item->isOrganization()) {
            return [];
        }

        $check = $item->isSeries()
            ? $this->unpublishBlockers->forSeries($item->id)
            : $this->unpublishBlockers->forCompetition($item->id);

        return $check->blockers();
    }
}
