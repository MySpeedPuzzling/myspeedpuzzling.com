<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Marketplace;

use SpeedPuzzling\Web\Exceptions\MarketplaceBanned;
use SpeedPuzzling\Web\Message\ChooseEventOffers;
use SpeedPuzzling\Web\Query\GetEventOfferChoices;
use SpeedPuzzling\Web\Query\GetMarketplaceEvents;
use SpeedPuzzling\Web\Repository\SellSwapListItemEventRepository;
use SpeedPuzzling\Web\Results\MarketplaceEvent;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "What will you bring to the event?" - the seller picks the listings they pack (docs/features/marketplace/11-events.md).
 * Only for members going to a marketplace event; everybody else is sent to the event page, and an event that is
 * not a marketplace event (online, over, not public) is a 404. `?joined=1` = the next step right after "I'm going"
 * (F1): a confirmation on top and "Skip for now" instead of "Cancel".
 *
 * GET costs 2 queries (the seller's marketplace events, then the listings with their links). Save dispatches
 * ChooseEventOffers and returns to the event page.
 */
final class EventOffersPickerController extends AbstractController
{
    // Stateless (config/packages/csrf.php): the token must not start a session write on every picker view
    public const string CSRF_TOKEN_ID = 'event_offers_picker';

    public function __construct(
        readonly private GetMarketplaceEvents $getMarketplaceEvents,
        readonly private GetEventOfferChoices $getEventOfferChoices,
        readonly private SellSwapListItemEventRepository $sellSwapListItemEventRepository,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/eventy/{competitionId}/co-privezu',
            'en' => '/en/events/{competitionId}/what-i-bring',
            'es' => '/es/eventos/{competitionId}/que-llevo',
            'ja' => '/ja/イベント/{competitionId}/持っていくもの',
            'fr' => '/fr/evenements/{competitionId}/ce-que-j-apporte',
            'de' => '/de/veranstaltungen/{competitionId}/was-ich-mitbringe',
        ],
        name: 'event_offers_picker',
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        assert($profile !== null);

        if ($profile->activeMembership === false) {
            return $this->redirectToEvent($this->getMarketplaceEvents->byId($competitionId));
        }

        $event = $this->goingTo($profile, $competitionId);

        if ($event === null) {
            // Not going - or not a marketplace event at all, byId() answers 404 then
            return $this->redirectToEvent($this->getMarketplaceEvents->byId($competitionId));
        }

        if ($request->isMethod('POST')) {
            return $this->save($request, $profile, $event);
        }

        return $this->render('marketplace/event_offers_picker.html.twig', [
            'event' => $event,
            'choices' => $this->getEventOfferChoices->forPicker($profile->playerId, $event->competitionId),
            'joined' => $request->query->getBoolean('joined'),
        ]);
    }

    private function save(Request $request, PlayerProfile $profile, MarketplaceEvent $event): Response
    {
        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw new AccessDeniedHttpException();
        }

        $listItemIds = [];

        foreach ($request->request->all('listings') as $listItemId) {
            if (is_string($listItemId) && $listItemId !== '') {
                $listItemIds[strtolower($listItemId)] = $listItemId;
            }
        }

        try {
            $this->messageBus->dispatch(new ChooseEventOffers(
                playerId: $profile->playerId,
                competitionId: $event->competitionId,
                listItemIds: array_values($listItemIds),
            ));
        } catch (HandlerFailedException $exception) {
            if ($exception->getPrevious() instanceof MarketplaceBanned) {
                throw $this->createAccessDeniedException();
            }

            throw $exception;
        }

        $count = count($this->sellSwapListItemEventRepository->forPlayerAndCompetition($profile->playerId, $event->competitionId));
        $eventName = $event->reference->displayName();

        $this->addFlash('success', $count > 0
            ? $this->translator->trans('marketplace_events.picker.saved', ['%count%' => $count, '%event%' => $eventName])
            : $this->translator->trans('marketplace_events.picker.saved_none', ['%event%' => $eventName]));

        return $this->redirectToEvent($event);
    }

    private function goingTo(PlayerProfile $profile, string $competitionId): null|MarketplaceEvent
    {
        foreach ($this->getMarketplaceEvents->forPlayer($profile->playerId) as $event) {
            if ($event->competitionId === strtolower($competitionId)) {
                return $event;
            }
        }

        return null;
    }

    private function redirectToEvent(MarketplaceEvent $event): Response
    {
        $routeName = $event->reference->routeName();

        if ($routeName === null) {
            return $this->redirectToRoute('events');
        }

        return $this->redirectToRoute($routeName, $event->reference->routeParameters());
    }
}
