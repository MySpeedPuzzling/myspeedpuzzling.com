<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\SellSwap;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\MarketplaceBanned;
use SpeedPuzzling\Web\Message\BringListingToEvent;
use SpeedPuzzling\Web\Query\GetMarketplaceEvents;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
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
use Symfony\UX\Turbo\TurboBundle;

/**
 * "Bring to event" in a conversation about a listing (docs/features/marketplace/11-events.md): "Bring it to the event"
 * or "Bring it and reserve it for <buyer>" - the buyer must be a conversation partner about this listing (404
 * otherwise), and a listing reserved meanwhile keeps its reservation (BringListingToEventHandler). Answers like the
 * reserve action in the conversation: a Turbo Stream that re-renders the menu (and the Reserve / Sold actions after a
 * reservation) with a toast, otherwise a flash and back.
 */
final class BringListingToEventController extends AbstractController
{
    // Stateless (config/packages/csrf.php): the menu sits on every conversation page a seller opens
    public const string CSRF_TOKEN_ID = 'sell_swap_bring_to_event';

    public function __construct(
        readonly private SellSwapListItemRepository $sellSwapListItemRepository,
        readonly private PlayerRepository $playerRepository,
        readonly private GetMarketplaceEvents $getMarketplaceEvents,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/en/sell-swap/{itemId}/bring-to-event',
        name: 'sell_swap_bring_to_event',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $itemId): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw new AccessDeniedHttpException();
        }

        $item = $this->sellSwapListItemRepository->get($itemId);

        if ($item->player->id->toString() !== $loggedPlayer->playerId) {
            throw $this->createAccessDeniedException();
        }

        if ($loggedPlayer->activeMembership === false) {
            $this->addFlash('warning', $this->translator->trans('sell_swap_list.membership_required.message'));

            return $this->redirectToRoute('sell_swap_list_detail', ['playerId' => $loggedPlayer->playerId]);
        }

        $competitionId = $request->request->getString('competition_id');
        $reserveForPlayerId = $request->request->getString('reserve_for_player_id');
        $reserveForPlayerId = $reserveForPlayerId !== '' ? $reserveForPlayerId : null;

        try {
            $this->messageBus->dispatch(new BringListingToEvent(
                playerId: $loggedPlayer->playerId,
                listItemId: $item->id->toString(),
                competitionId: $competitionId,
                reserveForPlayerId: $reserveForPlayerId,
            ));
        } catch (HandlerFailedException $exception) {
            if ($exception->getPrevious() instanceof MarketplaceBanned) {
                throw $this->createAccessDeniedException();
            }

            throw $exception;
        }

        $event = $this->getMarketplaceEvents->byId($competitionId);
        $message = $this->translator->trans('marketplace_events.chat.brought', [
            '%puzzle%' => $item->puzzle->name,
            '%event%' => $event->reference->displayName(),
        ]);

        if ($request->request->getString('context') === 'conversation' && TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $otherPlayerId = $request->request->getString('other_player_id');
            $buyer = Uuid::isValid($otherPlayerId) ? $this->playerRepository->get($otherPlayerId) : null;

            return new Response(
                $this->renderView('messaging/_conversation_bring_to_event_stream.html.twig', [
                    'message' => $message,
                    'item_id' => $item->id->toString(),
                    'reserved' => $item->reserved,
                    // Reserved by this action, or found reserved already (by another tab) - the actions show it either way
                    'reserved_now' => $reserveForPlayerId !== null && $item->reserved,
                    'events' => $this->getMarketplaceEvents->forListingSeller($loggedPlayer->playerId, $item->id->toString(), $buyer?->id->toString()),
                    'buyer_id' => $buyer?->id->toString(),
                    'buyer_name' => $buyer !== null ? ($buyer->name ?? $buyer->code) : null,
                ]),
                Response::HTTP_OK,
                ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
            );
        }

        $this->addFlash('success', $message);

        $referer = $request->headers->get('referer');
        if ($referer !== null && $referer !== '') {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('sell_swap_list_detail', ['playerId' => $loggedPlayer->playerId]);
    }
}
